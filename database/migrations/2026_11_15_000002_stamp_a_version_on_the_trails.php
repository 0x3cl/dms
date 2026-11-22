<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which version an audit row was about.
 *
 * Once a file has a history, "downloaded this document" stops being a complete
 * sentence: the reader who took a copy on Monday and the reader who took one on
 * Friday may be holding different bytes. A trail that cannot say which answers
 * the wrong question, and the office asked for the right one — a history
 * organised *by version*.
 *
 * A column rather than a key into `file_versions`, for the reason every other
 * column on these tables is a snapshot: this says what was true when it
 * happened. The current version moves on afterwards, and a version row is
 * cascaded away with its file, while an audit row has to keep reading either
 * way. It is also what makes the filter a filter — an integer `scopeTrail()`
 * can narrow on, where `context->version` would be a JSON probe the trail's
 * vocabulary has no word for.
 *
 * Nullable, with no backfill, and null is **meaningful rather than missing**: a
 * folder has no version, a document with no stored file has none, and history
 * written before this column existed does not know which version it concerned.
 * Stamping 1 across it would assert that every one of those rows was about a
 * first version — unverifiable, and false wherever the old `update()` already
 * swapped a document's file.
 *
 * No index on either. The document trail always narrows by `document_id`
 * first and orders by `occurred_at`, which `[document_id, occurred_at]`
 * already serves; a second, low-cardinality index would only offer the planner
 * a choice that costs it a sort. The activity report does not filter by
 * version at all — "version 2" without a subject would match version 2 of
 * every file in the agency — so there is nothing there to index for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_logs', function (Blueprint $table) {
            $table->unsignedSmallInteger('version')->nullable()->after('action');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            /*
             * `subject_version`, not `version`: this qualifies the subject, and
             * the table already names every subject column that way
             * (`subject_type`, `subject_id`, `subject_label`,
             * `subject_public_id`). A bare `version` beside them would read as
             * a version of the row itself.
             */
            $table->unsignedSmallInteger('subject_version')->nullable()->after('subject_public_id');
        });
    }

    public function down(): void
    {
        Schema::table('document_logs', function (Blueprint $table) {
            $table->dropColumn('version');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn('subject_version');
        });
    }
};
