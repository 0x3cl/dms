<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Enums\FileAbility;
use App\Models\File;
use App\Services\Access\AccessResolver;
use App\Services\Files\FileEventRecorder;
use App\Services\Files\FilePreview;
use App\Services\Files\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a file's bytes from the private disk, at an obfuscated URL.
 *
 *   /files/{ownerPublicId}/{filePublicId}
 *
 * Neither segment is a database id, and neither is a filename. Both are
 * 26-character opaque identifiers constrained in the route definition, so a
 * `..`, a `/` or a null byte cannot match the route at all — the traversal
 * check is the router's, before any of this code runs.
 *
 * Documents used to be served off the `public` disk, which meant every upload
 * was readable at /storage/documents/<hash>.pdf by anyone who had the URL, with
 * no authorization check whatsoever. Every byte now passes through
 * AccessResolver first.
 *
 * These return file responses, so they must be reached by a plain link, a real
 * form post or `window.location` — an Inertia XHR visit cannot follow one.
 */
class FileStreamController extends Controller
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly FileStorage $storage,
        private readonly FileEventRecorder $events,
        private readonly FilePreview $preview,
    ) {}

    public function show(Request $request, string $owner, string $file): StreamedResponse|BinaryFileResponse
    {
        $model = $this->locate($owner, $file);

        /*
         * A thumbnail is a view of the file, so it needs the same `view`
         * ability and nothing less. A thumbnail of a confidential document is
         * still a disclosure of that document — which is why thumbnails live on
         * the same private disk and come through this same gate rather than
         * being served as public assets.
         *
         * Not recorded in the activity log: a grid of twenty cards would write
         * twenty preview rows for one glance at a folder, which is noise that
         * buries the events that matter.
         */
        if ($request->string('variant')->toString() === 'thumb') {
            $this->authorizeAbility($request, $model, FileAbility::View);

            return $this->storage->thumbnail($model);
        }

        $wantsInline = $request->string('disposition')->toString() === 'inline';

        $this->authorizeAbility($request, $model, $wantsInline ? FileAbility::View : FileAbility::Download);

        // Checked before anything is recorded, so a file that has gone missing
        // off the disk never leaves a record of a release that did not happen.
        abort_unless($this->storage->exists($model), 404, 'The stored file is missing.');

        if ($wantsInline) {
            /*
             * Debounced, because an embedded preview iframe re-requests the
             * same file on a resize, a re-mount or a reload. One row per render
             * is a forged history: it says the person opened the document nine
             * times when they opened it once.
             */
            $this->events->recordOnce(ActivityAction::Previewed, $model, $request->user(), $request);

            return $this->storage->inline($model);
        }

        // A download is never debounced. Each one is a separate copy leaving
        // the server, which is the whole question the register answers.
        $this->events->record(ActivityAction::Downloaded, $model, $request->user(), $request);

        return $this->storage->download($model);
    }

    /**
     * How this file should be previewed, and where from.
     *
     * The page does not decide this. Which viewer a type needs, and whether the
     * bytes may be handed to one at all, is a property of the file and of the
     * person asking — so it is resolved once, here, behind the same `view`
     * ability the bytes themselves are behind. A page that chose its own viewer
     * would be choosing, for example, whether a confidential document gets sent
     * to a third party.
     *
     * JSON rather than props on the listing: a folder of forty files would
     * otherwise mint forty short-lived URLs on every navigation, all but one of
     * them unused and every one of them a live credential for five minutes.
     */
    public function previewDescriptor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner' => ['required', 'string', 'size:26'],
            'file' => ['required', 'string', 'size:26'],
        ]);

        $model = $this->locate($validated['owner'], $validated['file']);
        $this->authorizeAbility($request, $model, FileAbility::View);

        abort_unless($this->storage->exists($model), 404, 'The stored file is missing.');

        /*
         * Recorded here, debounced, rather than when the viewer fetches the
         * bytes. For an Office document the fetch is made by Microsoft's
         * servers, not by the person — so the request that carries an actor is
         * this one, and recording on the other side would write a preview with
         * no actor or none at all.
         */
        $this->events->recordOnce(ActivityAction::Previewed, $model, $request->user(), $request);

        return response()->json($this->preview->describe($model, $request->user()));
    }

    /**
     * The bytes, for an external document viewer, at a short-lived signed URL.
     *
     * Word, Excel and PowerPoint cannot be rendered by a browser at all, and
     * the viewer that renders them runs on Microsoft's servers and fetches the
     * file itself. That means the bytes have to be
     * reachable *without this application's session* for as long as the render
     * takes, which is the one place the private disk's rule is relaxed — so the
     * relaxation is made as narrow as it can be:
     *
     * - the URL is minted only after AccessResolver has granted `view` to the
     *   signed-in person asking for it;
     * - it names one file, is signed, and expires in minutes;
     * - a confidential item never gets one at all, the same rule that stops a
     *   confidential item having a share link;
     * - it is re-checked on every fetch, because an item can be reclassified
     *   after a URL has been handed out.
     *
     * No session is consulted, so there is no actor to record — the preview was
     * already recorded when the URL was minted.
     */
    public function viewer(Request $request, string $owner, string $file): StreamedResponse|BinaryFileResponse
    {
        $model = $this->locate($owner, $file);

        // Re-resolved rather than trusted to the signature: the signature says
        // this URL was issued, not that the item is still shareable.
        abort_unless($this->preview->mayLeaveForViewer($model), 404);
        abort_unless($this->storage->exists($model), 404);

        return $this->storage->inline($model);
    }

    /**
     * Resolve the pair, and 404 on any mismatch.
     *
     * The owner segment is checked against the file's actual owner rather than
     * ignored, so the URL is self-validating: a stranger cannot take a file
     * identifier they were legitimately given and recombine it under their own
     * owner segment to probe what else exists.
     *
     * A mismatch is 404 and not 403 on purpose. "Wrong owner for this file" and
     * "no such file" must be indistinguishable, or the difference between them
     * confirms that an identifier exists — which is the enumeration this URL
     * scheme is for preventing.
     */
    private function locate(string $owner, string $file): File
    {
        $model = File::query()->with(['folder', 'document', 'owner:id,public_id'])
            ->where('public_id', $file)
            ->first();

        abort_if($model === null, 404);
        abort_unless($model->owner?->public_id === $owner, 404);

        return $model;
    }

    /**
     * A refusal to read is a 404, for the same reason the mismatch above is.
     *
     * Everywhere else in the application an unauthorized read redirects to the
     * dashboard with an explanation, because the person is looking at a page
     * and deserves one. Here the caller is an `<img>`, an iframe or a download,
     * which cannot render an explanation — and a 403 on this route would
     * confirm the identifier is real to anyone probing it.
     */
    private function authorizeAbility(Request $request, File $file, FileAbility $ability): void
    {
        abort_unless($this->access->allows($request->user(), $file, $ability), 404);
    }
}
