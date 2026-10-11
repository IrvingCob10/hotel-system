<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoomLifecycleController extends Controller
{
    /**
     * Obtener el detalle financiero de la estancia más reciente de una habitación.
     * Sirve para conservar visible el historial después del check-out.
     */
    public function latestStay(
        Request $request,
        Hotel $hotel,
        string $roomNumber
    ): JsonResponse {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json([
                'message' => 'No tienes acceso a este hotel.',
            ], 403);
        }

        $room = $hotel->rooms()
            ->where('number', $roomNumber)
            ->firstOrFail();

        $stay = $room->stays()
            ->where('hotel_id', $hotel->id)
            ->with([
                'guest',
                'charges.product',
                'charges.service',
                'payments',
            ])
            ->orderByDesc('check_in_at')
            ->orderByDesc('id')
            ->first();

        if (! $stay) {
            return response()->json(['stay' => null]);
        }

        $chargesTotal = round(
            (float) $stay->charges->sum(
                fn ($charge) => (float) $charge->total
            ),
            2
        );

        $paidTotal = round(
            (float) $stay->payments->sum(
                fn ($payment) => (float) $payment->amount
            ),
            2
        );

        $guest = $stay->guest;

        return response()->json([
            'stay' => [
                'id' => (int) $stay->id,
                'status' => (string) $stay->status,
                'guest' => [
                    'id' => $guest?->id,
                    'first_name' => $guest?->first_name ?? '',
                    'last_name' => $guest?->last_name ?? '',
                    'phone' => $guest?->phone,
                    'email' => $guest?->email,
                ],
                'check_in_at' => $stay->check_in_at,
                'expected_check_out_at' => $stay->expected_check_out_at,
                'actual_check_out_at' => $stay->actual_check_out_at,
                'rate_name' => (string) $stay->rate_name,
                'rate_price' => (float) $stay->rate_price,
                'charges' => $stay->charges->map(function ($charge) {
                    return [
                        'id' => (int) $charge->id,
                        'type' => (string) $charge->type,
                        'description' => (string) $charge->description,
                        'quantity' => (float) $charge->quantity,
                        'unit_price' => (float) $charge->unit_price,
                        'total' => (float) $charge->total,
                        'product' => $charge->product ? [
                            'id' => (int) $charge->product->id,
                            'name' => (string) $charge->product->name,
                        ] : null,
                        'service' => $charge->service ? [
                            'id' => (int) $charge->service->id,
                            'name' => (string) $charge->service->name,
                        ] : null,
                    ];
                })->values(),
                'payments' => $stay->payments
                    ->sortByDesc('received_at')
                    ->values()
                    ->map(function ($payment) {
                        return [
                            'id' => (int) $payment->id,
                            'amount' => (float) $payment->amount,
                            'method' => (string) $payment->method,
                            'reference' => $payment->reference,
                            'received_at' => $payment->received_at,
                            'notes' => $payment->notes,
                        ];
                    }),
                'account' => [
                    'charges_total' => $chargesTotal,
                    'paid_total' => $paidTotal,
                    'balance' => max(0, round($chargesTotal - $paidTotal, 2)),
                ],
            ],
        ]);
    }

    /**
     * Completar la limpieza y dejar la habitación disponible.
     */
    public function completeCleaning(
        Request $request,
        Hotel $hotel,
        string $roomNumber
    ): JsonResponse {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json([
                'message' => 'No tienes acceso a este hotel.',
            ], 403);
        }

        $result = DB::transaction(function () use ($hotel, $roomNumber) {
            $room = Room::query()
                ->where('hotel_id', $hotel->id)
                ->where('number', $roomNumber)
                ->lockForUpdate()
                ->firstOrFail();

            if ($room->status !== 'cleaning') {
                throw ValidationException::withMessages([
                    'room' => [
                        'Solo se puede finalizar la limpieza de una habitación que esté en estado cleaning.',
                    ],
                ]);
            }

            $hasActiveStay = $room->stays()
                ->where('status', 'active')
                ->exists();

            if ($hasActiveStay) {
                throw ValidationException::withMessages([
                    'room' => [
                        'No se puede liberar la habitación porque tiene una estancia activa.',
                    ],
                ]);
            }

            $room->status = 'available';
            $room->save();

            return [
                'id' => (int) $room->id,
                'number' => (string) $room->number,
                'status' => (string) $room->status,
            ];
        });

        return response()->json([
            'message' => 'Limpieza completada. La habitación está disponible.',
            'room' => $result,
        ]);
    }

    /** Verificar que el usuario autenticado tenga acceso al hotel. */
    private function userHasHotelAccess(
        Request $request,
        Hotel $hotel
    ): bool {
        return $request->user()
            ->hotels()
            ->where('hotels.id', $hotel->id)
            ->exists();
    }
}
