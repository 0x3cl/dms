<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody asking the uploader to let them in.
 *
 * A reader refused a private or confidential document used to be told only
 * that they were not on its list — true, and a dead end. The only way forward
 * was to find out who filed it and ask them by other means, which is exactly
 * the sort of thing that does not happen and leaves work stuck.
 *
 * **Granting one creates a `document_recipients` row and nothing else.** That
 * is the test that this is built in the right place: `AccessResolver::
 * forDocument()` already reads that table, so no new way into a document is
 * introduced here and the resolver does not change at all. This table records
 * the asking and the answer; the recipient list remains the single statement
 * of who may read what.
 *
 * It follows the audit convention — a foreign key for following the row back,
 * a snapshot beside it for what was true at the time — because a decided
 * request is a record of something that happened, and must stay readable after
 * an account is closed or a division renamed.
 *
 * `document_id` cascades, unlike the audit tables: a request to be let into a
 * document that no longer exists grants nothing and is not history worth
 * keeping, where a trail entry about a deleted document still is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_requests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            // Snapshotted so a decided request still reads after a purge.
            $table->string('document_code');
            $table->string('document_title');

            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name');
            $table->string('requester_employee_id')->nullable();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->string('division_name')->nullable();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->string('section_name')->nullable();

            // The uploader at the time of asking. The *decision* is authorised
            // against the document's live owner, so a transferred document's
            // pending requests become answerable by whoever holds it now.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_name')->nullable();

            $table->text('message')->nullable();

            // An enum, not a bare string. A hand-compared status is how the
            // dashboard's "ended" count sat at zero for months.
            $table->string('status', 16)->index();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_name')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->index(['owner_id', 'status', 'created_at']);
            $table->index(['requester_id', 'status', 'created_at']);
            $table->index(['document_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
    }
};
