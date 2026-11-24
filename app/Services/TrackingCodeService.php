<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\DocumentType;
use App\Services\Documents\IssuedCode;
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
     * The shape of a tracking code: `ABBR-YYYYMMDD-NNNN`.
     *
     * Here rather than in a controller or a Vue file because this is the one
     * class that *composes* a code, and a reader of one has to agree with its
     * writer. The abbreviation is a `document_types.code`, which is validated
     * `alpha_dash` and `max:16`; the counter is zero-padded to
     * `COUNTER_WIDTH`, and the upper bound is slack for a type that issues
     * more than a day's padding allows.
     *
     * It is a *shape* check and never a claim that a document exists. The two
     * answers are deliberately different — "that is not a tracking code" tells
     * somebody to look at what they typed, while "no document carries that
     * code" tells them to look at the label — and conflating them sends every
     * mistyped code off to be hunted for in the register.
     */
    public const MASK = '/^[A-Za-z0-9_-]{1,16}-\d{8}-\d{3,8}$/';

    /**
     * Could this string be a tracking code at all?
     *
     * Asked before the database is, so a paste of a whole email or a QR code
     * carrying a vCard is answered without a query — and answered honestly,
     * rather than as a document that could not be found.
     */
    public static function looksLikeCode(string $value): bool
    {
        return preg_match(self::MASK, trim($value)) === 1;
    }

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
     * The same issuance, carrying the type it came from.
     *
     * `issue()` keeps returning a bare string because every existing caller
     * wants one and this class's contract is already tested through it. What
     * the new callers need is the *pair*: a code stamped into a file has to
     * be recorded against the type whose series produced it, and a method
     * that hands back only the string makes the caller look the type up
     * again — which is the second statement of a fact that was just decided.
     */
    public function issueFor(DocumentType $type, ?CarbonInterface $on = null): IssuedCode
    {
        return new IssuedCode($this->issue($type, $on), $type);
    }

    /**
     * Issue several codes for one type, under one lock.
     *
     * An archive extraction files every entry it writes, and doing that one
     * code at a time means one locked transaction per entry on the *same*
     * series row — two hundred round trips contending with each other for a
     * counter that is only ever going to end up at the same number. This
     * advances it once by the whole count.
     *
     * The codes are composed through the same private `compose()`, so there
     * is still exactly one place that formats a code; what differs is only
     * how many times the counter moves.
     *
     * @return list<IssuedCode>
     */
    public function issueMany(DocumentType $type, int $count, ?CarbonInterface $on = null): array
    {
        if ($count < 1) {
            return [];
        }

        $date = ($on ?? Carbon::now());
        $prefix = $this->prefix($type, $date);

        return DB::transaction(function () use ($type, $date, $prefix, $count): array {
            // Exactly as `issue()` does, and for the same reason: the row has
            // to exist before it can be locked.
            DocumentSeries::query()->insertOrIgnore([
                'key' => $prefix,
                'counter' => 0,
                'document_type_id' => $type->id,
                'issued_on' => $date->format('Y-m-d'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $series = DocumentSeries::query()->where('key', $prefix)->lockForUpdate()->first();

            $from = max($this->highestOnRecord($prefix), (int) $series->counter);
            $codes = [];

            for ($n = $from + 1; $n <= $from + $count; $n++) {
                $codes[] = new IssuedCode($this->compose($prefix, $n), $type);
            }

            $series->update([
                'counter' => $from + $count,
                'last_number' => $codes[array_key_last($codes)]->code,
            ]);

            return $codes;
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
     *
     * **The counter is read after the last `-`, not as the last four
     * characters.** `COUNTER_WIDTH` is the width a code is *composed* at;
     * `MASK` admits three to eight digits, which is deliberate slack for a
     * type that issues more in a day than the padding allows. Taking
     * `substr(-4)` of `OTHER-20261123-10000` is `0000`, so the moment a
     * series passed 9999 this read zero, the series restarted at 1, and the
     * one thing this class exists to guarantee — that a number issued is
     * never issued again — would have been broken by its own arithmetic. It
     * was unreachable while a type meant a kind of paperwork; it stops being
     * unreachable the moment one type absorbs every upload in an office.
     */
    private function highestOnRecord(string $prefix): int
    {
        $latest = Document::query()
            ->where('code', 'like', $prefix.'-%')
            ->orderByRaw('length(code) desc')
            ->orderByDesc('code')
            ->value('code');

        if ($latest === null) {
            return 0;
        }

        return (int) substr((string) $latest, strrpos((string) $latest, '-') + 1);
    }

    private function compose(string $prefix, int $counter): string
    {
        return $prefix.'-'.str_pad((string) $counter, self::COUNTER_WIDTH, '0', STR_PAD_LEFT);
    }
}
