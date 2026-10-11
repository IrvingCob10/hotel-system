<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Listar el catálogo de productos de un hotel.
     */
    public function index(
        Request $request,
        Hotel $hotel
    ): JsonResponse {
        // Verificar que el usuario tenga acceso al hotel.
        $hasAccess = $request->user()
            ->hotels()
            ->where('hotels.id', $hotel->id)
            ->exists();

        if (! $hasAccess) {
            return response()->json([
                'message' => 'No tienes acceso a este hotel.',
            ], 403);
        }

        // Consultar exclusivamente los productos del hotel.
        $products = $hotel->products()
            ->where('active', true)
            ->orderBy('name')
            ->get([
                'id',
                'hotel_id',
                'name',
                'description',
                'sku',
                'price',
                'stock',
                'active',
            ]);

        return response()->json($products);
    }
}