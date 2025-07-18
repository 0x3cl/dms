<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Add columns first
            $table->unsignedBigInteger('division_id')->nullable()->after('id');
            $table->unsignedBigInteger('section_id')->nullable()->after('division_id');

            // Then add foreign key constraints with correct table names
            $table->foreign('division_id')
                  ->references('id')
                  ->on('divisions')  // ✔️ Corrected: was 'division'
                  ->onDelete('set null');

            $table->foreign('section_id')
                  ->references('id')
                  ->on('sections')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['division_id']);
            $table->dropForeign(['section_id']);
            $table->dropColumn(['division_id', 'section_id']);
        });
    }
};
