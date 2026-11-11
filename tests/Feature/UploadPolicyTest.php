<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\UploadSetting;
use App\Models\User;
use App\Services\Files\FolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The administrator-editable upload policy.
 *
 * Two scopes, because a tracked document and a file in someone's personal
 * storage are not the same kind of thing. Every rule is enforced server-side;
 * the limits sent to the browser are a courtesy, not the control.
 */
class UploadPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    private function documentPolicy(): UploadSetting
    {
        return UploadSetting::query()->where('scope', UploadSetting::SCOPE_DOCUMENTS)->sole();
    }

    private function filePolicy(): UploadSetting
    {
        return UploadSetting::query()->where('scope', UploadSetting::SCOPE_FILES)->sole();
    }

    private function uploadToFiles(User $user, UploadedFile $file)
    {
        return $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [$file],
        ]);
    }

    private function uploadDocument(User $user, UploadedFile $file)
    {
        return $this->actingAs($user)->post('/documents', [
            'title' => 'Memo',
            'document_type_id' => DocumentType::query()->where('code', 'MEMO')->value('id'),
            'visibility' => 'public',
            'file' => $file,
        ]);
    }

    // ------------------------------------------------- the seeded policies

    /**
     * The `documents` policy is seeded with exactly the limits that were
     * hard-coded before this module existed, so installing it changed nothing.
     * A settings module that silently loosens the rules on arrival is not a
     * settings module.
     */
    public function test_the_documents_policy_reproduces_the_old_hard_coded_rules(): void
    {
        $policy = $this->documentPolicy();

        $this->assertSame(10240, $policy->max_size_kb);
        $this->assertSame(['pdf', 'doc', 'docx'], $policy->allowed_extensions);
    }

    public function test_the_two_scopes_are_independent(): void
    {
        $this->assertNotSame(
            $this->documentPolicy()->allowed_extensions,
            $this->filePolicy()->allowed_extensions,
        );
        $this->assertContains('png', $this->filePolicy()->allowed_extensions);
        $this->assertNotContains('png', $this->documentPolicy()->allowed_extensions);
    }

    // ------------------------------------------------------------ enforcement

    public function test_a_file_manager_upload_accepts_a_permitted_type(): void
    {
        $user = User::factory()->create();

        $this->uploadToFiles($user, UploadedFile::fake()->image('photo.png'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, File::query()->count());
    }

    public function test_an_extension_outside_the_allow_list_is_refused(): void
    {
        $user = User::factory()->create();

        $this->uploadToFiles($user, UploadedFile::fake()->create('notes.xyz', 4, 'text/plain'))
            ->assertSessionHasErrors('files');

        $this->assertSame(0, File::query()->count());
    }

    /**
     * The block-list is applied last, so it overrides the allow-list. That is
     * how one risky format is retired without re-auditing everything else.
     */
    public function test_a_prohibited_extension_is_refused_even_when_allow_listed(): void
    {
        $policy = $this->filePolicy();
        $policy->update([
            'allowed_extensions' => [...$policy->allowed_extensions, 'php'],
            'allowed_mime_types' => [...$policy->allowed_mime_types, 'text/x-php', 'text/plain'],
        ]);

        $this->assertContains('php', $policy->fresh()->allowed_extensions);
        $this->assertFalse($policy->fresh()->permitsExtension('php'));

        $this->uploadToFiles(User::factory()->create(), UploadedFile::fake()->create('shell.php', 1, 'text/plain'))
            ->assertSessionHasErrors('files');

        $this->assertSame(0, File::query()->count());
    }

    /**
     * The extension and the sniffed type are both checked. Checking only the
     * extension lets a renamed executable through; checking only the type lets
     * through a file whose name still ends `.php`.
     */
    public function test_a_file_lying_about_its_type_is_refused(): void
    {
        $policy = $this->filePolicy();
        // `pdf` stays allowed, but PDF's real MIME type does not.
        $policy->update(['allowed_mime_types' => ['image/png']]);

        $this->uploadToFiles(User::factory()->create(), UploadedFile::fake()->create('report.pdf', 4, 'application/pdf'))
            ->assertSessionHasErrors('files');
    }

    public function test_a_file_with_no_extension_is_refused(): void
    {
        $this->uploadToFiles(User::factory()->create(), UploadedFile::fake()->create('README', 2, 'text/plain'))
            ->assertSessionHasErrors('files');
    }

    public function test_tightening_the_policy_takes_effect_on_the_next_upload(): void
    {
        $user = User::factory()->create();

        $this->uploadToFiles($user, UploadedFile::fake()->image('first.png'))->assertSessionHasNoErrors();

        // The cache is busted on save, not given a TTL: an administrator who
        // tightens a policy expects the next upload to obey it.
        $this->filePolicy()->update(['allowed_extensions' => ['pdf']]);

        $this->uploadToFiles($user, UploadedFile::fake()->image('second.png'))->assertSessionHasErrors('files');

        $this->assertSame(1, File::query()->count());
    }

    public function test_the_size_limit_is_enforced(): void
    {
        $this->filePolicy()->update(['max_size_kb' => 10]);

        $this->uploadToFiles(User::factory()->create(), UploadedFile::fake()->create('big.pdf', 64, 'application/pdf'))
            ->assertSessionHasErrors('files.0');
    }

    public function test_the_file_count_limit_is_enforced(): void
    {
        $user = User::factory()->create();
        $this->filePolicy()->update(['max_files_per_upload' => 1]);

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [
                UploadedFile::fake()->create('a.pdf', 2, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 2, 'application/pdf'),
            ],
        ])->assertSessionHasErrors('files');

        $this->assertSame(0, File::query()->count());
    }

    /** A rejected batch stores nothing, not "everything up to the bad one". */
    public function test_one_bad_file_rejects_the_whole_batch(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [
                UploadedFile::fake()->create('good.pdf', 2, 'application/pdf'),
                UploadedFile::fake()->create('bad.xyz', 2, 'text/plain'),
            ],
        ])->assertSessionHasErrors('files');

        $this->assertSame(0, File::query()->count());
    }

    // ------------------------------------------------------ the document scope

    public function test_the_document_policy_governs_tracked_uploads(): void
    {
        $user = User::factory()->create();

        // An image is fine in the file manager and not for a tracked document.
        $this->uploadDocument($user, UploadedFile::fake()->image('scan.png'))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Document::query()->count());

        $this->uploadDocument($user, UploadedFile::fake()->create('memo.pdf', 8, 'application/pdf'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Document::query()->count());
    }

    /** Widening the document policy works without a deploy — the whole point. */
    public function test_widening_the_document_policy_permits_a_new_type(): void
    {
        $policy = $this->documentPolicy();
        $policy->update([
            'allowed_extensions' => [...$policy->allowed_extensions, 'png'],
            'allowed_mime_types' => [...$policy->allowed_mime_types, 'image/png'],
        ]);

        $this->uploadDocument(User::factory()->create(), UploadedFile::fake()->image('scan.png'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Document::query()->count());
    }

    // -------------------------------------------------------------- the screen

    public function test_the_settings_screen_needs_the_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/upload-settings')->assertForbidden();
        $this->actingAs(User::factory()->superadmin()->create())->get('/upload-settings')->assertOk();
    }

    public function test_saving_normalises_the_lists_and_snapshots_who_changed_them(): void
    {
        $admin = User::factory()->superadmin()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $policy = $this->filePolicy();

        $this->actingAs($admin)->put("/upload-settings/{$policy->id}", [
            'min_size_kb' => 0,
            'max_size_kb' => 2048,
            // Deliberately messy: leading dots, uppercase, duplicates, blanks.
            'allowed_extensions' => ['.PDF', 'pdf', 'Docx', ''],
            'allowed_mime_types' => ['Application/PDF', 'application/pdf'],
            'prohibited_extensions' => ['.PHP'],
            'max_files_per_upload' => 5,
            'max_archive_size_kb' => 1024,
            'max_archive_entries' => 10,
            'max_extracted_kb' => 2048,
            'max_archive_depth' => 3,
            'allow_nested_archives' => false,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $fresh = $policy->fresh();

        $this->assertSame(['docx', 'pdf'], $fresh->allowed_extensions);
        $this->assertSame(['application/pdf'], $fresh->allowed_mime_types);
        $this->assertSame(['php'], $fresh->prohibited_extensions);
        $this->assertSame('Ana Cruz', $fresh->updated_by_name);
    }

    public function test_a_max_below_the_min_is_refused(): void
    {
        $admin = User::factory()->superadmin()->create();
        $policy = $this->filePolicy();

        $this->actingAs($admin)->put("/upload-settings/{$policy->id}", [
            'min_size_kb' => 500,
            'max_size_kb' => 100,
            'allowed_extensions' => ['pdf'],
            'allowed_mime_types' => ['application/pdf'],
            'prohibited_extensions' => [],
            'max_files_per_upload' => 5,
            'max_archive_size_kb' => 0,
            'max_archive_entries' => 0,
            'max_extracted_kb' => 0,
            'max_archive_depth' => 1,
        ])->assertSessionHasErrors('max_size_kb');
    }

    /**
     * A missing row must degrade to "too strict", never to "anything goes".
     */
    public function test_a_missing_policy_row_falls_back_to_a_conservative_default(): void
    {
        UploadSetting::query()->delete();
        \Illuminate\Support\Facades\Cache::flush();

        $policy = UploadSetting::forScope(UploadSetting::SCOPE_FILES);

        $this->assertSame(['pdf', 'doc', 'docx'], $policy->allowed_extensions);
        $this->assertFalse($policy->permitsExtension('png'));
        $this->assertSame(0, $policy->max_archive_entries, 'Archives must be off without a policy.');
    }
}
