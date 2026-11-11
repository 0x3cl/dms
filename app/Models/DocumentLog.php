<?php

namespace App\Models;

use App\Enums\DocumentAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a document's travel history.
 *
 * Every row carries both a foreign key and a snapshot of who the actor was
 * and which office they sat in at the time. The snapshot is what the history
 * renders, so the trail says what was true when it happened rather than what
 * is true now — renaming a division no longer rewrites where a document has
 * been, and deleting one no longer blanks it. It also means the history page
 * needs no eager loading: `actor_name`, `division_name` and `section_name` are
 * already on the row.
 *
 * The foreign keys remain for filtering and for following an entry back to a
 * live account or office, and all three are `nullOnDelete` — an audit row
 * must outlive the records it points at.
 *
 * `action` is an App\Enums\DocumentAction; `action_label` is appended so a
 * page can render the sentence without carrying its own copy of the mapping.
 */
class DocumentLog extends Model
{
    protected $fillable = [
        'document_id',
        'actor_id',
        'actor_name',
        'actor_employee_id',
        'division_id',
        'division_name',
        'section_id',
        'section_name',
        'action',
        'remarks',
        'ip_address',
        'occurred_at',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['action_label', 'action_icon'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => DocumentAction::class,
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Record an action against a document, snapshotting the actor as they are
     * right now. This is the only place a trail row is built, so every writer
     * produces the same shape.
     */
    public static function record(
        Document $document,
        DocumentAction $action,
        ?User $actor,
        ?string $remarks = null,
        ?string $ipAddress = null,
    ): self {
        return self::query()->create([
            'document_id' => $document->id,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name ?? 'Unknown',
            'actor_employee_id' => $actor?->employee_id,
            'division_id' => $actor?->division_id,
            'division_name' => $actor?->division?->name,
            'section_id' => $actor?->section_id,
            'section_name' => $actor?->section?->name,
            'action' => $action,
            'remarks' => $remarks,
            'ip_address' => $ipAddress,
            'occurred_at' => now(),
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
     * The account that acted, when it still exists. The page renders
     * `actor_name` instead; this is for following the row back to the account.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
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

    public function getActionLabelAttribute(): string
    {
        return $this->action?->label() ?? 'Unknown';
    }

    public function getActionIconAttribute(): string
    {
        return $this->action?->icon() ?? 'fa-solid fa-circle';
    }

    /**
     * @param  Builder<$this>  $query
     * @param  list<DocumentAction>  $actions
     * @return Builder<$this>
     */
    public function scopeWhereAction(Builder $query, array $actions): Builder
    {
        return $query->whereIn('action', array_map(fn (DocumentAction $a): string => $a->value, $actions));
    }
}
