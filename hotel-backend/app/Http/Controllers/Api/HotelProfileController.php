<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class HotelProfileController extends Controller
{
    /**
     * Consultar los datos del hotel al que pertenece el usuario autenticado.
     * Cuando el usuario tiene varios hoteles, debe enviar hotel_id como query.
     */
    public function showMine(Request $request): JsonResponse
    {
        $hotel = $this->resolveHotel($request);

        if ($hotel instanceof JsonResponse) {
            return $hotel;
        }

        return response()->json($this->profilePayload($hotel));
    }

    /**
     * Actualizar datos del establecimiento y los datos fiscales del cliente.
     * Acepta multipart/form-data para permitir subir el logotipo.
     */
    public function updateMine(Request $request): JsonResponse
    {
        $hotel = $this->resolveHotel($request);

        if ($hotel instanceof JsonResponse) {
            return $hotel;
        }

        if ($request->hasFile('logo') && $request->boolean('remove_logo')) {
            throw ValidationException::withMessages([
                'logo' => ['Selecciona un logotipo nuevo o solicita quitar el actual, no ambas cosas.'],
            ]);
        }

        $validated = $request->validate([
            'hotel_id' => ['sometimes', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'commercial_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'country' => ['required', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'timezone' => ['required', 'timezone'],
            'customer_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'logo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
        ]);

        $previousLogo = $hotel->logo;
        $newLogoPath = null;

        if ($request->hasFile('logo')) {
            $newLogoPath = $request->file('logo')->store('hotels', 'public');
        }

        DB::transaction(function () use ($hotel, $validated, $newLogoPath, $request) {
            $hotel->forceFill([
                'name' => trim($validated['name']),
                'commercial_name' => $this->nullableTrim($validated['commercial_name'] ?? null),
                'email' => $this->nullableTrim($validated['email'] ?? null),
                'phone' => $this->nullableTrim($validated['phone'] ?? null),
                'address' => $this->nullableTrim($validated['address'] ?? null),
                'city' => $this->nullableTrim($validated['city'] ?? null),
                'state' => $this->nullableTrim($validated['state'] ?? null),
                'country' => trim($validated['country']),
                'postal_code' => $this->nullableTrim($validated['postal_code'] ?? null),
                'timezone' => $validated['timezone'],
            ]);

            if ($newLogoPath !== null) {
                $hotel->logo = $newLogoPath;
            } elseif ($request->boolean('remove_logo')) {
                $hotel->logo = null;
            }

            $hotel->save();

            // Customer representa la cuenta/propietario del SaaS; sus datos
            // fiscales pueden reutilizarse en los comprobantes del establecimiento.
            $customer = $hotel->customer;
            if ($customer) {
                $customer->forceFill([
                    'name' => trim($validated['customer_name']),
                    'legal_name' => $this->nullableTrim($validated['legal_name'] ?? null),
                    'tax_id' => $this->nullableTrim($validated['tax_id'] ?? null),
                    'email' => $this->nullableTrim($validated['customer_email'] ?? null),
                    'phone' => $this->nullableTrim($validated['customer_phone'] ?? null),
                ])->save();
            }
        });

        if (($newLogoPath !== null || $request->boolean('remove_logo')) && $previousLogo) {
            $this->deleteStoredLogo($previousLogo);
        }

        $hotel->refresh()->load('customer');

        return response()->json([
            'message' => 'Los datos del hotel se guardaron correctamente.',
            ...$this->profilePayload($hotel),
        ]);
    }

    /**
     * Resuelve solo hoteles vinculados al usuario autenticado.
     * No acepta un hotel_id arbitrario que no pertenezca a la relación del usuario.
     */
    private function resolveHotel(Request $request): Hotel|JsonResponse
    {
        $hotelsQuery = $request->user()
            ->hotels()
            ->with('customer')
            ->orderBy('hotels.id');

        $requestedHotelId = $request->input('hotel_id') ?? $request->query('hotel_id');

        if ($requestedHotelId !== null && $requestedHotelId !== '') {
            if (! is_numeric($requestedHotelId) || (int) $requestedHotelId < 1) {
                return response()->json([
                    'message' => 'El identificador del hotel no es válido.',
                ], 422);
            }

            $hotel = (clone $hotelsQuery)
                ->where('hotels.id', (int) $requestedHotelId)
                ->first();

            if (! $hotel) {
                return response()->json([
                    'message' => 'No tienes acceso a ese hotel.',
                ], 403);
            }

            return $hotel;
        }

        $hotels = $hotelsQuery->get();

        if ($hotels->isEmpty()) {
            return response()->json([
                'message' => 'Tu usuario todavía no tiene un hotel asignado. Solicita al administrador que complete la asignación.',
                'code' => 'HOTEL_NOT_ASSIGNED',
            ], 409);
        }

        if ($hotels->count() > 1) {
            return response()->json([
                'message' => 'Tu usuario tiene acceso a varios hoteles. Selecciona uno para continuar.',
                'code' => 'HOTEL_SELECTION_REQUIRED',
                'hotels' => $hotels->map(fn (Hotel $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'commercial_name' => $item->commercial_name,
                    'active' => (bool) $item->active,
                ])->values(),
            ], 409);
        }

        return $hotels->first();
    }

    private function profilePayload(Hotel $hotel): array
    {
        $hotel->loadMissing('customer');

        $logoUrl = null;
        if ($hotel->logo) {
            if (filter_var($hotel->logo, FILTER_VALIDATE_URL)) {
                $logoUrl = $hotel->logo;
            } elseif (str_starts_with($hotel->logo, '/')) {
                $logoUrl = url($hotel->logo);
            } else {
                $logoUrl = Storage::disk('public')->url($hotel->logo);
            }
        }

        $missingFields = [];
        foreach ([
            'name' => $hotel->name,
            'phone' => $hotel->phone,
            'address' => $hotel->address,
            'city' => $hotel->city,
        ] as $field => $value) {
            if (blank($value)) {
                $missingFields[] = $field;
            }
        }

        return [
            'hotel' => [
                'id' => (int) $hotel->id,
                'customer_id' => (int) $hotel->customer_id,
                'name' => $hotel->name,
                'commercial_name' => $hotel->commercial_name,
                'email' => $hotel->email,
                'phone' => $hotel->phone,
                'address' => $hotel->address,
                'city' => $hotel->city,
                'state' => $hotel->state,
                'country' => $hotel->country,
                'postal_code' => $hotel->postal_code,
                'timezone' => $hotel->timezone,
                'logo' => $hotel->logo,
                'logo_url' => $logoUrl,
                'active' => (bool) $hotel->active,
            ],
            'customer' => $hotel->customer ? [
                'id' => (int) $hotel->customer->id,
                'name' => $hotel->customer->name,
                'legal_name' => $hotel->customer->legal_name,
                'tax_id' => $hotel->customer->tax_id,
                'email' => $hotel->customer->email,
                'phone' => $hotel->customer->phone,
            ] : null,
            'needs_setup' => count($missingFields) > 0,
            'missing_hotel_fields' => $missingFields,
        ];
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === '' ? null : $value;
    }

    private function deleteStoredLogo(string $logo): void
    {
        if (! filter_var($logo, FILTER_VALIDATE_URL) && ! str_starts_with($logo, '/')) {
            Storage::disk('public')->delete($logo);
        }
    }
}
