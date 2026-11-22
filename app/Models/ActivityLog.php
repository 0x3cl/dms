<?php

namespace App\Models;

use App\Enums\ActivityAction;
use App\Events\ActivityTrailUpdated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * One entry in the general activity log.
 *
 * Write rows **only** through `record()`. It is the one place the shape is
 * built, which is what makes every writer produce the same row — the same
 * invariant `DocumentLog::record()` and `DocumentDownload::register()` hold.
 * A second construction site is how an audit table ends up with rows that are
 * missing the actor's office, and you only find out when you need them.
 */
class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'actor_id', 'actor_name', 'actor_employee_id',
        'division_id', 'division_name', 'section_id', 'section_name',
        'subject_type', 'subject_id', 'subject_label', 'subject_public_id', 'subject_version',
        'action', 'context', 'ip_address', 'user_agent', 'occurred_at',
    ];

    protected $casts = [
        'action' => ActivityAction::class,
        'subject_version' => 'integer',
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected $appends = ['action_label', 'action_icon'];

    /**
     * The one writer.
     *
     * The actor is snapshotted — name, employee ID, division and section as
     * they were at the time — beside the foreign keys, which are all
     * `nullOnDelete`. The page renders the snapshot. Renaming a division must
     * not rewrite where something happened two years ago, and deleting one must
     * not blank it.
     *
     * The subject is snapshotted the same way, because a row has to stay
     * readable after the file it refers to has been purged.
     *
     * @param  array<string, mixed>  $context
     */
    public static function record(
        ?User $actor,
        ActivityAction $action,
        ?Model $subject = null,
        array $context = [],
        ?Request $request = null,
        ?int $version = null,
    ): self {
        $row = self::query()->create([
            'actor_id' => $actor?->id,
            // 'System' rather than null: a row with no actor is still a row
            // about something that happened, and a blank name reads as a bug.
            'actor_name' => $actor?->name ?? 'System',
            'actor_employee_id' => $actor?->employee_id,
            'division_id' => $actor?->division_id,
            'division_name' => $actor?->division?->name,
            'section_id' => $actor?->section_id,
            'section_name' => $actor?->section?->name,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subject ? self::labelFor($subject) : null,
            'subject_public_id' => $subject->public_id ?? null,
            /*
             * Which version of the subject this was about, derived from the
             * subject when it is not passed — so every existing caller stays
             * correct untouched, since each concerns whatever version was
             * current at the time. A folder has no version and keeps null.
             */
            'subject_version' => $version ?? ($subject instanceof File ? $subject->versionNumber() : null),
            'action' => $action,
            'context' => $context === [] ? null : $context,
            'ip_address' => $request?->ip(),
            // Truncated to the column: a crafted header must not be able to
            // fail the insert and so prevent the row being written at all.
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'occurred_at' => now(),
        ]);

        /*
         * Announced from the one place an activity row is built, so no writer
         * can add one without /activity-trails hearing about it. A signal
         * only — what a given viewer may see depends on their filters as well
         * as their permission, and only the controller knows both.
         */
        event(ActivityTrailUpdated::from($row));

        return $row;
    }

    /**
     * How a subject is named in the trail.
     *
     * A file shows the name the person would recognise; a document shows its
     * tracking code, because that is what is printed on the paper.
     */
    private static function labelFor(Model $subject): ?string
    {
        return match (true) {
            $subject instanceof File => $subject->downloadName(),
            $subject instanceof Folder => $subject->name,
            $subject instanceof Document => $subject->code,
            default => null,
        };
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function getActionLabelAttribute(): string
    {
        return $this->action->label();
    }

    public function getActionIconAttribute(): string
    {
        return $this->action->icon();
    }

    /**
     * Search the snapshots, not the live records.
     *
     * The whole point of carrying them is that this is one table scan rather
     * than a `whereHas` against `users` and another against `files` on every
     * keystroke — and it keeps answering after either is deleted.
     */
    public function scopeMatching(Builder $query, string $term): Builder
    {
        return $query->when($term !== '', fn (Builder $inner) => $inner
            ->where(fn (Builder $group) => $group
                ->where('actor_name', 'like', "%{$term}%")
                ->orWhere('actor_employee_id', 'like', "%{$term}%")
                ->orWhere('subject_label', 'like', "%{$term}%")));
    }

    public function scopeOfAction(Builder $query, ?string $action): Builder
    {
        return $query->when(
            $action !== null && in_array($action, ActivityAction::values(), true),
            fn (Builder $inner) => $inner->where('action', $action),
        );
    }
}
