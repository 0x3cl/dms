<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('documents', function (Blueprint $table) {
            // Only add columns that are not already in the table
            // REMOVE or COMMENT OUT this line because 'code' already exists:
            // $table->string('code')->unique()->nullable();

            // If 'qr_code_path' already exists in the original migration, remove this too
            // $table->string('qr_code_path')->nullable();

            // If you're adding something else new, keep only that
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Optional: Drop columns here if needed, but make sure they exist
            // $table->dropColumn('code');
            // $table->dropColumn('qr_code_path');
        });
    }
};
