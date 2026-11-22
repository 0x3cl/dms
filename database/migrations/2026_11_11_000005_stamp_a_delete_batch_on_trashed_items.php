<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which delete trashed this row.
 *
 * Deleting a folder soft-deletes its **whole subtree** — every descendant
 * folder and every file in them. Restoring, however, only ever revived the
 * item itself and the trashed chain *above* it. Nothing walked downward, so
 * restoring a trashed folder returned an **empty folder**: its children stayed
 * soft-deleted and, because the trash lists only what was deleted directly,
 * they became unreachable from My Files *and* from the Trash. The UI asserted
 * the opposite — "restoring the folder brings all of it back together" — and
 * no test covered it, because every restore test used a single file.
 *
 * Fixing it needs more than "revive everything trashed under here". A child
 * deleted on its own last week, and then its parent deleted today, must stay
 * in the trash when the parent comes back: the person who threw that child
 * away never asked for it. So each delete stamps one identifier across every
 * row it touches, and a restore revives exactly the rows that share the stamp.
 *
 * `deleted_at` is not good enough for this. It is second-precision by default,
 * so two unrelated deletes in the same second are indistinguishable, and
 * comparing timestamps to infer intent is exactly the kind of guess an audit
 * trail should not make.
 *
 * Nullable with no backfill: rows already in the trash carry no batch and
 * restore as they did before, item and ancestors only. Inventing batches for
 * them would be asserting a grouping nobody can verify.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['files', 'folders'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->uuid('deleted_batch')->nullable()->after('deleted_at');
                // Restore reads this to find a delete's other members.
                $blueprint->index('deleted_batch', "{$table}_deleted_batch_index");
            });
        }
    }

    public function down(): void
    {
        foreach (['files', 'folders'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex("{$table}_deleted_batch_index");
                $blueprint->dropColumn('deleted_batch');
            });
        }
    }
};
