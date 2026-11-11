<?php

namespace Tests\Feature;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\UploadSetting;
use App\Models\User;
use App\Services\Files\ArchiveService;
use App\Services\Files\FileStorage;
use App\Services\Files\FolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

/**
 * Zip creation and extraction.
 *
 * The central claim being tested is not "the guards reject bad entries" but
 * something stronger: **nothing from an archive ever reaches a filesystem
 * path.** Every entry is written to a path this application generates from a
 * fresh `public_id`, so there is no attacker-controlled path to sanitise. The
 * entry-name checks are a second line, and they are tested too.
 */
class ArchiveSafetyTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');

        $this->scratch = sys_get_temp_dir().'/archive-tests-'.bin2hex(random_bytes(6));
        mkdir($this->scratch, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratch.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->scratch);

        parent::tearDown();
    }

    /**
     * Build a zip on disk and store it as a user's file.
     *
     * @param  array<string, string>  $entries  path => contents
     */
    private function archiveFor(User $owner, array $entries, ?callable $tweak = null): File
    {
        $path = $this->scratch.'/archive-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        if ($tweak) {
            $tweak($zip);
        }

        $zip->close();

        return app(FileStorage::class)->store(
            new UploadedFile($path, 'bundle.zip', 'application/zip', null, true),
            app(FolderService::class)->rootFor($owner),
            $owner,
        );
    }

    private function extract(User $actor, File $archive, ?Folder $target = null): array
    {
        return app(ArchiveService::class)->extract(
            $actor,
            $archive,
            $target ?? app(FolderService::class)->rootFor($actor),
        );
    }

    // ------------------------------------------------------------ the happy path

    public function test_a_plain_archive_extracts_into_the_folder(): void
    {
        $user = User::factory()->create();
        $archive = $this->archiveFor($user, [
            'report.pdf' => 'first',
            'notes.txt' => 'second',
        ]);

        $created = $this->extract($user, $archive);

        $this->assertCount(2, $created);
        foreach ($created as $file) {
            $this->assertSame('extract', $file->source);
            Storage::disk('documents')->assertExists($file->storage_path);
        }

        $this->assertSame(1, ActivityLog::query()->where('action', ActivityAction::Extracted)->count());
    }

    /**
     * The core claim: the extracted path is ours, derived from the row's own
     * identifier, and carries nothing the archive named.
     */
    public function test_an_extracted_files_path_comes_from_this_application(): void
    {
        $user = User::factory()->create();
        $archive = $this->archiveFor($user, ['deeply/nested/quarterly report.pdf' => 'x']);

        $file = $this->extract($user, $archive)[0];

        $this->assertStringContainsString($file->public_id, $file->storage_path);
        $this->assertStringNotContainsString('quarterly', $file->storage_path);
        $this->assertStringNotContainsString('nested', $file->storage_path);
        $this->assertStringNotContainsString(' ', $file->storage_path);

        // The folder structure is recreated as rows, from sanitised names.
        $this->assertDatabaseHas('folders', ['name' => 'deeply', 'user_id' => $user->id]);
        $this->assertDatabaseHas('folders', ['name' => 'nested', 'user_id' => $user->id]);
    }

    // --------------------------------------------------------------- zip slip

    public function test_a_traversal_entry_is_refused_and_nothing_is_written(): void
    {
        $user = User::factory()->create();
        $archive = $this->archiveFor($user, [
            'fine.pdf' => 'ok',
            '../../../.env' => 'APP_KEY=stolen',
        ]);

        $before = File::query()->count();

        try {
            $this->extract($user, $archive);
            $this->fail('A traversal entry must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('unsafe path', $e->getMessage());
        }

        // Validation happens before any write, so not even the safe sibling
        // entry was extracted.
        $this->assertSame($before, File::query()->count());
    }

    public function test_an_absolute_entry_path_is_refused(): void
    {
        $user = User::factory()->create();
        $archive = $this->archiveFor($user, ['/etc/passwd.txt' => 'x']);

        $this->expectException(ValidationException::class);
        $this->extract($user, $archive);
    }

    public function test_a_windows_drive_path_is_refused(): void
    {
        $user = User::factory()->create();
        $archive = $this->archiveFor($user, ['C:/windows/system.txt' => 'x']);

        $this->expectException(ValidationException::class);
        $this->extract($user, $archive);
    }

    // -------------------------------------------------------------- zip bombs

    /**
     * A few kilobytes that expand enormously. Refused on the per-entry
     * compression ratio, before a byte is written — legitimate documents do not
     * compress anywhere near this well.
     */
    public function test_a_high_ratio_entry_is_refused(): void
    {
        $user = User::factory()->create();
        // 4 MB of zeroes compresses to almost nothing.
        $archive = $this->archiveFor($user, ['bomb.txt' => str_repeat("\0", 4 * 1024 * 1024)]);

        $before = File::query()->count();

        try {
            $this->extract($user, $archive);
            $this->fail('A zip bomb must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('expands far more', $e->getMessage());
        }

        $this->assertSame($before, File::query()->count());
    }

    /** The total cap catches what the per-entry ratio does not. */
    public function test_an_archive_over_the_extracted_total_is_refused(): void
    {
        $user = User::factory()->create();

        UploadSetting::query()->where('scope', UploadSetting::SCOPE_FILES)
            ->update(['max_extracted_kb' => 1]);

        $archive = $this->archiveFor($user, [
            // Random bytes, so compression cannot hide the size and the
            // per-entry ratio stays plausible.
            'big.pdf' => random_bytes(64 * 1024),
        ]);

        $this->expectException(ValidationException::class);
        $this->extract($user, $archive);
    }

    public function test_an_archive_with_too_many_entries_is_refused(): void
    {
        $user = User::factory()->create();

        UploadSetting::query()->where('scope', UploadSetting::SCOPE_FILES)
            ->update(['max_archive_entries' => 2]);

        $archive = $this->archiveFor($user, [
            'a.pdf' => 'a', 'b.pdf' => 'b', 'c.pdf' => 'c',
        ]);

        $this->expectException(ValidationException::class);
        $this->extract($user, $archive);
    }

    public function test_an_archive_nested_deeper_than_allowed_is_refused(): void
    {
        $user = User::factory()->create();

        UploadSetting::query()->where('scope', UploadSetting::SCOPE_FILES)
            ->update(['max_archive_depth' => 2]);

        $archive = $this->archiveFor($user, ['a/b/c/d/deep.pdf' => 'x']);

        $this->expectException(ValidationException::class);
        $this->extract($user, $archive);
    }

    public function test_a_nested_archive_is_refused_by_default(): void
    {
        $user = User::factory()->create();
        $archive = $this->archiveFor($user, ['inner.zip' => 'PK']);

        try {
            $this->extract($user, $archive);
            $this->fail('A nested archive must be refused under the default policy.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('another archive', $e->getMessage());
        }
    }

    // ------------------------------------------------------------- other guards

    /** A symlink entry is how an archive writes outside its own tree. */
    public function test_a_symlink_entry_is_refused(): void
    {
        $user = User::factory()->create();
        $archive = $this->archiveFor($user, ['link' => '/etc/passwd'], function (ZipArchive $zip): void {
            // S_IFLNK (0xA000) | 0777, in the high 16 bits of the unix mode.
            $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, (0xA1FF) << 16);
        });

        try {
            $this->extract($user, $archive);
            $this->fail('A symlink entry must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('symbolic link', $e->getMessage());
        }
    }

    /** An entry type the policy forbids cannot arrive inside an archive. */
    public function test_a_prohibited_file_type_inside_an_archive_is_refused(): void
    {
        $user = User::factory()->create();
        $archive = $this->archiveFor($user, ['payload.php' => '<?php echo 1;']);

        try {
            $this->extract($user, $archive);
            $this->fail('A prohibited type must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not a permitted file type', $e->getMessage());
        }

        $this->assertSame(0, File::query()->where('extension', 'php')->count());
    }

    public function test_extraction_is_refused_when_the_policy_forbids_archives(): void
    {
        $user = User::factory()->create();
        $archive = $this->archiveFor($user, ['fine.pdf' => 'x']);

        UploadSetting::query()->where('scope', UploadSetting::SCOPE_FILES)
            ->update(['max_archive_entries' => 0]);

        try {
            $this->extract($user, $archive);
            $this->fail('Extraction must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not permitted', $e->getMessage());
        }
    }

    /** Extracting is a write into a folder, so it needs the ability on it. */
    public function test_a_stranger_cannot_extract_into_someone_elses_folder(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $archive = $this->archiveFor($stranger, ['fine.pdf' => 'x']);

        $this->expectException(ValidationException::class);
        app(ArchiveService::class)->extract(
            $stranger,
            $archive,
            app(FolderService::class)->rootFor($owner),
        );
    }

    // ----------------------------------------------------------------- zipping

    public function test_zipping_a_selection_returns_an_archive_and_is_recorded(): void
    {
        $user = User::factory()->create();
        $root = app(FolderService::class)->rootFor($user);
        $storage = app(FileStorage::class);

        $one = $storage->store(UploadedFile::fake()->create('a.pdf', 4, 'application/pdf'), $root, $user);
        $two = $storage->store(UploadedFile::fake()->create('b.pdf', 4, 'application/pdf'), $root, $user);

        $response = app(ArchiveService::class)->zip($user, [$one, $two]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, ActivityLog::query()->where('action', ActivityAction::Zipped)->count());
    }

    /** A selection posted from a stale page is re-resolved, not trusted. */
    public function test_zipping_silently_skips_files_the_user_cannot_download(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = app(FileStorage::class)->store(
            UploadedFile::fake()->create('mine.pdf', 4, 'application/pdf'),
            app(FolderService::class)->rootFor($user),
            $user,
        );
        $theirs = app(FileStorage::class)->store(
            UploadedFile::fake()->create('theirs.pdf', 4, 'application/pdf'),
            app(FolderService::class)->rootFor($other),
            $other,
        );

        app(ArchiveService::class)->zip($user, [$mine, $theirs]);

        // One row, for the one file that was actually included.
        $this->assertSame(1, ActivityLog::query()->where('action', ActivityAction::Zipped)->count());
    }

    public function test_zipping_nothing_the_user_can_download_is_refused(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $theirs = app(FileStorage::class)->store(
            UploadedFile::fake()->create('theirs.pdf', 4, 'application/pdf'),
            app(FolderService::class)->rootFor($other),
            $other,
        );

        $this->expectException(ValidationException::class);
        app(ArchiveService::class)->zip($user, [$theirs]);
    }
}
