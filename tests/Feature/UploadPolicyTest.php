<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\UploadSetting;
use App\Models\User;
use App\Services\Files\FolderService;
use App\Support\FileTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The administrator-editable upload policy.
 *
 * **One row, one list.** `allowed_types` is an entry per permitted extension
 * with its own size window; the MIME types an extension may sniff as are
 * derived from `App\Support\FileTypes`, and executable formats are refused in
 * code. Every rule is enforced server-side; the limits sent to the browser are
 * a courtesy, not the control.
 *
 * There were two policies, `documents` and `files`, and both upload paths now
 * read the one — so the first test here is that they do. The rest are organised
 * around the list: what it permits, what no list can permit, and what the
 * type-versus-contents check does and does not treat as a lie.
 */
class UploadPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    /** The policy. There is exactly one, which `sole()` asserts in passing. */
    private function policy(): UploadSetting
    {
        return UploadSetting::query()->sole();
    }

    /**
     * Build an `allowed_types` list from a terse spec, so a test can say what it
     * means rather than restate the array shape.
     *
     * @param  array<string, array{0: int, 1: int}|int>  $spec  extension => max KB, or [min, max]
     * @return list<array{extension: string, min_size_kb: int, max_size_kb: int}>
     */
    private function types(array $spec): array
    {
        $types = [];

        foreach ($spec as $extension => $window) {
            [$min, $max] = is_array($window) ? $window : [0, $window];

            $types[] = ['extension' => $extension, 'min_size_kb' => $min, 'max_size_kb' => $max];
        }

        return $types;
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
            'document_type_id' => DocumentType::query()->first()->id,
            'visibility' => 'public',
            'file' => $file,
        ]);
    }

    // ------------------------------------------------- the seeded policies

    /**
     * One policy, read by both upload paths.
     *
     * There were two rows — `documents` and `files` — and `UploadPolicy` took a
     * `$scope` argument to pick between them. The two modules are one workflow
     * over one store, so that was two answers to one question, and the gate's
     * answer depended on which screen the upload came from rather than on what
     * the file is. Tightening the policy here has to reach both.
     */
    public function test_one_policy_governs_both_upload_paths(): void
    {
        $this->assertSame(1, UploadSetting::query()->count(), 'There must be exactly one policy row.');

        $user = User::factory()->create();

        $this->policy()->update(['allowed_types' => $this->types(['pdf' => 2048])]);

        // Permitted by the one policy: accepted by both paths.
        $this->uploadToFiles($user, UploadedFile::fake()->create('a.pdf', 8, 'application/pdf'))
            ->assertSessionHasNoErrors();
        $this->uploadDocument($user, UploadedFile::fake()->create('b.pdf', 8, 'application/pdf'))
            ->assertSessionHasNoErrors();

        /*
         * Not permitted: refused by both. Sized at 8 KB on purpose — a
         * `fake()->image()` is a few hundred bytes and would be refused by the
         * size floor instead, leaving the test passing while proving nothing
         * about the type.
         */
        $this->uploadToFiles($user, UploadedFile::fake()->create('c.png', 8, 'image/png'))
            ->assertSessionHasErrors('files');
        $this->uploadDocument($user, UploadedFile::fake()->create('d.png', 8, 'image/png'))
            ->assertSessionHasErrors('file');

        // Deliberate filings only: these tests count what the *register's*
        // form accepted, and every My Files upload beside it is now filed
        // automatically as well.
        $this->assertSame(1, Document::query()->where('filed', 'deliberate')->count());
        // Two: the direct upload, and the one a tracked document stores
        // alongside its row. The two modules already share the store, which is
        // why they cannot sensibly have had two policies over it.
        $this->assertSame(2, File::query()->count());
    }

    /**
     * The office asked for images, PDF, Office documents and video, and the
     * seeded policy is where that answer lives. It was once `pdf,doc,docx` for
     * tracked documents — the limits `DocumentController` had hard-coded before
     * this module existed.
     */
    public function test_the_policy_permits_the_formats_the_office_receives(): void
    {
        $permitted = $this->policy()->allowed_extensions;

        foreach (['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'mp4', 'mov', 'mp3', 'zip'] as $extension) {
            $this->assertContains($extension, $permitted, ".{$extension} must be uploadable.");
        }
    }

    /**
     * Every category arrives with its safe types ticked.
     *
     * The seeded list is derived from the catalogue rather than written out, so
     * this asserts the property that matters: a category is never seeded empty,
     * and a format added to the catalogue is permitted on a fresh install
     * without anybody remembering a second list. An empty category is what the
     * settings screen renders as "0/5", with every upload of that kind refused
     * by a policy nobody chose.
     */
    public function test_every_category_is_seeded_with_its_safe_types(): void
    {
        $permitted = $this->policy()->allowed_extensions;

        foreach (FileTypes::catalogue() as $group) {
            $expected = collect($group['types'])
                ->pluck('extension')
                ->reject(fn (string $extension) => in_array($extension, UploadSetting::NOT_PERMITTED_BY_DEFAULT, true))
                ->values()
                ->all();

            $this->assertSame(
                $expected,
                array_values(array_intersect($permitted, $expected)),
                "The {$group['group']} category is missing one of its safe types.",
            );
        }
    }

    /**
     * A row that permits nothing is repaired by the seeder.
     *
     * It cannot be a deliberate edit — the settings screen refuses to save an
     * empty list — so the only way a row reaches that state is by never having
     * been configured, which a half-applied schema change leaves behind. The
     * result is a policy that refuses every upload in the application while the
     * screen reports "0 permitted".
     */
    public function test_the_seeder_repairs_a_policy_that_permits_nothing(): void
    {
        $this->policy()->update(['allowed_types' => []]);

        $this->assertSame([], $this->policy()->fresh()->allowed_extensions);

        $this->seed(\Database\Seeders\UploadSettingSeeder::class);

        $this->assertContains('xlsx', $this->policy()->fresh()->allowed_extensions);
        $this->assertSame(1, UploadSetting::query()->count(), 'Repairing must not add a second policy.');
    }

    /** A narrow policy is somebody's deliberate choice, and is left alone. */
    public function test_the_seeder_leaves_a_deliberately_narrow_policy_alone(): void
    {
        $this->policy()->update(['allowed_types' => $this->types(['pdf' => 2048])]);

        $this->seed(\Database\Seeders\UploadSettingSeeder::class);

        $this->assertSame(['pdf'], $this->policy()->fresh()->allowed_extensions);
    }

    /** No policy permits SVG: an SVG is a document that can carry script. */
    public function test_the_seeded_policy_does_not_permit_svg(): void
    {
        $this->assertNotContains('svg', $this->policy()->allowed_extensions);
    }

    // ----------------------------------------- the xlsx rejection, as reported

    /**
     * The bug this consolidation was made to fix.
     *
     * A genuine `pds.xlsx` was refused with “is not the kind of file it claims
     * to be (application/vnd.openxmlformats-officedocument.spreadsheetml.sheet)”
     * — the message naming the very type that should have let it through. The
     * extension had been added to one list; the second list, in a vocabulary
     * nobody types by hand, had not gained the matching OOXML type.
     *
     * There is no second list now, so permitting the extension is sufficient.
     */
    public function test_a_genuine_spreadsheet_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->uploadToFiles($user, UploadedFile::fake()->create('pds.xlsx', 64, self::XLSX))
            ->assertSessionHasNoErrors();

        $this->uploadDocument($user, UploadedFile::fake()->create('pds.xlsx', 64, self::XLSX))
            ->assertSessionHasNoErrors();

        // Deliberate filings only: these tests count what the *register's*
        // form accepted, and every My Files upload beside it is now filed
        // automatically as well.
        $this->assertSame(1, Document::query()->where('filed', 'deliberate')->count());
    }

    /**
     * Every OOXML and OpenDocument file *is* a zip archive, so a sniffer that
     * stops at the magic number reports `application/zip` and one that reads
     * the central directory reports the precise type. Both are right about the
     * same bytes, and refusing either is a false rejection.
     */
    #[DataProvider('officeSniffs')]
    public function test_an_office_file_is_accepted_however_it_sniffs(string $name, string $sniffed): void
    {
        $this->uploadToFiles(User::factory()->create(), UploadedFile::fake()->create($name, 32, $sniffed))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, File::query()->count());
    }

    public static function officeSniffs(): array
    {
        return [
            'xlsx reported precisely' => ['budget.xlsx', self::XLSX],
            'xlsx reported as its container' => ['budget.xlsx', 'application/zip'],
            'xlsx reported by older Windows tooling' => ['budget.xlsx', 'application/x-zip-compressed'],
            'docx reported as its container' => ['memo.docx', 'application/zip'],
            'legacy doc as an OLE2 container' => ['memo.doc', 'application/CDFV2'],
            'legacy xls as ms-office' => ['sheet.xls', 'application/vnd.ms-office'],
            'csv sniffed as plain text' => ['list.csv', 'text/plain'],
        ];
    }

    /**
     * `finfo` answers `application/octet-stream` for anything it has no magic
     * number for. That is not evidence of a mismatch, and treating it as one
     * refused ordinary office files — so an inconclusive sniff passes, and the
     * extension allow-list plus the unconditional refusal of executables carry
     * the decision.
     */
    public function test_an_inconclusive_sniff_is_not_treated_as_a_lie(): void
    {
        $this->uploadToFiles(User::factory()->create(), UploadedFile::fake()->create('plan.docx', 16, 'application/octet-stream'))
            ->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------ enforcement

    public function test_a_file_manager_upload_accepts_a_permitted_type(): void
    {
        $this->uploadToFiles(User::factory()->create(), UploadedFile::fake()->create('photo.png', 8, 'image/png'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, File::query()->count());
    }

    /**
     * The seeded floor is 1 KB, and a zero-byte file is refused by it.
     *
     * Never a document somebody meant to file: a failed export, an interrupted
     * copy or a placeholder — and it arrives looking exactly like a successful
     * upload, with a tracking code and a printed label, until somebody opens it
     * weeks later.
     *
     * This is also why the fixtures here are `create(…, 8)` rather than
     * `fake()->image()`: that helper writes a few hundred bytes, which the
     * floor correctly refuses.
     */
    public function test_an_empty_file_is_refused_by_the_seeded_floor(): void
    {
        $this->assertSame(1, $this->policy()->minSizeKbFor('png'));

        // `files.0`, not `files`: every permitted type shares the 1 KB floor,
        // so `smallestMinSizeKb()` is 1 and Laravel's own coarse rule refuses
        // this before the per-extension check is reached. Both answers are the
        // same refusal; the key differs by which rule got there first.
        $this->uploadToFiles(User::factory()->create(), UploadedFile::fake()->create('empty.png', 0, 'image/png'))
            ->assertSessionHasErrors('files.0');

        $this->assertSame(0, File::query()->count());
    }

    /** Every permitted type is seeded with the same window: 1 KB to 50 MB. */
    public function test_every_permitted_type_is_seeded_with_the_same_window(): void
    {
        foreach ($this->policy()->typeRules() as $extension => $rule) {
            $this->assertSame(1, $rule['min'], ".{$extension} should have the 1 KB floor.");
            $this->assertSame(51200, $rule['max'], ".{$extension} should have the 50 MB ceiling.");
        }
    }

    public function test_an_extension_outside_the_allow_list_is_refused(): void
    {
        $this->uploadToFiles(User::factory()->create(), UploadedFile::fake()->create('notes.xyz', 4, 'text/plain'))
            ->assertSessionHasErrors('files');

        $this->assertSame(0, File::query()->count());
    }

    /**
     * The refusal of executable formats is in code, not in a column, so a
     * policy row naming one still refuses it. This used to be an editable
     * `prohibited_extensions` veto; nothing an office does requires uploading a
     * `.phtml`, and a veto an administrator can empty is not a floor.
     */
    public function test_a_dangerous_extension_cannot_be_permitted_by_the_policy(): void
    {
        $policy = $this->policy();
        $policy->update(['allowed_types' => $this->types(['pdf' => 2048, 'php' => 2048])]);

        $fresh = $policy->fresh();

        $this->assertNotContains('php', $fresh->allowed_extensions, 'A dangerous type must never be listed as permitted.');
        $this->assertFalse($fresh->permitsExtension('php'));

        $this->uploadToFiles(User::factory()->create(), UploadedFile::fake()->create('shell.php', 1, 'text/plain'))
            ->assertSessionHasErrors('files');

        $this->assertSame(0, File::query()->count());
    }

    /** The settings screen cannot name one either. */
    public function test_the_screen_refuses_to_permit_a_dangerous_type(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->put('/upload-settings', $this->payload(['pdf' => 2048, 'phtml' => 2048]))
            ->assertSessionHasErrors('allowed_types.1.extension');
    }

    /**
     * The extension and the sniffed type are both checked. Checking only the
     * extension lets a renamed executable through; checking only the type lets
     * through a file whose name still ends `.php`. Only a *positive*
     * contradiction is a refusal.
     */
    public function test_a_file_lying_about_its_type_is_refused(): void
    {
        $this->uploadToFiles(
            User::factory()->create(),
            UploadedFile::fake()->create('report.pdf', 4, 'application/x-dosexec'),
        )->assertSessionHasErrors('files');

        $this->assertSame(0, File::query()->count());
    }

    public function test_a_file_with_no_extension_is_refused(): void
    {
        $this->uploadToFiles(User::factory()->create(), UploadedFile::fake()->create('README', 2, 'text/plain'))
            ->assertSessionHasErrors('files');
    }

    public function test_tightening_the_policy_takes_effect_on_the_next_upload(): void
    {
        $user = User::factory()->create();

        $this->uploadToFiles($user, UploadedFile::fake()->create('first.png', 8, 'image/png'))->assertSessionHasNoErrors();

        // The cache is busted on save, not given a TTL: an administrator who
        // tightens a policy expects the next upload to obey it.
        $this->policy()->update(['allowed_types' => $this->types(['pdf' => 2048])]);

        $this->uploadToFiles($user, UploadedFile::fake()->create('second.png', 8, 'image/png'))->assertSessionHasErrors('files');

        $this->assertSame(1, File::query()->count());
    }

    // ------------------------------------------------- per-extension windows

    /**
     * Each permitted type carries its own size window, which is the point of
     * the consolidation: a 200 MB video and a 2 MB signature scan are both
     * reasonable, and one shared ceiling cannot express that.
     */
    public function test_each_extension_has_its_own_size_ceiling(): void
    {
        $user = User::factory()->create();

        $this->policy()->update([
            'max_size_kb' => 1024,
            'allowed_types' => $this->types(['png' => 64, 'mp4' => 8192]),
        ]);

        $this->uploadToFiles($user, UploadedFile::fake()->create('clip.mp4', 4096, 'video/mp4'))
            ->assertSessionHasNoErrors();

        // The same number of kilobytes, refused, because it is a .png.
        $this->uploadToFiles($user, UploadedFile::fake()->create('shot.png', 4096, 'image/png'))
            ->assertSessionHasErrors('files');

        $this->assertSame(1, File::query()->count());
    }

    /**
     * The coarse Laravel rule has to be the *widest* window on offer, because
     * it runs before anything has looked at the file's extension. A coarse
     * bound set to the scope default would refuse the video above before the
     * rule permitting it was ever consulted.
     */
    public function test_the_coarse_rule_does_not_pre_empt_a_wider_per_type_window(): void
    {
        $policy = $this->policy();
        $policy->update(['max_size_kb' => 1024, 'allowed_types' => $this->types(['mp4' => 8192])]);

        $this->assertSame(8192, $policy->fresh()->largestMaxSizeKb());
    }

    /** A per-extension floor is enforced too, and names the type in the message. */
    public function test_a_per_extension_minimum_is_enforced(): void
    {
        $this->policy()->update(['allowed_types' => $this->types(['pdf' => [64, 2048]])]);

        $this->uploadToFiles(User::factory()->create(), UploadedFile::fake()->create('stub.pdf', 8, 'application/pdf'))
            ->assertSessionHasErrors('files');
    }

    /** A blank per-type ceiling inherits the scope default rather than meaning zero. */
    public function test_a_blank_per_type_ceiling_inherits_the_scope_default(): void
    {
        $policy = $this->policy();
        $policy->update(['max_size_kb' => 4096, 'allowed_types' => $this->types(['pdf' => 0])]);

        $this->assertSame(4096, $policy->fresh()->maxSizeKbFor('pdf'));

        $this->uploadToFiles(User::factory()->create(), UploadedFile::fake()->create('ok.pdf', 2048, 'application/pdf'))
            ->assertSessionHasNoErrors();
    }

    public function test_the_file_count_limit_is_enforced(): void
    {
        $user = User::factory()->create();
        $this->policy()->update(['max_files_per_upload' => 1]);

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

    // --------------------------------------------------- the document register

    /**
     * A tracked document obeys the same policy as everything else, which is the
     * change: the document register used to have its own, narrower one.
     */
    public function test_a_tracked_upload_obeys_the_one_policy(): void
    {
        $user = User::factory()->create();

        $this->policy()->update(['allowed_types' => $this->types(['pdf' => 2048])]);

        $this->uploadDocument($user, UploadedFile::fake()->create('bundle.zip', 8, 'application/zip'))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Document::query()->count());

        $this->uploadDocument($user, UploadedFile::fake()->create('memo.pdf', 8, 'application/pdf'))
            ->assertSessionHasNoErrors();

        // Deliberate filings only: these tests count what the *register's*
        // form accepted, and every My Files upload beside it is now filed
        // automatically as well.
        $this->assertSame(1, Document::query()->where('filed', 'deliberate')->count());
    }

    /** Widening the policy works without a deploy — the whole point. */
    public function test_widening_the_policy_permits_a_new_type(): void
    {
        $policy = $this->policy();
        $policy->update(['allowed_types' => [
            ...$policy->allowed_types,
            ['extension' => 'txt', 'min_size_kb' => 0, 'max_size_kb' => 1024],
        ]]);

        $this->uploadDocument(User::factory()->create(), UploadedFile::fake()->create('note.txt', 2, 'text/plain'))
            ->assertSessionHasNoErrors();

        // Deliberate filings only: these tests count what the *register's*
        // form accepted, and every My Files upload beside it is now filed
        // automatically as well.
        $this->assertSame(1, Document::query()->where('filed', 'deliberate')->count());
    }

    // -------------------------------------------------------------- the screen

    public function test_the_settings_screen_needs_the_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/upload-settings')->assertForbidden();
        $this->actingAs(User::factory()->superadmin()->create())->get('/upload-settings')->assertOk();
    }

    public function test_saving_normalises_the_type_list_and_snapshots_who_changed_it(): void
    {
        $admin = User::factory()->superadmin()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $policy = $this->policy();

        $this->actingAs($admin)->put('/upload-settings', [
            ...$this->payload([]),
            'allowed_types' => [
                // Deliberately messy: a leading dot, uppercase, a duplicate,
                // and a blank row the form left behind.
                ['extension' => '.PDF', 'min_size_kb' => 0, 'max_size_kb' => 2048],
                ['extension' => 'pdf', 'min_size_kb' => 0, 'max_size_kb' => 4096],
                ['extension' => 'Docx', 'min_size_kb' => 0, 'max_size_kb' => 0],
                ['extension' => '', 'min_size_kb' => 0, 'max_size_kb' => 0],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $fresh = $policy->fresh();

        $this->assertSame(['pdf', 'docx'], $fresh->allowed_extensions);
        // The later of two rows for one extension wins: two rows disagreeing
        // about one limit has no answer to which applies.
        $this->assertSame(4096, $fresh->maxSizeKbFor('pdf'));
        $this->assertSame('Ana Cruz', $fresh->updated_by_name);
    }

    public function test_a_max_below_the_min_is_refused(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->put('/upload-settings', [
                ...$this->payload(['pdf' => 2048]),
                'min_size_kb' => 500,
                'max_size_kb' => 100,
            ])->assertSessionHasErrors('max_size_kb');
    }

    /**
     * A type whose floor is above its own ceiling would be displayed as
     * permitted while refusing every file of that type.
     */
    public function test_a_type_whose_minimum_exceeds_its_maximum_is_refused(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->put('/upload-settings', $this->payload(['pdf' => [900, 100]]))
            ->assertSessionHasErrors('allowed_types.0.min_size_kb');
    }

    /** A policy permitting nothing is a module switched off by a screen that does not say so. */
    public function test_permitting_nothing_is_refused(): void
    {
        $this->actingAs(User::factory()->superadmin()->create())
            ->put('/upload-settings', $this->payload([]))
            ->assertSessionHasErrors('allowed_types');
    }

    /**
     * A missing row must degrade to "too strict", never to "anything goes".
     */
    public function test_a_missing_policy_row_falls_back_to_a_conservative_default(): void
    {
        UploadSetting::query()->delete();
        Cache::flush();

        $policy = UploadSetting::current();

        $this->assertSame(['pdf', 'doc', 'docx'], $policy->allowed_extensions);
        $this->assertFalse($policy->permitsExtension('png'));
        $this->assertSame(0, $policy->max_archive_entries, 'Archives must be off without a policy.');
    }

    // ------------------------------------------------------------ the catalogue

    /** Nothing in the catalogue the screen offers may be a format code could run. */
    public function test_the_catalogue_offers_nothing_dangerous(): void
    {
        foreach (FileTypes::extensions() as $extension) {
            $this->assertFalse(
                FileTypes::isDangerous($extension),
                ".{$extension} is in the catalogue and on the dangerous list, so the screen would offer a grant the server refuses.",
            );
        }
    }

    /** Every catalogue entry knows what it may sniff as, or the gate cannot check it. */
    public function test_every_catalogue_entry_declares_its_mime_types(): void
    {
        foreach (FileTypes::extensions() as $extension) {
            $this->assertNotEmpty(FileTypes::mimeTypes($extension), ".{$extension} declares no MIME types.");
        }
    }

    /**
     * A valid settings payload, so each test states only what it is about.
     *
     * @param  array<string, array{0: int, 1: int}|int>  $spec
     * @return array<string, mixed>
     */
    private function payload(array $spec): array
    {
        return [
            'min_size_kb' => 0,
            'max_size_kb' => 2048,
            'allowed_types' => $this->types($spec),
            'max_files_per_upload' => 5,
            'max_archive_size_kb' => 1024,
            'max_archive_entries' => 10,
            'max_extracted_kb' => 2048,
            'max_archive_depth' => 3,
            'allow_nested_archives' => false,
        ];
    }
}
