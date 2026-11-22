<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The actor's face, snapshotted beside their name.
 *
 * A document's trail renders who did what, and a row of identical grey
 * initials is something to read rather than something to recognise. The
 * obvious implementation is to eager-load `User` and take the photo from the
 * live record — and that is exactly what the snapshot convention on these
 * tables exists to prevent. An audit row says what was true *then*; joining a
 * live account to decorate it means the trail silently changes when somebody
 * updates their profile, and renders nothing at all once the account is
 * erased.
 *
 * So the two inputs `resources/js/avatar.js` needs are copied onto the row,
 * exactly as `actor_name` and `division_name` already are. Neither is a
 * credential: `avatar_seed` is an arbitrary token the person picked, and
 * `profile_image` is a path already served to anyone who can see them in a
 * list.
 *
 * Nullable with no backfill. An existing row keeps a name-derived face, which
 * `avatar.js` generates from `actor_name` alone — so history written before
 * this still has a distinct, stable face per person, and inventing seeds for
 * rows nobody can verify would be fabricating a snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_logs', function (Blueprint $table): void {
            $table->string('actor_avatar_seed')->nullable()->after('actor_employee_id');
            $table->string('actor_profile_image')->nullable()->after('actor_avatar_seed');
        });
    }

    public function down(): void
    {
        Schema::table('document_logs', function (Blueprint $table): void {
            $table->dropColumn(['actor_avatar_seed', 'actor_profile_image']);
        });
    }
};
