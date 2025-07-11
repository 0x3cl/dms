<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('employees', function (Blueprint $table) {
        $table->id();
        $table->string('employee_id')->unique(); // custom employee ID
        $table->string('first_name');
        $table->string('last_name');
        $table->string('email')->unique();
        $table->unsignedBigInteger('section_id');
        $table->timestamps();

        // Foreign key constraint
        $table->foreign('section_id')->references('id')->on('sections')->onDelete('cascade');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
