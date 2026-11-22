<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A viewer taken off a document is a record of something that happened, not a
 * row to erase.
 *
 * Removing somebody from a restricted document used to be impossible: nothing
 * in the application detached a `document_recipients` row, and had anything
 * done so by deleting it, the removal would have left no trace — so the person
 * who had been reading a case file last week would be told, on their next
 * visit, exactly what a stranger who guessed the tracking code is told. Those
 * are two different answers. "You are not on this document's list" invites the
 * reader to ask for access; "the uploader took you off it" is an answer to a
 * question they have already had decided.
 *
 * So the row survives the removal and carries when it happened and who did it.
 * That is also what makes re-tagging the same person an update of one row
 * rather than a second row the unique pair would refuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_recipients', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('notified_at');
            // nullOnDelete, like every other actor reference: the record of the
            // removal outlives the account that performed it.
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')
                ->constrained('users')->nullOnDelete();

            // Every read of this table now asks "still a viewer?" — the
            // resolver per document, the "Shared with me" tab per user.
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('document_recipients', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'revoked_at']);
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn('revoked_at');
        });
    }
};
