<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            // Customer / propietario de uno o varios hoteles
            $table->string('name');

            // Razón social
            $table->string('legal_name')->nullable();

            // RFC
            $table->string('tax_id')->nullable();

            // Información de contacto
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // Estado del cliente
            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};