<?php

namespace Tests\Feature;

use App\Enums\DocumentAction;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\DocumentSeries;
use App\Models\DocumentType;
use App\Models\User;
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
        $this->assertSame("{$document->code}.svg", $document->qr_code_path);
        // The type's name is snapshotted beside the foreign key, so renaming
        // the type cannot rewrite what this document was filed as.
        $this->assertSame('Memo', $document->type);
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

    public function test_a_private_upload_requires_recipients(): void
    {
        $this->actingAs(User::factory()->create())->post('/documents', [
            'title' => 'Case File',
            'document_type_id' => $this->typeId('CONFID'),
            'visibility' => 'private',
            'file' => UploadedFile::fake()->create('case.pdf', 16, 'application/pdf'),
        ])->assertSessionHasErrors('recipients');
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
}
