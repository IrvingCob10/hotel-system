<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    /**
     * Listar habitaciones de un hotel.
     */
    public function index(
        Request $request,
        Hotel $hotel
    ): JsonResponse {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json([
                'message' => 'No tienes acceso a este hotel.',
            ], 403);
        }

        $rooms = $hotel->rooms()
            ->with('roomType')
            ->orderBy('number')
            ->get();

        return response()->json($rooms);
    }

    /**
     * Registrar una habitación física. Las tarifas se administran aparte a nivel hotel.
     */
    public function store(
        Request $request,
        Hotel $hotel
    ): JsonResponse {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json([
                'message' => 'No tienes acceso a este hotel.',
            ], 403);
        }

        $validated = $request->validate([
            'room_type_id' => [
                'required',
                'integer',
                'exists:room_types,id',
            ],
            'number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('rooms', 'number')
                    ->where('hotel_id', $hotel->id),
            ],
            'floor' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'status' => [
                'nullable',
                'in:available,occupied,cleaning,maintenance,out_of_service',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ]
        ]);

        $roomType = $hotel->roomTypes()
            ->whereKey($validated['room_type_id'])
            ->where('active', true)
            ->first();

        if (! $roomType) {
            return response()->json([
                'message' => 'Selecciona un tipo de habitación activo que pertenezca a este hotel.',
            ], 422);
        }

        $room = DB::transaction(function () use ($hotel, $validated, $roomType) {
            $room = $hotel->rooms()->create([
                'room_type_id' => $roomType->id,
                'number' => trim($validated['number']),
                'floor' => $validated['floor'] ?? null,
                'status' => $validated['status'] ?? 'available',
                'notes' => isset($validated['notes'])
                    ? (trim($validated['notes']) ?: null)
                    : null,
                'active' => true,
            ]);

            return $room->load('roomType');
        });

        return response()->json([
            'message' => 'Habitación creada correctamente. Las tarifas se administran desde el catálogo general del hotel.',
            'room' => $room,
        ], 201);
    }

    /**
     * Actualizar los datos descriptivos de una habitación.
     * Nunca modifica el estado operativo ni las tarifas existentes.
     */
    public function update(
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

        $validated = $request->validate([
            'number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('rooms', 'number')
                    ->where('hotel_id', $hotel->id)
                    ->ignore($room->id),
            ],
            'room_type_id' => [
                'required',
                'integer',
                'exists:room_types,id',
            ],
            'floor' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $roomType = $hotel->roomTypes()
            ->whereKey($validated['room_type_id'])
            ->first();

        if (! $roomType) {
            return response()->json([
                'message' => 'El tipo de habitación no pertenece a este hotel.',
            ], 422);
        }

        $activeStayExists = $room->stays()
            ->where('status', 'active')
            ->exists();

        $identityChanged = trim($validated['number']) !== $room->number
            || (int) $validated['room_type_id'] !== (int) $room->room_type_id;

        if ($activeStayExists && $identityChanged) {
            return response()->json([
                'message' => 'No puedes cambiar el número ni el tipo mientras la habitación tenga una estancia activa. Actualiza el piso o las notas y vuelve a intentarlo.',
            ], 422);
        }

        if (! $roomType->active && (int) $validated['room_type_id'] !== (int) $room->room_type_id) {
            return response()->json([
                'message' => 'No puedes asignar un tipo inactivo a la habitación. Selecciona un tipo activo.',
            ], 422);
        }

        $room->fill([
            'number' => trim($validated['number']),
            'room_type_id' => (int) $validated['room_type_id'],
            'floor' => $validated['floor'] ?? null,
            'notes' => isset($validated['notes'])
                ? (trim($validated['notes']) ?: null)
                : null,
        ]);
        $room->save();
        $room->load(['roomType', 'rates']);

        return response()->json([
            'message' => 'Datos de habitación actualizados correctamente.',
            'room' => $room,
        ]);
    }

    /**
     * Consultar detalle operativo y financiero.
     */
    public function show(
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
            ->with(['roomType', 'rates'])
            ->where('number', $roomNumber)
            ->firstOrFail();

        $stay = $room->stays()
            ->where('status', 'active')
            ->with([
                'guest',
                'charges.product',
                'charges.service',
                'payments',
            ])
            ->orderByDesc('check_in_at')
            ->first();

        $chargesTotal = $stay
            ? round((float) $stay->charges->sum('total'), 2)
            : 0.0;

        $paidTotal = $stay
            ? round((float) $stay->payments->sum('amount'), 2)
            : 0.0;

        $balance = round($chargesTotal - $paidTotal, 2);

        // Gastos operativos de esta habitación.
        $expenses = $room->expenses()
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get();

        $roomExpensesTotal = round(
            (float) $expenses->sum('amount'),
            2
        );

        // Historial de estancias.
        $history = $room->stays()
            ->with('guest')
            ->withSum('charges', 'total')
            ->withSum('payments', 'amount')
            ->orderByDesc('check_in_at')
            ->limit(20)
            ->get()
            ->map(function ($item) {
                $total = round(
                    (float) ($item->charges_sum_total ?? 0),
                    2
                );

                $paid = round(
                    (float) ($item->payments_sum_amount ?? 0),
                    2
                );

                return [
                    'id' => $item->id,
                    'guest_name' => trim(
                        ($item->guest?->first_name ?? '') . ' ' .
                        ($item->guest?->last_name ?? '')
                    ),
                    'check_in_at' => $item->check_in_at,
                    'expected_check_out_at' =>
                        $item->expected_check_out_at,
                    'actual_check_out_at' =>
                        $item->actual_check_out_at,
                    'status' => $item->status,
                    'rate_name' => $item->rate_name,
                    'rate_price' => (float) $item->rate_price,
                    'charges_total' => $total,
                    'paid_total' => $paid,
                    'balance' => max(0, round($total - $paid, 2)),
                ];
            });

        return response()->json([
            'hotel' => [
                'id' => $hotel->id,
                'name' => $hotel->name,
            ],

            'room' => $room,

            'current_stay' => $stay ? [
                'id' => $stay->id,
                'status' => $stay->status,

                'guest' => [
                    'id' => $stay->guest->id,
                    'first_name' => $stay->guest->first_name,
                    'last_name' => $stay->guest->last_name,
                    'phone' => $stay->guest->phone,
                    'email' => $stay->guest->email,
                ],

                'check_in_at' => $stay->check_in_at,
                'expected_check_out_at' =>
                    $stay->expected_check_out_at,

                'rate_name' => $stay->rate_name,
                'rate_price' => (float) $stay->rate_price,

                'charges' => $stay->charges
                    ->map(fn ($charge) => [
                        'id' => $charge->id,
                        'type' => $charge->type,
                        'description' => $charge->description,
                        'quantity' => (float) $charge->quantity,
                        'unit_price' => (float) $charge->unit_price,
                        'total' => (float) $charge->total,
                        'product' => $charge->product,
                        'service' => $charge->service,
                    ])
                    ->values(),

                'payments' => $stay->payments
                    ->map(fn ($payment) => [
                        'id' => $payment->id,
                        'amount' => (float) $payment->amount,
                        'method' => $payment->method,
                        'reference' => $payment->reference,
                        'received_at' => $payment->received_at,
                    ])
                    ->values(),

                'account' => [
                    'charges_total' => $chargesTotal,
                    'paid_total' => $paidTotal,
                    'balance' => max(0, $balance),
                ],
            ] : null,

            'room_expenses' => [
                'total' => $roomExpensesTotal,
                'items' => $expenses,
            ],

            'stay_history' => $history,
        ]);
    }

    /**
     * Comprobar acceso del usuario al hotel.
     */
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