<?php

namespace Tests\Feature;

use App\Enums\DocumentFiling;
use App\Enums\DocumentVisibility;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\User;
use App\Notifications\PrivateDocumentShared;
use App\Services\Documents\QrLabelService;
use App\Services\Files\FileStorage;
use App\Services\Files\FolderService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tracking a document while putting it in your own storage.
 *
 * The office asked for one upload, not two: a file that belongs in My Files
 * and also needs a tracking code should not have to be uploaded twice, or
 * uploaded through Documents and then hunted for. Both paths now go through
 * `App\Services\Documents\DocumentRegistrar`, so the only difference between a
 * tracked upload from here and one from the register is **which folder it
 * lands in**.
 */
class TrackedUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Roles::STAFF);

        return $user;
    }

    private function typeId(string $code): int
    {
        return DocumentType::query()->where('code', $code)->value('id');
    }

    private function upload(User $user, array $extra = [], int $files = 1)
    {
        $folder = app(FolderService::class)->rootFor($user);

        return $this->actingAs($user)->post('/files/upload', [
            'folder' => $folder->public_id,
            'files' => collect(range(1, $files))
                ->map(fn (int $i) => UploadedFile::fake()->create("memo{$i}.pdf", 16, 'application/pdf'))
                ->all(),
            ...$extra,
        ]);
    }

    /** A tracked upload, returning the document it created. */
    private function uploadTracked(array $extra = [], ?User $user = null): Document
    {
        $this->upload($user ?? $this->staff(), [
            'track' => true,
            'title' => 'Office Hours',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
            ...$extra,
        ])->assertRedirect();

        return Document::query()->latest('id')->sole();
    }

    /**
     * An ordinary upload is filed too, and the difference is *how*.
     *
     * This test used to assert the opposite — that an ordinary upload issued
     * no tracking code at all — and that was the rule until the office asked
     * for every uploaded file to carry a code and a QR. What survives of the
     * old reading is the distinction it was really about: a file dropped into
     * My Files was not *filed* by anybody, and that is now recorded as
     * `DocumentFiling::Automatic` rather than as an absence.
     *
     * The three properties of an automatic filing are asserted together,
     * because each one alone would look like a bug: the type is inferred, the
     * title is the filename, and nobody is tagged.
     */
    public function test_an_ordinary_upload_is_filed_automatically(): void
    {
        $this->upload($this->staff())->assertRedirect();

        $this->assertSame(1, File::query()->count());

        $document = Document::query()->sole();

        $this->assertSame(DocumentFiling::Automatic, $document->filed);
        $this->assertFalse(
            $document->filed->governsItsFile(),
            'An automatic filing must not decide who may read the file it is stapled to.',
        );
        $this->assertNotNull($document->code);
        $this->assertSame($document->file->original_name, $document->title);
        $this->assertSame(0, $document->recipients()->count());
    }

    /**
     * And it costs no share link and no rendered label.
     *
     * Both are the expensive half of registration — a link row with its
     * activity entry, and a 512px symbol drawn on a 1536×1536 GD canvas — and
     * an automatic filing happens on every upload. The label's *name* is
     * still recorded, so the download route's stored-name match is unchanged
     * and the bytes are drawn the first time somebody asks.
     */
    public function test_an_automatic_filing_mints_no_link_and_renders_no_label(): void
    {
        $this->upload($this->staff())->assertRedirect();

        $document = Document::query()->sole();

        $this->assertSame(0, $document->shareLinks()->count());
        $this->assertNotNull($document->qr_code_path);
        $this->assertFalse(
            Storage::disk(FileStorage::DISK)->exists('qrcodes/'.$document->qr_code_path),
            'The label is drawn on demand, not on every upload.',
        );
    }

    public function test_a_tracked_upload_issues_a_code_and_creates_a_document(): void
    {
        $user = $this->staff();

        $this->upload($user, [
            'track' => true,
            'title' => 'Office Hours',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = Document::sole();
        $file = File::sole();

        $this->assertStringStartsWith('MEMO-', $document->code);
        $this->assertSame($file->id, $document->file_id);
        $this->assertSame('Office Hours', $document->title);
        $this->assertSame($user->id, $document->user_id);
        $this->assertSame("{$document->code}.png", $document->qr_code_path);
        Storage::disk('documents')->assertExists("qrcodes/{$document->qr_code_path}");
    }

    /**
     * It stays where the person put it.
     *
     * They chose the folder by navigating to it. Neither door invents a
     * folder any more: the register's form asks where to save and defaults to
     * the top of the uploader's own storage, and this one leaves the file
     * where the person already was.
     */
    public function test_a_tracked_upload_stays_in_the_browsed_folder(): void
    {
        $user = $this->staff();
        $folders = app(FolderService::class);
        $root = $folders->rootFor($user);
        $target = $folders->create($user, $root, 'Memoranda');

        $this->actingAs($user)->post('/files/upload', [
            'folder' => $target->public_id,
            'files' => [UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf')],
            'track' => true,
            'title' => 'Office Hours',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
        ])->assertRedirect();

        $this->assertSame($target->id, File::sole()->folder_id);
        $this->assertDatabaseMissing('folders', ['name' => 'Tracked Documents '.now()->year]);
    }

    /**
     * The register files where the uploader said, and at the top of their own
     * storage when they said nothing.
     *
     * It used to create a `Tracked Documents {year}` folder and put every
     * upload in it — a folder nobody made, holding files people then went
     * looking for in My Files, where they had put everything else.
     */
    public function test_a_register_upload_lands_at_the_top_of_my_files_by_default(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->post('/documents', [
            'title' => 'Office Hours',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $root = app(FolderService::class)->rootFor($user);

        $this->assertSame($root->id, File::sole()->folder_id);
        $this->assertDatabaseMissing('folders', ['name' => 'Tracked Documents '.now()->year]);
    }

    /** And into the folder the form's destination picker named. */
    public function test_a_register_upload_lands_in_the_chosen_folder(): void
    {
        $user = $this->staff();
        $folders = app(FolderService::class);
        $target = $folders->create($user, $folders->rootFor($user), 'Memoranda');

        $this->actingAs($user)->post('/documents', [
            'title' => 'Office Hours',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
            'folder' => $target->public_id,
            'file' => UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $this->assertSame($target->id, File::sole()->folder_id);
    }

    /**
     * A destination the uploader cannot write into is refused, not quietly
     * swapped for their root: an upload that lands somewhere other than where
     * it was sent is the failure the picker exists to prevent.
     */
    public function test_a_register_upload_refuses_somebody_elses_folder(): void
    {
        $user = $this->staff();
        $other = $this->staff();
        $folders = app(FolderService::class);
        $theirs = $folders->create($other, $folders->rootFor($other), 'Private');

        $this->actingAs($user)->post('/documents', [
            'title' => 'Office Hours',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
            'folder' => $theirs->public_id,
            'file' => UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
        ])->assertNotFound();

        $this->assertSame(0, Document::query()->count());
    }

    /**
     * A folder made from inside the picker is a destination like any other.
     *
     * The dialog posts to `files.folders.store`, the same endpoint the New
     * folder dialog uses, so the naming rules and the ability check have one
     * implementation; this asserts the round trip the form depends on —
     * create, then file into it.
     */
    public function test_a_folder_created_from_the_picker_can_receive_the_upload(): void
    {
        $user = $this->staff();
        $root = app(FolderService::class)->rootFor($user);

        $created = $this->actingAs($user)
            ->postJson('/files/folders', ['parent' => $root->public_id, 'name' => 'Memoranda'])
            ->assertOk()
            ->json('folder');

        $this->actingAs($user)->post('/documents', [
            'title' => 'Office Hours',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
            'folder' => $created['public_id'],
            'file' => UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $this->assertSame($created['public_id'], File::sole()->folder->public_id);
    }

    /**
     * The picker is offered only to somebody who can use it: it reads and
     * writes through the file manager's own endpoints, and a control that
     * answers 403 is worse than one that is absent — the upload lands at the
     * top of their storage either way.
     */
    public function test_the_upload_form_says_whether_the_destination_picker_is_usable(): void
    {
        $user = $this->staff();

        $can = $this->actingAs($user)->get('/documents/upload')->assertOk()
            ->viewData('page')['props']['can'];

        $this->assertTrue($can['chooseFolder']);
        $this->assertTrue($can['createFolder']);
    }

    /** The trail opens with the upload, as it does from the register. */
    public function test_a_tracked_upload_opens_the_document_trail(): void
    {
        $user = $this->staff();

        $this->upload($user, [
            'track' => true,
            'title' => 'Office Hours',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
        ]);

        $this->assertDatabaseHas('document_logs', [
            'document_id' => Document::sole()->id,
            'action' => 'uploaded',
            'actor_id' => $user->id,
        ]);
    }

    /**
     * A tracking code names one document.
     *
     * `documents.file_id` is singular and the QR Tracker is named after one
     * code, so several files would mean either several sets of metadata in a
     * dialog that cannot hold them, or one code over a set the schema cannot
     * express.
     */
    public function test_tracking_is_refused_for_more_than_one_file(): void
    {
        $this->upload($this->staff(), [
            'track' => true,
            'title' => 'Office Hours',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
        ], files: 2)->assertSessionHasErrors('files');

        $this->assertSame(0, Document::query()->count());
    }

    /**
     * `files.create` is not `documents.create`.
     *
     * The route gates the first; issuing a tracking code is the second, and
     * the check has to be in the controller because a route can only ask one
     * question.
     */
    public function test_tracking_requires_the_document_create_permission(): void
    {
        $user = $this->staff();
        $user->revokePermissionTo('documents.create');
        $user->roles->each->revokePermissionTo('documents.create');

        $this->upload($user->fresh(), [
            'track' => true,
            'title' => 'Office Hours',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
        ])->assertSessionHasErrors('track');

        $this->assertSame(0, Document::query()->count());
    }

    /**
     * The tracking dialog answers the register's question the same way: a
     * confidential document may be filed before anybody is tagged on it.
     *
     * It is the same rule in the same words because it is one workflow over
     * one store — two doors onto `DocumentRegistrar` — and a dialog that
     * refused what the upload form accepts would be the file manager holding
     * a second opinion about what confidential means.
     */
    public function test_a_confidential_tracked_upload_may_name_nobody(): void
    {
        $user = $this->staff();

        $this->upload($user, [
            'track' => true,
            'title' => 'Case File',
            'document_type_id' => $this->typeId('CONFID'),
            'visibility' => 'confidential',
        ])->assertSessionHasNoErrors();

        $document = Document::sole();

        $this->assertCount(0, $document->recipients);
        $this->assertSame(0, $document->shareLinks()->count(), 'A confidential document must never be minted a link.');
    }

    public function test_recipients_are_tagged_and_notified(): void
    {
        Notification::fake();

        $user = $this->staff();
        $recipient = $this->staff();

        $this->upload($user, [
            'track' => true,
            'title' => 'Case File',
            'document_type_id' => $this->typeId('CONFID'),
            'visibility' => 'confidential',
            'recipients' => [$recipient->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document = Document::sole();

        $this->assertSame(DocumentVisibility::Confidential, $document->visibility);
        $this->assertTrue($document->recipients->contains($recipient));
        Notification::assertSentTo($recipient, PrivateDocumentShared::class);
    }

    /**
     * Both paths produce the same document.
     *
     * This is what the registrar exists for: the shape cannot drift, because
     * there is one place that builds it.
     */
    public function test_both_upload_paths_produce_the_same_document_shape(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->post('/documents', [
            'title' => 'Through the register',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('a.pdf', 16, 'application/pdf'),
        ]);

        $this->upload($user, [
            'track' => true,
            'title' => 'Through my files',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
        ]);

        [$viaRegister, $viaFiles] = Document::query()->orderBy('id')->get()->all();

        $ignore = ['id', 'code', 'title', 'file_id', 'file_name', 'qr_code_path', 'created_at', 'updated_at'];

        $this->assertSame(
            collect($viaRegister->getAttributes())->except($ignore)->all(),
            collect($viaFiles->getAttributes())->except($ignore)->all(),
        );
    }

    // ------------------------------------------------- finding it both ways

    /** A tracked file carries its jacket into the listing. */
    public function test_my_files_badges_a_tracked_file(): void
    {
        $user = $this->staff();

        $this->upload($user, [
            'track' => true,
            'title' => 'Office Hours',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
        ]);

        $files = $this->actingAs($user)->get('/files')->viewData('page')['props']['files']['data'];

        $this->assertSame(Document::sole()->code, $files[0]['tracked']['code']);
    }

    public function test_my_files_search_finds_a_file_by_its_tracking_code(): void
    {
        $user = $this->staff();

        $this->upload($user, [
            'track' => true,
            'title' => 'Office Hours',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
        ]);

        $code = Document::sole()->code;

        $found = $this->actingAs($user)->get('/files?search='.$code)
            ->viewData('page')['props']['files']['data'];
        $this->assertCount(1, $found);

        $missing = $this->actingAs($user)->get('/files?search=MEMO-19000101-0001')
            ->viewData('page')['props']['files']['data'];
        $this->assertCount(0, $missing);
    }

    public function test_the_register_finds_a_document_by_its_file_name(): void
    {
        $user = $this->staff();

        $this->upload($user, [
            'track' => true,
            'title' => 'Nothing like the file name',
            'document_type_id' => $this->typeId('MEMO'),
            'visibility' => 'public',
        ]);

        $found = $this->actingAs($user)->get('/documents?tab=mine&search=memo1.pdf')
            ->viewData('page')['props']['documents']['data'];

        $this->assertCount(1, $found);
    }

    /**
     * A new document's label points at its share link.
     *
     * The label used to encode the tracking URL, which worked while that URL
     * was public. It is not any more, so a label encoding it would send
     * whoever scanned the folder to a sign-in page — no use to the courier it
     * was stuck there for. The link is minted with the document so the label
     * has something real to point at.
     */
    public function test_a_label_points_at_the_documents_share_link(): void
    {
        $document = $this->uploadTracked();

        $link = $document->shareLinks()->whereNull('revoked_at')->sole();

        $this->assertSame($link->url(), app(QrLabelService::class)->targetFor($document));
    }

    /**
     * A confidential document gets no link and its label falls back to the
     * tracking URL — staff scan it and sign in, which is what confidential
     * means.
     */
    public function test_a_confidential_documents_label_falls_back_to_the_tracking_url(): void
    {
        // A confidential document must name somebody, or it is a document
        // nobody can open.
        $document = $this->uploadTracked([
            'visibility' => 'confidential',
            'recipients' => [User::factory()->create()->id],
        ]);

        $labels = app(QrLabelService::class);

        $this->assertSame(0, $document->shareLinks()->count());
        $this->assertSame($labels->trackingUrl($document->code), $labels->targetFor($document));
    }

    /**
     * The label is a credential now, so it is not handed to anyone who can
     * guess `{CODE}.png`.
     */
    public function test_only_the_uploader_can_download_a_label(): void
    {
        $uploader = $this->staff();
        $document = $this->uploadTracked([], $uploader);

        $this->actingAs(User::factory()->create())
            ->get('/documents/qr/download/'.$document->qr_code_path)
            ->assertNotFound();

        $this->actingAs($uploader)
            ->get('/documents/qr/download/'.$document->qr_code_path)
            ->assertOk();
    }
}
