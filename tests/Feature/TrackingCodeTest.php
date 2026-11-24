<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\DocumentType;
use App\Services\TrackingCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one piece of this system that must never be wrong twice.
 *
 * A tracking code is printed on paper and is the record of which document is
 * which, so the guarantee is narrow and absolute: **a number that has been
 * issued is never issued again.** Everything here is a way of trying to break
 * that.
 *
 * The counter is `max(highest code on record, the series' own counter) + 1`
 * under a row lock. Both halves are load-bearing and are asserted separately:
 * reading the codes back makes an import continue an office's paper trail,
 * and the series row never going backwards stops a deleted document handing
 * its code to the next upload.
 */
class TrackingCodeTest extends TestCase
{
    use RefreshDatabase;

    private function codes(): TrackingCodeService
    {
        return app(TrackingCodeService::class);
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::query()->where('code', $code)->sole();
    }

    public function test_a_series_is_per_type_and_per_day(): void
    {
        $memo = $this->type('MEMO');
        $letter = $this->type('LETTER');

        $this->assertSame('MEMO-'.now()->format('Ymd').'-0001', $this->codes()->issue($memo));
        $this->assertSame('MEMO-'.now()->format('Ymd').'-0002', $this->codes()->issue($memo));

        // A second type on the same day starts at one: counting every document
        // created today gave the second type a number the first had used.
        $this->assertSame('LETTER-'.now()->format('Ymd').'-0001', $this->codes()->issue($letter));

        // And tomorrow starts again.
        $this->assertSame(
            'MEMO-'.now()->addDay()->format('Ymd').'-0001',
            $this->codes()->issue($memo, now()->addDay()),
        );
    }

    /**
     * Past 9999 the series must keep climbing.
     *
     * `COUNTER_WIDTH` is the width a code is *composed* at, while `MASK`
     * admits three to eight digits — deliberate slack for a type that issues
     * more in a day than the padding allows. `highestOnRecord()` read the
     * last four characters, so `...-10000` parsed as `0000`: the series
     * restarted at 1 and began handing out codes it had already issued. It
     * was unreachable while a type meant a kind of paperwork, and stops
     * being unreachable the moment one type absorbs every upload.
     */
    public function test_a_series_does_not_restart_when_it_passes_the_padding_width(): void
    {
        $type = $this->type('MEMO');
        $prefix = 'MEMO-'.now()->format('Ymd');

        // A code already on record that is wider than the padding, with the
        // series row behind it — which is what an import produces.
        Document::factory()->create(['code' => $prefix.'-10000']);

        $this->assertSame($prefix.'-10001', $this->codes()->issue($type));
        $this->assertSame($prefix.'-10002', $this->codes()->issue($type));

        // And the preview agrees with the issuer, or the number shown to
        // somebody before they upload is not the number they get.
        $this->assertSame($prefix.'-10003', $this->codes()->preview($type));
    }

    /**
     * Deleting the day's last document must not hand its code to the next one.
     *
     * This is what the series row is for: reading the codes back off
     * `documents` is self-correcting but goes *backwards* when a row is
     * removed, and two documents sharing a code is the worst thing a register
     * can do.
     */
    public function test_a_code_is_never_reissued_after_its_document_is_deleted(): void
    {
        $type = $this->type('MEMO');

        $first = $this->codes()->issue($type);
        Document::factory()->create(['code' => $first]);

        Document::query()->where('code', $first)->forceDelete();

        $this->assertNotSame($first, $this->codes()->issue($type));
    }

    /**
     * A series continues from an office's own paper trail.
     *
     * The other half: a fresh install importing historical records has a
     * counter of zero and codes on record, and must continue from the codes.
     */
    public function test_a_series_continues_from_codes_already_on_record(): void
    {
        $prefix = 'MEMO-'.now()->format('Ymd');
        Document::factory()->create(['code' => $prefix.'-0042']);

        $this->assertSame($prefix.'-0043', $this->codes()->issue($this->type('MEMO')));
    }

    /** `preview()` is shown to somebody and must not move the series. */
    public function test_preview_issues_nothing(): void
    {
        $type = $this->type('MEMO');

        $this->assertSame($this->codes()->preview($type), $this->codes()->preview($type));
        $this->assertSame($this->codes()->preview($type), $this->codes()->issue($type));
    }

    /**
     * Several codes at once, from one advance of the counter.
     *
     * An archive files every entry it writes; one locked transaction per
     * entry is two hundred round trips contending for a counter that ends up
     * at the same number either way.
     */
    public function test_issue_many_returns_consecutive_codes_and_advances_once(): void
    {
        $type = $this->type('MEMO');
        $prefix = 'MEMO-'.now()->format('Ymd');

        $codes = $this->codes()->issueMany($type, 3);

        $this->assertCount(3, $codes);
        $this->assertSame(
            [$prefix.'-0001', $prefix.'-0002', $prefix.'-0003'],
            array_map(fn ($issued) => $issued->code, $codes),
        );

        // Each carries the type it came from, which is the whole reason the
        // pair exists rather than a bare string.
        $this->assertTrue($codes[0]->type->is($type));

        // The counter sits at the last one handed out, so the next single
        // issuance follows it rather than colliding with it.
        $this->assertSame(3, (int) DocumentSeries::query()->where('key', $prefix)->value('counter'));
        $this->assertSame($prefix.'-0004', $this->codes()->issue($type));
    }

    public function test_issue_many_of_nothing_is_nothing(): void
    {
        $this->assertSame([], $this->codes()->issueMany($this->type('MEMO'), 0));
        $this->assertNull(DocumentSeries::query()->where('key', 'like', 'MEMO-%')->value('counter'));
    }
}
