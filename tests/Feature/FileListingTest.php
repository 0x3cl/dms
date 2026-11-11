<?php

namespace Tests\Feature;

use App\Enums\FileAbility;
use App\Enums\FileVisibility;
use App\Enums\GranteeType;
use App\Enums\ShareAbility;
use App\Models\Division;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Access\AccessResolver;
use App\Services\Files\FileStorage;
use App\Services\Files\FolderService;
use App\Services\Files\SharingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `AccessResolver::scopeVisible()` must agree with `AccessResolver::grant()`.
 *
 * They are two statements of one rule — one in SQL so a listing can be
 * paginated, one in PHP so a single item can be resolved. If they disagree, a
 * listing either hides things the user may open (annoying) or lists things it
 * must not (a disclosure, and the paginator total leaks existence even when the
 * row is never rendered).
 *
 * So the central test here does not assert a hand-written expectation: it
 * resolves *every* row in the database through `grant()` and asserts the
 * listing returns exactly that set.
 */
class FileListingTest extends TestCase
{
    use RefreshDatabase;

    private AccessResolver $access;

    private SharingService $sharing;

    private FolderService $folders;

    private FileStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');

        $this->access = app(AccessResolver::class);
        $this->sharing = app(SharingService::class);
        $this->folders = app(FolderService::class);
        $this->storage = app(FileStorage::class);
    }

    private function fileIn(Folder $folder, User $owner, string $name): File
    {
        return $this->storage->store(
            UploadedFile::fake()->create($name, 4, 'application/pdf'),
            $folder,
            $owner,
        );
    }

    /**
     * The two implementations of the rule, compared row by row.
     *
     * @param  class-string<File|Folder>  $model
     */
    private function assertListingMatchesResolver(User $user, string $model): void
    {
        $listed = $this->access
            ->scopeVisible($model::query(), $user)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $resolved = $model::query()->get()
            ->filter(fn ($item) => $this->access->allows($user, $item, FileAbility::View))
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $resolved,
            $listed,
            $model.": the SQL listing scope and the per-item resolver disagree.\n"
                .'  resolver allows: '.implode(',', $resolved)."\n"
                .'  listing returns: '.implode(',', $listed),
        );
    }

    /**
     * One scene containing every case the rule distinguishes, checked from the
     * point of view of five different people.
     */
    public function test_the_listing_scope_agrees_with_the_resolver_for_everyone(): void
    {
        $division = Division::factory()->create();

        $owner = User::factory()->create();
        $colleague = User::factory()->create(['division_id' => $division->id]);
        $friend = User::factory()->create();
        $stranger = User::factory()->create();

        $root = $this->folders->rootFor($owner);

        $open = $this->folders->create($owner, $root, 'Open', FileVisibility::PublicItem->value);
        $this->fileIn($open, $owner, 'public.pdf')->update(['visibility' => FileVisibility::PublicItem->value]);

        // Public file, but inside a private folder — must stay unreachable.
        $closed = $this->folders->create($owner, $root, 'Closed', FileVisibility::Private->value);
        $this->fileIn($closed, $owner, 'clamped.pdf')->update(['visibility' => FileVisibility::PublicItem->value]);

        // Confidential, with a share on the folder that must not reach into it.
        $cases = $this->folders->create($owner, $root, 'Cases', FileVisibility::Confidential->value);
        $this->fileIn($cases, $owner, 'case.pdf');
        $this->sharing->share($cases, GranteeType::User, $friend->id, ShareAbility::Manage, $owner);

        // Shared with a division, two levels down.
        $reports = $this->folders->create($owner, $root, 'Reports');
        $q1 = $this->folders->create($owner, $reports, 'Q1');
        $this->fileIn($q1, $owner, 'q1.pdf');
        $this->sharing->share($reports, GranteeType::Division, $division->id, ShareAbility::Download, $owner);

        // Shared with one person, on the file itself.
        $direct = $this->fileIn($root, $owner, 'direct.pdf');
        $this->sharing->share($direct, GranteeType::User, $friend->id, ShareAbility::View, $owner);

        // Revoked, and expired — neither may appear.
        $revoked = $this->fileIn($root, $owner, 'revoked.pdf');
        $this->sharing->revoke(
            $this->sharing->share($revoked, GranteeType::User, $stranger->id, ShareAbility::View, $owner)
        );
        $expired = $this->fileIn($root, $owner, 'expired.pdf');
        $this->sharing->share($expired, GranteeType::User, $stranger->id, ShareAbility::View, $owner, now()->subDay());

        foreach ([$owner, $colleague, $friend, $stranger] as $user) {
            $this->assertListingMatchesResolver($user->fresh(), File::class);
            $this->assertListingMatchesResolver($user->fresh(), Folder::class);
        }
    }

    public function test_a_stranger_sees_nothing_of_someone_elses_private_storage(): void
    {
        $owner = User::factory()->create();
        $this->fileIn($this->folders->rootFor($owner), $owner, 'secret.pdf');

        $visible = $this->access
            ->scopeVisible(File::query(), User::factory()->create())
            ->count();

        $this->assertSame(0, $visible);
    }

    /** A signed-out visitor lists nothing at all, rather than everything. */
    public function test_a_guest_lists_nothing(): void
    {
        $owner = User::factory()->create();
        $this->fileIn($this->folders->rootFor($owner), $owner, 'anything.pdf');

        $this->assertSame(0, $this->access->scopeVisible(File::query(), null)->count());
    }

    /**
     * A confidential file must not be listed to someone whose only claim is a
     * share on the folder above it.
     */
    public function test_a_confidential_file_is_not_listed_through_a_folder_share(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();

        $folder = $this->folders->create($owner, $this->folders->rootFor($owner), 'Cases');
        $file = $this->fileIn($folder, $owner, 'case.pdf');
        $file->update(['visibility' => FileVisibility::Confidential->value]);

        $this->sharing->share($folder, GranteeType::User, $friend->id, ShareAbility::Manage, $owner);

        $listed = $this->access->scopeVisible(File::query(), $friend->fresh())->pluck('id')->all();

        $this->assertNotContains($file->id, $listed);
    }

    /** `files.manage` is the administrator's read over all storage. */
    public function test_files_manage_lists_everything(): void
    {
        $owner = User::factory()->create();
        $this->fileIn($this->folders->rootFor($owner), $owner, 'theirs.pdf');

        $auditor = User::factory()->create();
        $auditor->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('files.manage', 'web'));

        $this->assertSame(1, $this->access->scopeVisible(File::query(), $auditor->fresh())->count());
    }

    /**
     * The listing must not multiply rows.
     *
     * Reaching someone through their division *and* a role is normal, and the
     * share checks are EXISTS subqueries rather than joins for exactly this
     * reason — a join would return the same file once per matching share and
     * quietly corrupt the paginator's totals.
     */
    public function test_several_matching_shares_do_not_duplicate_a_row(): void
    {
        $division = Division::factory()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create(['division_id' => $division->id]);
        $roleId = \Spatie\Permission\Models\Role::findByName(\App\Support\Roles::STAFF)->id;

        $file = $this->fileIn($this->folders->rootFor($owner), $owner, 'shared.pdf');
        $this->sharing->share($file, GranteeType::Division, $division->id, ShareAbility::View, $owner);
        $this->sharing->share($file, GranteeType::Role, $roleId, ShareAbility::Download, $owner);
        $this->sharing->share($file, GranteeType::User, $member->id, ShareAbility::Manage, $owner);

        $ids = $this->access->scopeVisible(File::query(), $member->fresh())->pluck('id')->all();

        $this->assertSame([$file->id], $ids);
    }
}
