<?php

namespace App\Services\Documents;

use App\Enums\DocumentAction;
use App\Enums\DocumentVisibility;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\User;
use App\Notifications\PrivateDocumentShared;
use App\Services\Files\FileStorage;
use App\Services\Files\SharingService;
use App\Services\TrackingCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Give a stored file a tracking jacket.
 *
 * A tracked document is a `files` row with a `documents` row pointing at it —
 * that is the whole difference. Everything after the bytes are stored is the
 * same work whichever screen the upload came from: issue a code, write the QR
 * Tracker, create the document, open its trail, tag the recipients and notify
 * them.
 *
 * It lived inline in `DocumentController::store()`, which was fine while there
 * was one way to upload. The office asked for a second — track a file as you
 * put it in your own storage — and copying forty lines into
 * `FileActionController` would have been two statements of one rule, in the
 * same shape that produced `AccessResolver` and `DocumentLog::record()`. One
 * registrar, two callers.
 *
 * It takes an **already stored** `File` rather than an upload, because the two
 * callers store differently and should keep doing so: the document register
 * files into "Tracked Documents {year}" because its form has no folder field,
 * while My Files puts it in the folder the person is looking at — which they
 * chose by navigating there, and overriding that would read as a failed
 * upload. Nothing downstream depends on the path: `AccessResolver::forFile()`
 * finds the document through `file_id`.
 */
class DocumentRegistrar
{
    public function __construct(
        private readonly TrackingCodeService $trackingCodes,
        private readonly QrLabelService $qrLabels,
        private readonly SharingService $sharing,
    ) {}

    /**
     * @param  list<int>  $recipientIds
     */
    public function register(
        File $file,
        DocumentType $type,
        string $title,
        DocumentVisibility $visibility,
        array $recipientIds,
        User $uploader,
        ?Request $request = null,
    ): Document {
        /*
         * Issued before anything else and outside any long transaction: the
         * series is read under a row lock, and holding that across byte writes
         * would serialise every concurrent upload of the same type.
         */
        $code = $this->trackingCodes->issue($type);

        $qrFileName = $this->qrLabels->fileName($code);

        $document = Document::create([
            'code' => $code,
            'title' => $title,
            'document_type_id' => $type->id,
            // The type's name as it stands today. A tracking code is
            // permanent, so renaming a type must not rewrite the history of
            // the documents issued under its old name.
            'type' => $type->name,
            'file_id' => $file->id,
            'file_name' => $file->original_name,
            'qr_code_path' => $qrFileName,
            'user_id' => $uploader->id,
            'uploader_name' => $uploader->name,
            'visibility' => $visibility,
        ]);

        /*
         * The share link, then the label that points at it.
         *
         * In that order, and after the document row exists, because a link is
         * minted *against the document* — there is nothing to point a label
         * at until both exist. A confidential document gets no link and its
         * label falls back to the tracking URL; see QrLabelService.
         *
         * The label is named after the code, never the document title — a
         * title is arbitrary user input that collided between documents and,
         * on a download route taking the filename, was a traversal vector.
         *
         * Written outside the database transaction, which is a real if small
         * cost: a later rollback leaves an orphaned PNG that nothing points
         * at. Widening the transaction over a disk write would be worse, since
         * disk writes are not transactional either way.
         */
        $this->sharing->ensureLinkFor($document, $uploader);

        Storage::disk(FileStorage::DISK)->put(
            "qrcodes/{$qrFileName}",
            $this->qrLabels->png($this->qrLabels->targetFor($document->refresh())),
        );

        // The trail opens with the upload itself, so a document's history
        // starts where the document does rather than at whoever first viewed
        // it.
        DocumentLog::record($document, DocumentAction::Uploaded, $uploader, null, $request?->ip());

        if ($visibility->taggable()) {
            $this->tag($document, User::findMany($recipientIds));
        }

        return $document;
    }

    /**
     * Name the people a restricted document is for.
     *
     * `syncWithoutDetaching`, so re-tagging somebody already on the list is a
     * no-op rather than a duplicate row or a second notification.
     *
     * @param  Collection<int, User>  $recipients
     */
    public function tag(Document $document, Collection $recipients, bool $notify = true): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        /*
         * Through `allRecipients()`, which is the whole table.
         *
         * A viewer the uploader removed still has a row, and the filtered
         * `recipients()` relation cannot see it — so a sync there would try to
         * insert a second row for that person and hit the unique pair. Here the
         * same call updates the row it finds, and clearing `revoked_at` is the
         * honest reading of tagging somebody again: they are a viewer once more,
         * and the trail still carries the removal that happened in between.
         */
        $document->allRecipients()->syncWithoutDetaching(
            $recipients->mapWithKeys(fn (User $recipient) => [
                $recipient->id => [
                    'notified_at' => now(),
                    'revoked_at' => null,
                    'revoked_by' => null,
                ],
            ])->all()
        );

        if ($notify) {
            Notification::send($recipients, new PrivateDocumentShared($document, $document->user ?? $recipients->first()));
        }
    }
}
