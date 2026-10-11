<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('hotel_id')
                ->constrained()
                ->restrictOnDelete();

            // Null = gasto general del hotel.
            // Con valor = gasto asignado a una habitación.
            $table->foreignId('room_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Usuario que registró el gasto.
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('category', 50);

            $table->string('description');

            $table->decimal('amount', 10, 2);

            $table->date('expense_date');

            $table->string('payment_method', 30)->nullable();

            $table->string('vendor')->nullable();

            $table->string('reference')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['hotel_id', 'expense_date']);
            $table->index(['room_id', 'expense_date']);
            $table->index(['hotel_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};