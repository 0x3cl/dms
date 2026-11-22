<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let somebody clear a request off their own list without erasing it.
 *
 * An `access_requests` row is the *only* record that a request was made and
 * answered — nothing writes the asking to `document_logs` or to
 * `activity_logs`, because who asked for what is not the document's travel
 * history and is not a file-manager event. So deleting the row would delete
 * the history with it: the uploader would lose the record of a decision they
 * made, and a granted request would leave a recipient nobody could account
 * for.
 *
 * A stamp instead, for the same reason `document_recipients.revoked_at` is a
 * stamp rather than a delete: the question "was this ever asked, and what was
 * answered" has to stay answerable after the asker has tidied their list.
 * Nullable with no backfill — a null means nobody has cleared it, which is
 * what every existing row means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_requests', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('access_requests', function (Blueprint $table) {
            $table->dropColumn('dismissed_at');
        });
    }
};
