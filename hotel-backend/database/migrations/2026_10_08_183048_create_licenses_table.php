<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();

            // Hotel al que pertenece la licencia
            $table->foreignId('hotel_id')
                ->constrained('hotels')
                ->cascadeOnDelete();

            // Plan contratado
            $table->foreignId('plan_id')
                ->constrained('plans')
                ->restrictOnDelete();

            // Identificador único de la licencia
            $table->string('license_key')->unique();

            // Estado de la licencia
            $table->string('status')->default('active');

            // Vigencia
            $table->dateTime('starts_at');
            $table->dateTime('expires_at');

            // Periodo de gracia posterior al vencimiento
            $table->dateTime('grace_until')->nullable();

            // Renovación automática
            $table->boolean('auto_renew')->default(false);

            // Indica si es una licencia de prueba
            $table->boolean('is_trial')->default(false);

            // Fecha de cancelación
            $table->dateTime('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['hotel_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};