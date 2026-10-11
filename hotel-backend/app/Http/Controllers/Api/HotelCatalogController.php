<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administración de los catálogos de productos y servicios por hotel.
 *
 * Estas rutas de catálogo son independientes de los endpoints de solo lectura
 * que consume la ficha de habitación. Todas las consultas están limitadas al
 * hotel de la ruta y se verifica la relación hotel-usuario antes de operar.
 */
class HotelCatalogController extends Controller
{
    public function productsIndex(Request $request, Hotel $hotel): JsonResponse
    {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return $this->forbidden();
        }

        $products = Product::query()
            ->where('hotel_id', $hotel->id)
            ->orderBy('name')
            ->get([
                'id', 'hotel_id', 'name', 'description', 'sku',
                'price', 'stock', 'active', 'created_at', 'updated_at',
            ]);

        return response()->json(['products' => $products]);
    }

    public function productsStore(Request $request, Hotel $hotel): JsonResponse
    {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sku' => [
                'nullable', 'string', 'max:100',
                Rule::unique('products', 'sku')->where('hotel_id', $hotel->id),
            ],
            'price' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'stock' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'active' => ['required', 'boolean'],
        ]);

        $product = new Product();
        $product->hotel_id = $hotel->id;
        $this->fillProduct($product, $validated);
        $product->save();

        return response()->json([
            'message' => 'Producto registrado correctamente.',
            'product' => $product->fresh(),
        ], 201);
    }

    public function productsUpdate(Request $request, Hotel $hotel, int $productId): JsonResponse
    {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return $this->forbidden();
        }

        $product = Product::query()
            ->where('hotel_id', $hotel->id)
            ->whereKey($productId)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sku' => [
                'nullable', 'string', 'max:100',
                Rule::unique('products', 'sku')
                    ->where('hotel_id', $hotel->id)
                    ->ignore($product->id),
            ],
            'price' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'stock' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'active' => ['required', 'boolean'],
        ]);

        $this->fillProduct($product, $validated);
        $product->save();

        return response()->json([
            'message' => 'Producto actualizado correctamente.',
            'product' => $product->fresh(),
        ]);
    }

    public function servicesIndex(Request $request, Hotel $hotel): JsonResponse
    {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return $this->forbidden();
        }

        $services = Service::query()
            ->where('hotel_id', $hotel->id)
            ->orderBy('name')
            ->get([
                'id', 'hotel_id', 'name', 'description',
                'price', 'active', 'created_at', 'updated_at',
            ]);

        return response()->json(['services' => $services]);
    }

    public function servicesStore(Request $request, Hotel $hotel): JsonResponse
    {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'active' => ['required', 'boolean'],
        ]);

        $service = new Service();
        $service->hotel_id = $hotel->id;
        $this->fillService($service, $validated);
        $service->save();

        return response()->json([
            'message' => 'Servicio registrado correctamente.',
            'service' => $service->fresh(),
        ], 201);
    }

    public function servicesUpdate(Request $request, Hotel $hotel, int $serviceId): JsonResponse
    {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return $this->forbidden();
        }

        $service = Service::query()
            ->where('hotel_id', $hotel->id)
            ->whereKey($serviceId)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'active' => ['required', 'boolean'],
        ]);

        $this->fillService($service, $validated);
        $service->save();

        return response()->json([
            'message' => 'Servicio actualizado correctamente.',
            'service' => $service->fresh(),
        ]);
    }

    private function fillProduct(Product $product, array $data): void
    {
        $product->name = trim($data['name']);
        $product->description = isset($data['description']) && trim((string) $data['description']) !== ''
            ? trim($data['description']) : null;
        $product->sku = isset($data['sku']) && trim((string) $data['sku']) !== ''
            ? trim($data['sku']) : null;
        $product->price = round((float) $data['price'], 2);
        $product->stock = (int) $data['stock'];
        $product->active = (bool) $data['active'];
    }

    private function fillService(Service $service, array $data): void
    {
        $service->name = trim($data['name']);
        $service->description = isset($data['description']) && trim((string) $data['description']) !== ''
            ? trim($data['description']) : null;
        $service->price = round((float) $data['price'], 2);
        $service->active = (bool) $data['active'];
    }

    private function userHasHotelAccess(Request $request, Hotel $hotel): bool
    {
        return $request->user()
            ->hotels()
            ->where('hotels.id', $hotel->id)
            ->exists();
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'No tienes acceso a este hotel.'], 403);
    }
}
