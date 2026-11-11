<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The narrower question behind /admin/download-logs: whose hands a *copy*
     * of the file ended up in. A download writes a row here and an entry in
     * `document_logs`, and `document_log_id` ties the two together so the
     * report can point at the trail entry that produced the copy.
     *
     * The two tables are deliberately not merged. `document_logs` is the
     * document's readable history, shown to everyone who can read the
     * document; this is a register of file releases, and it carries what a
     * release needs — the IP the copy went to — which has no business being
     * rendered on a document page.
     *
     * Like the trail, it snapshots the person and their office rather than
     * joining live rows: a download register that forgets who downloaded once
     * their account is deleted answers no question worth asking. Snapshots are
     * also why the report's search can read one table instead of running two
     * `whereHas` subqueries per keystroke.
     *
     * `downloaded_at` is what the report sorts on, and it had no index.
     */
    public function up(): void
    {
        Schema::create('document_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_log_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name');
            $table->string('user_employee_id')->nullable();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->string('division_name')->nullable();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->string('section_name')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('downloaded_at');
            $table->timestamps();

            $table->index('downloaded_at');
            $table->index(['document_id', 'downloaded_at']);
            $table->index(['user_id', 'downloaded_at']);
            $table->index('user_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_downloads');
    }
};
