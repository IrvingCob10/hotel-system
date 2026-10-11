<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();

            $table->foreignId('hotel_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('room_type_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('number');
            $table->unsignedInteger('floor')->nullable();

            $table->string('status')->default('available');

            $table->text('notes')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['hotel_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};