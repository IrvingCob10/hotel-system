<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Las credenciales son incorrectas.',
            ], 401);
        }

        // Conserva el token de Sanctum actual y añade los hoteles vinculados.
        // Así Vue puede seleccionar el hotel correcto en lugar de asumir id=1.
        $hotels = $user->hotels()
            ->orderBy('hotels.id')
            ->get([
                'hotels.id',
                'hotels.customer_id',
                'hotels.name',
                'hotels.commercial_name',
                'hotels.email',
                'hotels.phone',
                'hotels.address',
                'hotels.city',
                'hotels.state',
                'hotels.country',
                'hotels.postal_code',
                'hotels.timezone',
                'hotels.logo',
                'hotels.active',
            ]);

        $currentHotel = $hotels->firstWhere('active', true) ?? $hotels->first();
        $needsHotelSetup = $currentHotel === null
            || blank($currentHotel->name)
            || blank($currentHotel->phone)
            || blank($currentHotel->address)
            || blank($currentHotel->city);

        $token = $user->createToken('hotel-api')->plainTextToken;

        return response()->json([
            'message' => 'Inicio de sesión exitoso.',
            'token' => $token,
            // Se mantiene el campo user con su forma existente para compatibilidad.
            'user' => $user,
            'hotels' => $hotels->values(),
            'current_hotel' => $currentHotel,
            'requires_hotel_selection' => $hotels->count() > 1,
            'needs_hotel_setup' => $needsHotelSetup,
        ]);
    }
}
