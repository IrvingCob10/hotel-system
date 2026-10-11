<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('first_name')->nullable()->change();
            $table->string('last_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Los registros que se crearon sin nombre se conservan como cadenas vacías
        // si la aplicación revierte esta migración y vuelve a exigir ambos campos.
        DB::table('guests')->whereNull('first_name')->update(['first_name' => '']);
        DB::table('guests')->whereNull('last_name')->update(['last_name' => '']);

        Schema::table('guests', function (Blueprint $table) {
            $table->string('first_name')->nullable(false)->change();
            $table->string('last_name')->nullable(false)->change();
        });
    }
};
