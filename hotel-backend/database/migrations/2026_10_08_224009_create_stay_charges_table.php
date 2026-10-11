<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_charges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stay_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('type', 20);

            $table->string('description');

            $table->decimal('quantity', 10, 2)->default(1);

            $table->decimal('unit_price', 10, 2);

            $table->decimal('total', 10, 2);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['stay_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_charges');
    }
};