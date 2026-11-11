<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A kind of document the office tracks, and the abbreviation it contributes
 * to a tracking code.
 *
 * This was `DocumentController::TYPES`, a constant array that was both the
 * list offered on the upload form and the map to code prefixes. Keeping the
 * two in one place was right; keeping them in PHP was not — adding a type or
 * fixing an abbreviation meant a deploy. The table is the list and the map
 * now, and `App\Services\TrackingCodeService` is the only thing that reads
 * `code`.
 *
 * `code` is immutable in practice: it is stamped into every tracking code the
 * type has ever issued, and those codes are printed on paper — which is why the
 * edit form locks it once the type has issued one.
 *
 * **Retiring and deleting are different things.** `is_active = false` retires a
 * type: the upload form stops offering it, and it stays fully live for
 * filtering, counting and reporting. That is what you almost always want.
 * Deleting is for a type that should not exist — one created by mistake — and
 * it is a soft delete, so an existing document keeps both its type snapshot
 * (`documents.type`) and its link (`document_type_id`).
 */
class DocumentType extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The types the upload form offers, in office order.
     *
     * @return Collection<int, $this>
     */
    public static function selectable(): Collection
    {
        return self::query()
            ->active()
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    /**
     * Every type a document in the register might carry, including retired and
     * deleted ones.
     *
     * The register's type filter reads this rather than `selectable()`: a
     * document filed under a type that has since been retired is still in the
     * register, and offering a filter that cannot find it would be a filter
     * that lies.
     *
     * @return Collection<int, $this>
     */
    public static function filterable(): Collection
    {
        return self::withTrashed()
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_active', 'deleted_at']);
    }

    /**
     * Has this type issued a tracking code?
     *
     * The question the edit and delete screens turn on. A code that has been
     * issued is printed on paper, so the `code` column can no longer be
     * corrected — and the type is part of the office's record rather than a
     * list entry.
     */
    public function hasIssued(): bool
    {
        return $this->documents()->withTrashed()->exists()
            || DocumentSeries::query()->where('document_type_id', $this->id)->exists();
    }

    /**
     * How many documents were filed under this type, deleted ones included.
     */
    public function documentCount(): int
    {
        return $this->documents()->withTrashed()->count();
    }
}
