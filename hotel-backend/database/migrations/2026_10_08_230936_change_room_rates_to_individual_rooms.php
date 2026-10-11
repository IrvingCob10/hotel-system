<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * 1. Agregar room_id solo si todavía no existe.
         * Esto permite continuar después del intento fallido.
         */
        if (! Schema::hasColumn('room_rates', 'room_id')) {
            Schema::table('room_rates', function (Blueprint $table) {
                $table->unsignedBigInteger('room_id')
                    ->nullable()
                    ->after('id');
            });
        }

        /*
         * 2. Asignar las tarifas existentes a una habitación.
         * Solo lo hacemos cuando la asignación es inequívoca.
         */
        if (Schema::hasColumn('room_rates', 'room_type_id')) {
            $rates = DB::table('room_rates')
                ->whereNull('room_id')
                ->get(['id', 'room_type_id']);

            foreach ($rates as $rate) {
                $rooms = DB::table('rooms')
                    ->where('room_type_id', $rate->room_type_id)
                    ->get(['id']);

                if ($rooms->count() !== 1) {
                    throw new \RuntimeException(
                        "No se pudo asignar la tarifa {$rate->id}. "
                        . 'El tipo de habitación no tiene exactamente '
                        . 'una habitación asociada. Debe revisarse '
                        . 'la asignación antes de continuar.'
                    );
                }

                DB::table('room_rates')
                    ->where('id', $rate->id)
                    ->update([
                        'room_id' => $rooms->first()->id,
                    ]);
            }

            if (DB::table('room_rates')->whereNull('room_id')->exists()) {
                throw new \RuntimeException(
                    'Existen tarifas sin habitación asignada.'
                );
            }
        }

        /*
         * 3. Eliminar primero la clave foránea antigua.
         * MySQL no permite eliminar antes el índice que necesita.
         */
        $oldForeignKeyExists = DB::table(
            'information_schema.KEY_COLUMN_USAGE'
        )
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'room_rates')
            ->where('COLUMN_NAME', 'room_type_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();

        if ($oldForeignKeyExists) {
            Schema::table('room_rates', function (Blueprint $table) {
                $table->dropForeign(['room_type_id']);
            });
        }

        /*
         * 4. Eliminar el índice antiguo, si todavía existe.
         */
        $oldIndexExists = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'room_rates')
            ->where('INDEX_NAME', 'room_rates_room_type_id_name_unique')
            ->exists();

        if ($oldIndexExists) {
            Schema::table('room_rates', function (Blueprint $table) {
                $table->dropUnique('room_rates_room_type_id_name_unique');
            });
        }

        /*
         * 5. Eliminar room_type_id cuando siga presente.
         */
        if (Schema::hasColumn('room_rates', 'room_type_id')) {
            Schema::table('room_rates', function (Blueprint $table) {
                $table->dropColumn('room_type_id');
            });
        }

        /*
         * 6. Convertir room_id en obligatorio.
         */
        Schema::table('room_rates', function (Blueprint $table) {
            $table->unsignedBigInteger('room_id')
                ->nullable(false)
                ->change();
        });

        /*
         * 7. Crear la clave foránea nueva, si aún no existe.
         */
        $newForeignKeyExists = DB::table(
            'information_schema.KEY_COLUMN_USAGE'
        )
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'room_rates')
            ->where('COLUMN_NAME', 'room_id')
            ->where('REFERENCED_TABLE_NAME', 'rooms')
            ->exists();

        if (! $newForeignKeyExists) {
            Schema::table('room_rates', function (Blueprint $table) {
                $table->foreign('room_id')
                    ->references('id')
                    ->on('rooms')
                    ->restrictOnDelete();
            });
        }

        /*
         * 8. Cada habitación puede tener sus propias tarifas.
         */
        $newIndexExists = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'room_rates')
            ->where('INDEX_NAME', 'room_rates_room_id_name_unique')
            ->exists();

        if (! $newIndexExists) {
            Schema::table('room_rates', function (Blueprint $table) {
                $table->unique(['room_id', 'name']);
            });
        }
    }

    public function down(): void
    {
        /*
         * La reversión devuelve las tarifas a room_types.
         * Primero eliminamos las restricciones de room_id.
         */
        $foreignKeyExists = DB::table(
            'information_schema.KEY_COLUMN_USAGE'
        )
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'room_rates')
            ->where('COLUMN_NAME', 'room_id')
            ->where('REFERENCED_TABLE_NAME', 'rooms')
            ->exists();

        if ($foreignKeyExists) {
            Schema::table('room_rates', function (Blueprint $table) {
                $table->dropForeign(['room_id']);
            });
        }

        $indexExists = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'room_rates')
            ->where('INDEX_NAME', 'room_rates_room_id_name_unique')
            ->exists();

        if ($indexExists) {
            Schema::table('room_rates', function (Blueprint $table) {
                $table->dropUnique('room_rates_room_id_name_unique');
            });
        }

        if (! Schema::hasColumn('room_rates', 'room_type_id')) {
            Schema::table('room_rates', function (Blueprint $table) {
                $table->unsignedBigInteger('room_type_id')
                    ->nullable()
                    ->after('id');
            });
        }

        DB::statement('
            UPDATE room_rates
            INNER JOIN rooms ON rooms.id = room_rates.room_id
            SET room_rates.room_type_id = rooms.room_type_id
        ');

        Schema::table('room_rates', function (Blueprint $table) {
            $table->unsignedBigInteger('room_type_id')
                ->nullable(false)
                ->change();
        });

        if (Schema::hasColumn('room_rates', 'room_id')) {
            Schema::table('room_rates', function (Blueprint $table) {
                $table->dropColumn('room_id');
            });
        }

        Schema::table('room_rates', function (Blueprint $table) {
            $table->foreign('room_type_id')
                ->references('id')
                ->on('room_types')
                ->restrictOnDelete();

            $table->unique(['room_type_id', 'name']);
        });
    }
};