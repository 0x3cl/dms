<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Enums\FileAbility;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\ShareLink;
use App\Services\Access\AccessResolver;
use App\Services\Files\FileStorage;
use App\Services\Files\SharingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Redeeming a share link.
 *
 * Unauthenticated by design — the token *is* the credential. Which is why
 * every request re-resolves it rather than trusting the landing page: a link
 * can be revoked, expire, run out of views, or have its item reclassified as
 * confidential between the page loading and the download being clicked.
 */
class ShareLinkController extends Controller
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly SharingService $sharing,
        private readonly FileStorage $storage,
    ) {}

    public function show(string $token): Response
    {
        [$link, $item] = $this->resolve($token);

        $grant = $this->access->grant(null, $item, $link);
        abort_if($grant->isDenied(), 404);

        $link->recordAccess();

        /*
         * No actor: whoever opened this is, by construction, not signed in.
         * The IP is the only identifying thing there is, which is exactly why
         * the link exists as an auditable object rather than as a bare
         * permission — someone has to be able to see that it was used, and how
         * often.
         */
        ActivityLog::record(null, ActivityAction::LinkAccessed, $item, [
            'ability' => $link->ability->value,
            'views' => $link->view_count + 1,
            'created_by' => $link->created_by_name,
        ], request());

        return Inertia::render('share/show', [
            'token' => $link->token,
            'item' => [
                'name' => $item instanceof File ? $item->downloadName() : $item->name,
                'kind' => $item instanceof File ? 'file' : 'folder',
                'size' => $item instanceof File ? $item->size_bytes : null,
                'mime_type' => $item instanceof File ? $item->mime_type : null,
                'shared_by' => $link->created_by_name,
                'expires_at' => $link->expires_at,
            ],
            'can' => [
                'download' => $grant->allows(FileAbility::Download),
                // Previewing in place is only offered for types the browser can
                // be trusted with; everything else would be a download anyway.
                'preview' => $item instanceof File
                    && $grant->allows(FileAbility::View)
                    && in_array($item->mime_type, FileStorage::INLINE_TYPES, true),
            ],
        ]);
    }

    public function stream(Request $request, string $token): StreamedResponse|BinaryFileResponse
    {
        [$link, $item] = $this->resolve($token);

        abort_unless($item instanceof File, 404, 'A folder cannot be streamed.');

        $inline = $request->string('disposition')->toString() === 'inline';
        $needed = $inline ? FileAbility::View : FileAbility::Download;

        // Re-resolved, not carried over from the landing page.
        abort_unless($this->access->allows(null, $item, $needed, $link), 404);
        abort_unless($this->storage->exists($item), 404, 'The stored file is missing.');

        return $inline ? $this->storage->inline($item) : $this->storage->download($item);
    }

    /**
     * Token to link and item, 404 on anything wrong.
     *
     * "Revoked", "expired", "spent" and "never existed" all answer identically.
     * Distinguishing them would confirm to anyone probing that a token was once
     * real, which is the one thing a bearer URL must not leak.
     *
     * @return array{0: ShareLink, 1: File|Folder}
     */
    private function resolve(string $token): array
    {
        $link = $this->sharing->resolveLink($token);
        abort_if($link === null, 404);

        $item = $link->shareable;
        abort_unless($item instanceof File || $item instanceof Folder, 404);

        return [$link, $item];
    }
}
