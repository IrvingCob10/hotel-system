<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\RoomRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomRateController extends Controller
{
    /** Lista el catálogo global de tarifas del hotel; no se agrupa por habitación física. */
    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json(['message' => 'No tienes acceso a este hotel.'], 403);
        }

        $rates = RoomRate::query()
            ->where('hotel_id', $hotel->id)
            ->whereNull('room_id')
            ->where('name', 'not like', 'MANUAL#%')
            ->where('name', 'not like', 'LEGACY#%')
            ->orderByDesc('active')
            ->orderBy('duration')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json(['rates' => $rates]);
    }

    /** Crea una tarifa para todo el hotel, disponible desde cualquier habitación. */
    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json(['message' => 'No tienes acceso a este hotel.'], 403);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                'not_regex:/^(MANUAL|LEGACY)#/i',
                Rule::unique('room_rates', 'name')->where(
                    fn ($query) => $query->where('hotel_id', $hotel->id)->whereNull('room_id')
                ),
            ],
            'duration' => ['required', 'integer', 'min:1', 'max:10000'],
            'unit' => ['required', Rule::in(['minutos', 'horas', 'días', 'noches', 'semanas'])],
            'price' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $rate = RoomRate::create([
            'hotel_id' => $hotel->id,
            'room_id' => null,
            'name' => trim($validated['name']),
            'duration' => (int) $validated['duration'],
            'unit' => $validated['unit'],
            'price' => round((float) $validated['price'], 2),
            'active' => $validated['active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Tarifa creada para todo el hotel.',
            'rate' => $rate,
        ], 201);
    }

    /** Actualiza una tarifa global; los importes de estancias ya iniciadas permanecen intactos. */
    public function update(Request $request, Hotel $hotel, int $rateId): JsonResponse
    {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json(['message' => 'No tienes acceso a este hotel.'], 403);
        }

        $rate = RoomRate::query()
            ->whereKey($rateId)
            ->where('hotel_id', $hotel->id)
            ->whereNull('room_id')
            ->where('name', 'not like', 'MANUAL#%')
            ->where('name', 'not like', 'LEGACY#%')
            ->firstOrFail();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                'not_regex:/^(MANUAL|LEGACY)#/i',
                Rule::unique('room_rates', 'name')
                    ->where(fn ($query) => $query->where('hotel_id', $hotel->id)->whereNull('room_id'))
                    ->ignore($rate->id),
            ],
            'duration' => ['required', 'integer', 'min:1', 'max:10000'],
            'unit' => ['required', Rule::in(['minutos', 'horas', 'días', 'noches', 'semanas'])],
            'price' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'active' => ['required', 'boolean'],
        ]);

        $rate->update([
            'name' => trim($validated['name']),
            'duration' => (int) $validated['duration'],
            'unit' => $validated['unit'],
            'price' => round((float) $validated['price'], 2),
            'active' => (bool) $validated['active'],
        ]);

        return response()->json([
            'message' => 'Tarifa actualizada correctamente. Las estancias existentes conservan el precio contratado.',
            'rate' => $rate->fresh(),
        ]);
    }

    private function userHasHotelAccess(Request $request, Hotel $hotel): bool
    {
        return $request->user()
            ->hotels()
            ->where('hotels.id', $hotel->id)
            ->exists();
    }
}
