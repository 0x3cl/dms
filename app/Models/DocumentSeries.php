<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The high-water mark of one tracking-code series.
 *
 * A series is a document type on a date, so `key` is the code prefix without
 * the counter — `MEMO-20261110` — and `counter` is the highest number that
 * prefix has handed out.
 *
 * App\Services\TrackingCodeService treats this as a floor rather than as the
 * whole answer: the next number is `max(highest code in documents, counter) + 1`.
 * Counting the codes on record is what lets an office import its historical
 * documents and have the series continue from their paper trail; this counter
 * is what stops a number being reissued after the document that held it is
 * deleted. See the migration for the longer version.
 *
 * Modelled on PROCMIS `DocumentSeries`, which solves the same problem for
 * procurement document numbers.
 */
class DocumentSeries extends Model
{
    protected $table = 'document_series';

    protected $fillable = [
        'key',
        'last_number',
        'counter',
        'document_type_id',
        'issued_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'counter' => 'integer',
            'issued_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * The floor for a series: 0 when it has issued nothing, which leaves the
     * tracking-code service counting documents alone.
     */
    public static function baselineFor(string $key): int
    {
        return (int) self::query()->where('key', $key)->value('counter');
    }

    public static function lastNumberFor(string $key): ?string
    {
        return self::query()->where('key', $key)->value('last_number');
    }
}
