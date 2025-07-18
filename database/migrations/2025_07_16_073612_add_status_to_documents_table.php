<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('documents', function (Blueprint $table) {
        // Add a status column, defaulting to 'active'
        $table->string('status')->default('active')->after('type');
    });
}

public function down(): void
{
    Schema::table('documents', function (Blueprint $table) {
        $table->dropColumn('status');
    });
}
};
