<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The storage substrate: a folder tree per account, and the files in it.
 *
 * A tracked document becomes a `files` row *plus* its existing `documents` row
 * — a tracking jacket over the bytes. `documents.code` is printed on paper and
 * `document_logs`, `document_recipients` and `document_downloads` all point at
 * `documents.id`, so repointing those would have been the disruptive option.
 * Adding one foreign key is not.
 *
 * Two delete strategies, and the difference matters:
 *
 * - `parent_id` and `folder_id` are `restrictOnDelete`. Deleting a tree is a
 *   *service* operation that soft-deletes the subtree in a transaction and
 *   writes an audit row per subject. A database cascade would hard-delete
 *   descendants with no record of it — the exact failure
 *   `employees.section_id` had, where deleting a section silently deleted
 *   everyone recorded in it.
 * - `user_id` is `nullOnDelete`, beside an `owner_name` snapshot, because a
 *   file has to outlive the account that uploaded it. This is the same rule
 *   the audit tables follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique()->collation('ascii_bin');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('owner_name')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('folders')->restrictOnDelete();
            $table->boolean('is_root')->default(false);
            $table->string('name');

            /*
             * The materialized ancestor path, '/{public_id}/{public_id}/'.
             *
             * Effective visibility is the most restrictive value on an item and
             * every folder above it, and inherited shares come from the nearest
             * ancestor carrying one. Both are one `LIKE`/`whereIn` against this
             * column instead of a query per level, which is what keeps a
             * listing from costing one round trip per folder of depth.
             */
            $table->string('path', 768)->default('');
            $table->unsignedTinyInteger('depth')->default(0);
            $table->string('visibility', 16)->default('private');
            $table->softDeletes();
            $table->timestamps();

            /*
             * Every account has exactly one `is_root` folder and everything
             * else has a parent, which is what makes this pair bite: MySQL
             * treats NULLs as distinct, so a unique index over a nullable
             * parent would silently allow duplicates at the top level.
             * FolderService::rootFor() is what upholds the one-root rule.
             */
            $table->unique(['parent_id', 'name']);
            $table->index(['user_id', 'parent_id']);
            $table->index(['user_id', 'deleted_at']);
            $table->index('path');
        });

        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique()->collation('ascii_bin');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('owner_name')->nullable();
            $table->foreignId('folder_id')->constrained()->restrictOnDelete();

            // `name` is what the user renames; `original_name` is what they
            // uploaded, kept as metadata for the download filename. Neither
            // ever reaches the filesystem — see App\Services\Files\FilePathFactory.
            $table->string('name');
            $table->string('original_name');
            $table->string('extension', 16)->default('');
            $table->string('mime_type', 191)->default('application/octet-stream');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('checksum', 64)->nullable();
            $table->string('disk', 32)->default('documents');

            /*
             * Deliberately NOT `path`.
             *
             * `folders.path` is the materialized *ancestor* path, and a query
             * that joins the two and writes `path` gets whichever the optimiser
             * resolves it to. That collision silently broke the visibility
             * listing once already: the ancestor check searched a file's disk
             * path for a folder id and found nothing, so a public file inside a
             * private folder was listed to everyone.
             */
            $table->string('storage_path');
            $table->string('visibility', 16)->default('private');

            // How the row came to exist: upload | copy | extract | import.
            // A copy is a new row with its own bytes, never a reference, so
            // revoking the original's share cannot reach into a copier's
            // storage.
            $table->string('source', 16)->default('upload');
            $table->boolean('has_thumbnail')->default(false);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['folder_id', 'name']);
            $table->index(['folder_id', 'deleted_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['visibility', 'created_at']);
            $table->index('checksum');
        });

        Schema::table('documents', function (Blueprint $table) {
            /*
             * `nullOnDelete`, because a tracked document's *record* — its code,
             * its trail, its recipients — must outlive the bytes. With the file
             * gone, accessFile() reports that the stored file is missing, which
             * it already does today for a file deleted off the disk.
             */
            $table->foreignId('file_id')->nullable()->after('qr_code_path')
                ->constrained()->nullOnDelete();
            $table->unique('file_id');

            /*
             * `file_path` becomes nullable and read-only.
             *
             * It is the old public-disk path, kept so rows that predate the
             * move keep serving while `files:import-documents` works through
             * them. Nothing writes it any more — the bytes are located through
             * `file_id` — and keeping it populated as well would be a second,
             * drifting copy of where a file lives.
             */
            $table->string('file_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['file_id']);
            $table->dropConstrainedForeignId('file_id');
        });

        Schema::dropIfExists('files');
        Schema::dropIfExists('folders');
    }
};
