<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document types gain a group.
 *
 * The list had grown past the point where a flat dropdown is a list of
 * choices and become a wall somebody reads top to bottom: an office order,
 * a contract, a video, a photograph of a signing — all equally weighted, all
 * one scroll. A group is the first question ("what kind of thing is this?")
 * asked before the second ("which one?").
 *
 * **A table, not an enum**, for the same reason `document_types` is a table
 * rather than the PHP constant it replaced: naming a new group, renaming one
 * or reordering them is office business, not a deploy.
 *
 * `group_id` is **nullOnDelete** and nullable. A type is a real part of the
 * office's record — it is stamped into every tracking code it has issued —
 * and a grouping is an arrangement of the picker. Deleting a group must
 * rearrange the picker, never retire a type; an ungrouped type simply shows
 * under "Other".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_type_groups', function (Blueprint $table) {
            $table->id();
            // A code for the same reason every other lookup here carries one:
            // it is the stable handle that survives a rename, which is what
            // lets a seeder be idempotent without matching on a label people
            // are free to correct.
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('document_types', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('id')
                ->constrained('document_type_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
        });

        Schema::dropIfExists('document_type_groups');
    }
};
