<?php

namespace App\Services\Access;

use App\Enums\DocumentVisibility;
use App\Enums\FileAbility;
use App\Enums\FileVisibility;
use App\Models\Document;
use App\Models\File;
use App\Models\Folder;
use App\Models\ItemShare;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The one place access to a file, folder or document is decided.
 *
 * `DocumentController::isAuthorized()` and `DocumentLogController::canSee()`
 * were a deliberate, documented duplicate of each other — a document's travel
 * history is exactly as sensitive as the document, so the rule had to exist
 * twice and be remembered twice. They both delegate here now, which is the only
 * thing that stops that being a standing hazard.
 *
 * Resolution is **first match wins**, in this order:
 *
 *   1. a share link, when one is presented
 *   2. the tracked-document rule, for a file carrying a tracking code
 *   3. ownership
 *   4. an explicit share on the item itself
 *   5. a share inherited from the nearest ancestor folder carrying one
 *   6. effective visibility
 *   7. the `files.manage` module permission — read only
 *   8. otherwise denied
 *
 * Everything fails closed: an unrecognised subject, a null user, a missing
 * folder chain and an expired grant all resolve to denied rather than to "no
 * rule said no".
 */
class AccessResolver
{
    /**
     * What a reader who is not the uploader may do with a tracked document.
     *
     * A flat constant rather than a method, because there is no longer a
     * document state that narrows it: the End Action, which took `download`
     * away from everyone but the uploader once a document was closed, has been
     * removed along with `documents.status`.
     *
     * @var list<FileAbility>
     */
    private const READER_ABILITIES = [FileAbility::View, FileAbility::Download];

    public function allows(?User $user, File|Folder|Document $subject, FileAbility $ability, ?ShareLink $link = null): bool
    {
        return $this->grant($user, $subject, $link)->allows($ability);
    }

    public function grant(?User $user, File|Folder|Document $subject, ?ShareLink $link = null): AccessGrant
    {
        if ($link !== null) {
            return $this->fromLink($link, $subject);
        }

        return match (true) {
            $subject instanceof Document => $this->forDocument($user, $subject),
            $subject instanceof File => $this->forFile($user, $subject),
            $subject instanceof Folder => $this->forFolder($user, $subject),
        };
    }

    /**
     * A link's grant, capped by the link's own state.
     *
     * A confidential item is refused outright, and the check is here rather
     * than only at creation because an item can be reclassified as
     * confidential *after* a link has been handed out. The link has to stop
     * working at that moment, not at the next time somebody thinks to revoke
     * it.
     *
     * The link must also actually point at the subject being asked about:
     * without that, a link for one file would authorize a request for any
     * other, which is the whole value of the token.
     */
    private function fromLink(ShareLink $link, File|Folder|Document $subject): AccessGrant
    {
        if ($subject instanceof Document) {
            return AccessGrant::denied();
        }

        if (! $link->isUsable()) {
            return AccessGrant::denied();
        }

        if ($link->shareable_type !== $subject::class || (int) $link->shareable_id !== (int) $subject->getKey()) {
            return AccessGrant::denied();
        }

        if (! $this->effectiveVisibility($subject)->linkable()) {
            return AccessGrant::denied();
        }

        /*
         * Narrowed to what a link may ever carry, so a row holding `manage` —
         * however it got there — cannot grant write to whoever has the URL.
         *
         * A strict in_array rather than array_intersect: the latter compares by
         * string, and an enum case has no string form, so it throws.
         */
        $allowed = [FileAbility::View, FileAbility::Download];
        $abilities = array_values(array_filter(
            $link->ability->abilities(),
            fn (FileAbility $ability) => in_array($ability, $allowed, true),
        ));

        return AccessGrant::of($abilities, AccessSource::Link);
    }

