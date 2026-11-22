<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A file is a sequence of versions, and the `files` row is the current one.
 *
 * Replacing a file was unrecorded and, in the one place it already happened,
 * destructive in effect: `DocumentController::update()` stored the replacement
 * as a **brand-new `files` row**, repointed `documents.file_id` at it and left
 * the previous row orphaned — no link back to the document, nothing in either
 * audit trail, no transaction, not even a thumbnail. `files:purge --orphans`
 * exists to sweep up the wreckage, which says plainly that this was unfinished
 * rather than intended. In personal storage the same act was worse disguised:
 * re-uploading a file of one name produced `notes (2).pdf`, so the second
 * version of a document read as a second, unrelated document.
 *
 * **The current version is the `files` row; only superseded versions move
 * here.** The alternative — a `files` row per version with a current-version
 * flag — is what `documentation/modules/files.md` predicted, and it would cost
 * the `(folder_id, name)` unique index, because versions share a name by
 * definition. That index is what makes two files of one name in one folder
 * impossible. Keeping the current row as the pointer keeps the index, keeps
 * `AccessResolver::scopeVisible()` and its differential test meaning what they
 * mean, and keeps `documents.file_id` — so a replacement disturbs neither the
 * tracking code, the printed label, the share link nor the file's URL. MySQL
 * also cannot express "exactly one current row per file" as an index, and this
 * schema keeps that class of invariant *in* the index rather than in PHP.
 *
 * It also means the current bytes are stated in exactly one place,
 * `files.storage_path`, as they always were. A `file_versions` row for the
 * current version would be a second statement of that, and the first drift is
 * a download serving the wrong bytes — the same mistake `documents.file_path`
 * is on record as.
 *
 * **Each row describes the version it holds, not the act that ended it.** The
 * obvious shape — `replaced_by_id` and `superseded_at` alone — records "who
 * put version 3 there" on version 2's row and "who put the *current* version
 * there" nowhere at all. That fact cannot be borrowed from `activity_logs`
 * either, because that table is behind `activity-trails.read` while this
 * history has to be readable by anyone who may read the file. So a version
 * carries its own author, note and date, and `files` mirrors the same five
 * columns for the current version. A replacement is then one sentence: move
 * the current-version descriptor off `files` into `file_versions` verbatim,
 * then write the new descriptor onto `files`.
 *
 * `file_id` is `cascadeOnDelete`, unlike every key on an audit row, and the
 * distinction is the usual one here. `files.folder_id` is `restrictOnDelete`
 * because a cascade would hard-delete *audited subjects* with no audit row. A
 * version is not an audited subject: nothing points at it, nothing renders it
 * once its file is gone, and the rows recording what happened live in
 * `activity_logs`, whose subject morph deliberately carries no foreign key and
 * stays readable after everything it names is purged. A version has no
 * existence apart from its file, and `restrictOnDelete` here would make
 * purging a replaced file impossible.
 *
 * No soft deletes. Keeping every version indefinitely means nothing ever
 * soft-deletes one, and a `deleted_at` nothing writes is the dormant column
 * this schema argues against everywhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');

            /*
             * The storage facts for *these* bytes, in full rather than as a
             * diff against the `files` row: a version is only useful if it can
             * be served on its own, and which viewer draws it is decided from
             * its extension and MIME type — both of which a replacement is
             * free to change, since a `.doc` may be superseded by a `.docx`.
             *
             * `disk` beside `storage_path` for the same reason `files` carries
             * both: `Document::storedFile()` already proves a row can name a
             * disk that is not today's default.
             */
            $table->string('disk', 32)->default('documents');
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('extension', 16)->default('');
            $table->string('mime_type', 191)->default('application/octet-stream');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('checksum', 64)->nullable();
            $table->boolean('has_thumbnail')->default(false);

            /*
             * Who put this version there, snapshotted beside the key — the
             * audit convention, and `nullOnDelete` for its reason: a version
             * outlives the account that uploaded it, exactly as `files.user_id`
             * does beside `owner_name`.
             */
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();

            /*
             * Why, in their own words. Optional on purpose: a required reason
             * is one more thing to get past on a correction somebody is in a
             * hurry to make, and a box typed through to be rid of it is worth
             * less than an honest blank.
             */
            $table->string('note')->nullable();

            /*
             * Set when this version is a copy of an earlier one, so "version 4
             * is version 1 brought back" is structural rather than prose in a
             * note somebody may not have written.
             */
            $table->unsignedSmallInteger('reverted_from')->nullable();

            $table->timestamp('became_current_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            // One row per version of a file, enforced rather than assumed: two
            // rows claiming to be version 2 is a history nobody can read. It is
            // also the backstop under the row lock `FileManager::replace()`
            // takes — a race becomes a failed insert, never a silent overwrite.
            $table->unique(['file_id', 'version']);
            // The history read, in order.
            $table->index(['file_id', 'version']);
            // The same reason `files.checksum` is indexed: "are these bytes
            // already on record" without reading them.
            $table->index('checksum');
        });

        /*
         * The current version's descriptor, mirrored onto the file.
         *
         * Nothing is backfilled but `version`, and that default is a fact
         * rather than a guess: every existing file genuinely is its own first
         * version.
         *
         * `version_created_by_id` is deliberately **not** backfilled from
         * `user_id`. `OwnershipTransferService` rewrites `user_id` and
         * `owner_name` when an account is closed, so on any transferred file
         * that copy would assert the new owner uploaded version 1 — false, and
         * precisely the kind of invented record this feature exists to end. A
         * never-replaced file therefore reads as "Original upload" dated
         * `files.created_at`, which is honest about not knowing: the same
         * answer a null `deleted_batch` and a null `actor_avatar_seed` give.
         *
         * For the same reason `OwnershipTransferService` must never touch these
         * columns: ownership is a fact about now, a version's author a fact
         * about then — exactly the distinction it already draws for
         * `documents.uploader_name`.
         */
        Schema::table('files', function (Blueprint $table) {
            $table->unsignedSmallInteger('version')->default(1)->after('source');
            $table->foreignId('version_created_by_id')->nullable()->after('version')
                ->constrained('users')->nullOnDelete();
            $table->string('version_created_by_name')->nullable()->after('version_created_by_id');
            $table->string('version_note')->nullable()->after('version_created_by_name');
            $table->unsignedSmallInteger('version_reverted_from')->nullable()->after('version_note');
            $table->timestamp('version_created_at')->nullable()->after('version_reverted_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_versions');

        Schema::table('files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('version_created_by_id');
            $table->dropColumn([
                'version', 'version_created_by_name', 'version_note',
                'version_reverted_from', 'version_created_at',
            ]);
        });
    }
};
