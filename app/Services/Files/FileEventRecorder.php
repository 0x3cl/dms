<?php

namespace App\Services\Files;

use App\Enums\ActivityAction;
use App\Enums\DocumentAction;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\DocumentLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The bridge between the general activity log and a document's travel trail.
 *
 * Every file or folder event writes `activity_logs`. A **tracked** file's view
 * or download *also* writes the document's own `document_logs` entry, because
 * that trail is what the document page renders and it has to keep working.
 *
 * What it deliberately does not do is forward everything. `document_logs` is
 * shown to **everyone who can read the document**, so a `copied`, `extracted`
 * or `link_created` row appearing there would turn the trail into a disclosure
 * about the file manager. Only the two actions that already have a meaning in a
 * document's history cross over.
 *
 * The one place these two tables are written together, so the rule about which
 * events cross over exists once.
 *
 * **A replacement crosses over too, and deliberately not through this map.**
 * `DocumentAction::FileReplaced` is written directly by
 * App\Services\Files\FileVersionService's caller, `FileManager::replace()`,
 * because this map exists for actions fired from four different doors — the
 * stream controller, the preview descriptor, the document page, a share link —
 * which all need one mirroring point. A replacement has exactly one writer.
 * Routing it through here would mean every future action added beside these two
 * entries is one typo away from appearing in every document's public trail, and
 * it would need a second entry for a revert, which must *not* mirror
 * separately. So this map stays at two entries, forever if possible.
 */
class FileEventRecorder
{
    /**
     * The only actions that mean the same thing in a document's travel history
     * as they do in the file manager.
     *
     * @var array<string, DocumentAction>
     */
    private const DOCUMENT_EQUIVALENTS = [
        ActivityAction::Previewed->value => DocumentAction::Viewed,
        ActivityAction::Downloaded->value => DocumentAction::Downloaded,
    ];

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        ActivityAction $action,
        File|Folder $subject,
        ?User $actor,
        ?Request $request = null,
        array $context = [],
        ?int $version = null,
    ): ActivityLog {
        $entry = ActivityLog::record($actor, $action, $subject, $context, $request, $version);

        if ($subject instanceof File) {
            $this->mirrorToDocumentTrail($action, $subject, $actor, $request, $version);
        }

        return $entry;
    }

    /**
     * Preview and download, debounced.
     *
     * An embedded preview iframe can re-request the same file on every render —
     * a resize, a re-mount, a reload — and a trail entry per render is a forged
     * history: it says the person opened the document nine times when they
     * opened it once.
     *
     * So a repeat of the same action by the same person on the same subject
     * inside the window is treated as the same event. The window is short
     * enough that genuinely opening something again later is still recorded.
     *
     * **The version is part of the key**, and leaving it out would be a bug in
     * the opposite direction: previewing version 1 and then version 2 inside
     * five minutes are two different things being opened, and recording only
     * the first would lose exactly the version-scoped history this column was
     * added for.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordOnce(
        ActivityAction $action,
        File|Folder $subject,
        ?User $actor,
        ?Request $request = null,
        array $context = [],
        int $withinMinutes = 5,
        ?int $version = null,
    ): ?ActivityLog {
        $subjectVersion = $version ?? ($subject instanceof File ? $subject->versionNumber() : null);

        $alreadyRecorded = ActivityLog::query()
            ->where('action', $action->value)
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->where('actor_id', $actor?->id)
            ->where('subject_version', $subjectVersion)
            ->where('occurred_at', '>=', now()->subMinutes($withinMinutes))
            ->exists();

        if ($alreadyRecorded) {
            return null;
        }

        return $this->record($action, $subject, $actor, $request, $context, $subjectVersion);
    }

    /**
     * The document's own trail, for the two actions that belong in it.
     *
     * `document_downloads` is written too for a release, because that register
     * is what `/admin/download-logs` reads and it is document-scoped: its
     * `document_id` is not nullable, so an untracked file's download has no
     * row there and does not need one.
     */
    private function mirrorToDocumentTrail(
        ActivityAction $action,
        File $file,
        ?User $actor,
        ?Request $request,
        ?int $version = null,
    ): void {
        $equivalent = self::DOCUMENT_EQUIVALENTS[$action->value] ?? null;

        if ($equivalent === null) {
            return;
        }

        $document = $file->document;

        if (! $document instanceof Document) {
            return;
        }

        /*
         * The version is passed through rather than derived, because this is
         * the path that serves a *superseded* version: deriving it would
         * record the view of version 1 against whatever is current now.
         */
        $log = DocumentLog::record($document, $equivalent, $actor, null, $request?->ip(), null, $version);

        if ($equivalent === DocumentAction::Downloaded) {
            DocumentDownload::register($document, $actor, $log, $request?->ip());
        }
    }
}
