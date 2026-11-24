<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a stamp was drawn becomes a fact about the **bytes**, not the
 * document.
 *
 * It arrived on `documents.stamp`, which only ever described whatever
 * version the document currently points at: replace the file and the answer
 * was reset, and the superseded version's — which still exists, still has
 * its own bytes on disk, and is still downloadable from the version history
 * — was gone. A reader opening version 1 had no way to know whether the copy
 * they were about to print carried its code.
 *
 * So it moves onto `files`, which is the current version's descriptor, and
 * is mirrored into `file_versions` exactly as `original_name`, `extension`,
 * `mime_type`, `size_bytes` and `checksum` already are. That mirroring is
 * the established shape here: each row describes *the version it holds*, so
 * a superseded one keeps its own answer when the current one changes.
 *
 * `documents.stamp` is dropped rather than left in step, because two columns
 * over one fact is the drift this schema keeps warning about — and the first
 * drift would be a document claiming a mark its file does not carry.
 * `Document::stamp` is an accessor onto the file now, so every reader is
 * unchanged.
 *
 * The existing values are carried across rather than recomputed: a document
 * that says `image` says so because the bytes were actually drawn into, and
 * that is not something a migration could work out by looking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('stamp', 16)->default('none')->after('has_thumbnail');
        });

        Schema::table('file_versions', function (Blueprint $table) {
            $table->string('stamp', 16)->default('none')->after('has_thumbnail');
        });

        // The current version's answer, from the document that held it.
        DB::table('files')
            ->join('documents', 'documents.file_id', '=', 'files.id')
            ->update(['files.stamp' => DB::raw('documents.stamp')]);

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('stamp');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('stamp', 16)->default('none')->after('filed');
        });

        DB::table('documents')
            ->join('files', 'documents.file_id', '=', 'files.id')
            ->update(['documents.stamp' => DB::raw('files.stamp')]);

        Schema::table('files', fn (Blueprint $table) => $table->dropColumn('stamp'));
        Schema::table('file_versions', fn (Blueprint $table) => $table->dropColumn('stamp'));
    }
};
