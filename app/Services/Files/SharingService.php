<?php

namespace App\Services\Files;

use App\Enums\ActivityAction;
use App\Enums\GranteeType;
use App\Enums\ShareAbility;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\File;
use App\Models\Folder;
use App\Models\ItemShare;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Granting and revoking access to a file or folder.
 *
 * Every rule about *what may be shared* lives here, so there is one answer
 * rather than one per calling screen.
 */
class SharingService
{
    public function __construct(private readonly AccessResolver $access) {}

    /**
     * Share an item with a person, a division, a section or a role.
     *
     * Idempotent on the target: re-sharing with the same grantee updates the
     * ability and clears any revocation, rather than failing on the unique
     * index or leaving two rows whose effective answer depends on row order.
     */
    public function share(
        File|Folder $item,
        GranteeType $granteeType,
        int $granteeId,
        ShareAbility $ability,
        User $grantedBy,
        ?\DateTimeInterface $expiresAt = null,
    ): ItemShare {
        $this->assertNotOwner($item, $granteeType, $granteeId);

        $share = DB::transaction(fn (): ItemShare => ItemShare::query()->updateOrCreate(
            [
                'shareable_type' => $item::class,
                'shareable_id' => $item->getKey(),
                'grantee_type' => $granteeType->value,
                'user_id' => $granteeType === GranteeType::User ? $granteeId : null,
                'division_id' => $granteeType === GranteeType::Division ? $granteeId : null,
                'section_id' => $granteeType === GranteeType::Section ? $granteeId : null,
                'role_id' => $granteeType === GranteeType::Role ? $granteeId : null,
            ],
            [
                'ability' => $ability->value,
                'granted_by_id' => $grantedBy->id,
                // Snapshotted, so "who gave me this" stays answerable after
                // that person has left.
                'granted_by_name' => $grantedBy->name,
                'expires_at' => $expiresAt,
                'revoked_at' => null,
            ],
        ));

        ActivityLog::record($grantedBy, ActivityAction::Shared, $item, [
            'grantee_type' => $granteeType->value,
            'grantee_id' => $granteeId,
            // The label as well as the id: a share row whose division is later
            // deleted cascades away, and this is then the only record of who it
            // was granted to.
            'grantee' => $share->granteeLabel(),
            'ability' => $ability->value,
            'expires_at' => $expiresAt?->format('c'),
        ]);

        return $share;
    }

    /**
     * Revoke a share.
     *
     * Marked rather than deleted: the screen has to be able to show that access
     * was withdrawn, and re-granting should be a deliberate act rather than
     * something that happens by accident when a form is resubmitted.
     */
    public function revoke(ItemShare $share, ?User $actor = null): void
    {
        $share->update(['revoked_at' => now()]);

        ActivityLog::record($actor, ActivityAction::ShareRevoked, $share->shareable, [
            'grantee_type' => $share->grantee_type->value,
            'grantee' => $share->granteeLabel(),
            'ability' => $share->ability->value,
        ]);
    }

    /**
     * The one live link an item may have, if it has one.
     *
     * Revoked links are excluded, not because they are secret but because
     * they are over: the question this answers is "is there a link out there
     * now", and a revoked row opens nothing.
     */
    public function activeLinkFor(File|Folder|Document $item): ?ShareLink
    {
        return ShareLink::query()
            ->where('shareable_type', $item::class)
            ->where('shareable_id', $item->getKey())
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();
    }

