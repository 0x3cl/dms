<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per time a document's file actually left the server, behind
 * /admin/download-logs.
 *
 * Deliberately separate from `document_logs`: that table is the document's
 * readable history, shown to everyone who can read the document, while this
 * is a register of file releases and carries what a release needs — the IP the
 * copy went to — which has no business on a document page. `document_log_id`
 * ties a release back to the trail entry that produced it.
 *
 * Like the trail, the person and their office are snapshotted, so the
 * register still answers "who took a copy" after the account is gone, and the
 * report's search reads one table instead of two `whereHas` subqueries.
 */
class DocumentDownload extends Model
{
    protected $fillable = [
        'document_id',
        'document_log_id',
        'user_id',
        'user_name',
        'user_employee_id',
        'division_id',
        'division_name',
        'section_id',
        'section_name',
        'ip_address',
        'downloaded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'downloaded_at' => 'datetime',
        ];
    }

    /**
     * Register a release of a document's file, snapshotting the user as they
     * are now and pointing at the trail entry it accompanies.
     */
    public static function register(
        Document $document,
        ?User $user,
        ?DocumentLog $log = null,
        ?string $ipAddress = null,
    ): self {
        return self::query()->create([
            'document_id' => $document->id,
            'document_log_id' => $log?->id,
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'Unknown',
            'user_employee_id' => $user?->employee_id,
            'division_id' => $user?->division_id,
            'division_name' => $user?->division?->name,
            'section_id' => $user?->section_id,
            'section_name' => $user?->section?->name,
            'ip_address' => $ipAddress,
            'downloaded_at' => now(),
        ]);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<DocumentLog, $this>
     */
    public function documentLog(): BelongsTo
    {
        return $this->belongsTo(DocumentLog::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Division, $this>
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * The download report's search. It reads the snapshot columns on this row
     * plus the document it points at, rather than two correlated subqueries
     * against `users` and `documents`.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeMatching(Builder $query, string $term): Builder
    {
        return $query->when($term !== '', fn (Builder $query) => $query->where(
            fn (Builder $query) => $query
                ->where('user_name', 'like', "%{$term}%")
                ->orWhere('user_employee_id', 'like', "%{$term}%")
                ->orWhereHas('document', fn (Builder $documents) => $documents
                    ->where('title', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%"))
        ));
    }
}
