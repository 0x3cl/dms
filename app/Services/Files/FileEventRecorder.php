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
    ): ActivityLog {
        $entry = ActivityLog::record($actor, $action, $subject, $context, $request);

        if ($subject instanceof File) {
            $this->mirrorToDocumentTrail($action, $subject, $actor, $request);
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
     * @param  array<string, mixed>  $context
     */
    public function recordOnce(
        ActivityAction $action,
        File|Folder $subject,
        ?User $actor,
        ?Request $request = null,
        array $context = [],
        int $withinMinutes = 5,
    ): ?ActivityLog {
        $alreadyRecorded = ActivityLog::query()
            ->where('action', $action->value)
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->where('actor_id', $actor?->id)
            ->where('occurred_at', '>=', now()->subMinutes($withinMinutes))
            ->exists();

        if ($alreadyRecorded) {
            return null;
        }

        return $this->record($action, $subject, $actor, $request, $context);
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
    ): void {
        $equivalent = self::DOCUMENT_EQUIVALENTS[$action->value] ?? null;

        if ($equivalent === null) {
            return;
        }

        $document = $file->document;

        if (! $document instanceof Document) {
            return;
        }

        $log = DocumentLog::record($document, $equivalent, $actor, null, $request?->ip());

        if ($equivalent === DocumentAction::Downloaded) {
            DocumentDownload::register($document, $actor, $log, $request?->ip());
        }
    }
}
