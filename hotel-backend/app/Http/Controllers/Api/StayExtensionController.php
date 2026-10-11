<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StayExtensionController extends Controller
{
    /**
     * Actualizar la salida prevista de una estancia activa.
     *
     * Si additional_charge viene vacío o null, solo se actualiza la fecha y
     * hora: no se inserta ningún cargo financiero. Si se envía explícitamente
     * 0, se registra un movimiento de extensión sin costo en el historial.
     */
    public function update(
        Request $request,
        Hotel $hotel,
        string $roomNumber,
        int $stayId
    ): JsonResponse {
        if (! $request->user()->hotels()->where('hotels.id', $hotel->id)->exists()) {
            return response()->json([
                'message' => 'No tienes acceso a este hotel.',
            ], 403);
        }

        // `filled` distingue un importe explícito de un campo vacío/null.
        // Un importe vacío permite corregir el horario sin generar un cargo.
        $registerCharge = $request->filled('additional_charge');

        $validated = $request->validate([
            'expected_check_out_at' => ['required', 'date'],
            'additional_charge' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'agreement_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $additionalCharge = $registerCharge
            ? round((float) ($validated['additional_charge'] ?? 0), 2)
            : 0.0;
        $agreementNotes = trim((string) ($validated['agreement_notes'] ?? ''));

        $result = DB::transaction(function () use (
            $hotel,
            $roomNumber,
            $stayId,
            $validated,
            $registerCharge,
            $additionalCharge,
            $agreementNotes
        ): array {
            $room = Room::query()
                ->where('hotel_id', $hotel->id)
                ->where('number', $roomNumber)
                ->where('active', true)
                ->lockForUpdate()
                ->firstOrFail();

            $stay = Stay::query()
                ->whereKey($stayId)
                ->where('hotel_id', $hotel->id)
                ->where('room_id', $room->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($stay->status !== 'active' || $room->status !== 'occupied') {
                throw ValidationException::withMessages([
                    'stay' => ['Solo se puede extender una estancia activa en una habitación ocupada.'],
                ]);
            }

            // El cliente convierte datetime-local a ISO 8601 con zona horaria,
            // así que Carbon compara el instante real y no una hora ambigua.
            $newCheckOut = Carbon::parse($validated['expected_check_out_at']);
            $now = now();

            if ($newCheckOut->lessThanOrEqualTo($now)) {
                throw ValidationException::withMessages([
                    'expected_check_out_at' => ['La nueva salida prevista debe ser posterior a la fecha y hora actuales.'],
                ]);
            }

            $previousCheckOut = $stay->expected_check_out_at;
            // Si se proporcionó un importe, la operación es una extensión real:
            // la nueva salida debe ser posterior a la anterior. Si el campo está
            // vacío, se trata de una corrección administrativa y puede ajustar
            // la hora hacia adelante o hacia atrás, siempre que siga siendo futura.
            if ($registerCharge && $previousCheckOut && $newCheckOut->lessThanOrEqualTo($previousCheckOut)) {
                throw ValidationException::withMessages([
                    'expected_check_out_at' => ['Para registrar una extensión con importe, la nueva salida debe ser posterior a la salida prevista actual. Si solo corriges un horario, deja el importe vacío.'],
                ]);
            }

            $previousLabel = $previousCheckOut
                ? $previousCheckOut->format('d/m/Y H:i')
                : 'sin fecha prevista';
            $newLabel = $newCheckOut->copy()->setTimezone(config('app.timezone'))->format('d/m/Y H:i');

            $stay->expected_check_out_at = $newCheckOut;
            $stay->save();

            $description = ($additionalCharge > 0
                ? 'Extensión de estancia'
                : 'Extensión de estancia sin cargo')
                . ': salida de ' . $previousLabel . ' a ' . $newLabel;

            $notes = 'Salida prevista anterior: ' . $previousLabel
                . '. Nueva salida prevista: ' . $newLabel
                . '. Cargo adicional acordado: $' . number_format($additionalCharge, 2, '.', ',') . '.';

            if ($agreementNotes !== '') {
                $notes .= ' Acuerdo/autorización: ' . $agreementNotes;
            } elseif ($additionalCharge <= 0) {
                $notes .= ' Extensión autorizada sin cargo adicional.';
            }

            // Solo crear un movimiento financiero cuando el usuario escribió
            // un importe. Si el campo quedó vacío, esto es una corrección
            // operativa de la fecha y no debe aparecer como cargo.
            $charge = null;
            if ($registerCharge) {
                $charge = $stay->charges()->create([
                    'type' => 'other',
                    'description' => $description,
                    'quantity' => 1,
                    'unit_price' => $additionalCharge,
                    'total' => $additionalCharge,
                    'notes' => $notes,
                ]);
            }

            $chargesTotal = round((float) $stay->charges()->sum('total'), 2);
            $paidTotal = round((float) $stay->payments()->sum('amount'), 2);

            return [
                'stay' => [
                    'id' => (int) $stay->id,
                    'status' => (string) $stay->status,
                    'expected_check_out_at' => $stay->expected_check_out_at,
                ],
                'charge' => $charge ? [
                    'id' => (int) $charge->id,
                    'type' => (string) $charge->type,
                    'description' => (string) $charge->description,
                    'quantity' => (float) $charge->quantity,
                    'unit_price' => (float) $charge->unit_price,
                    'total' => (float) $charge->total,
                    'created_at' => $charge->created_at,
                ] : null,
                'account' => [
                    'charges_total' => $chargesTotal,
                    'paid_total' => $paidTotal,
                    'balance' => max(0, round($chargesTotal - $paidTotal, 2)),
                ],
            ];
        });

        $message = ! $registerCharge
            ? 'Salida prevista actualizada correctamente. No se registró ningún cargo adicional.'
            : ($additionalCharge > 0
                ? 'Estancia extendida y cargo adicional registrado correctamente.'
                : 'Estancia extendida sin costo adicional. El movimiento quedó registrado en el historial.');

        return response()->json([
            'message' => $message,
            ...$result,
        ]);
    }
}
