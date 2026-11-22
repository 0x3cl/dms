<?php

namespace App\Models;

use App\Enums\DocumentAction;
use App\Events\DocumentTrailUpdated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
        'actor_avatar_seed',
        'actor_profile_image',
        'division_id',
        'division_name',
        'section_id',
        'section_name',
        'action',
        'version',
        'remarks',
        'ip_address',
        'occurred_at',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['action_label', 'action_icon', 'action_tone'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => DocumentAction::class,
            'version' => 'integer',
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
        ?string $actorLabel = null,
        ?int $version = null,
    ): self {
        $row = self::query()->create([
            'document_id' => $document->id,
            'actor_id' => $actor?->id,
            /*
             * `$actorLabel` is for the one actor that is not an account: a
             * visitor who opened a share link and said who they were. It never
             * overrides a real account — a signed-in actor's own name wins —
             * and it arrives with `actor_id` null, so nothing can mistake a
             * self-asserted name for a verified one. See GuestIdentity.
             */
            'actor_name' => $actor?->name ?? $actorLabel ?? 'Unknown',
            'actor_employee_id' => $actor?->employee_id,
            // The two inputs resources/js/avatar.js needs, snapshotted for
            // the same reason the name is: a trail must not change when
            // somebody updates their profile, nor blank when they leave.
            'actor_avatar_seed' => $actor?->avatar_seed,
            'actor_profile_image' => $actor?->profile_image,
            'division_id' => $actor?->division_id,
            'division_name' => $actor?->division?->name,
            'section_id' => $actor?->section_id,
            'section_name' => $actor?->section?->name,
            'action' => $action,
            /*
             * Which version of the file this row was about.
             *
             * **Derived when it is not passed**, which is what keeps every
             * existing caller correct without touching one: a view, a
             * download, a receipt, a link being shared — each concerns
             * whatever version was current at that moment, and that is exactly
             * `$document->file->version`. The one caller that passes it
             * explicitly is the one serving a *superseded* version, where the
             * current number would be a lie.
             *
             * Null stays meaningful: a document with no stored file has no
             * version, and neither does a row written before this column
             * existed.
             */
            'version' => $version ?? $document->file?->version,
            'remarks' => $remarks,
            'ip_address' => $ipAddress,
            'occurred_at' => now(),
        ]);

        /*
         * Announced from the one place a trail row is built, so no writer can
         * add a row without the page hearing about it. A signal only — see
         * App\Events\DocumentTrailUpdated for why the row itself is not sent.
         */
        event(DocumentTrailUpdated::from($row));

        return $row;
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
     * The badge colour the trail renders this entry in.
     *
     * Appended beside the icon for the same reason: the mapping lives in
     * `DocumentAction` and no page carries its own copy, so correcting how an
     * action reads is one edit rather than a search across templates.
     */
    public function getActionToneAttribute(): string
    {
        return $this->action?->tone() ?? 'bg-slate-100 text-slate-500';
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

    /**
     * The trail, narrowed and newest first.
     *
     * One builder for both the panel on the document page and the full-history
     * page, because they are the same list at two lengths — and two hand-built
     * queries over one table is how a search that works on one screen quietly
     * does not on the other.
     *
     * **Every filter reads a column on this row.** Searching covers the
     * actor's name, their employee ID and the remarks; the office filter
     * reads the division *snapshot*, never a join to `divisions`. That is
     * precisely what the snapshots are for — the alternative is a `whereHas`
     * against `users` on every keystroke, and it would also answer the wrong
     * question: filtering by where somebody sits *now* would hide the rows
     * from when they sat somewhere else, which is the one thing an audit
     * trail must not do.
     *
     * @param  array{search?: ?string, action?: ?string, from?: ?string, to?: ?string, office?: ?string, version?: int|'none'|null}  $filters
     */
    public function scopeTrail(Builder $query, array $filters = []): Builder
    {
        $search = $filters['search'] ?? null;
        $action = $filters['action'] ?? null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $office = $filters['office'] ?? null;
        $version = $filters['version'] ?? null;

        return $query
            ->when($action, fn (Builder $inner) => $inner->where('action', $action))
            /*
             * Which version of the file the row was about — the trail read
             * version by version, which is the whole point of the column.
             *
             * `'none'` is the rows that name no version: a document with no
             * stored file, and every row written before the column existed.
             * It is a value rather than an absence because "no version" is a
             * tab somebody can choose, and `null` here already means "every
             * version" — the whole trail.
             */
            ->when($version === 'none', fn (Builder $inner) => $inner->whereNull('version'))
            ->when(is_int($version), fn (Builder $inner) => $inner->where('version', $version))
            ->when($search, function (Builder $inner) use ($search): void {
                $term = '%'.$search.'%';

                $inner->where(function (Builder $group) use ($term): void {
                    $group->where('actor_name', 'like', $term)
                        ->orWhere('actor_employee_id', 'like', $term)
                        ->orWhere('remarks', 'like', $term);
                });
            })
            // Whole days, in the application's timezone: somebody filtering
            // "to 12 November" means the end of it, not midnight at its start.
            ->when($from, fn (Builder $inner) => $inner->where('occurred_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($to, fn (Builder $inner) => $inner->where('occurred_at', '<=', Carbon::parse($to)->endOfDay()))
            ->when($office, fn (Builder $inner) => $inner->where('division_name', $office))
            ->latest('occurred_at')
            ->latest('id');
    }

    /**
     * The offices that actually appear in this trail.
     *
     * Derived from the rows rather than from the `divisions` table, so the
     * filter can never offer an option that matches nothing — and so a
     * division renamed since cannot silently drop every row recorded under
     * its old name. The set is small by construction: it is one document's
     * history.
     *
     * @return list<string>
     */
    public static function officesFor(int $documentId): array
    {
        return self::query()
            ->where('document_id', $documentId)
            ->whereNotNull('division_name')
            ->distinct()
            ->orderBy('division_name')
            ->pluck('division_name')
            ->all();
    }

    /**
     * The versions that actually appear in this trail, newest first.
     *
     * Derived from the rows, exactly as `officesFor()` is, and for the same
     * reason: a filter must not offer a choice that matches nothing. It is
     * deliberately **not** `range(1, $file->version)` — a file replaced three
     * times may have no trail rows at all against version 2, and offering it
     * would be offering an empty answer. Rows written before the version
     * column existed carry null and are simply not among the options.
     *
     * @return list<int>
     */
    public static function versionsFor(int $documentId): array
    {
        return self::query()
            ->where('document_id', $documentId)
            ->whereNotNull('version')
            ->distinct()
            ->orderByDesc('version')
            ->pluck('version')
            ->map(fn ($version): int => (int) $version)
            ->all();
    }

    /**
     * The trail split per version, as the tabs that render it.
     *
     * One row per version the history actually holds, newest first, with the
     * unversioned rows last — so the tabs the reader is offered are exactly
     * the ones that have something behind them, for the same reason
     * `versionsFor()` is derived from the rows rather than from `1..current`.
     *
     * **Counted under every filter except the version itself.** A tab says how
     * many entries choosing it would show, which is a statement about the
     * trail the reader is currently looking at: with a search applied, a count
     * of the whole history would send somebody to an empty tab. Dropping
     * `version` from the filters is what makes that true — counting with it
     * left in would report zero against every tab but the open one.
     *
     * The unversioned bucket is a tab rather than a silent remainder. Rows
     * written before the version column existed carry null, and leaving them
     * out of the split would make the per-version counts quietly disagree with
     * the whole: a reader would see a history of forty entries and tabs
     * accounting for thirty.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{version: ?int, entries: int}>
     */
    public static function versionTabsFor(int $documentId, array $filters = []): array
    {
        $rows = self::query()
            ->where('document_id', $documentId)
            ->trail([...$filters, 'version' => null])
            // `trail()` orders newest-first, which a grouped count cannot
            // carry: `occurred_at` is neither grouped nor aggregated, and
            // MySQL's ONLY_FULL_GROUP_BY refuses it outright.
            ->reorder()
            ->getQuery()
            ->select('version')
            ->selectRaw('count(*) as entries')
            ->groupBy('version')
            ->orderByRaw('version is null')
            ->orderByDesc('version')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'version' => $row->version === null ? null : (int) $row->version,
                'entries' => (int) $row->entries,
            ])
            ->all();
    }
}
