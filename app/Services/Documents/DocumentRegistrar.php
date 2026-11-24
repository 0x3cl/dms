<?php

namespace App\Services\Documents;

use App\Enums\DocumentAction;
use App\Enums\DocumentFiling;
use App\Enums\DocumentStamp;
use App\Enums\DocumentVisibility;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\DocumentType;
use App\Models\File;
use App\Models\User;
use App\Notifications\PrivateDocumentShared;
use App\Services\Files\FileStorage;
use App\Services\Files\SharingService;
use App\Services\Files\ThumbnailGenerator;
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
 * callers store differently and should keep doing so: My Files puts it in the
 * folder the person is looking at — which they chose by navigating there, and
 * overriding that would read as a failed upload — while the register's form
 * asks, with a destination picker defaulting to the top of the uploader's own
 * storage. Neither invents a folder; the register used to create a
 * `Tracked Documents {year}` one, which is the system arranging somebody
 * else's filing for them. Nothing downstream depends on the path:
 * `AccessResolver::forFile()` finds the document through `file_id`.
 */
class DocumentRegistrar
{
    public function __construct(
        private readonly TrackingCodeService $trackingCodes,
        private readonly QrLabelService $qrLabels,
        private readonly SharingService $sharing,
        private readonly DocumentTypeInference $types,
        private readonly DocumentStampService $stamps,
        private readonly ThumbnailGenerator $thumbnails,
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
        DocumentFiling $filing = DocumentFiling::Deliberate,
        DocumentStamp $stamp = DocumentStamp::None,
        ?IssuedCode $issued = null,
    ): Document {
        /*
         * Issued before anything else and outside any long transaction: the
         * series is read under a row lock, and holding that across byte writes
         * would serialise every concurrent upload of the same type.
         *
         * `$issued` is the one case where it has already happened, and it is
         * an `IssuedCode` rather than a string on purpose: a file stamped in
         * the browser had to know its code before the bytes were uploaded, so
         * the client necessarily holds one and posts it back. Only
         * `TrackingCodeService` and `CodeReservationService::claim()`
         * construct the object, so there is no path by which a posted string
         * becomes a code.
         */
        $code = $issued?->code ?? $this->trackingCodes->issue($type);

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
            'filed' => $filing,
        ]);

        // The stamp is a fact about the bytes, so it is written on the file
        // rather than here — see `Document::stamp()`.
        $file->update(['stamp' => $stamp]);

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
        /*
         * **Only for a deliberate filing**, and both halves for the same
         * reason: an automatic filing happens on every upload, and this is
         * the expensive part.
         *
         * The label is a 512px symbol drawn on a 1536×1536 GD canvas and
         * resampled down — fine once per filed document, ruinous once per
         * entry of a two-hundred-file archive. `QrLabelService::materialise()`
         * renders it on first demand instead, and `qr_code_path` is set
         * above either way, so `downloadQr()`'s stored-name match and its
         * traversal guard are unchanged.
         *
         * The link is skipped because nothing needs one: an automatic
         * filing's stamp encodes the *tracking URL*, so there is no link for
         * a label to point at. One is minted the first time somebody opens
         * the share drawer or asks for the label, through the same
         * `ensureLinkFor()`.
         */
        if ($filing->governsItsFile()) {
            $this->sharing->ensureLinkFor($document, $uploader);

            Storage::disk(FileStorage::DISK)->put(
                "qrcodes/{$qrFileName}",
                $this->qrLabels->png($this->qrLabels->targetFor($document->refresh())),
            );
        }

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
     * File a document for a file nobody was asked about.
     *
     * The office's rule is that every uploaded file carries a tracking code
     * and a QR, so a file dropped into My Files is filed too — with the type
     * inferred from its format and the title taken from its name, because
     * neither was asked for and inventing an answer to either would put a
     * wrong word into a permanent record.
     *
     * **It is the same `register()` underneath**, which is the point: a
     * second registration path would be a second place where codes are
     * issued, labels are named and trails are opened, and the first drift
     * would be a document filed without a trail. What this adds is only the
     * three things an automatic filing decides differently.
     *
     * - The **visibility is the file's own**, mapped across, which in
     *   personal storage is private. Public would publish the institute's
     *   personal storage through `/track` to anybody walking a short,
     *   sequential code.
     * - The filing is **automatic**, so it does not govern who may read the
     *   file — see `File::governingDocument()`.
     * - **Nobody is tagged.** A recipient list is something a person builds;
     *   an empty one is a document waiting to be addressed, which is exactly
     *   what an automatically filed file is.
     *
     * Returns null when the office has no selectable type, because a type's
     * `code` is the tracking code's prefix and registration cannot invent
     * one. The upload still succeeded; it simply is not filed.
     *
     * A file that already carries a document — including a **withdrawn** one,
     * since `documents.file_id` is unique over soft-deleted rows — is
     * returned as it is rather than filed twice, which is what stops a
     * withdraw-then-backfill sequence being a duplicate-key 500.
     */
    public function registerAutomatically(
        File $file,
        User $uploader,
        ?Request $request = null,
        ?DocumentStamp $stamp = null,
        ?IssuedCode $issued = null,
        bool $wantsStamp = true,
    ): ?Document {
        $existing = $file->document()->withTrashed()->first();

        if ($existing) {
            return $existing;
        }

        $type = $issued?->type ?? $this->types->forFile($file);

        if ($type === null) {
            return null;
        }

        /*
         * Planned here, applied below, recorded on the row.
         *
         * The plan is what the *format* can carry; the record is what
         * actually happened to these bytes. They differ in the ordinary case
         * — a corrupt image, a picture too small to stamp without covering
         * it — and a page that reported the plan would tell a reader their
         * file carries a code it does not.
         *
         * Null means the caller has already stamped and is telling us what
         * it did: that is the browser's PDF, which is drawn before the bytes
         * are ever uploaded.
         */
        $plan = $this->stamps->plan((string) $file->extension, (string) $file->mime_type);

        /*
         * **The row records what happened, never what was planned.**
         *
         * `Unsupported` is already true at registration — that format can
         * never carry a code — but `Pdf` and `Image` are only *intentions*
         * here: the image has not been drawn yet, and a PDF is drawn in the
         * browser and may never come back at all. Writing the plan in would
         * tell every later reader their file carries a code that was never
         * drawn into it, which is the one thing this column exists to answer
         * honestly. `stampFile()` and `acceptStamp()` set it when the bytes
         * actually change.
         */
        $stamp ??= $plan === DocumentStamp::Unsupported || ! $wantsStamp
            ? DocumentStamp::Unsupported
            : DocumentStamp::None;

        /*
         * A declined mark records `Unsupported` only where the format truly
         * cannot carry one; a format that could and was declined records
         * `None`, which is the honest "these bytes carry no code".
         */
        if (! $wantsStamp && $plan !== DocumentStamp::Unsupported) {
            $stamp = DocumentStamp::None;
        }

        return $this->register(
            $file,
            $type,
            $file->original_name ?: $file->name,
            DocumentVisibility::forFile($file->visibility),
            [],
            $uploader,
            $request,
            DocumentFiling::Automatic,
            $stamp,
            $issued,
        );
    }

    /**
     * Draw the code into the bytes, and record what actually happened.
     *
     * Called after the document exists, because the stamp contains its code,
     * and it rewrites the stored file in place: `files.size_bytes` and
     * `files.checksum` are corrected to the bytes that are now on disk, or
     * the row would describe a file nobody has.
     *
     * **It is not a version.** `FileVersionService` is the history of
     * replacements somebody made; our own edit belongs in neither that table
     * nor its numbering, or "version 1" stops meaning what the uploader sent.
     *
     * A refusal — the format, a picture too small, a corrupt image, GD
     * missing — leaves the bytes alone and records the honest answer rather
     * than the hopeful one.
     */
    public function stampFile(Document $document, File $file, bool $wanted = true): void
    {
        /*
         * The uploader can decline the mark, and declining is recorded as
         * `none` — these bytes carry no code, which is the truth whether it
         * was refused or merely not drawn yet.
         *
         * A separate "declined" case was the alternative and it would buy
         * nothing a reader could act on: the question any screen asks of
         * this column is "does printing this carry the code", and there is
         * one answer to that for both.
         */
        if (! $wanted) {
            return;
        }

        $plan = $this->stamps->plan((string) $file->extension, (string) $file->mime_type);

        /*
         * A PDF is the browser's to draw, so the server's part is to open
         * the window for it.
         *
         * `files.stamp` only accepts bytes for a file whose own timestamp
         * moved in the last few minutes — which is what makes it the second
         * half of an upload rather than a way to rewrite any file. Stamping
         * a document that has sat in storage for a year is a deliberate act
         * happening *now*, so touching the file is the honest way to say so,
         * and it is the same window every other path gets.
         */
        if ($plan === DocumentStamp::Pdf) {
            $file->touch();

            return;
        }

        // Everything else either cannot carry one or is drawn here.
        if (! in_array($plan, [
            DocumentStamp::Image,
            DocumentStamp::Docx,
            DocumentStamp::Xlsx,
            DocumentStamp::Pptx,
        ], true)) {
            return;
        }

        $disk = Storage::disk($file->disk);
        $bytes = $disk->get($file->storage_path);

        $stamped = match (true) {
            $bytes === null => null,
            $plan === DocumentStamp::Docx => $this->stamps->stampWord(
                $bytes,
                $document->code,
                $this->coverFor($document),
            ),
            $plan === DocumentStamp::Xlsx => $this->stamps->stampExcel(
                $bytes,
                $document->code,
                $this->coverFor($document),
            ),
            $plan === DocumentStamp::Pptx => $this->stamps->stampPowerPoint(
                $bytes,
                $document->code,
                $this->coverFor($document),
            ),
            default => $this->stamps->stampImage($bytes, $document->code, (string) $file->mime_type),
        };

        // A refusal leaves the bytes and the row alone: the row already says
        // `none`, which is the truth.
        if ($stamped === null) {
            return;
        }

        $disk->put($file->storage_path, $stamped);

        $file->update([
            'size_bytes' => strlen($stamped),
            'checksum' => hash('sha256', $stamped),
        ]);

        $file->update(['stamp' => $plan]);

        $this->redrawThumbnail($file);
    }

    /**
     * The picture on the card is of the bytes, so it is redrawn with them.
     *
     * A stamp rewrites a file in place and keeps its version, which is right
     * — it is a mark on the bytes, not a new version of them — but the
     * thumbnail is drawn per version and nothing regenerated it. So a
     * stamped photograph showed its *unstamped* self everywhere a card, a
     * row or a preview pane drew it, and the only way to see the mark was to
     * download the file. That reads exactly like a stamp that never
     * happened.
     */
    private function redrawThumbnail(File $file): void
    {
        if ($this->thumbnails->supports($file)) {
            $this->thumbnails->generate($file->refresh());
        }
    }

    /**
     * Re-stamp a document whose file has been replaced.
     *
     * A replacement is a new version of the same file: `documents.file_id`
     * never changes and the tracking code is the one already printed on
     * paper, so the new bytes carry the **same** code. What they do not
     * carry is the old version's mark — the stamp was drawn into bytes that
     * have just been superseded — so version 2 of a scan would arrive
     * unmarked while its row still said `image`, which is the row claiming a
     * code the file does not have.
     *
     * The plan is recomputed rather than carried over, because a replacement
     * may change the format entirely: a `.docx` replaced by a photograph can
     * now carry a mark, and a photograph replaced by a `.docx` can no longer
     * carry one. `stamp` is reset to what the *new* bytes can hold before
     * anything is drawn, so an interrupted browser leaves an honest record
     * exactly as it does on a first upload.
     */
    public function restampAfterReplacement(Document $document, File $file, bool $wanted = true): void
    {
        $plan = $this->stamps->plan((string) $file->extension, (string) $file->mime_type);

        $file->update([
            'stamp' => $plan === DocumentStamp::Unsupported
                ? DocumentStamp::Unsupported
                : DocumentStamp::None,
        ]);

        // Images are drawn here; a PDF is the browser's, and it re-stamps
        // through `files.stamp` exactly as it does after an upload.
        $this->stampFile($document, $file->refresh(), $wanted);
    }

    /**
     * What a cover page says about a document, beside the symbol.
     *
     * The same facts the PDF cover carries, from the same places and for the
     * same reasons: the **type** is `documents.type`, the name as it stood
     * when the document was filed, so renaming a type later cannot rewrite
     * what a printed cover said; the classification's wording belongs to
     * `DocumentVisibility`. The PDF's copy is assembled in the controller
     * because the browser draws that one — this is the server's own, and the
     * two agree because both read these fields rather than composing their
     * own.
     *
     * @return array<string, string|null>
     */
    private function coverFor(Document $document): array
    {
        return [
            'title' => $document->title,
            'type' => $document->type ?? $document->documentType?->name,
            'visibility' => $document->visibility->label(),
            'visibility_note' => $document->visibility->description(),
            'uploader' => $document->uploaderName(),
            'filed_on' => $document->created_at?->format('j F Y'),
        ];
    }

    /**
     * Accept a stamp the browser drew, and record that it landed.
     *
     * The other half of `stampFile()`, for the one format this server cannot
     * draw into. A PDF is stamped with `pdf-lib` in the browser — there is no
     * PDF library here and the documented constraint is that there will not
     * be one — so the bytes come back in a second request and are written
     * over the stored file exactly as the image path writes over its own.
     *
     * **`stamp` is set here and not at registration**, so an interrupted
     * browser leaves an honest record. A row that claimed `Pdf` the moment a
     * PDF was uploaded would be telling every later reader their file carries
     * a code that was never drawn into it.
     *
     * The caller is responsible for deciding that this actor may do this at
     * all; see `FileActionController::stamp()`, which is deliberately a
     * one-shot completion of an upload rather than a way to rewrite any
     * file's bytes.
     */
    public function acceptStamp(Document $document, File $file, string $bytes): void
    {
        Storage::disk($file->disk)->put($file->storage_path, $bytes);

        $file->update([
            'size_bytes' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'stamp' => DocumentStamp::Pdf,
        ]);

        // The cover page is page one, so a thumbnail of page one is now of
        // something else.
        $this->redrawThumbnail($file);
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
