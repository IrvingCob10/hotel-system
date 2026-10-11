<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_rates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('room_type_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('name');

            $table->unsignedInteger('duration');

            $table->string('unit', 20);

            $table->decimal('price', 10, 2);

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['room_type_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_rates');
    }
};