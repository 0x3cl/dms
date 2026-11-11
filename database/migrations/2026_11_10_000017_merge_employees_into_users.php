<?php

use App\Support\Modules;
use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Collapses the staff directory into the accounts table.
 *
 * `employees` and `users` were two records of the same person. Both carried an
 * employee ID, a name, an email and a section, and `employees.user_id` existed
 * only to say that an entry and an account were the same human — a link that
 * granted nothing and that somebody had to remember to make. So an office
 * maintained every person twice, and the two copies disagreed the moment one
 * was edited.
 *
 * The directory doc said that unifying them "is a migration and a decision,
 * not a refactor". This is the migration.
 *
 * It only matters to an install that already holds data. A fresh install gets
 * the merged shape straight from the baseline, which declares `first_name` /
 * `last_name` on `users` and no `employees` table at all.
 *
 * Order is load-bearing: the columns have to exist before anything is written
 * into them, every employee row has to be read before the table is dropped,
 * and the columns can only be made required once every row has a value.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'first_name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('first_name')->nullable()->after('employee_id');
                $table->string('last_name')->nullable()->after('first_name');
                $table->index(['last_name', 'first_name']);
            });
        }

        $this->splitExistingNames();

        if (Schema::hasTable('employees')) {
            $this->adoptLinkedEntries();
            $this->accountsForUnlinkedEntries();
        }

        // Anything still blank — an account whose `name` was empty — gets a
        // placeholder rather than failing the NOT NULL below. A blank name is
        // already broken data; refusing to migrate does not fix it.
        DB::table('users')->whereNull('first_name')->orWhere('first_name', '')
            ->update(['first_name' => 'Unknown']);
        DB::table('users')->whereNull('last_name')->update(['last_name' => '']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable(false)->change();
            $table->string('last_name')->nullable(false)->change();
        });

        Schema::dropIfExists('employees');

        $this->moveThePermissions();
    }

    /**
     * Splits each account's single `name` into the two new columns.
     *
     * On the last space, so a compound surname is kept whole and a middle name
     * stays with the given names — "Maria Clara de los Santos" becomes
     * "Maria Clara de los" + "Santos", which is wrong about as often as any
     * other guess and is the one an administrator can correct in two fields.
     * A single word becomes a first name with no surname.
     */
    private function splitExistingNames(): void
    {
        DB::table('users')
            ->select('id', 'name')
            ->whereNull('first_name')
            ->orderBy('id')
            ->chunk(200, function ($accounts) {
                foreach ($accounts as $account) {
                    $name = trim((string) $account->name);
                    $cut = mb_strrpos($name, ' ');

                    DB::table('users')->where('id', $account->id)->update([
                        'first_name' => $cut === false ? $name : trim(mb_substr($name, 0, $cut)),
                        'last_name' => $cut === false ? '' : trim(mb_substr($name, $cut + 1)),
                    ]);
                }
            });
    }

    /**
     * Where a directory entry already pointed at an account, the entry's
     * first/last name wins over the split above and `name` is recomposed from
     * it. The directory is where somebody typed the two halves deliberately;
     * the split is a guess at the same thing.
     */
    private function adoptLinkedEntries(): void
    {
        $linked = DB::table('employees')->whereNotNull('user_id')->get();

        foreach ($linked as $entry) {
            $first = trim((string) $entry->first_name);
            $last = trim((string) $entry->last_name);

            DB::table('users')->where('id', $entry->user_id)->update([
                'first_name' => $first,
                'last_name' => $last,
                'name' => trim("{$first} {$last}"),
            ]);
        }
    }

    /**
     * Every directory entry that was never linked becomes an account.
     *
     * The password is random and unknown to anybody — these people never had a
     * sign-in, and the point is that they are now listed in one place, not
     * that they can suddenly log in. An administrator sets a real one from the
     * Security tab, or the person resets it if they have an address on file.
     *
     * An entry whose employee ID or email already belongs to an account is
     * skipped rather than failing the migration: that is the duplicate this
     * whole change exists to end, and the account is the copy to keep.
     */
    private function accountsForUnlinkedEntries(): void
    {
        $staff = Role::findOrCreate(Roles::STAFF, 'web');
        $divisionOfSection = DB::table('sections')->pluck('division_id', 'id');

        $entries = DB::table('employees')->whereNull('user_id')->orderBy('id')->get();

        foreach ($entries as $entry) {
            if (DB::table('users')->where('employee_id', $entry->employee_id)->exists()) {
                continue;
            }

            $email = $entry->email ?: null;

            if ($email !== null && DB::table('users')->where('email', $email)->exists()) {
                $email = null;
            }

            $first = trim((string) $entry->first_name);
            $last = trim((string) $entry->last_name);

            $id = DB::table('users')->insertGetId([
                'public_id' => $this->freshPublicId(),
                'employee_id' => $entry->employee_id,
                'first_name' => $first,
                'last_name' => $last,
                'name' => trim("{$first} {$last}"),
                'email' => $email,
                'division_id' => $entry->section_id ? ($divisionOfSection[$entry->section_id] ?? null) : null,
                'section_id' => $entry->section_id,
                'password' => Hash::make(Str::random(32)),
                'created_at' => $entry->created_at,
                'updated_at' => now(),
            ]);

            DB::table('model_has_roles')->insertOrIgnore([
                'role_id' => $staff->id,
                'model_type' => \App\Models\User::class,
                'model_id' => $id,
            ]);
        }
    }

    /**
     * Mirrors App\Models\Concerns\HasPublicId without booting the model, since
     * a migration must not depend on the model's current shape.
     */
    private function freshPublicId(): string
    {
        do {
            $candidate = Str::random(26);
        } while (DB::table('users')->where('public_id', $candidate)->exists());

        return $candidate;
    }

    /**
     * `employees.*` becomes `users.*`, and only SUPERADMIN gets it.
     *
     * Staff held `employees.read` — a people lookup. The replacement module
     * hands out roles and sets other people's passwords, so it is not the same
     * grant and is not carried over. Deleting the old permission rows takes
     * them off every role that held them, including custom ones.
     */
    private function moveThePermissions(): void
    {
        $permissions = collect(Modules::permissions())
            ->filter(fn (string $name) => str_starts_with($name, 'users.'))
            ->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        Role::findOrCreate(Roles::SUPERADMIN, 'web')
            ->givePermissionTo($permissions);

        Permission::query()->where('name', 'like', 'employees.%')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Deliberately empty.
     *
     * The reverse of a merge is a split, and the information needed to perform
     * it — which accounts were directory entries and which were always
     * sign-ins — is exactly what the merge discards. Restoring `employees`
     * would mean inventing it. The baseline is the schema; this file is the
     * one-way bridge a deployed install crosses.
     */
    public function down(): void {}
};
