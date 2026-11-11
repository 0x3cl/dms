<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laravel's own database-notification table: the app notifies through
     * `Notifiable`, so the shape is the framework's and must stay that way.
     *
     * The one addition is the second index. The header bell runs two queries
     * on every page — the latest few for this user, and a count of their
     * unread ones — and `morphs()` alone indexes the notifiable pair without
     * `read_at`, so the unread count read every row the user had ever
     * received.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_notifiable_read_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
