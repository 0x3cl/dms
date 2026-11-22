<?php

namespace App\Http\Controllers;

use App\Enums\DocumentAction;
use App\Enums\FileAbility;
use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\DocumentLog;
use App\Services\Access\AccessResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Comments on a document, written about one version of its file.
 *
 * **Who may write one is the document's own read rule and nothing new.**
 * `AccessResolver` already decides who may open a document; a comment is
 * addressed to that same audience, so a second gate here would be a second
 * statement of one rule — the thing the resolver exists to end. Anyone the
 * resolver grants `view` may comment, which is the uploader, the tagged
 * recipients, and any member of staff for a public document.
 *
 * A **share-link visitor may not**, and that is deliberate rather than an
 * omission. A link carries no account and this application verifies nothing
 * about who holds it, so a comment from one would be an unverified name
 * sitting in a thread of real ones. `GuestIdentity` solves that for an
 * audit row, where the suffix "(link visitor)" qualifies the claim; it does
 * not solve it for content somebody is expected to act on.
 *
 * **The toggle is the uploader's**, because deciding whether a document
 * collects discussion is part of filing it. It gates writing only: turning
 * comments off closes the thread, it does not erase what was said, because
 * deleting other people's words as a side effect of a setting is not
 * something a toggle should do.
 */
class DocumentCommentController extends Controller
{
    public function __construct(private readonly AccessResolver $access) {}

    public function store(Request $request, Document $document): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            /*
             * Which version the comment is about. Sent by the page rather
             * than inferred, because the page knows which bytes the person
             * was looking at — somebody reading version 1 and commenting is
             * commenting on version 1, not on whatever is current by the
             * time the request lands.
             */
            'version' => ['nullable', 'integer', 'min:1'],
        ]);

        if (! $this->mayComment($request, $document)) {
            return back()->with('error', 'You cannot comment on this document.');
        }

        if (! $document->comments_enabled) {
            return back()->with('error', 'Comments are turned off for this document.');
        }

        $version = $validated['version'] ?? $document->file?->versionNumber();

        $comment = DocumentComment::write($document, $request->user(), $validated['body'], $version);

        /*
         * The trail records that a comment was written, never what it said.
         * A comment is content its author may delete; the trail must not
         * change, so copying the body in would put a mutable paragraph
         * inside an immutable record.
         */
        DocumentLog::record(
            $document,
            DocumentAction::Commented,
            $request->user(),
            null,
            $request->ip(),
            null,
            $comment->version,
        );

        return back()->with('success', 'Comment posted.');
    }

    /**
     * Remove one's own comment.
     *
     * The author, or the document's uploader — who is responsible for what
     * accumulates on a document they filed. A superadmin too, so nothing
     * stalls while somebody is on leave, which is the same reasoning as
     * deciding an access request.
     *
     * No trail row for a deletion, deliberately: `Commented` already says a
     * comment was written, and a reader finding no comment where the trail
     * says there was one learns exactly as much as a second row would tell
     * them, without this enum growing a case per mechanic.
     */
    public function destroy(Request $request, Document $document, DocumentComment $comment): RedirectResponse
    {
        abort_unless((int) $comment->document_id === (int) $document->id, 404);

        $user = $request->user();
        $mine = $comment->user_id !== null && (int) $comment->user_id === (int) $user->id;
        $theirs = $document->user_id !== null && (int) $document->user_id === (int) $user->id;

        if (! $mine && ! $theirs && ! $user->hasRole(\App\Support\Roles::SUPERADMIN)) {
            return back()->with('error', 'You cannot delete that comment.');
        }

        $comment->delete();

        return back()->with('success', 'Comment deleted.');
    }

    /** The document's own read rule, asked of the resolver. */
    private function mayComment(Request $request, Document $document): bool
    {
        return $request->user() !== null
            && $this->access->allows($request->user(), $document, FileAbility::View);
    }
}
