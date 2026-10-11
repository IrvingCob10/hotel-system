<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotels', function (Blueprint $table) {
            $table->id();

            // Customer / propietario del hotel
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            // Información principal
            $table->string('name');
            $table->string('commercial_name')->nullable();

            // Información de contacto
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // Dirección
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->default('Mexico');
            $table->string('postal_code')->nullable();

            // Configuración
            $table->string('timezone')->default('America/Merida');

            // Logotipo
            $table->string('logo')->nullable();

            // Estado
            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotels');
    }
};