<?php

namespace App\Enums;

/**
 * Every entry a document's trail can carry.
 *
 * These used to be human-readable strings written straight into the column
 * (`'Received and Downloaded'`, `'Acknowledged / Forwarded'`) and compared
 * against literals in three controllers and two Vue pages. The stored value
 * is a slug now and the sentence is `label()`, which is the PROCMIS
 * convention: the wording on screen can be corrected without rewriting
 * history, and a mistyped comparison is a type error rather than a check that
 * quietly never matches.
 *
 * `postable()` is the subset a user may submit through the Log action form.
 * The rest are written by the system when it happens — the endpoint used to
 * accept any string up to 255 characters, so a client could post whatever
 * sentence it liked into a document's history.
 */
enum DocumentAction: string
{
    case Uploaded = 'uploaded';
    case Viewed = 'viewed';
    case Downloaded = 'downloaded';
    case Received = 'received';
    case Acknowledged = 'acknowledged';
    case Reviewed = 'reviewed';
    case Returned = 'returned';

    /*
     * Sharing by link is part of a document's travel history, not only of the
     * file-manager trail: "who was this handed to" is the question the trail
     * answers, and a link handed to somebody without an account is a hand-off.
     * None of the three is postable — all are written by the system at the
     * moment the link is minted, re-addressed or revoked, so none can be
     * forged by a client. A regeneration is on this trail and not only on the
     * activity one because it *ends* a hand-off: every copy of the URL that
     * was sent out stops working, and a reader holding one is entitled to
     * find out why from the document's own history.
     */
    case LinkShared = 'link_shared';
    case LinkRegenerated = 'link_regenerated';
    case LinkRevoked = 'link_revoked';

    /*
     * Adding and taking away a named viewer on a restricted document. Written
     * by the system when the uploader does it, never postable: "who may read
     * this" is a decision, and a client that could post the decision's
     * *record* could claim one that was never made.
     *
     * `ViewerAdded` is here for the same reason `ViewerRemoved` is, read the
     * other way round. A trail that records every withdrawal and no later
     * grant *understates* who can read the document, which is the mirror of
     * the fault that put the removal on this trail — and a reader who finds
     * somebody in the recipient chips with nothing in the history saying when
     * they arrived has no way to tell a tagging from an upload. Tagging at
     * upload is not one of these rows: the `Uploaded` entry already says the
     * document was filed with the list it carries, and a row per name beside
     * it would be the same fact twice.
     */
    case ViewerAdded = 'viewer_added';
    case ViewerRemoved = 'viewer_removed';

    /*
     * The document's file was replaced — version 2, 3, 4.
     *
     * This is the one file-manager fact that belongs in a document's travel
     * history, and the reasoning is worth keeping because it reads like an
     * exception to the audience rule. `copied`, `extracted` and `link_created`
     * stay out of this enum because they disclose the file manager: where the
     * bytes live, who took a copy, what an archive held. None of that is part
     * of a document's travel. But "the file this tracking code names is now
     * version 2" is a fact about the **document**: every reader who downloaded
     * version 1 is holding a stale copy, and a trail that goes on saying
     * "Downloaded file" while the bytes change underneath is not protecting
     * them from a disclosure — it is withholding the one fact that makes their
     * copy misleading. It discloses nothing besides: a reader can already open
     * the file and see that it differs.
     *
     * A revert does not get its own case. To a reader it is a replacement —
     * the code now names different bytes — and "these particular bytes are a
     * copy of version 1" is storage mechanics, which lives in `activity_logs`
     * as ActivityAction::VersionReverted. An enum rendered to every reader
     * turns into an implementation log if it grows a case per mechanic.
     *
     * Never postable: a client claiming the file changed does not change it.
     */
    case FileReplaced = 'file_replaced';