    /**
     * Mint a share link.
     *
     * **An item has at most one live link.** Several links to one thing is
     * several credentials with separate expiries and view caps, and no screen
     * can say which of them the message somebody sent last week carried — so
     * revoking the wrong one is the normal outcome rather than the unlucky
     * one. One link, whose terms can be changed and which can be withdrawn,
     * is a thing a person can actually reason about.
     *
     * The rule is refused here rather than in the two controllers, because a
     * rule stated twice is a rule that holds in one place.
     *
     * Three refusals, and all three matter:
     *
     * - A **confidential** item never gets a link. `AccessResolver` checks this
     *   again on every redemption, because an item can be reclassified after a
     *   link has already been handed out — but refusing here means the
     *   administrator finds out when they try, not silently later.
     * - A link may only ever carry restricted, view or download. The holder of
     *   the URL is the audience, so no link grants rename, move, delete or
     *   re-share — and `restricted` is a document's term alone.
     * - An item that already has a live link does not get a second one.
     */
    public function createLink(
        File|Folder|Document $item,
        ShareAbility $ability,
        User $createdBy,
        ?\DateTimeInterface $expiresAt = null,
        ?int $maxViews = null,
    ): ShareLink {
        /*
         * A document answers for itself; a file or folder answers for its whole
         * chain. Both end at the same question — may this be reached by a URL
         * whoever holds it can open — and a confidential subject never may.
         *
         * `AccessResolver::linkableNow()` is that question, and asking it
         * here rather than restating it is what fixes a third case the
         * restatement missed: a **tracked file**. The ternary below asked a
         * `File` for its own visibility, which for anything in personal
         * storage is `private` — so a confidential document's bytes, reached
         * from My Files rather than from the register, minted a link
         * happily. Nothing leaked, because redemption asks the resolver and
         * the resolver was right; what the office got was a link that 404'd
         * for everyone it was sent to, which is the failure this method
         * exists to raise early.
         *
         * The check is repeated on every redemption in AccessResolver, because
         * a subject can be reclassified after a link has been handed out.
         */
        if (! $this->access->linkableNow($item)) {
            throw new RuntimeException('A confidential item cannot be shared through a link.');
        }

        $this->assertLinkable($ability, $item);

        if ($this->activeLinkFor($item) !== null) {
            throw new RuntimeException('This item already has a share link. Change or revoke that one.');
        }

        $link = ShareLink::create([
            'shareable_type' => $item::class,
            'shareable_id' => $item->getKey(),
            'ability' => $ability->value,
            'expires_at' => $expiresAt,
            'max_views' => $maxViews,
            'created_by_id' => $createdBy->id,
            'created_by_name' => $createdBy->name,
        ]);

        // The token is deliberately **not** recorded. An audit row that carries
        // a live credential is a way to obtain that credential by reading the
        // audit report, which is a strictly worse disclosure than the one it
        // was written to detect.
        ActivityLog::record($createdBy, ActivityAction::LinkCreated, $item, [
            'ability' => $ability->value,
            'expires_at' => $expiresAt?->format('c'),
            'max_views' => $maxViews,
        ]);

        return $link;
    }

    /**
     * The item's live link, minting one if it has none.
     *
     * This exists because a QR Tracker label has to resolve to *something*
     * that opens for whoever is holding the folder, and since the tracking
     * URL stopped being public that something is a share link. A label
     * pointing at a link that does not exist yet is a label pointing at
     * nothing, so the link is made when the label is.
     *
     * No expiry and no cap are the deliberate defaults: a label is
     * printed and stuck to a folder that may sit in an office for years, so
     * an expiry would silently turn it into a dead sticker, and nobody is
     * there to notice. Narrow it, cap it or revoke it from the document's own
     * screen — which is the point of its being a link rather than a code.
     *
     * Returns null for a confidential item, which may never have a link. Its
     * label falls back to the tracking URL, so staff scan it and sign in.
     */
    public function ensureLinkFor(File|Folder|Document $item, User $actor): ?ShareLink
    {
        $existing = $this->activeLinkFor($item);

        if ($existing !== null) {
            return $existing;
        }

        /*
         * `linkable()` again, for every level but confidential.
         *
         * This briefly asked a narrower question — public only — because an
         * automatically minted link printed on a folder was a bearer
         * credential nobody had asked for. That is no longer what a link to a
         * restricted document is: `/s/{token}` resolves the *holder's* own
         * access now, so a private document's label opens for the people
         * tagged on it and states the classification to everyone else.
         *
         * With the credential gone, withholding the link only cost the label
         * its one shape. Every document that may have a link gets one, and
         * what the link does when it is redeemed is the thing that varies.
         */
        if (! $this->access->linkableNow($item)) {
            return null;
        }

        return $this->createLink($item, $this->defaultLinkAbility($item), $actor);
    }

    /**
     * The term an automatically minted link starts on.
     *
     * **Restricted the moment the subject is restricted to a named list**,
     * and view otherwise. This link is the one printed on a QR label, which
     * is stuck to a folder anybody walking past can photograph — so a
     * restricted document's label must be an address and not a key. Widening
     * it to view or download is a deliberate act on the document's own
     * screen, which is the whole point of its being a link rather than a
     * code.
     *
     * A file or folder cannot carry the restricted term, so it starts on
     * view as it always has.
     */
    private function defaultLinkAbility(File|Folder|Document $item): ShareAbility
    {
        if (! $item instanceof Document) {
            return ShareAbility::View;
        }

        return $item->visibility->restrictedToNamedPeople()
            ? ShareAbility::Restricted
            : ShareAbility::View;
    }

    /**
     * Change an existing link's terms, keeping its URL.
     *
     * Keeping the token is the whole point: the alternative to editing is
     * revoke-and-recreate, which silently breaks every message the old URL was
     * pasted into. Someone narrowing a link from download to view wants the
     * people they already sent it to to be narrowed, not cut off.
     *
     * `view_count` is deliberately untouched. Views are a record of what
     * happened, not a budget — and raising `max_views` on a spent link revives
     * it, which is the honest reading of "give it a few more opens".
     */
    public function updateLink(
        ShareLink $link,
        ShareAbility $ability,
        User $actor,
        ?\DateTimeInterface $expiresAt = null,
        ?int $maxViews = null,
    ): ShareLink {
        $this->assertLinkable($ability, $link->shareable);

        $link->update([
            'ability' => $ability->value,
            'expires_at' => $expiresAt,
            'max_views' => $maxViews,
        ]);

        ActivityLog::record($actor, ActivityAction::LinkUpdated, $link->shareable, [
            'ability' => $ability->value,
            'expires_at' => $expiresAt?->format('c'),
            'max_views' => $maxViews,
        ]);

        return $link->refresh();
    }

