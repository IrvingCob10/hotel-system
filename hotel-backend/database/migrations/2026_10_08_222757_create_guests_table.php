<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('hotel_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('first_name');
            $table->string('last_name');

            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            $table->string('document_type')->nullable();
            $table->string('document_number')->nullable();

            $table->text('notes')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index(['hotel_id', 'last_name']);
            $table->index(['hotel_id', 'document_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};