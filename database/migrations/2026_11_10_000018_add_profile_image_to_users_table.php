<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A profile picture for an account.
 *
 * `resources/js/components/UserAvatar.js` was written against this column and
 * shipped before it existed — it renders `/storage/{profile_image}` when there
 * is one and two-letter initials when there is not, and until now there never
 * was one. This is the other half.
 *
 * The path is relative to the `public` disk, which is why it is a plain string
 * and not the `public_id`-derived path the file manager uses. An avatar is
 * shown beside a name wherever people are listed, so it is served as a static
 * asset through the `storage:link` symlink; routing every face in a paginated
 * list through a controller would put a database read in front of each one.
 * Nothing confidential belongs here, and the upload rules on both forms are
 * narrowed to images for that reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'profile_image')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_image')->nullable()->after('section_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_image');
        });
    }
};
