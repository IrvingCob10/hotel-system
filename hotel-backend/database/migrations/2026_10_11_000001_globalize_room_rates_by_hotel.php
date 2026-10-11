<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('room_rates') || ! Schema::hasColumn('room_rates', 'room_id')) {
            throw new RuntimeException(
                'No se modificó la base de datos: se esperaba room_rates.room_id, que es la columna usada por la base actual del proyecto.'
            );
        }

        if (! Schema::hasColumn('room_rates', 'hotel_id')) {
            Schema::table('room_rates', function (Blueprint $table) {
                $table->foreignId('hotel_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('hotels')
                    ->restrictOnDelete();
            });
        }

        // Asigna el hotel de la habitación a las tarifas históricas que todavía no tenían hotel_id.
        $legacyRates = DB::table('room_rates')
            ->whereNull('hotel_id')
            ->whereNotNull('room_id')
            ->get(['id', 'room_id']);

        foreach ($legacyRates as $legacyRate) {
            $hotelId = DB::table('rooms')->where('id', $legacyRate->room_id)->value('hotel_id');

            if (! $hotelId) {
                throw new RuntimeException(
                    "No se pudo identificar el hotel de la tarifa antigua ID {$legacyRate->id}. No continúes hasta revisar esa fila."
                );
            }

            DB::table('room_rates')
                ->where('id', $legacyRate->id)
                ->update(['hotel_id' => $hotelId]);
        }

        $unassigned = DB::table('room_rates')->whereNull('hotel_id')->count();
        if ($unassigned > 0) {
            throw new RuntimeException(
                "Hay {$unassigned} tarifa(s) sin hotel_id que no se pudieron relacionar con una habitación. No se eliminaron registros; revisa room_rates antes de continuar."
            );
        }

        // room_id se conserva para filas MANUAL# asociadas a una estancia y para las filas históricas.
        // Las tarifas globales usan room_id = NULL.
        Schema::table('room_rates', function (Blueprint $table) {
            $table->unsignedBigInteger('room_id')->nullable()->change();
        });

        // Migra las tarifas por habitación a un catálogo global por hotel.
        // Si existe más de una fila con el mismo nombre, deja una sola global y desactiva las antiguas.
        // Se prioriza una tarifa activa cuyo nombre coincida con su duración/unidad (p. ej. "24 horas").
        $rows = DB::table('room_rates')
            ->whereNotNull('hotel_id')
            ->orderBy('hotel_id')
            ->orderBy('id')
            ->get(['id', 'hotel_id', 'room_id', 'name', 'duration', 'unit', 'price', 'active']);

        $groups = [];
        foreach ($rows as $row) {
            $name = trim((string) $row->name);
            if (str_starts_with(strtoupper($name), 'MANUAL#') || str_starts_with(strtoupper($name), 'LEGACY#')) {
                continue;
            }

            $key = (int) $row->hotel_id . '|' . mb_strtolower($name, 'UTF-8');
            $groups[$key][] = $row;
        }

        foreach ($groups as $group) {
            usort($group, function ($a, $b) {
                $score = static function ($row): int {
                    $expectedName = mb_strtolower(trim((string) $row->duration . ' ' . (string) $row->unit), 'UTF-8');
                    $actualName = mb_strtolower(trim((string) $row->name), 'UTF-8');
                    $activePenalty = (bool) $row->active ? 0 : 100;
                    $roomPenalty = $row->room_id === null ? 0 : 10;
                    $labelPenalty = $actualName === $expectedName ? 0 : 1;
                    return $activePenalty + $roomPenalty + $labelPenalty;
                };

                $difference = $score($a) <=> $score($b);
                return $difference !== 0 ? $difference : ((int) $a->id <=> (int) $b->id);
            });

            $canonical = $group[0];
            DB::table('room_rates')->where('id', $canonical->id)->update([
                'room_id' => null,
                'hotel_id' => $canonical->hotel_id,
                'name' => trim((string) $canonical->name),
            ]);

            foreach (array_slice($group, 1) as $duplicate) {
                // No se eliminan filas con posibles referencias desde stays.room_rate_id.
                // Si ya era una fila global duplicada, se marca como LEGACY# y se desactiva.
                $updates = ['active' => false];
                if ($duplicate->room_id === null) {
                    $updates['name'] = 'LEGACY#' . $duplicate->id;
                }

                DB::table('room_rates')->where('id', $duplicate->id)->update($updates);
            }
        }

        // Las tarifas manuales conservan room_id, pero también quedan asociadas a su hotel.
    }

    public function down(): void
    {
        // Esta migración reubica tarifas y consolida duplicados sin eliminar sus filas.
        // No es seguro revertirla automáticamente porque se perdería el alcance global por hotel.
        throw new RuntimeException(
            'La migración de tarifas globales no se revierte automáticamente para evitar re-asignar precios a habitaciones arbitrarias. Restaura una copia de seguridad si necesitas volver al esquema anterior.'
        );
    }
};
