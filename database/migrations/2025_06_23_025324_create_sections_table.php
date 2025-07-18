<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('division_id');
            $table->timestamps();

            // 👇 Make sure to match the correct table name: 'divisions'
            $table->foreign('division_id')->references('id')->on('divisions')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sections');
    }
};
