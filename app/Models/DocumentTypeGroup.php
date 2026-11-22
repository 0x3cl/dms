<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A heading in the document-type picker.
 *
 * A group answers the first question somebody asks of a long list — what kind
 * of thing is this? — so the second one, which type exactly, is asked of six
 * options instead of twenty.
 *
 * It is a row rather than an enum because naming a group, renaming one or
 * reordering them is office business, and because the office will invent a
 * group nobody writing this file thought of. `code` is the stable handle that
 * survives a rename; `name` is what people read.
 *
 * A group gates nothing and is not part of a tracking code. Only
 * `document_types.code` reaches a tracking code, which is why a group may be
 * renamed or deleted freely while a type effectively may not.
 */
class DocumentTypeGroup extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'description', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return HasMany<DocumentType, $this>
     */
    public function types(): HasMany
    {
        return $this->hasMany(DocumentType::class, 'group_id');
    }

    /**
     * Every group, in office order.
     *
     * @return Collection<int, $this>
     */
    public static function ordered(): Collection
    {
        return self::query()->orderBy('position')->orderBy('name')->get(['id', 'code', 'name', 'description']);
    }
}
