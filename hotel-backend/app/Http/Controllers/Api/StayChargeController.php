<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Product;
use App\Models\Service;
use App\Models\Stay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StayChargeController extends Controller
{
    /**
     * Agregar un producto a una estancia activa.
     */
    public function addProduct(
        Request $request,
        Hotel $hotel,
        string $roomNumber,
        int $stayId
    ): JsonResponse {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json([
                'message' => 'No tienes acceso a este hotel.',
            ], 403);
        }

        $validated = $request->validate([
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')
                    ->where('hotel_id', $hotel->id)
                    ->where('active', true),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $room = $hotel->rooms()
            ->where('number', $roomNumber)
            ->firstOrFail();

        $stay = $room->stays()
            ->where('id', $stayId)
            ->where('hotel_id', $hotel->id)
            ->where('status', 'active')
            ->first();

        if (! $stay) {
            return response()->json([
                'message' => 'No existe una estancia activa en esta habitación.',
            ], 422);
        }

        $result = DB::transaction(function () use ($hotel, $stay, $validated) {
            $product = Product::query()
                ->where('hotel_id', $hotel->id)
                ->whereKey($validated['product_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $quantity = (int) $validated['quantity'];
            $stock = (int) $product->stock;

            if (! $product->active) {
                throw ValidationException::withMessages([
                    'product_id' => ['El producto no está disponible.'],
                ]);
            }

            if ($stock < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => ["Existencias insuficientes. Disponibles: {$stock}."],
                ]);
            }

            $unitPrice = (float) $product->price;
            $total = round($unitPrice * $quantity, 2);

            $product->decrement('stock', $quantity);

            $charge = $stay->charges()->create([
                'product_id' => $product->id,
                'type' => 'product',
                'description' => $product->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $total,
                'notes' => null,
            ]);

            $charge->inventory_deducted = true;
            $charge->save();

            return [
                'charge' => $charge->load('product'),
                'stock_remaining' => (int) $product->fresh()->stock,
                'account' => $this->accountSummary($stay),
            ];
        });

        return response()->json([
            'message' => 'Producto agregado correctamente.',
            ...$result,
        ], 201);
    }

    /**
     * Agregar un servicio a una estancia activa. No modifica inventario.
     */
    public function addService(
        Request $request,
        Hotel $hotel,
        string $roomNumber,
        int $stayId
    ): JsonResponse {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json([
                'message' => 'No tienes acceso a este hotel.',
            ], 403);
        }

        $validated = $request->validate([
            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')
                    ->where('hotel_id', $hotel->id)
                    ->where('active', true),
            ],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $room = $hotel->rooms()
            ->where('number', $roomNumber)
            ->firstOrFail();

        $stay = $room->stays()
            ->where('id', $stayId)
            ->where('hotel_id', $hotel->id)
            ->where('status', 'active')
            ->first();

        if (! $stay) {
            return response()->json([
                'message' => 'No existe una estancia activa en esta habitación.',
            ], 422);
        }

        $result = DB::transaction(function () use ($hotel, $stay, $validated) {
            $service = Service::query()
                ->where('hotel_id', $hotel->id)
                ->whereKey($validated['service_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $service->active) {
                throw ValidationException::withMessages([
                    'service_id' => ['El servicio no está disponible.'],
                ]);
            }

            $quantity = (int) $validated['quantity'];
            $unitPrice = (float) $service->price;
            $total = round($unitPrice * $quantity, 2);

            $charge = $stay->charges()->create([
                'service_id' => $service->id,
                'type' => 'service',
                'description' => $service->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $total,
                'notes' => null,
            ]);

            return [
                'charge' => $charge->load('service'),
                'account' => $this->accountSummary($stay),
            ];
        });

        return response()->json([
            'message' => 'Servicio agregado correctamente.',
            ...$result,
        ], 201);
    }

    /**
     * Registrar un pago o abono a una estancia activa.
     */
    public function addPayment(
        Request $request,
        Hotel $hotel,
        string $roomNumber,
        int $stayId
    ): JsonResponse {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json([
                'message' => 'No tienes acceso a este hotel.',
            ], 403);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'method' => ['required', Rule::in(['cash', 'card', 'transfer', 'other'])],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $room = $hotel->rooms()
            ->where('number', $roomNumber)
            ->firstOrFail();

        $stay = $room->stays()
            ->where('id', $stayId)
            ->where('hotel_id', $hotel->id)
            ->where('status', 'active')
            ->first();

        if (! $stay) {
            return response()->json([
                'message' => 'No existe una estancia activa en esta habitación.',
            ], 422);
        }

        $result = DB::transaction(function () use ($hotel, $room, $stay, $validated) {
            $lockedStay = Stay::query()
                ->whereKey($stay->id)
                ->where('hotel_id', $hotel->id)
                ->where('room_id', $room->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedStay->status !== 'active') {
                throw ValidationException::withMessages([
                    'stay' => ['La estancia ya no está activa.'],
                ]);
            }

            $chargesTotal = round((float) $lockedStay->charges()->sum('total'), 2);
            $paidTotal = round((float) $lockedStay->payments()->sum('amount'), 2);
            $balance = round($chargesTotal - $paidTotal, 2);
            $receivedAmount = round((float) $validated['amount'], 2);
            $method = (string) $validated['method'];

            if ($balance <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['La cuenta no tiene saldo pendiente.'],
                ]);
            }

            // Solo efectivo admite entregar más dinero que el saldo; el excedente
            // no se suma a la cuenta: se registra como cambio devuelto.
            if ($method !== 'cash' && $receivedAmount > $balance) {
                throw ValidationException::withMessages([
                    'amount' => ['El pago con tarjeta o transferencia no puede superar el saldo pendiente.'],
                ]);
            }

            $appliedAmount = $method === 'cash'
                ? min($receivedAmount, $balance)
                : $receivedAmount;
            $changeGiven = $method === 'cash'
                ? max(0, round($receivedAmount - $balance, 2))
                : 0.0;

            $payment = $lockedStay->payments()->create([
                'amount' => $appliedAmount,
                'method' => $method,
                'reference' => $validated['reference'] ?? null,
                'received_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            // Campos agregados por una migración aditiva. Se asignan después de
            // crear el pago para no depender del $fillable del modelo existente.
            $payment->amount_received = $method === 'cash' ? $receivedAmount : $appliedAmount;
            $payment->change_given = $changeGiven;
            $payment->save();

            $payment->refresh();

            return [
                'payment' => $payment,
                'account' => $this->accountSummary($lockedStay),
                'cash_summary' => [
                    'amount_received' => $method === 'cash' ? $receivedAmount : 0.0,
                    'amount_applied' => $appliedAmount,
                    'change_given' => $changeGiven,
                ],
            ];
        });

        $cashSummary = $result['cash_summary'];
        unset($result['cash_summary']);

        $message = $cashSummary['change_given'] > 0
            ? 'Pago registrado. Aplicado a la cuenta: $' . number_format($cashSummary['amount_applied'], 2, '.', ',')
                . '; efectivo recibido: $' . number_format($cashSummary['amount_received'], 2, '.', ',')
                . '; cambio a entregar: $' . number_format($cashSummary['change_given'], 2, '.', ',') . '.'
            : 'Pago registrado correctamente.';

        return response()->json([
            'message' => $message,
            ...$result,
        ], 201);
    }

    /**
     * Check-out: requiere que la cuenta esté totalmente liquidada.
     */
    public function checkout(
        Request $request,
        Hotel $hotel,
        string $roomNumber,
        int $stayId
    ): JsonResponse {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json([
                'message' => 'No tienes acceso a este hotel.',
            ], 403);
        }

        $room = $hotel->rooms()
            ->where('number', $roomNumber)
            ->firstOrFail();

        $result = DB::transaction(function () use ($hotel, $room, $stayId) {
            $lockedRoom = $hotel->rooms()
                ->whereKey($room->id)
                ->lockForUpdate()
                ->firstOrFail();

            $stay = Stay::query()
                ->whereKey($stayId)
                ->where('hotel_id', $hotel->id)
                ->where('room_id', $lockedRoom->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($stay->status !== 'active') {
                throw ValidationException::withMessages([
                    'stay' => ['La estancia no está activa o ya fue cerrada.'],
                ]);
            }

            $chargesTotal = round((float) $stay->charges()->sum('total'), 2);
            $paidTotal = round((float) $stay->payments()->sum('amount'), 2);
            $balance = round($chargesTotal - $paidTotal, 2);

            if ($balance > 0) {
                throw ValidationException::withMessages([
                    'balance' => [
                        'No se puede realizar el check-out. Saldo pendiente: $'
                        . number_format($balance, 2, '.', ',')
                        . '. Registra el pago restante primero.',
                    ],
                ]);
            }

            if ($balance < 0) {
                throw ValidationException::withMessages([
                    'balance' => [
                        'La cuenta presenta un saldo a favor. Revisa los cargos y pagos antes del check-out.',
                    ],
                ]);
            }

            $checkedOutAt = now();
            $stay->actual_check_out_at = $checkedOutAt;
            $stay->status = 'completed';
            $stay->save();

            $lockedRoom->status = 'cleaning';
            $lockedRoom->save();

            return [
                'stay' => [
                    'id' => $stay->id,
                    'status' => $stay->status,
                    'actual_check_out_at' => $checkedOutAt,
                ],
                'room' => [
                    'id' => $lockedRoom->id,
                    'number' => $lockedRoom->number,
                    'status' => $lockedRoom->status,
                ],
                'account' => [
                    'charges_total' => $chargesTotal,
                    'paid_total' => $paidTotal,
                    'balance' => $balance,
                ],
            ];
        });

        return response()->json([
            'message' => 'Check-out realizado correctamente. La habitación quedó en limpieza.',
            ...$result,
        ]);
    }

    /**
     * Actualizar la cantidad de un producto cargado.
     */
    public function updateQuantity(
        Request $request,
        Hotel $hotel,
        string $roomNumber,
        int $stayId,
        int $chargeId
    ): JsonResponse {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json([
                'message' => 'No tienes acceso a este hotel.',
            ], 403);
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $room = $hotel->rooms()
            ->where('number', $roomNumber)
            ->firstOrFail();

        $stay = $room->stays()
            ->where('id', $stayId)
            ->where('hotel_id', $hotel->id)
            ->where('status', 'active')
            ->first();

        if (! $stay) {
            return response()->json([
                'message' => 'La estancia no está activa en esta habitación.',
            ], 422);
        }

        $result = DB::transaction(function () use ($hotel, $stay, $chargeId, $validated) {
            $charge = $stay->charges()
                ->whereKey($chargeId)
                ->where('type', 'product')
                ->lockForUpdate()
                ->firstOrFail();

            if (! $charge->product_id) {
                throw ValidationException::withMessages([
                    'charge' => ['El cargo no está asociado a un producto.'],
                ]);
            }

            $product = Product::query()
                ->where('hotel_id', $hotel->id)
                ->whereKey($charge->product_id)
                ->lockForUpdate()
                ->firstOrFail();

            $newQuantity = (int) $validated['quantity'];
            $oldQuantity = (int) $charge->quantity;
            $inventoryChange = (bool) $charge->inventory_deducted
                ? $newQuantity - $oldQuantity
                : $newQuantity;

            if ($inventoryChange > 0) {
                if ((int) $product->stock < $inventoryChange) {
                    throw ValidationException::withMessages([
                        'quantity' => ['No hay existencias suficientes para aumentar la cantidad.'],
                    ]);
                }
                $product->decrement('stock', $inventoryChange);
            } elseif ($inventoryChange < 0) {
                $product->increment('stock', abs($inventoryChange));
            }

            $charge->quantity = $newQuantity;
            $charge->total = round((float) $charge->unit_price * $newQuantity, 2);
            $charge->inventory_deducted = true;
            $charge->save();

            return [
                'charge' => $charge->fresh()->load('product'),
                'stock_remaining' => (int) $product->fresh()->stock,
                'account' => $this->accountSummary($stay),
            ];
        });

        return response()->json([
            'message' => 'Cantidad actualizada correctamente.',
            ...$result,
        ]);
    }

    /**
     * Obtener los totales actuales de cargos, pagos y saldo.
     */
    private function accountSummary(Stay $stay): array
    {
        $chargesTotal = round((float) $stay->charges()->sum('total'), 2);
        $paidTotal = round((float) $stay->payments()->sum('amount'), 2);

        return [
            'charges_total' => $chargesTotal,
            'paid_total' => $paidTotal,
            'balance' => max(0, round($chargesTotal - $paidTotal, 2)),
        ];
    }

    /**
     * Verificar que el usuario autenticado tenga acceso al hotel.
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
