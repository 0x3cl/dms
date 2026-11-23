<?php

namespace App\Http\Controllers;

use App\Enums\FileAbility;
use App\Models\Document;
use App\Services\Access\AccessResolver;
use App\Services\Files\FilePreview;
use App\Services\Files\FileStorage;
use App\Services\TrackingCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public tracking surface — a label scanned, or a code typed, by somebody
 * with no account.
 *
 * This is a **narrowing** of the authenticated tracking surface rather than a
 * return to the open one that preceded it. The reasoning that closed
 * `/documents/view/{code}` still holds in full: a tracking code is printed on
 * the folder, it is a short per-type daily series, and treating it as a
 * credential would let anyone who photographed a desk read every public
 * document in the institute. So a code still buys **nothing it did not buy
 * before**:
 *
 * - A **public** document is readable without an account, which is exactly
 *   what `AccessResolver::forDocument()` has always said about it — the
 *   resolver is untouched, and this page asks it rather than deciding for
 *   itself.
 * - A **private** or **confidential** document answers with its classification
 *   and nothing else. No title, no uploader, no type, no file, no trail. The
 *   page says it exists, says it is restricted, and points at the two real
 *   doors: sign in, or ask whoever holds it for a share link.
 *
 * What a code does buy is the one thing the closed surface could not give the
 * courier holding the folder: an honest answer. Before this, every scan by
 * somebody without an account landed on a sign-in form, which says nothing
 * about the document and nothing about why.
 *
 * Two consequences are worth stating plainly, because they are the cost of
 * this and not an oversight:
 *
 * - **Existence is disclosed.** Somebody walking the series can learn that a
 *   code is live and how it is classified. That is the price of answering the
 *   courier at all, and it is blunted rather than removed: the route is rate
 *   limited, and a restricted document discloses nothing beyond its level.
 * - **A signed-in reader is sent to the real page.** When the viewer has an
 *   account and the resolver grants them `view`, this redirects to
 *   `documents.view`, so the trail, the recipient list and the actions are
 *   never a worse experience for being reached through the public door.
 */
class PublicTrackingController extends Controller
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly FilePreview $preview,
        private readonly FileStorage $files,
    ) {}

    /**
     * Look a tracking code up, for anybody.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        /*
         * Bounded on the way in, because this parameter carries whatever a
         * symbol encoded or somebody pasted — a whole email, a vCard, a URL
         * to another site. Truncated rather than refused with a 422: this is
         * a GET with no form behind it, so a validation redirect would have
         * nowhere to render the message, and the mask below already answers
         * honestly. The cap exists so the echo back to the page is a code and
         * not a paragraph.
         */
        $code = Str::limit($request->string('code')->trim()->toString(), 64, '');
        $method = $request->string('method')->toString();
        $method = in_array($method, ['code', 'scan', 'upload'], true) ? $method : 'code';

        $miss = fn (array $extra = []): Response => Inertia::render('track/index', [
            'code' => $code,
            'method' => $method,
            'notFound' => false,
            'malformed' => false,
            'result' => null,
            ...$extra,
        ]);

        if ($code === '') {
            return $miss();
        }

        /*
         * Three answers, not two.
         *
         * "That is not a tracking code" and "no document carries that code"
         * send a person to different places — the first to what they typed,
         * the second to the printed label — so conflating them means every
         * mistyped code is reported as a document that could not be found,
         * and somebody goes looking in the register for it. The shape lives
         * in `TrackingCodeService`, beside the code that composes one, and is
         * asked before the database is.
         */
        if (! TrackingCodeService::looksLikeCode($code)) {
            return $miss(['malformed' => true]);
        }

        $document = Document::with(['user:id,name', 'documentType:id,name', 'file'])
            ->where('code', $code)
            ->first();

        if (! $document) {
            return $miss(['notFound' => true]);
        }

        /*
         * A reader who may actually open it gets the document page, not this
         * one. The resolver decides that, exactly as it does everywhere else —
         * this page never answers the access question itself.
         */
        if ($request->user() && $this->access->allows($request->user(), $document, FileAbility::View)) {
            return to_route('documents.view', $document->code);
        }

        return $miss(['result' => $this->resultFor($request, $document)]);
    }

    /**
     * What a visitor with no grant is told about a document.
     *
     * The shape is deliberately two-sided. A public document is described in
     * full and previewed; a restricted one carries its code and its level and
     * nothing that would describe its subject, its author or its office. Being
     * told "this is confidential" is not a disclosure — being told what the
     * confidential thing is about would be.
     *
     * @return array<string, mixed>
     */
    private function resultFor(Request $request, Document $document): array
    {
        /*
         * The effective restriction, not the level.
         *
         * A public document sealed by a live `restricted` link is closed to
         * everybody but its named list, and this page is one of the two
         * doors the level used to leave open — so asking the level here
         * would describe and preview a document the resolver has just
         * refused. `Document::restrictsToNamedPeople()` is the one place
         * that question is answered.
         */
        $open = ! $document->restrictsToNamedPeople();

        // The restricted shape is stated once, on the model, because the
        // share-link page answers the same question for a link whose
        // document does not open for its holder.
        $result = [...$document->restrictedSummary(), 'open' => $open];

        if (! $open) {
            return $result;
        }

        $file = $document->file;

        return [
            ...$result,
            /*
             * Restored, because the summary withholds it and this is no
             * longer a refusal. `restrictedSummary()` drops the code
             * wherever the code is itself the door — which is a *public*
             * document being refused by its link's restricted term. Here the
             * same document is being described to somebody the level admits,
             * so the code is the heading over the thing they are reading.
             */
            'code' => $document->code,
            'title' => $document->title,
            'type' => $document->documentType?->name ?? $document->type,
            'uploader' => $document->uploaderName(),
            'uploaded_at' => $document->created_at?->format('j M Y, g:i a'),
            /*
             * The descriptor the server built, not a decision this page made.
             * `FilePreview` picks the viewer and, for a signed-out reader,
             * reports `can_download` false — so a visitor holding a code may
             * look at a public document and not take a copy of it. The bytes
             * behind it come from `files.stream`, which re-asks the resolver
             * on every fetch and writes the trail entry itself.
             */
            'preview' => $file && $this->files->exists($file)
                ? $this->preview->describe($file, $request->user())
                : null,
        ];
    }
}
