<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts, plus the two framework tables keyed off them.
     *
     * `employee_id` is the credential the office issues, and **sign-in
     * accepts it or `email`** — one field, resolved to a column by the shape
     * of what was typed (see App\Http\Requests\Auth\LoginRequest). `email`
     * is nullable and unique: it exists so Laravel's password broker has
     * something to look an account up by, and an account without one is
     * unreachable by email, cannot self-serve a reset, and needs an
     * administrator. `password_reset_tokens` is therefore keyed by email,
     * which is the only key the broker can use.
     *
     * The division and section are foreign keys only. The old
     * `division_name` / `section_name` string columns are gone: they were a
     * second, unmaintained copy of the same fact that nothing read, and a
     * rename updated one of the two. Where a *historical* name matters it is
     * snapshotted on the audit row itself, not on the account.
     *
     * There is no `email_verified_at`: DocTrack has no email verification, so
     * there is nothing to verify. Reinstating it means adding this column and
     * `MustVerifyEmail` on the model together.
     *
     * The name is stored three ways and only two of them are input.
     * `first_name` and `last_name` are what an administrator types and what
     * the list sorts by; `name` is the two of them joined, written by a
     * `saving` hook on the model and never edited directly. It stays a real
     * column because every audit snapshot and every file-ownership row reads
     * it (`document_logs.actor_name`, `files.owner_name`), and those readers
     * must not have to know how a name is assembled.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id')->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            // A path on the `public` disk, not the private `documents` one: an
            // avatar is shown beside a name on pages anyone signed in can see,
            // and serving each one through a controller would put a database
            // read in front of every face in a list.
            $table->string('profile_image')->nullable();
            /*
             * The seed behind a generated avatar, when there is no uploaded
             * photo. Most accounts will never have one: a null seed means "use
             * the person's name", which is what makes every account have a
             * recognisable face from the moment it is created without anybody
             * being asked to choose one. A value here is a deliberate choice
             * made in the avatar picker.
             *
             * A seed, not a URL. The style, the framing and the provider are
             * presentation (resources/js/avatar.js), and baking a URL into a
             * column would mean every stored face had to be rewritten to change
             * any of them.
             */
            $table->string('avatar_seed')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();

            $table->index('name');

            // The list is ordered by surname; the section filter rides the
            // index MySQL already builds for the foreign key.
            $table->index(['last_name', 'first_name']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
