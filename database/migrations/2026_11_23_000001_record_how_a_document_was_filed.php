<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two facts about a document that could not be asked of it before.
 *
 * Every uploaded file is filed now, so the register needs to be able to tell
 * what somebody sat down and filed from what was given a code because it
 * arrived — and a reader of a document needs to know whether its *bytes*
 * carry the code, because that decides whether printing it carries the code
 * or loses it.
 *
 * **Neither column is backfilled, and both defaults are the truth rather than
 * a guess.** Before this change the only ways to get a `documents` row were
 * the register's own form and an opt-in checkbox, so every existing row
 * genuinely is a deliberate filing; and no existing file has ever been
 * stamped, so `none` is what every existing row honestly says about itself. A
 * migration that wrote those values over every row would be restating what
 * the default already states.
 *
 * `filed` is indexed with `created_at` because the register orders by date
 * inside the filter, exactly as `visibility` is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('filed', 16)->default('deliberate')->after('visibility');
            $table->string('stamp', 16)->default('none')->after('filed');

            $table->index(['filed', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['filed', 'created_at']);
            $table->dropColumn(['filed', 'stamp']);
        });
    }
};
