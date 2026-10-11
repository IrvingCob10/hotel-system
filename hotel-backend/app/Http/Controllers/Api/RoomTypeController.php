<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomTypeController extends Controller
{
    /**
     * Lista los tipos de habitación de este hotel.
     * La operación normal devuelve solo activos para el formulario de habitaciones.
     * include_inactive=1 permite al dueño administrar también los inactivos.
     */
    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json(['message' => 'No tienes acceso a este hotel.'], 403);
        }

        $query = $hotel->roomTypes()->orderBy('name');
        if (! $request->boolean('include_inactive')) {
            $query->where('active', true);
        }

        return response()->json($query->get([
            'id', 'hotel_id', 'name', 'description', 'capacity', 'active',
        ]));
    }

    /** Crear un tipo exclusivamente dentro del hotel autorizado. */
    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json(['message' => 'No tienes acceso a este hotel.'], 403);
        }

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('room_types', 'name')->where('hotel_id', $hotel->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $roomType = $hotel->roomTypes()->create([
            'name' => trim($validated['name']),
            'description' => $this->nullableTrim($validated['description'] ?? null),
            'capacity' => (int) $validated['capacity'],
            'active' => true,
        ]);

        return response()->json([
            'message' => 'Tipo de habitación creado correctamente.',
            'room_type' => $roomType,
        ], 201);
    }

    /** Actualizar un tipo de habitación del hotel; no elimina registros históricos. */
    public function update(Request $request, Hotel $hotel, int $roomTypeId): JsonResponse
    {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json(['message' => 'No tienes acceso a este hotel.'], 403);
        }

        $roomType = $hotel->roomTypes()->whereKey($roomTypeId)->firstOrFail();

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('room_types', 'name')
                    ->where('hotel_id', $hotel->id)
                    ->ignore($roomType->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'active' => ['required', 'boolean'],
        ]);

        $roomType->update([
            'name' => trim($validated['name']),
            'description' => $this->nullableTrim($validated['description'] ?? null),
            'capacity' => (int) $validated['capacity'],
            'active' => (bool) $validated['active'],
        ]);

        return response()->json([
            'message' => 'Tipo de habitación actualizado correctamente.',
            'room_type' => $roomType->fresh(),
        ]);
    }

    private function userHasHotelAccess(Request $request, Hotel $hotel): bool
    {
        return $request->user()
            ->hotels()
            ->where('hotels.id', $hotel->id)
            ->exists();
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
