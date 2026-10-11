<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();

            // Nombre comercial del plan
            $table->string('name');

            // Identificador interno
            $table->string('slug')->unique();

            // Descripción del plan
            $table->text('description')->nullable();

            // Precio mensual
            $table->decimal('monthly_price', 10, 2)->default(0);

            // Precio anual
            $table->decimal('yearly_price', 10, 2)->default(0);

            // Límites del plan
            $table->unsignedInteger('max_rooms')->nullable();
            $table->unsignedInteger('max_users')->nullable();

            // Estado
            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};