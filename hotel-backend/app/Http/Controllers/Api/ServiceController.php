<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    /**
     * Listar los servicios activos de un hotel.
     */
    public function index(
        Request $request,
        Hotel $hotel
    ): JsonResponse {
        // Verificar el acceso del usuario al hotel.
        $hasAccess = $request->user()
            ->hotels()
            ->where('hotels.id', $hotel->id)
            ->exists();

        if (! $hasAccess) {
            return response()->json([
                'message' => 'No tienes acceso a este hotel.',
            ], 403);
        }

        // Consultar únicamente los servicios de este hotel.
        $services = Service::query()
            ->where('hotel_id', $hotel->id)
            ->where('active', true)
            ->orderBy('name')
            ->get([
                'id',
                'hotel_id',
                'name',
                'description',
                'price',
                'active',
            ]);

        return response()->json($services);
    }
}