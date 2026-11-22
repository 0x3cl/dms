<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comments on a document, scoped to the version they were written about.
 *
 * **A comment names its version because a file has a history.** "This needs
 * the annex" said about version 1 is not advice about version 3, and a
 * thread that silently carries forward across a replacement tells the next
 * reader that a resolved objection is still open. So the version is part of
 * the row, exactly as it is on `document_logs` — a column rather than a key
 * into `file_versions`, for the same reason every other snapshot here is:
 * it says what was true when it happened and survives the row it refers to.
 *
 * Nullable, and null is meaningful rather than missing: a document whose
 * bytes have gone, or one imported from before versioning, has no version to
 * name and inventing one would be a fabricated snapshot.
 *
 * **`actor_name` beside `user_id`**, the convention every audit table here
 * follows: the key is for filtering and following a row back to the live
 * record, the snapshot is what the page renders, and the row outlives the
 * account because `user_id` is `nullOnDelete`. A comment attributed to
 * nobody after an account closes would be a worse record than one that still
 * says who wrote it.
 *
 * `documents.comments_enabled` defaults to **true**, because the office
 * asked for commenting and a feature that has to be switched on per document
 * is one nobody will find. The uploader turns it off for a document that
 * should not collect discussion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('comments_enabled')->default(true)->after('visibility');
        });

        Schema::create('document_comments', function (Blueprint $table) {
            $table->id();
            // Cascade: a comment on a deleted document is about nothing. It
            // is not an audit row — the trail records that a comment was
            // posted, and that row survives.
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->text('body');
            $table->timestamps();

            // The document page reads one document's comments newest-first,
            // and the panel narrows them to the version on screen.
            $table->index(['document_id', 'version', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_comments');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('comments_enabled');
        });
    }
};
