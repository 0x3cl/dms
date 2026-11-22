<?php

use App\Enums\FileAbility;
use App\Models\Document;
use App\Models\User;
use App\Services\Access\AccessResolver;
use Illuminate\Support\Facades\Broadcast;

/*
 * Who may listen.
 *
 * `/broadcasting/auth` carries `web` and `auth` and **no** `permission:`
 * middleware, so these callbacks are the entire gate. Anything they forget is
 * open — which is why `ChannelAuthorizationTest` resolves the same matrix
 * through `AccessResolver` and asserts the channel agrees, in the same spirit
 * as `FileListingTest`.
 */

/**
 * A document's trail.
 *
 * Authorized by the **same rule that decides whether the trail page opens** —
 * `AccessResolver` on the document — because a document's travel history is
 * exactly as sensitive as the document. A log listing who received a
 * confidential case file is a disclosure of its own, so this must never become
 * a second, looser statement of that rule.
 *
 * `find()`, never `findOrFail()`: an exception inside an authorization
 * callback is a 500, not a refusal. A soft-deleted document returns null and
 * is refused, which is right — its trail page is unreachable too.
 */
Broadcast::channel('documents.{document}', function (User $user, string $document): bool {
    $subject = Document::query()->find($document);

    return $subject !== null
        // The module gate the trail's own routes carry.
        && $user->can('documents.read')
        && app(AccessResolver::class)->allows($user, $subject, FileAbility::View);
});

/** The file-and-folder report, behind the permission its route carries. */
Broadcast::channel('activity-trails', fn (User $user): bool => $user->can('activity-trails.read'));

/** Your own notifications, and nobody else's. */
Broadcast::channel('users.{id}', fn (User $user, string $id): bool => (int) $user->id === (int) $id);
