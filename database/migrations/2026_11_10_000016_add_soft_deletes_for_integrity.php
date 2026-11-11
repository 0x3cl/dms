<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletes on the records other things point at.
 *
 * The problem this solves is specific. `documents.user_id` and `files.user_id`
 * are `nullOnDelete`, so a hard delete preserved the *row* but destroyed the
 * *link*: the document survived its uploader and immediately had no owner, so
 * nobody could edit or end it. That was the intended outcome of a worse earlier
 * design (it used to cascade and delete the documents outright) but it is not a
 * good one, and `/profile` lets a user delete their own account.
 *
 * With a soft delete the link stays intact. The account cannot sign in — the
 * auth provider applies the model's global scopes — and everything it owns
 * still knows who owns it, which is what makes an ownership transfer possible
 * at all rather than archaeology.
 *
 * `folders` and `files` already have theirs, from the storage migration.
 *
 * Note what this does **not** change: every audit row keeps its `nullOnDelete`
 * key beside a name snapshot. An audit trail must survive a *hard* delete too,
 * and the snapshot is what the page renders either way.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['users', 'divisions', 'sections', 'documents'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->softDeletes();
            });
        }

        // Every list is "newest first inside a filter", and a soft-deleted row
        // now has to be excluded from each one.
        Schema::table('users', fn (Blueprint $table) => $table->index('deleted_at'));
        Schema::table('documents', fn (Blueprint $table) => $table->index(['deleted_at', 'created_at']));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex(['deleted_at']));
        Schema::table('documents', fn (Blueprint $table) => $table->dropIndex(['deleted_at', 'created_at']));

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
