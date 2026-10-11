<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stay_id')
                ->constrained()
                ->restrictOnDelete();

            $table->decimal('amount', 10, 2);

            $table->string('method', 30);

            $table->string('reference')->nullable();

            $table->dateTime('received_at');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['stay_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};