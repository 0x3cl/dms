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
    $table->unsignedBigInteger('target_division_id')->nullable();
    $table->unsignedBigInteger('target_section_id')->nullable();

    $table->foreign('target_division_id')->references('id')->on('divisions')->nullOnDelete();
    $table->foreign('target_section_id')->references('id')->on('sections')->nullOnDelete();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            //
        });
    }
};