    /**
     * The tracked-document rule, unchanged from the one it replaces.
     *
     * Reproduced clause for clause and in the same order, because
     * tests/Feature/DocumentAuthorizationTest.php is the contract for it and
     * that file is not permitted to move:
     *
     *   a public document is readable by anyone, the uploader can always read
     *   their own, and a named recipient can read a private one.
     *
     * Reading and writing are separate questions. A *public* document is
     * readable by everyone and still editable only by its uploader, which is
     * why ownership grants the full set and visibility grants only a read.
     */
    private function forDocument(?User $user, Document $document): AccessGrant
    {
        if ($user && $document->user_id !== null && $document->user_id === $user->id) {
            return AccessGrant::of(FileAbility::all(), AccessSource::Uploader);
        }

        if ($document->visibility === DocumentVisibility::Public) {
            return AccessGrant::of(self::READER_ABILITIES, AccessSource::PublicItem);
        }

        if ($user && $document->recipients()->where('user_id', $user->id)->exists()) {
            return AccessGrant::of(self::READER_ABILITIES, AccessSource::Recipient);
        }

        return AccessGrant::denied();
    }

    /**
     * A file.
     *
     * When the file carries a tracking code the document's rule decides, and
     * decides alone. There must not be a second, looser way to reach a
     * document's bytes than the one its trail and recipient list were built
     * around — the obfuscated file URL is not a side door.
     */
    private function forFile(?User $user, File $file): AccessGrant
    {
        $document = $file->document;

        if ($document) {
            return $this->forDocument($user, $document);
        }

        return $this->forItem($user, $file);
    }

    private function forFolder(?User $user, Folder $folder): AccessGrant
    {
        return $this->forItem($user, $folder);
    }

    /**
     * The shared path for an untracked file or a folder.
     */
    private function forItem(?User $user, File|Folder $item): AccessGrant
    {
        if ($user && $item->user_id !== null && $item->user_id === $user->id) {
            return AccessGrant::of(FileAbility::all(), AccessSource::Owner);
        }

        $visibility = $this->effectiveVisibility($item);

        if ($user) {
            // An explicit share on the item itself. This is the only route into
            // a confidential item, which is what "confidential" means here.
            $direct = $this->bestShare(ItemShare::query()->for($item)->active()->reaching($user)->get());

            if ($direct) {
                return AccessGrant::of($direct->ability->abilities(), AccessSource::Share, $direct->id);
            }

            if ($visibility !== FileVisibility::Confidential) {
                $inherited = $this->inheritedShare($user, $item);

                if ($inherited) {
                    return AccessGrant::of(
                        $inherited->ability->abilities(),
                        AccessSource::InheritedShare,
                        $inherited->id,
                    );
                }
            }
        }

        $byVisibility = $this->fromVisibility($user, $visibility);

        if (! $byVisibility->isDenied()) {
            return $byVisibility;
        }

        /*
         * `files.manage` — the administrator's read.
         *
         * Read only, and deliberately so: somebody auditing storage needs to
         * be able to look, not to edit or delete in another person's name.
         * Write still requires ownership or a share.
         */
        if ($user?->can('files.manage')) {
            return AccessGrant::of([FileAbility::View, FileAbility::Download], AccessSource::ModuleManage);
        }

        return AccessGrant::denied();
    }

    /**
     * The best share reaching this person from the nearest ancestor that has
     * one.
     *
     * **Nearest wins, and the walk stops there.** Shares travel downward, so a
     * grant on a subfolder is the more specific statement about it — continuing
     * up past one and unioning everything above would let a broad grant near
     * the root silently widen a narrower one made deliberately further down.
     */
    private function inheritedShare(User $user, File|Folder $item): ?ItemShare
    {
        $chain = $this->ancestorChain($item);

        if ($chain->isEmpty()) {
            return null;
        }

        // Nearest first.
        $ordered = $chain->sortByDesc('depth')->values();

        $shares = ItemShare::query()
            ->where('shareable_type', Folder::class)
            ->whereIn('shareable_id', $ordered->pluck('id'))
            ->active()
            ->reaching($user)
            ->get()
            ->groupBy('shareable_id');

        foreach ($ordered as $folder) {
            $candidates = $shares->get($folder->id);

            if ($candidates && $candidates->isNotEmpty()) {
                return $this->bestShare($candidates);
            }
        }

        return null;
    }

