<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seed behind a generated avatar.
 *
 * An account with no uploaded photo used to render two grey initials. Those are
 * legible and completely interchangeable: in a list of thirty staff, half a
 * dozen "DC"s say nothing about which Dela Cruz is which. A generated avatar is
 * distinct per person and stable, so a face becomes something you recognise in
 * a hand-off trail rather than something you read.
 *
 * Null is the normal state and means "generate one from this person's name", so
 * every account has a face from the moment it exists without anybody choosing.
 * A value is a deliberate pick made in the avatar picker, which is the whole
 * reason the column exists: a name-derived avatar cannot be changed without
 * changing the name.
 *
 * A seed and not a URL, deliberately. The provider, the style and the framing
 * are presentation — they live in `resources/js/avatar.js` — and a stored URL
 * would mean every account's face had to be rewritten to change any of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'avatar_seed')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_seed')->nullable()->after('profile_image');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_seed');
        });
    }
};
