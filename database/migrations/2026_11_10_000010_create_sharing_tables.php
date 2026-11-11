<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a file or folder has been shared with, and the links handed out for it.
 *
 * Two deliberate differences from the audit tables, which snapshot their actor
 * and keep every foreign key `nullOnDelete`:
 *
 * - **The grantee foreign keys cascade.** A share is not a record of something
 *   that happened; it is a live grant. A share whose grantee no longer exists
 *   grants access to nobody, and leaving it as a half-row would mean every
 *   resolver and every listing query had to special-case a null grantee —
 *   which is exactly the kind of branch that eventually gets one `OR` wrong and
 *   fails open. The revocation is still recorded, in the activity log.
 *
 * - **`granted_by_name` is snapshotted** beside a `nullOnDelete` key, because
 *   "who gave me this" has to stay answerable after that person leaves.
 *
 * A "group" is a role (`grantee_type = 'role'`). That reuses the membership the
 * roles screen already manages rather than adding a second, parallel notion of
 * a group that someone has to keep in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_shares', function (Blueprint $table) {
            $table->id();

            // Morphs to App\Models\File or App\Models\Folder. A folder share is
            // inherited by everything beneath it; see AccessResolver.
            $table->morphs('shareable');

            $table->string('grantee_type', 16);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('division_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('role_id')->nullable();

            // view | download | manage — App\Enums\ShareAbility.
            $table->string('ability', 16)->default('view');

            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('granted_by_name')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            /*
             * One grant per target. Without it the same division can be added
             * twice with different abilities and the effective answer depends
             * on row order — which is the bug `document_recipients` had before
             * it gained its unique pair.
             */
            $table->unique(
                ['shareable_type', 'shareable_id', 'grantee_type', 'user_id', 'division_id', 'section_id', 'role_id'],
                'item_shares_target_unique',
            );

            // The two directions this is read: "what is shared with me" and
            // "who is this shared with".
            $table->index(['grantee_type', 'user_id']);
            $table->index(['grantee_type', 'division_id']);
            $table->index(['grantee_type', 'section_id']);
            $table->index(['shareable_type', 'shareable_id', 'revoked_at']);

            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::create('share_links', function (Blueprint $table) {
            $table->id();

            // 64 characters of CSPRNG. The link *is* the credential, so it has
            // to be long enough that holding one is the only way to have it.
            $table->char('token', 64)->unique()->collation('ascii_bin');

            $table->morphs('shareable');

            // Only view or download. A link never grants write and never grants
            // manage: an anonymous holder of a URL must not be able to rename,
            // move, delete or re-share what it points at.
            $table->string('ability', 16)->default('view');

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('max_views')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['shareable_type', 'shareable_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_links');
        Schema::dropIfExists('item_shares');
    }
};