    /**
     * The most generous of several grants reaching the same person.
     *
     * Someone can be reached by their division *and* their section *and* a
     * role, and those may say different things. Taking the widest is the only
     * answer that does not depend on row order; it also matches what a person
     * granting the broader of the two intended.
     *
     * @param  Collection<int, ItemShare>  $shares
     */
    private function bestShare(Collection $shares): ?ItemShare
    {
        return $shares
            ->sortByDesc(fn (ItemShare $share) => count($share->ability->abilities()))
            ->first();
    }

    /**
     * Every folder above an item, root-first.
     *
     * @return Collection<int, Folder>
     */
    private function ancestorChain(File|Folder $item): Collection
    {
        // Both models expose ancestors() off the materialized `path`, so this
        // costs one query regardless of how deep the tree is.
        return $item->ancestors();
    }

    /**
     * The visibility that actually applies: the most restrictive value on the
     * item and every folder above it.
     *
     * A file marked public inside a confidential folder is confidential. The
     * rule cannot be widened from below and it fails closed, which is why it
     * lives in one place rather than being re-expressed by each caller.
     */
    public function effectiveVisibility(File|Folder $item): FileVisibility
    {
        /*
         * The account's root is excluded.
         *
         * A root is a container, not a folder anyone sees or sets a visibility
         * on — it is named `root-{id}` and never rendered. It is created
         * `private`, so leaving it in the chain would make "most restrictive
         * wins" clamp *everything* to private and a public folder could never
         * actually be public.
         *
         * Nothing is weakened by this: a folder created under the root inherits
         * `private` as its own value, so the default is still private. What
         * changes is that it becomes the *item's* privacy rather than an
         * unalterable ceiling.
         */
        $chain = $this->ancestorChain($item)
            ->reject(fn (Folder $folder) => $folder->is_root)
            ->map->visibility
            ->push($item->visibility)
            ->filter();

        return FileVisibility::mostRestrictive($chain);
    }

    /**
     * What a visibility alone grants, to someone with no other claim.
     *
     * Only `public` grants anything, and only to a signed-in user: an open
     * register for the office is not an open register for the internet.
     * `confidential` grants nothing here by construction — it is reachable only
     * by its owner or by a share naming the item itself.
     */
    private function fromVisibility(?User $user, FileVisibility $visibility): AccessGrant
    {
        if ($user && $visibility === FileVisibility::PublicItem) {
            return AccessGrant::of(FileAbility::readAndTake(), AccessSource::PublicItem);
        }

        return AccessGrant::denied();
    }

    /**
     * Narrow a listing to what this person may see.
     *
     * **Every listing query must go through this.** A listing that fetches
     * broadly and filters in PHP afterwards is the leak: it gets the paginator
     * totals wrong, it leaks existence through those totals, and the first
     * `->map()` that forgets the filter discloses the rows themselves.
     *
     * Confidential items are matched only by a share on the item itself, which
     * is the same rule grant() applies — stated here in SQL because a listing
     * cannot resolve each row in turn.
     *
     * @param  Builder<File>|Builder<Folder>  $query
     * @return Builder<File>|Builder<Folder>
     */
    public function scopeVisible(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->can('files.manage')) {
            return $query;
        }

        $model = $query->getModel();
        $type = $model::class;
        $table = $model->getTable();
        $ancestors = $this->ancestorPathSql($type, $table);

