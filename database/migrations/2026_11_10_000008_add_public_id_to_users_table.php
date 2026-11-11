<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The account's public, opaque identifier.
 *
 * Every file URL carries its owner's identifier as its first segment, and that
 * segment must not be the primary key: a sequential id in a URL tells anyone
 * who holds one how many accounts exist, lets them walk the range, and ties a
 * file back to a database row.
 *
 * 26 characters of `Str::random()` is 155 bits from the CSPRNG. Deliberately
 * not a ULID or a UUIDv7, both of which lead with a timestamp and so leak
 * creation order and time.
 *
 * `ascii` collation because the column is compared on every file request and a
 * case-insensitive utf8 collation would both slow the index and make two
 * distinct identifiers compare equal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable first: the unique index cannot be added until every
            // existing row has a value.
            $table->char('public_id', 26)->nullable()->after('id')->collation('ascii_bin');
        });

        DB::table('users')->whereNull('public_id')->orderBy('id')->pluck('id')
            ->each(fn (int $id) => DB::table('users')->where('id', $id)
                ->update(['public_id' => Str::random(26)]));

        Schema::table('users', function (Blueprint $table) {
            $table->char('public_id', 26)->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
