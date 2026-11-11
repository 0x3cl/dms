<?php

namespace Tests\Feature;

use App\Enums\FileAbility;
use App\Enums\FileVisibility;
use App\Enums\GranteeType;
use App\Enums\ShareAbility;
use App\Models\Division;
use App\Models\File;
use App\Models\Folder;
use App\Models\ItemShare;
use App\Models\Section;
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
 * Shares, inheritance and share links.
 *
 * The rules under test, stated once:
 *
 * - effective visibility is the **most restrictive** value on an item and every
 *   folder above it;
 * - shares travel **downward** only, and the **nearest** ancestor carrying one
 *   wins;
 * - a **confidential** item is reachable only by a share naming the item
 *   itself — never inherited, never through a link.
 */
class FileSharingTest extends TestCase
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

    private function fileIn(Folder $folder, User $owner, string $name = 'notes.pdf'): File
    {
        return $this->storage->store(
            UploadedFile::fake()->create($name, 8, 'application/pdf'),
            $folder,
            $owner,
        );
    }

    private function can(?User $user, File|Folder $item, FileAbility $ability): bool
    {
        return $this->access->allows($user, $item->fresh(), $ability);
    }

    // ---------------------------------------------------------------- ownership

    public function test_an_owner_holds_everything_and_a_stranger_holds_nothing(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileIn($this->folders->rootFor($owner), $owner);

        $this->assertTrue($this->can($owner, $file, FileAbility::Delete));
        $this->assertFalse($this->can(User::factory()->create(), $file, FileAbility::View));
    }

    // ------------------------------------------------------------------- shares

    public function test_a_share_with_a_person_grants_what_it_says_and_no_more(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $file = $this->fileIn($this->folders->rootFor($owner), $owner);

        $this->sharing->share($file, GranteeType::User, $friend->id, ShareAbility::View, $owner);

        $this->assertTrue($this->can($friend, $file, FileAbility::View));
        $this->assertFalse($this->can($friend, $file, FileAbility::Download));
        $this->assertFalse($this->can($friend, $file, FileAbility::Delete));
    }

    public function test_a_division_share_reaches_every_member_and_nobody_else(): void
    {
        $division = Division::factory()->create();
        $other = Division::factory()->create();

        $owner = User::factory()->create();
        $member = User::factory()->create(['division_id' => $division->id]);
        $outsider = User::factory()->create(['division_id' => $other->id]);

        $file = $this->fileIn($this->folders->rootFor($owner), $owner);
        $this->sharing->share($file, GranteeType::Division, $division->id, ShareAbility::Download, $owner);

        $this->assertTrue($this->can($member, $file, FileAbility::Download));
        $this->assertFalse($this->can($outsider, $file, FileAbility::View));
    }

    public function test_a_section_share_reaches_that_section(): void
    {
        $section = Section::factory()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create([
            'division_id' => $section->division_id,
            'section_id' => $section->id,
        ]);

        $file = $this->fileIn($this->folders->rootFor($owner), $owner);
        $this->sharing->share($file, GranteeType::Section, $section->id, ShareAbility::View, $owner);

        $this->assertTrue($this->can($member, $file, FileAbility::View));
    }

    /** A "group" is a role, reusing the membership the roles screen manages. */
    public function test_a_role_share_reaches_holders_of_that_role(): void
    {
        $owner = User::factory()->create();
        $staff = User::factory()->create();
        $roleId = \Spatie\Permission\Models\Role::findByName(\App\Support\Roles::STAFF)->id;

        $file = $this->fileIn($this->folders->rootFor($owner), $owner);
        $this->sharing->share($file, GranteeType::Role, $roleId, ShareAbility::View, $owner);

        $this->assertTrue($this->can($staff, $file, FileAbility::View));
    }

    /**
     * A user with no division must not be matched by a share addressed to a
     * division — which is what a naive `where('division_id', $user->division_id)`
     * does when both sides are null.
     */
    public function test_a_user_without_a_division_is_not_matched_by_a_division_share(): void
    {
        $division = Division::factory()->create();
        $owner = User::factory()->create();
        $unposted = User::factory()->create(['division_id' => null, 'section_id' => null]);

        $file = $this->fileIn($this->folders->rootFor($owner), $owner);
        $this->sharing->share($file, GranteeType::Division, $division->id, ShareAbility::View, $owner);

        $this->assertFalse($this->can($unposted, $file, FileAbility::View));
    }

    public function test_revoking_a_share_takes_effect_immediately(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $file = $this->fileIn($this->folders->rootFor($owner), $owner);

        $share = $this->sharing->share($file, GranteeType::User, $friend->id, ShareAbility::View, $owner);
        $this->assertTrue($this->can($friend, $file, FileAbility::View));

        $this->sharing->revoke($share);

        $this->assertFalse($this->can($friend, $file, FileAbility::View));
    }

    public function test_an_expired_share_grants_nothing(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $file = $this->fileIn($this->folders->rootFor($owner), $owner);

        $this->sharing->share($file, GranteeType::User, $friend->id, ShareAbility::View, $owner, now()->subMinute());

        $this->assertFalse($this->can($friend, $file, FileAbility::View));
    }

    /** Re-sharing updates the grant instead of failing on the unique index. */
    public function test_resharing_with_the_same_grantee_updates_the_ability(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $file = $this->fileIn($this->folders->rootFor($owner), $owner);

        $this->sharing->share($file, GranteeType::User, $friend->id, ShareAbility::View, $owner);
        $this->sharing->share($file, GranteeType::User, $friend->id, ShareAbility::Download, $owner);

        $this->assertSame(1, ItemShare::query()->for($file)->count());
        $this->assertTrue($this->can($friend, $file, FileAbility::Download));
    }

    /**
     * Someone can be reached by their division *and* a role, saying different
     * things. The widest wins, so the answer does not depend on row order.
     */
    public function test_the_most_generous_of_several_grants_applies(): void
    {
        $division = Division::factory()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create(['division_id' => $division->id]);
        $roleId = \Spatie\Permission\Models\Role::findByName(\App\Support\Roles::STAFF)->id;

        $file = $this->fileIn($this->folders->rootFor($owner), $owner);
        $this->sharing->share($file, GranteeType::Division, $division->id, ShareAbility::View, $owner);
        $this->sharing->share($file, GranteeType::Role, $roleId, ShareAbility::Manage, $owner);

        $this->assertTrue($this->can($member, $file, FileAbility::Delete));
    }

    // -------------------------------------------------------------- inheritance

    public function test_a_folder_share_is_inherited_by_what_is_inside_it(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();

        $parent = $this->folders->create($owner, $this->folders->rootFor($owner), 'Reports');
        $child = $this->folders->create($owner, $parent, 'Q1');
        $file = $this->fileIn($child, $owner);

        $this->sharing->share($parent, GranteeType::User, $friend->id, ShareAbility::Download, $owner);

        $this->assertTrue($this->can($friend, $file, FileAbility::Download));
        $this->assertTrue($this->can($friend, $child, FileAbility::View));
    }

    /**
     * The nearest ancestor carrying a share wins, and the walk stops there. A
     * broad grant near the root must not silently widen a narrower one made
     * deliberately further down.
     */
    public function test_the_nearest_ancestor_share_wins_over_a_broader_one_above(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();

        $top = $this->folders->create($owner, $this->folders->rootFor($owner), 'All');
        $inner = $this->folders->create($owner, $top, 'Restricted');
        $file = $this->fileIn($inner, $owner);

        $this->sharing->share($top, GranteeType::User, $friend->id, ShareAbility::Manage, $owner);
        $this->sharing->share($inner, GranteeType::User, $friend->id, ShareAbility::View, $owner);

        $this->assertTrue($this->can($friend, $file, FileAbility::View));
        $this->assertFalse(
            $this->can($friend, $file, FileAbility::Delete),
            'The nearer, narrower share must win over the broader one above it.',
        );
    }

    /** A share on the item itself beats anything inherited. */
    public function test_a_share_on_the_item_beats_an_inherited_one(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();

        $folder = $this->folders->create($owner, $this->folders->rootFor($owner), 'Shared');
        $file = $this->fileIn($folder, $owner);

        $this->sharing->share($folder, GranteeType::User, $friend->id, ShareAbility::Manage, $owner);
        $this->sharing->share($file, GranteeType::User, $friend->id, ShareAbility::View, $owner);

        $this->assertFalse($this->can($friend, $file, FileAbility::Delete));
    }

    // --------------------------------------------------------------- visibility

    public function test_a_public_file_is_readable_by_any_signed_in_user(): void
    {
        $owner = User::factory()->create();
        $folder = $this->folders->create($owner, $this->folders->rootFor($owner), 'Open', FileVisibility::PublicItem->value);
        $file = $this->fileIn($folder, $owner);

        $this->assertTrue($this->can(User::factory()->create(), $file, FileAbility::View));
        $this->assertFalse($this->can(null, $file, FileAbility::View), 'Open to the office is not open to the internet.');
    }

    /** Most restrictive wins, and it cannot be widened from below. */
    public function test_a_public_file_inside_a_confidential_folder_is_confidential(): void
    {
        $owner = User::factory()->create();
        $secret = $this->folders->create($owner, $this->folders->rootFor($owner), 'Case files', FileVisibility::Confidential->value);

        $file = $this->fileIn($secret, $owner);
        $file->update(['visibility' => FileVisibility::PublicItem->value]);

        $this->assertSame(FileVisibility::Confidential, $this->access->effectiveVisibility($file->fresh()));
        $this->assertFalse($this->can(User::factory()->create(), $file, FileAbility::View));
    }

    /**
     * Confidential is the one level a folder share does not reach into. Only a
     * share naming the item itself does.
     */
    public function test_a_confidential_file_is_not_reached_by_an_inherited_share(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();

        $folder = $this->folders->create($owner, $this->folders->rootFor($owner), 'Cases');
        $file = $this->fileIn($folder, $owner);
        $file->update(['visibility' => FileVisibility::Confidential->value]);

        $this->sharing->share($folder, GranteeType::User, $friend->id, ShareAbility::Manage, $owner);

        $this->assertFalse($this->can($friend, $file, FileAbility::View));

        // But a share naming the file itself does reach it.
        $this->sharing->share($file->fresh(), GranteeType::User, $friend->id, ShareAbility::View, $owner);

        $this->assertTrue($this->can($friend, $file, FileAbility::View));
    }

    // -------------------------------------------------------------------- links

    public function test_a_view_only_link_grants_view_and_not_download(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileIn($this->folders->rootFor($owner), $owner);

        $link = $this->sharing->createLink($file, ShareAbility::View, $owner);

        $this->assertTrue($this->access->allows(null, $file->fresh(), FileAbility::View, $link));
        $this->assertFalse($this->access->allows(null, $file->fresh(), FileAbility::Download, $link));
    }

    public function test_a_revoked_or_expired_link_grants_nothing(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileIn($this->folders->rootFor($owner), $owner);

        $revoked = $this->sharing->createLink($file, ShareAbility::Download, $owner);
        $this->sharing->revokeLink($revoked);

        $expired = $this->sharing->createLink($file, ShareAbility::Download, $owner, now()->subMinute());

        $this->assertFalse($this->access->allows(null, $file->fresh(), FileAbility::View, $revoked->fresh()));
        $this->assertFalse($this->access->allows(null, $file->fresh(), FileAbility::View, $expired->fresh()));
        $this->assertNull($this->sharing->resolveLink($revoked->token));
    }

    /**
     * A link authorizes only the item it points at. Otherwise one legitimate
     * link would authorize a request for any other file.
     */
    public function test_a_link_does_not_authorize_a_different_item(): void
    {
        $owner = User::factory()->create();
        $root = $this->folders->rootFor($owner);
        $shared = $this->fileIn($root, $owner, 'shared.pdf');
        $private = $this->fileIn($root, $owner, 'private.pdf');

        $link = $this->sharing->createLink($shared, ShareAbility::Download, $owner);

        $this->assertFalse($this->access->allows(null, $private->fresh(), FileAbility::View, $link));
    }

    public function test_a_confidential_item_cannot_be_linked(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileIn($this->folders->rootFor($owner), $owner);
        $file->update(['visibility' => FileVisibility::Confidential->value]);

        $this->expectException(\RuntimeException::class);
        $this->sharing->createLink($file->fresh(), ShareAbility::View, $owner);
    }

    /**
     * The check runs again on redemption, because an item can be reclassified
     * as confidential *after* a link has been handed out. The link has to stop
     * working at that moment, not when somebody remembers to revoke it.
     */
    public function test_a_link_stops_working_when_its_item_becomes_confidential(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileIn($this->folders->rootFor($owner), $owner);
        $link = $this->sharing->createLink($file, ShareAbility::Download, $owner);

        $this->assertTrue($this->access->allows(null, $file->fresh(), FileAbility::View, $link));

        $file->update(['visibility' => FileVisibility::Confidential->value]);

        $this->assertFalse($this->access->allows(null, $file->fresh(), FileAbility::View, $link));
    }

    /** Reclassifying a folder above it has the same effect. */
    public function test_a_link_stops_working_when_a_folder_above_becomes_confidential(): void
    {
        $owner = User::factory()->create();
        $folder = $this->folders->create($owner, $this->folders->rootFor($owner), 'Reports');
        $file = $this->fileIn($folder, $owner);
        $link = $this->sharing->createLink($file, ShareAbility::Download, $owner);

        $folder->update(['visibility' => FileVisibility::Confidential->value]);

        $this->assertFalse($this->access->allows(null, $file->fresh(), FileAbility::View, $link));
    }

    public function test_a_link_can_never_grant_write(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileIn($this->folders->rootFor($owner), $owner);

        $this->expectException(\RuntimeException::class);
        $this->sharing->createLink($file, ShareAbility::Manage, $owner);
    }

    public function test_a_spent_link_grants_nothing(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileIn($this->folders->rootFor($owner), $owner);

        $link = $this->sharing->createLink($file, ShareAbility::View, $owner, null, 1);
        $link->recordAccess();

        $this->assertFalse($this->access->allows(null, $file->fresh(), FileAbility::View, $link->fresh()));
    }

    /** A link never reaches a tracked document, which has its own rule. */
    public function test_a_link_cannot_be_used_against_a_tracked_document(): void
    {
        $owner = User::factory()->create();
        $file = $this->fileIn($this->folders->rootFor($owner), $owner);
        $link = $this->sharing->createLink($file, ShareAbility::Download, $owner);

        $document = \App\Models\Document::create([
            'code' => 'MEMO-20260101-0001',
            'title' => 'Confidential memo',
            'document_type_id' => \App\Models\DocumentType::query()->where('code', 'MEMO')->value('id'),
            'type' => 'Memo',
            'file_id' => $file->id,
            'user_id' => $owner->id,
            'uploader_name' => $owner->name,
            'status' => 'active',
            'visibility' => 'private',
        ]);

        $this->assertFalse($this->access->allows(null, $document, FileAbility::View, $link));

        // And the file is now governed by the document's rule, not the link's.
        $this->assertFalse($this->access->allows(User::factory()->create(), $file->fresh(), FileAbility::View));
    }

    // ------------------------------------------------------- administrator read

    /**
     * `files.manage` is a read, deliberately. Auditing storage means being able
     * to look, not to edit or delete in someone else's name.
     */
    public function test_files_manage_grants_read_but_not_write(): void
    {
        $owner = User::factory()->create();
        $auditor = User::factory()->superadmin()->create();
        $file = $this->fileIn($this->folders->rootFor($owner), $owner);

        // Exercise the permission itself, not the superadmin Gate bypass.
        $plain = User::factory()->create();
        $plain->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('files.manage', 'web'));
        $plain = $plain->fresh();

        $this->assertTrue($this->can($plain, $file, FileAbility::View));
        $this->assertTrue($this->can($plain, $file, FileAbility::Download));
        $this->assertFalse($this->can($plain, $file, FileAbility::Delete));
        $this->assertFalse($this->can($plain, $file, FileAbility::Rename));
        $this->assertNotNull($auditor);
    }
}
