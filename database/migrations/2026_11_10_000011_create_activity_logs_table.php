<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The general activity log: who did what to which file or folder, and when.
 *
 * A **third** audit table, deliberately, beside `document_logs` and
 * `document_downloads`. The reason is the audience, not the shape:
 *
 * - `document_logs` is a document's travel history, rendered to **everyone who
 *   can read that document**. File-manager internals — a copy taken into
 *   someone's private storage, a zip extracted, a share link minted — must not
 *   appear there, or the trail becomes a disclosure of its own.
 * - `document_downloads` is the narrower "a copy left the server" register,
 *   with the IP it went to, behind its own permission.
 * - this table is everything that happens to a file or folder, behind
 *   `activity-trails.read`.
 *
 * A sibling `file_logs` was the alternative and would have been a third copy of
 * the same snapshot factory. One generic table with a morph subject is one.
 *
 * Like the other two, every row **snapshots its actor** beside a `nullOnDelete`
 * key: an audit trail that changes when the org chart changes is not an audit
 * trail. See documentation/architecture.md → Snapshots beside foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('actor_employee_id')->nullable();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->string('division_name')->nullable();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->string('section_name')->nullable();

            /*
             * The subject, as a plain morph with **no** foreign key.
             *
             * The row has to outlive what it points at — including a file that
             * was hard-deleted — so `subject_label` and `subject_public_id`
             * carry the snapshot, exactly as `document_logs.actor_name` does
             * for the actor. The keys are for following a row back to a live
             * record; the snapshot is what the page renders.
             */
            $table->string('subject_type', 191)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->char('subject_public_id', 26)->nullable()->collation('ascii_bin');

            $table->string('action', 32);

            // Whatever the action needs to be understood later: the folder a
            // file moved out of, how many entries an archive held, who a share
            // was granted to, why access was allowed.
            $table->json('context')->nullable();

            $table->string('ip_address')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
