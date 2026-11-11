<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\DocumentType;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Issues tracking codes: `ABBR-YYYYMMDD-NNNN`, where the abbreviation is the
 * document type's `code` and the counter is that type's series for that day.
 *
 * This was `DocumentController::nextTrackingCode()`. It is a service now for
 * the reason PROCMIS has `DocumentNumberService`: issuing a number is the one
 * piece of this system that must never be wrong twice, it has to be callable
 * from outside a web request (an importer, a console command), and it needs
 * its own tests.
 *
 * The counter is `max(highest code already on record, the series' own counter) + 1`,
 * read under a row lock inside a transaction. Both halves are load-bearing:
 *
 * - Reading it back off the codes themselves is self-correcting, so importing
 *   an office's historical documents makes the series continue from where
 *   their paper trail left off.
 * - The series row never goes backwards, so deleting a document does not hand
 *   its tracking code to the next upload. That mattered: in a system whose
 *   whole job is to be the record of which document is which, two documents
 *   sharing a code is the worst thing it can do.
 *
 * The naive version was wrong in two further ways at once, both now covered by
 * tests: counting every document created today gave the second type uploaded
 * that day a number the first series had already used, and two uploads
 * arriving together read the same count.
 */
class TrackingCodeService
{
    /** Digits in the counter: `MEMO-20261110-0001`. */
    private const COUNTER_WIDTH = 4;

    /**
     * Issue the next code for a type and advance its series.
     */
    public function issue(DocumentType $type, ?CarbonInterface $on = null): string
    {
        $date = ($on ?? Carbon::now());
        $prefix = $this->prefix($type, $date);

        return DB::transaction(function () use ($type, $date, $prefix): string {
            // The row has to exist before it can be locked — `lockForUpdate`
            // on a row that is not there locks nothing, and two uploads
            // arriving together would each believe they were the first. The
            // unique key on `key` is what makes this safe to race.
            DocumentSeries::query()->insertOrIgnore([
                'key' => $prefix,
                'counter' => 0,
                'document_type_id' => $type->id,
                'issued_on' => $date->format('Y-m-d'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $series = DocumentSeries::query()->where('key', $prefix)->lockForUpdate()->first();

            $next = max($this->highestOnRecord($prefix), (int) $series->counter) + 1;
            $code = $this->compose($prefix, $next);

            $series->update(['counter' => $next, 'last_number' => $code]);

            return $code;
        });
    }

    /**
     * What the next code would be, without issuing it. For showing a user the
     * code their upload is about to be given — never for storing.
     */
    public function preview(DocumentType $type, ?CarbonInterface $on = null): string
    {
        $prefix = $this->prefix($type, $on ?? Carbon::now());

        return $this->compose($prefix, max($this->highestOnRecord($prefix), DocumentSeries::baselineFor($prefix)) + 1);
    }

    /**
     * The series key for a type on a date: a code with its counter cut off.
     */
    public function prefix(DocumentType $type, ?CarbonInterface $on = null): string
    {
        return $type->code.'-'.($on ?? Carbon::now())->format('Ymd');
    }

    /**
     * The highest counter this prefix has issued according to `documents`.
     *
     * Ordering by the code works because the counter is zero-padded to a
     * fixed width, so it sorts the same as a number would.
     */
    private function highestOnRecord(string $prefix): int
    {
        $latest = Document::query()
            ->where('code', 'like', $prefix.'-%')
            ->orderByDesc('code')
            ->value('code');

        return (int) substr((string) $latest, -self::COUNTER_WIDTH);
    }

    private function compose(string $prefix, int $counter): string
    {
        return $prefix.'-'.str_pad((string) $counter, self::COUNTER_WIDTH, '0', STR_PAD_LEFT);
    }
}
