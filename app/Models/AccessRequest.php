<?php

namespace App\Models;

use App\Enums\AccessRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to be let into a document, and the answer.
 *
 * Built in one place, `open()`, for the same reason `DocumentLog::record()` is:
 * a second construction site is how a row ends up missing the snapshot of who
 * asked and which office they sat in.
 *
 * Granting does **not** happen here. It is a `document_recipients` row, written
 * through `DocumentRegistrar::tag()`, because that is the single statement of
 * who may read a document — this table records the asking, not the access.
 */
class AccessRequest extends Model
{
    protected $fillable = [
        'document_id', 'document_code', 'document_title',
        'requester_id', 'requester_name', 'requester_employee_id',
        'division_id', 'division_name', 'section_id', 'section_name',
        'owner_id', 'owner_name',
        'message', 'status',
        'decided_by', 'decided_by_name', 'decision_note', 'decided_at',
        'dismissed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AccessRequestStatus::class,
            'decided_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /**
     * Record a request, snapshotting who asked and where they sat.
     */
    public static function open(Document $document, User $requester, ?string $message): self
    {
        return self::query()->create([
            'document_id' => $document->id,
            'document_code' => $document->code,
            'document_title' => $document->title,
            'requester_id' => $requester->id,
            'requester_name' => $requester->name,
            'requester_employee_id' => $requester->employee_id,
            'division_id' => $requester->division_id,
            'division_name' => $requester->division?->name,
            'section_id' => $requester->section_id,
            'section_name' => $requester->section?->name,
            'owner_id' => $document->user_id,
            'owner_name' => $document->uploader_name,
            'message' => $message,
            'status' => AccessRequestStatus::Pending,
        ]);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * Whoever answered the request.
     *
     * `decided_by_name` is the snapshot and stays the thing that is *read* —
     * this relation exists so the notification can take their face, which has
     * nowhere else to come from. It is `nullOnDelete`, so a closed account
     * leaves the name standing and the face simply absent.
     *
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @param  Builder<$this>  $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AccessRequestStatus::Pending);
    }
}
