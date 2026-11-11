<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletes on `document_types`, so removing a type is both safe and
 * reversible.
 *
 * A document was already protected against its type being deleted, by two
 * things that were there from the start: `documents.document_type_id` is
 * `nullOnDelete`, and `documents.type` carries the type's **name as it stood at
 * upload**. Every screen renders that snapshot, so a document's type still
 * reads correctly even with no type row left at all.
 *
 * What a hard delete still cost was the *link*. The document would know it was
 * a "Memo" and no longer know which type row that was, so it dropped out of the
 * register's type filter and could not be counted. A soft delete keeps the link
 * intact while taking the type out of circulation, which is the same
 * distinction `users` draws — see documentation/modules/data-integrity.md.
 *
 * Note this is **not** the way to retire a type. `is_active` is: it stops the
 * upload form offering a type while leaving it fully live for filtering and
 * reporting. Deleting is for a type that should not exist — one created by
 * mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
