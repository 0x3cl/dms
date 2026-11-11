<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per tracking-code series, holding the highest number that series
     * has issued. A series is a document type on a date, so the `key` is the
     * code prefix without the counter: `MEMO-20261110`.
     *
     * App\Services\TrackingCodeService reads this as a floor, not as the whole
     * truth — the next number is `max(highest code on record, this counter) + 1`.
     * That combination is deliberate, and each half fixes something the other
     * cannot:
     *
     * - Counting the codes already in `documents` is self-correcting, so
     *   importing an office's historical records makes the series continue
     *   from where their paper trail left off. On its own, though, deleting
     *   the day's last document hands its tracking code to the next upload —
     *   two different documents, the same code, in a system whose whole job
     *   is to be the record of which is which.
     * - This counter never goes backwards, so a number that has been issued
     *   is never issued again. On its own it would restart every series at 1
     *   on a fresh install.
     *
     * `last_number` keeps the full code exactly as it was handed out, for
     * display beside the counter parsed from it.
     */
    public function up(): void
    {
        Schema::create('document_series', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('last_number')->nullable();
            $table->unsignedInteger('counter')->default(0);
            $table->foreignId('document_type_id')->nullable()->constrained()->nullOnDelete();
            $table->date('issued_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_series');
    }
};
