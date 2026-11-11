<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The office hierarchy every other table hangs off: a division holds
     * sections, and a section holds staff.
     *
     * Both carry a `code` as well as a `name`, following PROCMIS: a code is
     * the short, stable handle that survives a rename, which is what makes it
     * safe to print one on a report or compare one across systems. A section's
     * code is unique only inside its division, because two divisions may both
     * run a "Records" section.
     *
     * `sections` previously had no unique constraint at all, so the same
     * section could be created twice under one division and quietly split that
     * office's staff and audit rows between two rows.
     */
    public function up(): void
    {
        Schema::create('divisions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('division_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['division_id', 'code']);
            $table->unique(['division_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
        Schema::dropIfExists('divisions');
    }
};
