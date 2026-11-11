<?php

namespace App\Services\Files;

use App\Enums\ActivityAction;
use App\Enums\GranteeType;
use App\Enums\ShareAbility;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\ItemShare;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Support\Facades\DB;
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
     * Mint a share link.
     *
     * Two refusals, and both matter:
     *
     * - A **confidential** item never gets a link. `AccessResolver` checks this
     *   again on every redemption, because an item can be reclassified after a
     *   link has already been handed out — but refusing here means the
     *   administrator finds out when they try, not silently later.
     * - A link may only ever carry view or download. The holder of the URL is
     *   the audience, so no link grants rename, move, delete or re-share.
     */
    public function createLink(
        File|Folder $item,
        ShareAbility $ability,
        User $createdBy,
        ?\DateTimeInterface $expiresAt = null,
        ?int $maxViews = null,
    ): ShareLink {
        if (! $this->access->effectiveVisibility($item)->linkable()) {
            throw new RuntimeException('A confidential item cannot be shared through a link.');
        }

        if (! in_array($ability, ShareAbility::linkable(), true)) {
            throw new RuntimeException('A share link can only grant view or download.');
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
