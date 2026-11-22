<?php

namespace Tests\Feature;

use App\Enums\DocumentAction;
use App\Enums\DocumentVisibility;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\DocumentSeries;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\Documents\QrLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Both: QR labels and document bytes live on the private `documents`
        // disk now, and the `public` disk is still faked so a legacy-path read
        // in a test cannot reach the real filesystem.
        Storage::fake('documents');
        Storage::fake('public');
    }

    private function typeId(string $code): int
    {
        return DocumentType::query()->where('code', $code)->value('id');
    }

    /**
     * Every level but confidential is minted a link, so the label has one
     * shape.
     *
     * This briefly withheld the link from a private document, because an
     * automatic link printed on a folder was a bearer credential nobody had
     * asked for. That is no longer what a link to a restricted document is:
     * `/s/{token}` resolves the holder's own access, so a private label opens
     * for the people tagged on it and states the classification to anybody
     * else. With the credential gone, the reason to withhold it went too.
     *
     * Confidential keeps none, which is the level's whole definition.
     */
    public function test_every_level_but_confidential_is_given_a_link_automatically(): void
    {
        $labels = app(QrLabelService::class);

        foreach (['public' => true, 'private' => true, 'confidential' => false] as $visibility => $expected) {
            $user = User::factory()->create();
            $tagged = User::factory()->create();

            $this->actingAs($user)->post('/documents', [
                'title' => "A {$visibility} memo",
                'document_type_id' => $this->typeId('MEMO'),
                'visibility' => $visibility,
                // The restricted levels are a named list, so they are filed
                // the way the form files them.
                'recipients' => $visibility === 'public' ? [] : [$tagged->id],
                'file' => UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
            ])->assertSessionHasNoErrors()->assertRedirect();

            $document = Document::query()->latest('id')->sole();
            $link = $document->shareLinks()->whereNull('revoked_at')->first();

            $this->assertSame(
                $expected,
                $link !== null,
                "A {$visibility} upload should ".($expected ? 'be given' : 'not be given').' a link automatically.',
            );

            /*
             * And the label follows it. A document with no link carries the
             * tracking URL, which asks a scanner to sign in — the whole
             * point of withholding the link.
             */
            $this->assertSame(
                $expected
                    ? $link->url()
                    : $labels->trackingUrl($document->code),
                $labels->targetFor($document),
                "A {$visibility} document's label points at the wrong thing.",
            );

            $document->forceDelete();
        }
    }

    public function test_uploading_a_document_issues_a_tracking_code_and_a_label(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/documents', [
            'title' => 'Memo on Office Hours',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
        ])->assertRedirect();

        $document = Document::sole();

        $this->assertStringStartsWith('MEMO-', $document->code);
        // PNG now, with the seal embedded: endroid/qr-code renders through
        // GD, where simple-qrcode needed the Imagick backend these servers
        // do not have. Rows written before the switch keep their .svg names.
        $this->assertSame("{$document->code}.png", $document->qr_code_path);
        // The type's name is snapshotted beside the foreign key, so renaming
        // the type cannot rewrite what this document was filed as. Read off
        // the type rather than spelled out, because what the office calls a
        // type is office business — the snapshotting is what is under test.
        $this->assertSame(
            DocumentType::query()->where('code', 'MEMO')->value('name'),
            $document->type,
        );
        $this->assertSame($user->name, $document->uploader_name);
        $this->assertSame('memo.pdf', $document->file_name);
    }

    /**
     * A document's history starts where the document does.
     */
    public function test_an_upload_opens_the_document_trail(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/documents', [
            'title' => 'Memo',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
        ]);

        $log = Document::sole()->logs()->sole();

        $this->assertSame(DocumentAction::Uploaded, $log->action);
        $this->assertSame($user->id, $log->actor_id);
        // Snapshotted, so the entry still names the actor after the account
        // is gone.
        $this->assertSame($user->name, $log->actor_name);
    }

    /**
     * The counter used to be "every document created today, plus one", so a
     * second type uploaded the same day skipped a number and two uploads of
     * the same type could collide on one. Each series is its own now.
     */
    public function test_tracking_codes_are_sequential_within_a_type_and_independent_across_types(): void
    {
        $user = User::factory()->create();

        foreach ([['MEMO', 'memo-a'], ['MEMO', 'memo-b'], ['SO', 'so-a']] as [$code, $name]) {
            $this->actingAs($user)->post('/documents', [
                'title' => $name,
                'document_type_id' => $this->typeId($code),
                'visibility' => 'public',
                'file' => UploadedFile::fake()->create("{$name}.pdf", 16, 'application/pdf'),
            ])->assertRedirect();
        }

        $codes = Document::query()->orderBy('id')->pluck('code')->all();

        $this->assertMatchesRegularExpression('/^MEMO-\d{8}-0001$/', $codes[0]);
        $this->assertMatchesRegularExpression('/^MEMO-\d{8}-0002$/', $codes[1]);
        $this->assertMatchesRegularExpression('/^SO-\d{8}-0001$/', $codes[2]);
    }

    /**
     * Counting the codes already on record is self-correcting, but on its own
     * it hands a deleted document's code to the next upload. `document_series`
     * is the floor that stops that: in a system whose whole job is to be the
     * record of which document is which, two documents sharing a tracking
     * code is the worst thing it can do.
     */
    public function test_a_deleted_document_does_not_hand_its_tracking_code_to_the_next_upload(): void
    {
        $user = User::factory()->create();

        $upload = fn (string $title) => $this->actingAs($user)->post('/documents', [
            'title' => $title,
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
        ]);

        $upload('first');
        $first = Document::sole();
        $firstCode = $first->code;

        $first->delete();
        $upload('second');

        $this->assertNotSame($firstCode, Document::sole()->code);
        $this->assertSame(2, DocumentSeries::baselineFor(substr($firstCode, 0, -5)));
    }

    /**
     * Importing an office's historical documents makes the series continue
     * from where their paper trail left off, rather than restarting at 1 and
     * colliding with it.
     */
    public function test_a_series_continues_from_the_highest_code_already_on_record(): void
    {
        $user = User::factory()->create();
        $prefix = 'MEMO-'.now()->format('Ymd');

        Document::factory()->create([
            'code' => "{$prefix}-0417",
            'document_type_id' => $this->typeId('MEMO'),
        ]);

        $this->actingAs($user)->post('/documents', [
            'title' => 'next',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
        ]);

        $this->assertSame("{$prefix}-0418", Document::query()->latest('id')->value('code'));
    }

    /**
     * The register tells the page whether a link may exist, rather than
     * leaving a template to work it out from the level's name.
     */
    public function test_the_register_says_whether_each_document_can_be_linked(): void
    {
        $user = User::factory()->create();

        foreach (['public' => true, 'private' => true, 'confidential' => false] as $level => $expected) {
            $document = Document::factory()->create([
                'user_id' => $user->id,
                'visibility' => $level,
            ]);

            $this->assertSame(
                $expected,
                $document->linkable,
                "A {$level} document reported the wrong linkability.",
            );
            $this->assertArrayHasKey('linkable', $document->toArray());
        }
    }

    /**
     * A confidential upload may name nobody, which is the levels agreeing
     * rather than confidential being relaxed.
     *
     * It was refused, on the reasoning that a confidential document has no
     * door but its recipient list, so an empty one is filed where nobody can
     * act on it. The document was read right and the conclusion was wrong:
     * filing something before you know who it goes to is ordinary office
     * work, and the refusal did not produce a tagged document — it produced
     * an *arbitrarily* tagged one, because the way past the form was to name
     * whoever came to mind. A tag somebody has to notice and undo is worse
     * than an empty list, which is just a document waiting to be addressed.
     *
     * What the level means is unchanged: still no link, ever, and still the
     * uploader plus the tagged list. The list simply starts empty, and
     * tagging somebody is an edit.
     */
    public function test_a_confidential_upload_may_name_nobody(): void
    {
        $uploader = User::factory()->create();

        $this->actingAs($uploader)->post('/documents', [
            'title' => 'Case File',
            'document_type_id' => $this->typeId('CONFID'),
            'visibility' => 'confidential',
            'file' => UploadedFile::fake()->create('case.pdf', 16, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $document = Document::sole();

        $this->assertSame(DocumentVisibility::Confidential, $document->visibility);
        $this->assertCount(0, $document->recipients);

        // And it is still confidential: no link was minted with it, and none
        // can be.
        $this->assertSame(0, $document->shareLinks()->count());
        $this->actingAs($uploader)
            ->post("/documents/{$document->id}/share-links", ['ability' => 'view'])
            ->assertSessionHas('error');

        // Readable by its uploader, and by nobody else.
        $this->actingAs(User::factory()->create())
            ->get("/documents/view/{$document->code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/restricted', false));
    }

    /**
     * Private is the other one, and the difference is the link.
     * "Restricted, and I will decide who later" is a real thing to want;
     * refusing the upload until a name was typed made the office tag somebody
     * arbitrarily just to get past the form.
     */
    public function test_a_private_upload_may_name_nobody(): void
    {
        $uploader = User::factory()->create();

        $this->actingAs($uploader)->post('/documents', [
            'title' => 'Draft Policy',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'private',
            'file' => UploadedFile::fake()->create('draft.pdf', 16, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $document = Document::sole();

        $this->assertSame(DocumentVisibility::Private, $document->visibility);
        $this->assertCount(0, $document->recipients);
    }

    /** And it is readable by its uploader and nobody else. */
    public function test_an_untagged_private_document_is_readable_only_by_its_uploader(): void
    {
        $uploader = User::factory()->create();

        $this->actingAs($uploader)->post('/documents', [
            'title' => 'Draft Policy',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'private',
            'file' => UploadedFile::fake()->create('draft.pdf', 16, 'application/pdf'),
        ]);

        $code = Document::sole()->code;

        $this->actingAs($uploader)->get("/documents/view/{$code}")->assertOk();
        $this->actingAs(User::factory()->create())->get("/documents/view/{$code}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('documents/restricted', false));
    }

    /**
     * `document_recipients` had no unique pair, so the same person could be
     * attached twice — notified twice, and counted twice by every visibility
     * check that read those rows. The table rejects it now, and so does
     * validation, which is where the user gets told.
     */
    public function test_naming_the_same_recipient_twice_is_refused(): void
    {
        $recipient = User::factory()->create();

        $this->actingAs(User::factory()->create())->post('/documents', [
            'title' => 'Case File',
            'document_type_id' => $this->typeId('CONFID'),
            'visibility' => 'private',
            'recipients' => [$recipient->id, $recipient->id],
            'file' => UploadedFile::fake()->create('case.pdf', 16, 'application/pdf'),
        ])->assertSessionHasErrors('recipients.0');
    }

    /**
     * A retired type is off the upload form, and must be off the endpoint too.
     */
    public function test_a_retired_document_type_cannot_be_uploaded_against(): void
    {
        $retired = DocumentType::factory()->retired()->create();

        $this->actingAs(User::factory()->create())->post('/documents', [
            'title' => 'Memo',
            'document_type_id' => $retired->id,
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
        ])->assertSessionHasErrors('document_type_id');
    }

    /**
     * Nothing ever wrote to `document_downloads`, so the Download Logs report
     * was permanently empty.
     *
     * This used to be able to assert only the *negative*. The controller built
     * its path with `storage_path()`, which reaches past `Storage::fake()`, so
     * the file was never found and the test could only check that a missing
     * file records nothing. Reading through the Storage facade is what makes
     * the real path testable at all.
     */
    public function test_downloading_a_file_records_a_download_row(): void
    {
        $user = User::factory()->create();
        $document = $this->upload($user);

        $this->actingAs($user)
            ->get("/documents/{$document->id}/file/download")
            ->assertOk();

        $this->assertSame(1, DocumentDownload::query()->where('document_id', $document->id)->count());
        $this->assertSame(
            $user->id,
            DocumentDownload::query()->where('document_id', $document->id)->value('user_id'),
        );
    }

    /**
     * The existence check happens before any row is written, so a file that has
     * gone missing off the disk does not leave a record of a release that never
     * happened.
     */
    public function test_a_missing_file_records_no_download(): void
    {
        $user = User::factory()->create();
        $document = $this->upload($user);

        Storage::disk('documents')->delete($document->file->storage_path);

        $this->actingAs($user)
            ->get("/documents/{$document->id}/file/download")
            ->assertRedirect();

        $this->assertSame(0, DocumentDownload::query()->where('document_id', $document->id)->count());
    }

    /**
     * The bytes go to the private disk, under a path built from the file row's
     * own opaque identifier — never the uploaded filename, which stays as
     * display metadata.
     */
    public function test_an_upload_lands_on_the_private_disk_under_an_opaque_path(): void
    {
        $document = $this->upload(User::factory()->create(), 'Quarterly Report.pdf');

        $file = $document->file;

        $this->assertNotNull($file);
        $this->assertSame('documents', $file->disk);
        $this->assertSame('Quarterly Report.pdf', $file->original_name);

        Storage::disk('documents')->assertExists($file->storage_path);

        // The path is derived from the public id and carries nothing the
        // uploader typed.
        $this->assertStringContainsString($file->public_id, $file->storage_path);
        $this->assertStringNotContainsString('Quarterly', $file->storage_path);
        $this->assertStringNotContainsString(' ', $file->storage_path);

        // And nothing was written where the web server could serve it.
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    private function upload(User $user, string $filename = 'memo.pdf'): Document
    {
        $this->actingAs($user)->post('/documents', [
            'title' => 'Memo',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create($filename, 16, 'application/pdf'),
        ]);

        return Document::query()->with('file')->latest('id')->sole();
    }

    /**
     * The success page hands the label to the browser as **markup**, not as an
     * object.
     *
     * `QrCode::generate()` returns an `Illuminate\Support\HtmlString`. Blade
     * and the disk both stringify it, so it is right everywhere else in this
     * controller — and Inertia does not: `HtmlString` is neither
     * `JsonSerializable` nor a carrier of public properties, so the prop
     * serialised as `{}` and the page rendered `[object Object]` where the QR
     * label should have been.
     */
    public function test_the_success_page_sends_the_label_as_markup(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/documents', [
            'title' => 'Memo on Office Hours',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
        ])->assertRedirect();

        $document = Document::sole();

        $props = $this->actingAs($user)
            ->get("/documents/success/{$document->code}/{$document->qr_code_path}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertIsString($props['qrCodeSvg']);
        $this->assertStringContainsString('<svg', $props['qrCodeSvg']);
    }

    // ------------------------------------------ editing who it is shared with

    /**
     * A private document filed with one reader, returned so each test can
     * change who it is for.
     *
     * @return array{Document, User, User, User} document, uploader, tagged, untagged
     */
    private function restricted(string $level = 'private'): array
    {
        $uploader = User::factory()->superadmin()->create();
        $tagged = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($uploader)->post('/documents', [
            'title' => 'Case File',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => $level,
            'file' => UploadedFile::fake()->create('case.pdf', 16, 'application/pdf'),
            'recipients' => [$tagged->id],
        ])->assertSessionHasNoErrors();

        return [Document::query()->latest('id')->firstOrFail(), $uploader, $tagged, $other];
    }

    private function save(User $uploader, Document $document, array $overrides = [])
    {
        return $this->actingAs($uploader)->put("/documents/{$document->id}", [
            'title' => $document->title,
            'document_type_id' => $document->document_type_id,
            'comments_enabled' => $document->comments_enabled,
            ...$overrides,
        ]);
    }

    /**
     * The edit form carries the picker and the live list.
     *
     * It used to ship `users => []`, which is what made tagging a decision
     * taken once at upload: a document filed before anybody knew who it was
     * for could never be addressed afterwards.
     */
    public function test_the_edit_form_offers_the_picker_and_the_current_recipients(): void
    {
        [$document, $uploader, $tagged] = $this->restricted();

        $props = $this->actingAs($uploader)->get("/documents/{$document->id}/edit")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame([$tagged->id], $props['recipients']);
        $this->assertNotEmpty($props['users'], 'the edit form must offer somebody to tag');
    }

    /** Tagging somebody on an existing document gives them the document. */
    public function test_a_recipient_added_on_the_edit_form_can_open_the_document(): void
    {
        [$document, $uploader, $tagged, $other] = $this->restricted();

        $this->assertSame(
            'documents/restricted',
            $this->actingAs($other)->get("/documents/view/{$document->code}")->viewData('page')['component'],
        );

        $this->save($uploader, $document, ['recipients' => [$tagged->id, $other->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'documents/show',
            $this->actingAs($other)->get("/documents/view/{$document->code}")->viewData('page')['component'],
            'a person tagged on the edit form must be able to open the document',
        );
    }

    /**
     * Taking somebody off here is the same act as the chip's remove button:
     * the row is stamped rather than deleted, so the refusal can still tell a
     * removed viewer from somebody never tagged.
     */
    public function test_a_recipient_dropped_on_the_edit_form_is_revoked_not_deleted(): void
    {
        [$document, $uploader, $tagged] = $this->restricted();

        $this->save($uploader, $document, ['recipients' => []])->assertSessionHasNoErrors();

        $this->assertFalse($document->recipients()->whereKey($tagged->id)->exists());
        $this->assertTrue($document->allRecipients()->whereKey($tagged->id)->exists());
        $this->assertTrue($document->fresh()->wasRemovedAsViewer($tagged));
    }

    /** Both halves are on the document's own trail, one row per person. */
    public function test_adding_and_removing_a_viewer_are_recorded_on_the_trail(): void
    {
        [$document, $uploader, $tagged, $other] = $this->restricted();

        $this->save($uploader, $document, ['recipients' => [$other->id]])->assertSessionHasNoErrors();

        $added = $document->logs()->where('action', DocumentAction::ViewerAdded->value)->get();
        $removed = $document->logs()->where('action', DocumentAction::ViewerRemoved->value)->get();

        $this->assertCount(1, $added);
        $this->assertCount(1, $removed);
        $this->assertStringContainsString($other->name, $added->first()->remarks);
        $this->assertStringContainsString($tagged->name, $removed->first()->remarks);
    }

    /**
     * A save that changes nothing about the list touches nobody.
     *
     * The diff is the point: a sync would re-stamp `notified_at` and
     * re-notify everybody tagged every time a title was corrected.
     */
    public function test_saving_an_unrelated_edit_does_not_re_tag_anybody(): void
    {
        [$document, $uploader, $tagged] = $this->restricted();

        $this->save($uploader, $document, ['title' => 'Case File (final)', 'recipients' => [$tagged->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame('Case File (final)', $document->fresh()->title);
        $this->assertSame(0, $document->logs()->whereIn('action', [
            DocumentAction::ViewerAdded->value,
            DocumentAction::ViewerRemoved->value,
        ])->count());
    }

    /**
     * A public document tags nobody, so the field is ignored rather than
     * obeyed: the level is what decides, and it is not editable here.
     */
    public function test_a_public_document_ignores_a_posted_recipient_list(): void
    {
        $uploader = User::factory()->superadmin()->create();
        $other = User::factory()->create();

        $this->actingAs($uploader)->post('/documents', [
            'title' => 'Office circular',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('circular.pdf', 16, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $document = Document::query()->latest('id')->firstOrFail();

        $this->save($uploader, $document, ['recipients' => [$other->id]])->assertSessionHasNoErrors();

        $this->assertSame(0, $document->allRecipients()->count());
    }

    /** Only the uploader may change the list, as with every other edit. */
    public function test_only_the_uploader_may_change_who_a_document_is_shared_with(): void
    {
        [$document, , $tagged, $other] = $this->restricted();

        $this->actingAs($tagged)->put("/documents/{$document->id}", [
            'title' => $document->title,
            'document_type_id' => $document->document_type_id,
            'recipients' => [$tagged->id, $other->id],
        ])->assertRedirect(route('dashboard'));

        $this->assertSame([$tagged->id], $document->recipients()->pluck('users.id')->all());
    }
}