    /**
     * Somebody wrote a comment on the document.
     *
     * **The fact, never the words.** A comment is content its author may
     * delete; the trail is a record of events that must not change, so the
     * body lives in `document_comments` and this row says only that one was
     * written, by whom, about which version. Copying the text in would put
     * a mutable paragraph inside an immutable record — and would disclose
     * it to anyone who may read the trail, which is the same audience but
     * for a different reason than the one the comment was written for.
     *
     * Never postable: it is written by the controller that stores the
     * comment, so a client cannot claim one that does not exist.
     */
    case Commented = 'commented';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Viewed => 'Viewed file',
            self::Downloaded => 'Downloaded file',
            self::Received => 'Received',
            self::Acknowledged => 'Acknowledged / Forwarded',
            self::Reviewed => 'Reviewed',
            self::Returned => 'Returned',
            self::LinkShared => 'Shared by link',
            self::LinkRegenerated => 'Share link address replaced',
            self::LinkRevoked => 'Share link revoked',
            self::ViewerAdded => 'Viewer added',
            self::ViewerRemoved => 'Viewer removed',
            self::FileReplaced => 'File replaced',
            self::Commented => 'Commented',
        };
    }

    /** Font Awesome icon for the trail entry. */
    public function icon(): string
    {
        return match ($this) {
            self::Uploaded => 'fa-solid fa-upload',
            self::Viewed => 'fa-solid fa-eye',
            self::Downloaded => 'fa-solid fa-download',
            self::Received => 'fa-solid fa-inbox',
            self::Acknowledged => 'fa-solid fa-share-from-square',
            self::Reviewed => 'fa-solid fa-circle-check',
            self::Returned => 'fa-solid fa-rotate-left',
            self::LinkShared => 'fa-solid fa-link',
            self::LinkRegenerated => 'fa-solid fa-rotate',
            self::LinkRevoked => 'fa-solid fa-link-slash',
            self::ViewerAdded => 'fa-solid fa-user-plus',
            self::ViewerRemoved => 'fa-solid fa-user-slash',
            self::FileReplaced => 'fa-solid fa-arrow-up-from-bracket',
            self::Commented => 'fa-solid fa-comment',
        };
    }

    /**
     * The colour the trail renders this action's badge in.
     *
     * Grouped by what the entry *means*, not one shade per case: arrival and
     * completion read green, an onward hand-off blue, a reversal amber, a
     * withdrawal of access rose, and passive reads stay neutral so they recede
     * behind the entries somebody actually did something in. Thirteen distinct
     * colours would be a legend nobody learns.
     *
     * Tailwind utility classes rather than a token, because the trail renders
     * them directly — and no `dark:` variants: the dark theme remaps these
     * same utilities under `html.dark`.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Uploaded => 'bg-sky-100 text-[#07559e]',
            self::Received, self::Reviewed => 'bg-emerald-100 text-emerald-600',
            self::Acknowledged => 'bg-sky-100 text-[#07559e]',
            self::Returned => 'bg-amber-100 text-amber-600',
            self::LinkShared, self::LinkRegenerated => 'bg-violet-100 text-violet-600',
            // Violet with the link share: giving somebody the document is one
            // act whether it is done by name or by URL.
            self::ViewerAdded => 'bg-violet-100 text-violet-600',
            // The same blue as an upload, because it is one: new bytes
            // arriving under an existing code.
            self::FileReplaced => 'bg-sky-100 text-[#07559e]',
            self::LinkRevoked, self::ViewerRemoved => 'bg-rose-100 text-rose-600',
            // Violet with the other act of communication: a comment is
            // somebody addressing the document's readers, which is what a
            // link share is too.
            self::Commented => 'bg-violet-100 text-violet-600',
            self::Viewed, self::Downloaded => 'bg-slate-100 text-slate-500',
        };
    }

    /**
     * The actions a user may record by hand. Everything else is recorded by
     * the system at the moment it happens and must not be submittable.
     *
     * `Received` joins this list, and it is the one case that moved. It used
     * to be written only by a "Receive & download" endpoint that acknowledged
     * receipt *and* handed back the file in one request — which fused two
     * different facts. Taking a copy is something the server observes;
     * confirming that a document reached you is something only you can assert,
     * and you may well have received the folder by hand without downloading
     * anything. They are recorded separately now: `Downloaded` by the system
     * when bytes leave, `Received` by the person, here.
     *
     * @return list<self>
     */
    public static function postable(): array
    {
        return [self::Received, self::Acknowledged, self::Reviewed, self::Returned];
    }

    /**
     * The actions that mean this document reached this person — what the
     * dashboard counts as a document received.
     *
     * Both still count, for the same reason they are now separate: a download
     * is the server observing that a copy left, and a logged `Received` is the
     * person stating the document reached them. Either is delivery; neither
     * implies the other.
     *
     * @return list<self>
     */
    public static function deliveries(): array
    {
        return [self::Downloaded, self::Received];
    }

    /** @return list<string> */
    public static function postableValues(): array
    {
        return array_map(fn (self $case): string => $case->value, self::postable());
    }

    /** @return list<string> */
    public static function deliveryValues(): array
    {
        return array_map(fn (self $case): string => $case->value, self::deliveries());
    }

    /**
     * The shape the Vue pages render: the stored value, the sentence and the
     * icon, so no page has to carry its own copy of this mapping.
     *
     * @return list<array{value: string, label: string, icon: string}>
     */
    public static function options(): array
    {
        return self::describe(self::postable());
    }

    /**
     * Every action, in the same shape — for the trail's *filter*.
     *
     * `options()` is what a person may submit; this is what they may have to
     * look for. A filter offering only the postable set could not find an
     * upload, a download or a revoked link, which is most of what a trail
     * actually contains.
     *
     * @return list<array{value: string, label: string, icon: string}>
     */
    public static function all(): array
    {
        return self::describe(self::cases());
    }

    /**
     * @param  list<self>  $cases
     * @return list<array{value: string, label: string, icon: string}>
     */
    private static function describe(array $cases): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'icon' => $case->icon(),
            'tone' => $case->tone(),
        ], $cases);
    }
}
