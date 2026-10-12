
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\StayChargeController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\RoomLifecycleController;
use App\Http\Controllers\Api\DailyHistoryController;
use App\Http\Controllers\Api\StayController;
use App\Http\Controllers\Api\DepartureAlertController;
use App\Http\Controllers\Api\StayExtensionController;
use App\Http\Controllers\Api\RoomTypeController;
use App\Http\Controllers\Api\RoomRateController;
use App\Http\Controllers\Api\HotelProfileController;
use App\Http\Controllers\Api\HotelCatalogController;

/*
|--------------------------------------------------------------------------
| Usuario autenticado
|--------------------------------------------------------------------------
*/

Route::get('/user', function (Request $request) {
    return $request->user()->load('hotels');
})->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| Autenticación
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Rutas protegidas de la API
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Habitaciones
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/hotels/{hotel}/rooms',
        [RoomController::class, 'index']
    );

    Route::post(
        '/hotels/{hotel}/rooms',
        [RoomController::class, 'store']
    );

    Route::get(
        '/hotels/{hotel}/rooms/{roomNumber}',
        [RoomController::class, 'show']
    );

    Route::put(
        '/hotels/{hotel}/rooms/{roomNumber}',
        [RoomController::class, 'update']
    );

    /*
    |--------------------------------------------------------------------------
    | Gastos del hotel
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/hotels/{hotel}/expenses',
        [ExpenseController::class, 'index']
    );

    /*
    |--------------------------------------------------------------------------
    | Catálogo de productos y servicios
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/hotels/{hotel}/products',
        [ProductController::class, 'index']
    );

    Route::get(
        '/hotels/{hotel}/services',
        [ServiceController::class, 'index']
    );

    /*
    |--------------------------------------------------------------------------
    | Operaciones de la estancia: productos y servicios
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/hotels/{hotel}/rooms/{roomNumber}/stays/{stayId}/products',
        [StayChargeController::class, 'addProduct']
    );

    Route::post(
        '/hotels/{hotel}/rooms/{roomNumber}/stays/{stayId}/services',
        [StayChargeController::class, 'addService']
    );

    Route::patch(
        '/hotels/{hotel}/rooms/{roomNumber}/stays/{stayId}/charges/{chargeId}/quantity',
        [StayChargeController::class, 'updateQuantity']
    );

    /*
    |--------------------------------------------------------------------------
    | Pagos y check-out
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/hotels/{hotel}/rooms/{roomNumber}/stays/{stayId}/payments',
        [StayChargeController::class, 'addPayment']
    );

    Route::post(
        '/hotels/{hotel}/rooms/{roomNumber}/stays/{stayId}/checkout',
        [StayChargeController::class, 'checkout']
    );

    /*
    |--------------------------------------------------------------------------
    | Ciclo de vida de la habitación
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/hotels/{hotel}/rooms/{roomNumber}/latest-stay',
        [RoomLifecycleController::class, 'latestStay']
    );

    Route::post(
        '/hotels/{hotel}/rooms/{roomNumber}/cleaning/complete',
        [RoomLifecycleController::class, 'completeCleaning']
    );

    /*
    |--------------------------------------------------------------------------
    | Huéspedes y check-in
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/hotels/{hotel}/guests',
        [StayController::class, 'guests']
    );

    Route::post(
        '/hotels/{hotel}/rooms/{roomNumber}/check-in',
        [StayController::class, 'checkIn']
    );

    /*
    |--------------------------------------------------------------------------
    | Extensión de estancia
    |--------------------------------------------------------------------------
    */

    Route::patch(
        '/hotels/{hotel}/rooms/{roomNumber}/stays/{stayId}/extension',
        [StayExtensionController::class, 'update']
    );

    /*
    |--------------------------------------------------------------------------
    | Alertas de salida
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/hotels/{hotel}/departure-alerts',
        [DepartureAlertController::class, 'index']
    );

    /*
    |--------------------------------------------------------------------------
    | Tipos de habitación
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/hotels/{hotel}/room-types',
        [RoomTypeController::class, 'index']
    );

    Route::post(
        '/hotels/{hotel}/room-types',
        [RoomTypeController::class, 'store']
    );

    Route::put(
        '/hotels/{hotel}/room-types/{roomTypeId}',
        [RoomTypeController::class, 'update']
    );

    /*
    |--------------------------------------------------------------------------
    | Tarifas
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/hotels/{hotel}/rates',
        [RoomRateController::class, 'index']
    );

    Route::post(
        '/hotels/{hotel}/rates',
        [RoomRateController::class, 'store']
    );

    Route::put(
        '/hotels/{hotel}/rates/{rateId}',
        [RoomRateController::class, 'update']
    );

    /*
    |--------------------------------------------------------------------------
    | Catálogo administrativo de productos
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/hotels/{hotel}/catalog/products',
        [HotelCatalogController::class, 'productsIndex']
    );

    Route::post(
        '/hotels/{hotel}/catalog/products',
        [HotelCatalogController::class, 'productsStore']
    );

    Route::put(
        '/hotels/{hotel}/catalog/products/{productId}',
        [HotelCatalogController::class, 'productsUpdate']
    );

    /*
    |--------------------------------------------------------------------------
    | Catálogo administrativo de servicios
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/hotels/{hotel}/catalog/services',
        [HotelCatalogController::class, 'servicesIndex']
    );

    Route::post(
        '/hotels/{hotel}/catalog/services',
        [HotelCatalogController::class, 'servicesStore']
    );

    Route::put(
        '/hotels/{hotel}/catalog/services/{serviceId}',
        [HotelCatalogController::class, 'servicesUpdate']
    );

    /*
    |--------------------------------------------------------------------------
    | Perfil y datos del hotel
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/my/hotel',
        [HotelProfileController::class, 'showMine']
    );

    Route::patch(
        '/my/hotel',
        [HotelProfileController::class, 'updateMine']
    );

    /*
    |--------------------------------------------------------------------------
    | Historial del día
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/hotels/{hotel}/history/day',
        [DailyHistoryController::class, 'index']
    );

});
