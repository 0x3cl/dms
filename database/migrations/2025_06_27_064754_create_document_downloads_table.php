<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_downloads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                  ->constrained()
                  ->onDelete('cascade');

            $table->foreignId('user_id')
                  ->constrained()
                  ->onDelete('cascade');

            // Fix: reference 'divisions' (plural)
            $table->unsignedBigInteger('division_id')->nullable();
            $table->foreign('division_id')
                  ->references('id')
                  ->on('divisions')
                  ->onDelete('set null');

            $table->unsignedBigInteger('section_id')->nullable();
            $table->foreign('section_id')
                  ->references('id')
                  ->on('sections')
                  ->onDelete('set null');

            $table->timestamp('downloaded_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_downloads');
    }
};
