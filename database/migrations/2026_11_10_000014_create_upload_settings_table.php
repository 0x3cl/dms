<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The upload policy, editable by an administrator instead of being a deploy.
 *
 * **Two rows, keyed by scope**, because the two things being uploaded are not
 * the same kind of thing:
 *
 * - `documents` — a tracked document gets a tracking code and a printed QR
 *   label, so the office has a reason to keep it to the formats it files.
 * - `files` — a personal filing cabinet restricted to three document types is
 *   not a filing cabinet.
 *
 * One shared policy would force the office to choose between stamping tracking
 * codes onto arbitrary images and crippling the file manager.
 *
 * The archive limits live here too rather than in their own table: they are
 * upload limits, they are edited on the same screen, and an archive's own size
 * is already governed by `max_size_kb`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 32)->unique();

            $table->unsignedInteger('min_size_kb')->default(0);
            $table->unsignedInteger('max_size_kb')->default(10240);

            // Extensions and MIME types are both checked. An extension is what
            // the user sees and what most tooling keys off; the sniffed MIME
            // type is what the file actually is. Neither alone is enough.
            $table->json('allowed_extensions');
            $table->json('allowed_mime_types');

            /*
             * Applied last, so it always wins over the allow-list. A
             * block-list on its own is never complete, but as a veto over an
             * allow-list it is how you retire one dangerous format without
             * re-auditing everything that was already permitted.
             */
            $table->json('prohibited_extensions');

            $table->unsignedSmallInteger('max_files_per_upload')->default(20);

            $table->unsignedInteger('max_archive_size_kb')->default(51200);
            $table->unsignedInteger('max_archive_entries')->default(2000);
            // The zip-bomb limit: total *uncompressed* bytes, which is what a
            // compression ratio turns a small upload into.
            $table->unsignedBigInteger('max_extracted_kb')->default(204800);
            $table->unsignedTinyInteger('max_archive_depth')->default(8);
            $table->boolean('allow_nested_archives')->default(false);

            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('updated_by_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_settings');
    }
};
