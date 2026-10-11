<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\RoomRate;
use App\Models\Stay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Closure;

class StayController extends Controller
{
    /**
     * Listar huéspedes activos del hotel. Acepta búsqueda por nombre,
     * teléfono, correo o documento y limita el resultado para la interfaz.
     */
    public function guests(Request $request, Hotel $hotel): JsonResponse
    {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json(['message' => 'No tienes acceso a este hotel.'], 403);
        }

        $search = trim((string) $request->query('search', ''));

        $query = Guest::query()
            ->where('hotel_id', $hotel->id)
            ->where('active', true);

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($builder) use ($like) {
                $builder->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('document_number', 'like', $like);
            });
        }

        $guests = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(100)
            ->get([
                'id',
                'first_name',
                'last_name',
                'phone',
                'email',
                'document_type',
                'document_number',
            ]);

        return response()->json(['guests' => $guests]);
    }

    /**
     * Registrar el check-in, crear el cargo inicial de habitación y ocuparla.
     * Todo se procesa en una transacción para no dejar estados parciales.
     */
    public function checkIn(
        Request $request,
        Hotel $hotel,
        string $roomNumber
    ): JsonResponse {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json(['message' => 'No tienes acceso a este hotel.'], 403);
        }

        $usingExistingGuest = $request->filled('guest_id');

        // Validar que se envíe exactamente una opción de tarifa. Esta comprobación
        // explícita evita depender de la regla prohibited_with, que no está
        // disponible en algunas instalaciones/versiones del validador de Laravel.
        $hasRoomRateId = $request->filled('room_rate_id');
        $hasManualRate = $request->input('manual_rate') !== null;

        if ($hasRoomRateId === $hasManualRate) {
            throw ValidationException::withMessages([
                'room_rate_id' => ['Selecciona una tarifa del catálogo o captura una tarifa manual, pero no ambas.'],
            ]);
        }

        $rules = [
            // Selecciona una tarifa guardada o captura una tarifa especial para esta estancia.
            'room_rate_id' => [
                'nullable',
                'required_without:manual_rate',
                'integer',
            ],
            'manual_rate' => [
                'nullable',
                'required_without:room_rate_id',
                'array',
            ],
            'manual_rate.name' => ['required_with:manual_rate', 'string', 'max:100'],
            'manual_rate.duration' => ['required_with:manual_rate', 'integer', 'min:1', 'max:10000'],
            'manual_rate.unit' => ['required_with:manual_rate', Rule::in(['minutos', 'horas', 'días', 'noches', 'semanas'])],
            'manual_rate.price' => ['required_with:manual_rate', 'numeric', 'gt:0', 'max:99999999.99'],
            'expected_check_out_at' => [
                'required',
                'date',
                function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        // Interpretar la fecha con la zona horaria configurada para el hotel.
                        $checkout = \Illuminate\Support\Carbon::parse(
                            (string) $value,
                            config('app.timezone', 'America/Merida')
                        );
                        $current = now(config('app.timezone', 'America/Merida'));

                        if (! $checkout->greaterThan($current)) {
                            $fail('La salida prevista debe ser posterior a la fecha y hora actuales.');
                        }
                    } catch (\Throwable $exception) {
                        $fail('La fecha de salida prevista no es válida.');
                    }
                },
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        if ($usingExistingGuest) {
            $rules['guest_id'] = [
                'required',
                'integer',
                Rule::exists('guests', 'id')
                    ->where('hotel_id', $hotel->id)
                    ->where('active', true),
            ];
        } else {
            $rules['guest'] = ['required', 'array'];
            $rules['guest.first_name'] = ['required', 'string', 'max:120'];
            $rules['guest.last_name'] = ['required', 'string', 'max:120'];
            $rules['guest.phone'] = ['nullable', 'string', 'max:50'];
            $rules['guest.email'] = ['nullable', 'email', 'max:255'];
            $rules['guest.document_type'] = ['nullable', 'string', 'max:50'];
            $rules['guest.document_number'] = ['nullable', 'string', 'max:100'];
        }

        $validated = $request->validate($rules);

        $result = DB::transaction(function () use (
            $hotel,
            $roomNumber,
            $validated,
            $usingExistingGuest
        ): array {
            // Bloquear la habitación previene dos check-ins simultáneos.
            $room = Room::query()
                ->where('hotel_id', $hotel->id)
                ->where('number', $roomNumber)
                ->where('active', true)
                ->lockForUpdate()
                ->firstOrFail();

            if ($room->status !== 'available') {
                throw ValidationException::withMessages([
                    'room' => ['La habitación debe estar disponible para registrar el check-in.'],
                ]);
            }

            $hasActiveStay = $room->stays()
                ->where('hotel_id', $hotel->id)
                ->where('status', 'active')
                ->exists();

            if ($hasActiveStay) {
                throw ValidationException::withMessages([
                    'room' => ['La habitación ya tiene una estancia activa.'],
                ]);
            }

            // Al capturar una tarifa manual se guarda una fila interna vinculada a la
            // habitación y al hotel para respetar la FK obligatoria stays.room_rate_id.
            // Las tarifas normales del catálogo son globales (room_id = NULL).
            if (isset($validated['manual_rate'])) {
                $manual = $validated['manual_rate'];
                $rate = $room->rates()->create([
                    'hotel_id' => $hotel->id,
                    'name' => 'MANUAL#' . Str::uuid(),
                    'duration' => (int) $manual['duration'],
                    'unit' => $manual['unit'],
                    'price' => round((float) $manual['price'], 2),
                    'active' => false,
                ]);
                $rateName = trim($manual['name']);
                $ratePrice = round((float) $manual['price'], 2);
            } else {
                $rate = RoomRate::query()
                    ->where('hotel_id', $hotel->id)
                    ->whereNull('room_id')
                    ->where('active', true)
                    ->where('name', 'not like', 'MANUAL#%')
                    ->where('name', 'not like', 'LEGACY#%')
                    ->whereKey($validated['room_rate_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $rate) {
                    throw ValidationException::withMessages([
                        'room_rate_id' => ['Selecciona una tarifa activa del catálogo del hotel o captura una tarifa manual válida.'],
                    ]);
                }

                $rateName = (string) $rate->name;
                $ratePrice = round((float) $rate->price, 2);
            }

            if ($usingExistingGuest) {
                $guest = Guest::query()
                    ->where('hotel_id', $hotel->id)
                    ->where('active', true)
                    ->whereKey($validated['guest_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $guest) {
                    throw ValidationException::withMessages([
                        'guest_id' => ['El huésped seleccionado no está disponible en este hotel.'],
                    ]);
                }
            } else {
                $guestData = $validated['guest'];
                $guest = Guest::create([
                    'hotel_id' => $hotel->id,
                    'first_name' => trim($guestData['first_name']),
                    'last_name' => trim($guestData['last_name']),
                    'phone' => isset($guestData['phone']) ? trim($guestData['phone']) ?: null : null,
                    'email' => isset($guestData['email']) ? trim($guestData['email']) ?: null : null,
                    'document_type' => $guestData['document_type'] ?? null,
                    'document_number' => isset($guestData['document_number']) ? trim($guestData['document_number']) ?: null : null,
                    'active' => true,
                ]);
            }

            $checkInAt = now();
            $expectedCheckOutAt = \Illuminate\Support\Carbon::parse(
                $validated['expected_check_out_at'],
                config('app.timezone', 'America/Merida')
            );

            $stay = Stay::create([
                'hotel_id' => $hotel->id,
                'room_id' => $room->id,
                'guest_id' => $guest->id,
                'room_rate_id' => $rate->id,
                'rate_name' => $rateName,
                'rate_price' => $ratePrice,
                'check_in_at' => $checkInAt,
                'expected_check_out_at' => $expectedCheckOutAt,
                'actual_check_out_at' => null,
                'status' => 'active',
                'notes' => $validated['notes'] ?? null,
            ]);

            // El importe de la tarifa se registra también en cargos, porque los
            // totales de la cuenta se calculan a partir de stay_charges.
            $stay->charges()->create([
                'type' => 'room',
                'description' => $rateName,
                'quantity' => 1,
                'unit_price' => $ratePrice,
                'total' => $ratePrice,
                'notes' => 'Cargo inicial de la tarifa de habitación.',
            ]);

            $room->status = 'occupied';
            $room->save();

            return [
                'room' => [
                    'id' => (int) $room->id,
                    'number' => (string) $room->number,
                    'status' => (string) $room->status,
                ],
                'stay' => [
                    'id' => (int) $stay->id,
                    'status' => (string) $stay->status,
                    'guest_id' => (int) $guest->id,
                    'room_rate_id' => (int) $rate->id,
                    'rate_name' => $rateName,
                    'rate_price' => $ratePrice,
                    'check_in_at' => $stay->check_in_at,
                    'expected_check_out_at' => $stay->expected_check_out_at,
                ],
            ];
        });

        return response()->json([
            'message' => 'Check-in registrado correctamente. La habitación está ocupada.',
            ...$result,
        ], 201);
    }

    private function userHasHotelAccess(Request $request, Hotel $hotel): bool
    {
        return $request->user()
            ->hotels()
            ->where('hotels.id', $hotel->id)
            ->exists();
    }
}
