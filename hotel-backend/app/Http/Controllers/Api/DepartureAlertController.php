<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Stay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartureAlertController extends Controller
{
    /**
     * Salidas próximas (próximos 10 minutos) y salidas vencidas.
     * Las alertas se calculan a partir de las estancias activas; no se
     * cierra la estancia ni se cambia el estado de la habitación.
     */
    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        if (! $request->user()->hotels()->where('hotels.id', $hotel->id)->exists()) {
            return response()->json([
                'message' => 'No tienes acceso a este hotel.',
            ], 403);
        }

        $now = now();
        $cutoff = $now->copy()->addMinutes(10);

        $stays = Stay::query()
            ->where('hotel_id', $hotel->id)
            ->where('status', 'active')
            ->whereNotNull('expected_check_out_at')
            ->where('expected_check_out_at', '<=', $cutoff)
            ->with([
                'room:id,hotel_id,room_type_id,number,status',
                'room.roomType:id,name',
                'guest:id,first_name,last_name,phone',
            ])
            ->withSum('charges as charges_total', 'total')
            ->withSum('payments as paid_total', 'amount')
            ->orderBy('expected_check_out_at')
            ->get();

        $alerts = $stays->map(function (Stay $stay) use ($now): array {
            $expectedCheckout = $stay->expected_check_out_at;
            $secondsRemaining = $expectedCheckout->getTimestamp() - $now->getTimestamp();
            $isOverdue = $secondsRemaining <= 0;
            $chargesTotal = round((float) ($stay->charges_total ?? 0), 2);
            $paidTotal = round((float) ($stay->paid_total ?? 0), 2);

            return [
                'stay_id' => (int) $stay->id,
                'room_number' => (string) ($stay->room?->number ?? ''),
                'room_status' => (string) ($stay->room?->status ?? ''),
                'room_type' => (string) ($stay->room?->roomType?->name ?? ''),
                'guest_name' => trim(($stay->guest?->first_name ?? '') . ' ' . ($stay->guest?->last_name ?? '')) ?: 'Huésped sin nombre',
                'guest_phone' => $stay->guest?->phone,
                'expected_check_out_at' => $expectedCheckout->toIso8601String(),
                'type' => $isOverdue ? 'overdue' : 'upcoming',
                'minutes_until_checkout' => $isOverdue ? 0 : (int) ceil($secondsRemaining / 60),
                'minutes_overdue' => $isOverdue ? (int) ceil(abs($secondsRemaining) / 60) : 0,
                'charges_total' => $chargesTotal,
                'paid_total' => $paidTotal,
                'balance' => round($chargesTotal - $paidTotal, 2),
            ];
        });

        return response()->json([
            'server_time' => $now->toIso8601String(),
            'window_minutes' => 10,
            'summary' => [
                'total' => $alerts->count(),
                'overdue' => $alerts->where('type', 'overdue')->count(),
                'upcoming' => $alerts->where('type', 'upcoming')->count(),
            ],
            'alerts' => $alerts->values(),
        ]);
    }
}
