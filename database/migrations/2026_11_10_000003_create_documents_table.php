<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An uploaded office document, and the named recipients of a private one.
     *
     * A document carries no lifecycle state. `is_ended` (a second, drifting
     * copy of `status`), `target_division_id` and `target_section_id` (a
     * routing model that was never built) were leftovers nothing read; `status`
     * and `ended_at` went with the End Action, which the office asked to have
     * removed. Every document is simply on record, and an uploader who wants
     * one out of circulation withdraws it — which is a delete, and leaves the
     * trail behind.
     *
     * `user_id` is `nullOnDelete`, not `cascadeOnDelete`. Deleting an account
     * used to delete every document that account had uploaded — including the
     * ones other offices were still tracking — and /profile lets a user delete
     * their own account. The document survives the uploader now, and
     * `uploader_name` keeps the name the audit trail needs to stay readable.
     * A document with no uploader has no owner, so nobody can edit or delete
     * it; that is the intended outcome, not a gap.
     *
     * `document_type_id` points at the lookup table while `type` keeps the
     * type's name as it stood at upload. The snapshot is the PROCMIS pattern
     * (`purchase_requests.division_name` beside `division_id`): a tracking
     * code is permanent, so renaming a type must not rewrite the history of
     * the documents issued under its old name.
     *
     * `document_recipients` gains the unique pair it never had. The same user
     * could be attached to one document twice, which notified them twice and
     * duplicated every visibility check that counted those rows.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->foreignId('document_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->nullable();
            $table->string('file_path');
            $table->string('file_name')->nullable();
            $table->string('qr_code_path')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('uploader_name')->nullable();
            $table->string('visibility', 32)->default('public');
            $table->timestamps();

            // Every document list is "newest first" inside some filter, and
            // none of these had an index: the Documents page's three tabs and
            // the dashboard's counts.
            $table->index(['user_id', 'created_at']);
            $table->index(['document_type_id', 'created_at']);
            $table->index(['visibility', 'created_at']);
            $table->index('created_at');
            // The public QR-label route looks a document up by this filename.
            $table->index('qr_code_path');
        });

        Schema::create('document_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'user_id']);
            // The "documents shared with me" tab reads this way round.
            $table->index(['user_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_recipients');
        Schema::dropIfExists('documents');
    }
};
