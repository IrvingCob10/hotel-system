<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stays', function (Blueprint $table) {
            $table->id();

            $table->foreignId('hotel_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('room_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('guest_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('room_rate_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('rate_name');
            $table->decimal('rate_price', 10, 2);

            $table->dateTime('check_in_at');
            $table->dateTime('expected_check_out_at')->nullable();
            $table->dateTime('actual_check_out_at')->nullable();

            $table->string('status')->default('active');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['hotel_id', 'status']);
            $table->index(['room_id', 'status']);
            $table->index(['guest_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stays');
    }
};