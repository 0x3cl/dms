<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Enums\DocumentAction;
use App\Enums\FileAbility;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\ShareLink;
use App\Services\Access\AccessResolver;
use App\Services\Files\FilePreview;
use App\Services\Files\FileStorage;
use App\Services\Files\GuestIdentity;
use App\Services\Files\SharingService;
use Illuminate\Http\RedirectResponse;
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
        private readonly FilePreview $preview,
        private readonly GuestIdentity $guests,
    ) {}

    public function show(Request $request, string $token): Response|RedirectResponse
    {
        [$link, $item] = $this->resolve($token);

        /*
         * Resolved **as the person holding it**, not as nobody.
         *
         * For a bearer link the user makes no difference — the link clause
         * answers first and the token is the whole credential. For a
         * `restricted` one it is the entire question: that term resolves to
         * the holder's own access, so a tagged reader following the link gets
         * what their own grant allows and a stranger gets nothing.
         */
        $grant = $this->access->grant($request->user(), $item, $link);

        /*
         * A link on the restricted term is not a key to its document.
         *
         * Answered with the same page a tracking code gets — it exists, here
         * is its level, and here are the two real doors — rather than with a
         * 404, which would be a lie to somebody holding a link the uploader
         * really did send them, or with the document, which the term says
         * they may not read. Nothing is recorded and no view is spent,
         * because nothing was opened.
         *
         * It runs before the denied-grant 404 because the two are different
         * answers. A link that is revoked, expired, spent or pointed at
         * something since reclassified confidential has nothing to say, and
         * every such refusal looks the same. A restricted link held by
         * somebody it is not for is working exactly as intended.
         */
        if (! $this->opensForHolder($request, $item, $link)) {
            // Only a document has a page to be refused with: the restricted
            // term is a document's alone, so anything else reaching here is a
            // link that should not exist and answers like every other refusal.
            abort_unless($item instanceof Document, 404);

            return Inertia::render('track/index', [
                'code' => $item->code,
                'method' => 'code',
                'notFound' => false,
                'malformed' => false,
                'result' => $item->restrictedSummary(),
            ]);
        }

        abort_if($grant->isDenied(), 404);

        /*
         * A signed-in reader who may open the document is sent to the
         * document, not to a card about it.
         *
         * The label is one link whatever the level, so the person scanning it
         * is as often a member of staff as a courier. For them the share page
         * is a worse version of the page they are entitled to: no trail, no
         * comments, no version history, and a Download button where the
         * document itself would be. The token did its job by naming which
         * document to open.
         *
         * A guest is untouched by this — there is no page behind
         * `documents.view` for them, which is why that route forwards one to
         * the public lookup.
         *
         * **It runs after the term has been honoured, and that order is the
         * whole of it.** It asks what the person may do with *no link*,
         * because a bearer link is redeemed by a stranger and a stranger has
         * no document page to be sent to — so this cannot be the question
         * that decides whether the URL opens. Asked first, it was: on a
         * public document every signed-in person passes it, so a link marked
         * Restricted redirected them straight to the document it was closed
         * to, and the term meant nothing for anybody holding an account. The
         * gate above now answers first, and this only decides *which page* a
         * reader who is already through it should be looking at.
         */
        if ($item instanceof Document
            && $request->user() !== null
            && $this->access->allows($request->user(), $item, FileAbility::View)) {
            return redirect()->route('documents.view', ['code' => $item->code]);
        }

        // A document's link opens the document, and its bytes are the file
        // underneath. Resolved here so the page below has one shape to render.
        $file = $item instanceof Document ? $item->file : ($item instanceof File ? $item : null);

        /*
         * Who is this?
         *
         * Nobody is signed in — that is what a share link is for — so the
         * trail would say "Unknown" on every row, which tells a reader
         * nothing at all. The visitor is asked once and may decline; see
         * GuestIdentity for why declining produces a stable handle rather
         * than a refusal.
         *
         * Nothing is recorded and no view is spent until they answer, so a
         * link with a cap of three is not emptied by a page somebody has not
         * read. The prompt is the whole page in that state.
         */
        $identity = $request->user() !== null ? null : $this->guests->current($request);

        if ($request->user() === null && $identity === null) {
            return $this->prompt($link, $item, $file);
        }

        $actor = $request->user();
        $visitor = $actor !== null ? null : $this->guests->label($identity);

        $link->recordAccess();

        /*
         * No actor: whoever opened this is, by construction, not signed in.
         * The IP is the only identifying thing there is, which is exactly why
         * the link exists as an auditable object rather than as a bare
         * permission — someone has to be able to see that it was used, and how
         * often.
         */
        ActivityLog::record($actor, ActivityAction::LinkAccessed, $item, [
            'ability' => $link->ability->value,
            'views' => $link->view_count + 1,
            'created_by' => $link->created_by_name,
            // Self-asserted and labelled as such — see GuestIdentity.
            'visitor' => $visitor,
        ], $request);

        /*
         * A tracked document's own trail gets the entry too.
         *
         * The two tables answer to different audiences — the activity trail is
         * an administrator's report, the document trail is shown to everyone
         * who can read the document — and "somebody opened this through the
         * link you sent" belongs in the second. Without it the trail says the
         * document was never opened, which is precisely the question a hand-off
         * history exists to answer.
         *
         * No actor, because whoever opened it is by construction not signed in.
         * The remark says how it was reached, so the row is not mistaken for a
         * read by somebody on the recipient list.
         */
        if ($item instanceof Document) {
            DocumentLog::record(
                $item,
                DocumentAction::Viewed,
                $actor,
                'Opened through a share link created by '.($link->created_by_name ?? 'the uploader'),
                $request->ip(),
                $visitor,
            );
        }

        return Inertia::render('share/show', [
            'token' => $link->token,
            'item' => [
                'name' => $item instanceof Document ? $item->title : ($file ? $file->downloadName() : $item->name),
                'kind' => match (true) {
                    $item instanceof Document => 'document',
                    $item instanceof File => 'file',
                    default => 'folder',
                },
                'size' => $file?->size_bytes,
                'mime_type' => $file?->mime_type,
                // A tracked document says so, because the code is what the
                // recipient will be asked to quote back.
                'code' => $item instanceof Document ? $item->code : null,
                'shared_by' => $link->created_by_name,
                'expires_at' => $link->expires_at,
                /*
                 * The classification, so the page says what the reader is
                 * holding.
                 *
                 * It discloses nothing they do not already have — they are
                 * looking at the document — and it is the difference between
                 * a link somebody may forward and one they should not. A
                 * document answers with its own level, which is what governs
                 * a tracked file; anything else answers with the effective
                 * visibility the resolver computed, inheritance included.
                 */
                ...$this->classification($item),
            ],
            /*
             * The same descriptor the file manager previews from, resolved
             * through the link rather than through a session.
             *
             * It used to be a one-line `in_array($file->mime_type,
             * INLINE_TYPES)` here, which is a second answer to a question
             * FilePreview already owns — and a worse one: every OOXML file
             * sniffs as `application/zip`, so a shared Word document offered
             * no preview and, on a view-only link, no download either. The
             * page then said the link did not permit opening the file, which
             * was not true of the link but of this check.
             */
            'preview' => $file !== null && $grant->allows(FileAbility::View)
                ? $this->preview->describe($file, null, $link)
                : null,
            'can' => [
                'download' => $grant->allows(FileAbility::Download),
            ],
            // Shown back to them, so somebody who typed a name can see which
            // name the trail will carry — and that an anonymous handle is a
            // handle rather than a failure to be recognised.
            'visitor' => $identity,
        ]);
    }

    /**
     * Tell us who you are, or decline — then the link opens.
     *
     * A POST rather than a query parameter because it sets a cookie and writes
     * the answer that every later audit row will carry; and `back()` rather
     * than a rendered page, so the landing page is reached by exactly one code
     * path whether or not an identity already existed.
     */
    public function identify(Request $request, string $token): RedirectResponse
    {
        // Resolved first: an invalid token must not be told it is invalid only
        // after somebody has typed their name into it.
        $this->resolve($token);

        $validated = $request->validate([
            // Nullable, because "stay anonymous" is posting no name at all —
            // not a separate endpoint, and not a sentinel string somebody
            // could type by hand.
            'name' => ['nullable', 'string', 'max:60'],
        ]);

        $identity = $this->guests->issue($validated['name'] ?? null);

        return redirect()
            ->route('share.show', ['token' => $token])
            ->withCookie($this->guests->cookie($identity));
    }

    public function stream(Request $request, string $token): StreamedResponse|BinaryFileResponse|RedirectResponse
    {
        [$link, $item] = $this->resolve($token);

        /*
         * The bytes are behind the same question the page is.
         *
         * Without this the prompt would be decoration: anyone could skip
         * straight to `/s/{token}/file` and the trail would be back to saying
         * "Unknown". Sent to the landing page rather than refused, because the
         * person has done nothing wrong — they have simply not answered yet.
         */
        if ($request->user() === null && $this->guests->current($request) === null) {
            return redirect()->route('share.show', ['token' => $token]);
        }

        $file = $item instanceof Document ? $item->file : $item;

        abort_unless($file instanceof File, 404, 'A folder cannot be streamed.');

        $inline = $request->string('disposition')->toString() === 'inline';
        $needed = $inline ? FileAbility::View : FileAbility::Download;

        /*
         * A link reaches the **current version only**, and a `version`
         * parameter naming anything else is a 404 like every other refusal on
         * this route.
         *
         * A link is a bearer credential minted for a thing at a moment: its
         * terms are an ability, an expiry and a view cap, none of which says
         * anything about history. Letting whoever holds it walk back through
         * earlier versions would also *widen every token already in
         * circulation* the day versioning ships — including ones sent before
         * the feature existed — which is the exact opposite of the rule this
         * module is built on, that a link's reach is re-evaluated on every
         * redemption and only ever narrowed after it has been sent.
         */
        abort_if(
            $request->integer('version') !== 0
                && $request->integer('version') !== $file->versionNumber(),
            404,
        );

        /*
         * Re-resolved against the **file**, not carried over from the landing
         * page — and for a document's link that is the whole point of
         * `AccessResolver::linkCovers()`: the link names the document, and the
         * resolver is what decides that this file is that document's. A check
         * against the document alone would authorize the token for any file.
         */
        abort_unless($this->access->allows($request->user(), $file, $needed, $link), 404);
        // And the same question the landing page asked, because a page that
        // refuses while the bytes behind it still serve is not a refusal.
        abort_unless($this->opensForHolder($request, $item, $link), 404);
        abort_unless($this->storage->exists($file), 404, 'The stored file is missing.');

        return $inline ? $this->storage->inline($file) : $this->storage->download($file);
    }

    /**
     * The landing page before the visitor has said who they are.
     *
     * It carries the item's name and nothing else that costs anything: no
     * descriptor, no stream URL, no view spent, no audit row. Enough to show
     * that the link is real and what it is for, which is what somebody needs
     * before deciding whether to give their name.
     */
    private function prompt(ShareLink $link, File|Folder|Document $item, ?File $file): Response
    {
        return Inertia::render('share/show', [
            'token' => $link->token,
            'item' => [
                'name' => $item instanceof Document ? $item->title : ($file ? $file->downloadName() : $item->name),
                'kind' => match (true) {
                    $item instanceof Document => 'document',
                    $item instanceof File => 'file',
                    default => 'folder',
                },
                'size' => $file?->size_bytes,
                'mime_type' => $file?->mime_type,
                'code' => $item instanceof Document ? $item->code : null,
                'shared_by' => $link->created_by_name,
                'expires_at' => $link->expires_at,
                /*
                 * The classification, so the page says what the reader is
                 * holding.
                 *
                 * It discloses nothing they do not already have — they are
                 * looking at the document — and it is the difference between
                 * a link somebody may forward and one they should not. A
                 * document answers with its own level, which is what governs
                 * a tracked file; anything else answers with the effective
                 * visibility the resolver computed, inheritance included.
                 */
                ...$this->classification($item),
            ],
            'preview' => null,
            'can' => ['download' => false],
            'visitor' => null,
            'needsIdentity' => true,
        ]);
    }

    /**
     * Token to link and item, 404 on anything wrong.
     *
     * "Revoked", "expired", "spent" and "never existed" all answer identically.
     * Distinguishing them would confirm to anyone probing that a token was once
     * real, which is the one thing a bearer URL must not leak.
     *
     * @return array{0: ShareLink, 1: File|Folder|Document}
     */
    private function resolve(string $token): array
    {
        $link = $this->sharing->resolveLink($token);
        abort_if($link === null, 404);

        $item = $link->shareable;
        abort_unless(
            $item instanceof File || $item instanceof Folder || $item instanceof Document,
            404,
        );

        return [$link, $item];
    }

    /**
     * Does this link open its subject for whoever is holding it?
     *
     * **The link's own term answers this, not the document's level.** A
     * bearer term — view, or view and download — is redeemed by whoever has
     * the URL, which is what a link is for, and it is redeemed that way
     * whatever the document's classification is: an uploader who widens a
     * private document's link is deciding that this particular URL is the
     * door, and the restricted list goes on governing every other way in.
     * A `restricted` link is not a key at all: it opens only for the
     * document's **named audience** — the uploader and the people tagged on
     * it — whatever the document's level says. On a public document that
     * closes the link to the public, which is exactly what choosing the term
     * asks for; resolving the level instead made the term mean nothing
     * there.
     *
     * That choice is where the old rule went. It used to be read off the
     * document's level, so a restricted document's link was never a key and
     * the uploader had no say; the office needs the say, because a document
     * restricted to three named colleagues is the ordinary case in which
     * somebody outside the office has to be sent a copy. Making it a *term*
     * keeps both answers available and, crucially, keeps the safe one the
     * default: `SharingService::defaultLinkAbility()` mints `restricted` for
     * any document restricted to a named list, so the automatic link behind a
     * printed QR label is still an address rather than a credential.
     *
     * Confidential is untouched by all of it. There is no link to carry a
     * term, because `linkable()` refuses to mint one and `AccessResolver`
     * refuses to redeem one.
     *
     * Asked of the document, never of the file: a tracked file's access is
     * its document's question, and asking the file would be the looser
     * second door `AccessResolver::forFile()` exists to close.
     */
    private function opensForHolder(Request $request, Document|File|Folder $item, ShareLink $link): bool
    {
        if ($link->ability->isBearer()) {
            return true;
        }

        // Asked **through the link**, so this is the resolver's own answer
        // rather than a second statement of it. It used to ask what the
        // holder could do with no link at all, which on a public document is
        // "everything a reader may do" — so a link marked Restricted opened
        // for whoever held it. See AccessResolver::forNamedAudience().
        return $this->access->allows($request->user(), $item, FileAbility::View, $link);
    }

    /**
     * The classification badge for whatever a link points at.
     *
     * One place, because the landing page and the re-render after somebody
     * identifies themselves are the same page and must not disagree about
     * what the reader is holding.
     *
     * @return array<string, string>
     */
    private function classification(Document|File|Folder $item): array
    {
        $visibility = $item instanceof Document
            ? $item->visibility
            : $this->access->effectiveVisibility($item);

        // The value alone: `VisibilityBadge` owns the wording, the tone and
        // the icon, so sending them too would be a second statement of one
        // thing and the first to drift. The three levels share their values
        // across both enums, so a file and a document badge identically.
        return ['visibility' => $visibility->value];
    }
}
