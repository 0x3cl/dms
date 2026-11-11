<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The document's travel history: every view, download, acknowledgement and
     * hand-off, and the closing "ended" entry. Shown to anyone who can read
     * the document.
     *
     * The shape follows PROCMIS `purchase_request_process`: an actor foreign
     * key for joining, beside a snapshot of who they were at the time.
     *
     * The snapshots are the point of this table's refactor. The old version
     * stored only `user_id`, `division_id` and `section_id`, so the history
     * was rendered by joining live rows — which means renaming a division
     * silently rewrote where a document had been two years ago, and deleting
     * one blanked it. An audit trail that changes when the org chart changes
     * is not an audit trail. The foreign keys stay for filtering and for
     * following a row back to a live record; the names are what the page
     * renders, which also means the trail needs no eager loading at all.
     *
     * Renames from the old table: `user_id` → `actor_id` (the trail records
     * who acted, which is not always the document's user) and `received_at` →
     * `occurred_at` (it was written for every action, not just receipts). The
     * dead `status` column is gone — only `action` was ever set.
     *
     * `action` holds an App\Enums\DocumentAction value. It used to be a free
     * string validated at `max:255`, so the trail accepted any text a client
     * cared to post, and the checks that compared it against literals like
     * `'Received and Downloaded'` were one typo away from silently failing.
     */
    public function up(): void
    {
        Schema::create('document_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('actor_employee_id')->nullable();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->string('division_name')->nullable();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->string('section_name')->nullable();
            $table->string('action', 32);
            $table->text('remarks')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            // The history page, newest first.
            $table->index(['document_id', 'occurred_at']);
            // "Has this person already received this document?" and "was the
            // last entry this person's own acknowledgement?" — both read by
            // document and actor, and neither had an index.
            $table->index(['document_id', 'actor_id', 'action']);
            // The dashboard's "documents I have received" and recent activity.
            $table->index(['actor_id', 'action']);
            $table->index(['actor_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_logs');
    }
};