        return $query->where(function (Builder $outer) use ($user, $type, $table, $ancestors): void {
            $outer->where("{$table}.user_id", $user->id);

            // Public — but only where nothing above it is stricter. Effective
            // visibility is the most restrictive value on the item and its
            // ancestors, and this is that rule in SQL.
            $outer->orWhere(fn (Builder $q) => $q
                ->where("{$table}.visibility", FileVisibility::PublicItem->value)
                ->whereNotExists(fn ($sub) => $sub
                    ->selectRaw('1')
                    ->from('folders as ancestor')
                    // Roots excluded, exactly as effectiveVisibility() excludes
                    // them. If these two disagree, a listing stops showing
                    // things the resolver allows — or worse, shows things it
                    // does not.
                    ->where('ancestor.is_root', false)
                    ->whereIn('ancestor.visibility', [
                        FileVisibility::Private->value,
                        FileVisibility::Confidential->value,
                    ])
                    ->whereRaw("locate(concat('/', ancestor.public_id, '/'), {$ancestors}) > 0")));

            // Shared with them directly, on this very row.
            $outer->orWhereExists(fn ($sub) => $this->sharedDirectly($sub, $user, $type, $table));

            // Shared with them through a folder above.
            $outer->orWhereExists(fn ($sub) => $this->sharedByAncestor($sub, $user, $table, $ancestors));
        });
    }

    /**
     * SQL for "the ancestor path of this row".
     *
     * A folder carries its own. A **file** does not: `files.storage_path` is
     * where its bytes are on disk, which has nothing to do with the tree. Its
     * ancestor path is its folder's path plus that folder's own id, because the
     * folder holding a file is itself one of the file's ancestors.
     *
     * Getting this wrong is not a visible error, it is a silent one: searching
     * a file's disk path for a folder id simply never matches, so every
     * ancestor rule — the visibility clamp and inherited shares alike —
     * evaluates as "no ancestors" and the listing quietly disagrees with the
     * resolver. That is what the differential test in FileListingTest exists to
     * catch.
     */
    private function ancestorPathSql(string $type, string $table): string
    {
        if ($type === Folder::class) {
            return "{$table}.path";
        }

        return "(select concat(holder.path, holder.public_id, '/') from folders as holder where holder.id = {$table}.folder_id)";
    }

    /**
     * EXISTS: an active share on this very row reaching this user.
     *
     * EXISTS rather than a join, here and below, because a person can be
     * reached by their division *and* their section *and* a role at once. A
     * join would return the row once per matching share and quietly corrupt
     * every paginator total.
     */
    private function sharedDirectly($query, User $user, string $type, string $table)
    {
        return $query
            ->selectRaw('1')
            ->from('item_shares')
            ->whereColumn('item_shares.shareable_id', "{$table}.id")
            ->where('item_shares.shareable_type', $type)
            ->whereNull('item_shares.revoked_at')
            ->where(fn ($q) => $q->whereNull('item_shares.expires_at')->orWhere('item_shares.expires_at', '>', now()))
            ->where(fn ($q) => $this->granteeMatches($q, $user));
    }

    /**
     * EXISTS: an active folder share reaching this user, on a folder whose
     * public id appears in this row's ancestor path.
     *
     * Confidential rows are excluded: a confidential item is reachable only by
     * a share naming it, never by inheritance.
     */
    private function sharedByAncestor($query, User $user, string $table, string $ancestors)
    {
        return $query
            ->selectRaw('1')
            ->from('item_shares')
            ->join('folders as shared_folder', 'shared_folder.id', '=', 'item_shares.shareable_id')
            ->where('item_shares.shareable_type', Folder::class)
            ->whereNull('item_shares.revoked_at')
            ->where(fn ($q) => $q->whereNull('item_shares.expires_at')->orWhere('item_shares.expires_at', '>', now()))
            ->whereRaw("{$table}.visibility <> ?", [FileVisibility::Confidential->value])
            ->whereRaw("locate(concat('/', shared_folder.public_id, '/'), {$ancestors}) > 0")
            ->where(fn ($q) => $this->granteeMatches($q, $user));
    }

    private function granteeMatches($query, User $user)
    {
        $query->where(fn ($q) => $q->where('item_shares.grantee_type', 'user')
            ->where('item_shares.user_id', $user->id));

        if ($user->division_id !== null) {
            $query->orWhere(fn ($q) => $q->where('item_shares.grantee_type', 'division')
                ->where('item_shares.division_id', $user->division_id));
        }

        if ($user->section_id !== null) {
            $query->orWhere(fn ($q) => $q->where('item_shares.grantee_type', 'section')
                ->where('item_shares.section_id', $user->section_id));
        }

        $roleIds = $user->roles->pluck('id')->all();

        if ($roleIds !== []) {
            $query->orWhere(fn ($q) => $q->where('item_shares.grantee_type', 'role')
                ->whereIn('item_shares.role_id', $roleIds));
        }

        return $query;
    }
}