    /**
     * A link may only ever carry restricted, view or download. The holder of
     * the URL is the audience, so no link grants rename, move, delete or
     * re-share.
     *
     * And `restricted` is a document's term. It means "defer to the
     * subject's own rule about who may read this", and a file's own rule is
     * its visibility and its shares — which a link is already the sanctioned
     * way around. Deferring there would make the link mean nothing at all.
     */
    private function assertLinkable(ShareAbility $ability, File|Folder|Document|null $subject = null): void
    {
        if (! in_array($ability, ShareAbility::linkable(), true)) {
            throw new RuntimeException('A share link can only grant view or download.');
        }

        if ($ability->documentOnly() && ! $subject instanceof Document) {
            throw new RuntimeException('Only a tracked document\'s link can be restricted to the people who already have access.');
        }

        /*
         * And only where the document *is* a list of names.
         *
         * The term says "the uploader and the people tagged on this
         * document". That is a narrowing of a private document and a
         * contradiction of a public one: to mean anything on a public
         * document it would have to close the register and the printed
         * tracking code too, and a public document is public — a document
         * that should be for named people is filed as private, which is what
         * the level is for.
         *
         * It was briefly offered at every level, with the resolver treating
         * a live restricted link as a condition laid over an open document.
         * The office reversed it: the link's terms describe the URL, and the
         * level describes who may read. Refused here rather than only in the
         * dialog, because an option a page merely hides is a rule only the
         * page keeps.
         */
        if ($ability->documentOnly()
            && $subject instanceof Document
            && ! $subject->visibility->restrictedToNamedPeople()) {
            throw new RuntimeException('Only a private or confidential document can have a restricted link. A public document is open to everyone who has its tracking code.');
        }
    }

    /**
     * Mint a new token for this link, keeping its terms.
     *
     * The act somebody actually wants when a URL has gone further than they
     * meant it to: the ability, the expiry and the cap are the decisions they
     * already made and are not what went wrong — the *address* is. So those
     * survive, the old URL stops working at that moment, and the item keeps
     * its one live link rather than accumulating a revoked row and a fresh
     * one whose history starts empty.
     *
     * **`view_count` is reset, and that is the one place this parts company
     * with `updateLink()`.** There, views are a record of what happened and
     * raising a cap on a spent link revives it. Here the URL those views were
     * spent against no longer exists, so carrying the count forward would
     * hand somebody a brand-new link that is already partly — or entirely —
     * used up. What it was before is written into the audit row, which is
     * where a count that is no longer true belongs.
     *
     * The new token is deliberately **not** recorded, for the same reason the
     * first one was not: an audit row carrying a live credential is a way to
     * obtain that credential by reading the audit report.
     */
    public function regenerateLink(ShareLink $link, ?User $actor = null): ShareLink
    {
        $spent = $link->view_count;

        $link->update([
            'token' => Str::random(64),
            'view_count' => 0,
            'last_accessed_at' => null,
        ]);

        ActivityLog::record($actor, ActivityAction::LinkRegenerated, $link->shareable, [
            'ability' => $link->ability->value,
            'expires_at' => $link->expires_at?->format('c'),
            'max_views' => $link->max_views,
            // What the dead URL had been opened. The count starts again, so
            // this row is the only place the old one survives.
            'previous_views' => $spent,
        ]);

        return $link->refresh();
    }

    public function revokeLink(ShareLink $link, ?User $actor = null): void
    {
        $link->update(['revoked_at' => now()]);

        ActivityLog::record($actor, ActivityAction::LinkRevoked, $link->shareable, [
            'ability' => $link->ability->value,
            'views' => $link->view_count,
        ]);
    }

    /**
     * Resolve a token to a link that is still good.
     *
     * Returns null rather than throwing, so the caller answers 404 — "revoked"
     * and "never existed" must look the same, or the difference confirms the
     * token was real.
     */
    public function resolveLink(string $token): ?ShareLink
    {
        return ShareLink::query()->usable()->where('token', $token)->first();
    }

    /**
     * Sharing an item with its own owner is meaningless — they already hold
     * everything — and it would put a row in the table that can only confuse
     * whoever reads the share list later.
     */
    private function assertNotOwner(File|Folder $item, GranteeType $granteeType, int $granteeId): void
    {
        if ($granteeType === GranteeType::User && $item->user_id === $granteeId) {
            throw new RuntimeException('An item cannot be shared with its own owner.');
        }
    }
}
