<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The kinds of document the office tracks, and the abbreviation each one
     * contributes to a tracking code (`MEMO` in `MEMO-20261110-0001`).
     *
     * This was a PHP constant — `DocumentController::TYPES` — which meant
     * adding a document type, correcting an abbreviation or retiring a type
     * all required a code change and a deploy. It is a lookup table now, like
     * every other list in PROCMIS: the upload form reads it, validation reads
     * it, and the tracking-code series are keyed by its `code`.
     *
     * `code` is the part that must never change once a type has issued a
     * tracking code, because the codes already printed on paper carry it.
     * `is_active` retires a type without deleting it, so the documents that
     * already used it keep their type.
     *
     * `position` orders the upload form's dropdown by office convention
     * rather than alphabetically.
     */
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
