<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    /**
     * Consultar gastos y totales de un hotel.
     */
    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        // Comprobar que el usuario tenga acceso al hotel.
        $hasAccess = $request->user()
            ->hotels()
            ->where('hotels.id', $hotel->id)
            ->exists();

        if (! $hasAccess) {
            return response()->json([
                'message' => 'No tienes acceso a este hotel.',
            ], 403);
        }

        $expensesQuery = $hotel->expenses();

        $generalTotal = (clone $expensesQuery)
            ->whereNull('room_id')
            ->sum('amount');

        $roomTotal = (clone $expensesQuery)
            ->whereNotNull('room_id')
            ->sum('amount');

        $total = (clone $expensesQuery)->sum('amount');

        $expenses = $hotel->expenses()
            ->with('room:id,number')
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get();

        $roomTotals = $hotel->expenses()
            ->join('rooms', 'expenses.room_id', '=', 'rooms.id')
            ->select(
                'rooms.id as room_id',
                'rooms.number as room_number'
            )
            ->selectRaw('SUM(expenses.amount) as total')
            ->groupBy('rooms.id', 'rooms.number')
            ->orderBy('rooms.number')
            ->get();

        return response()->json([
            'hotel' => [
                'id' => $hotel->id,
                'name' => $hotel->name,
            ],
            'summary' => [
                'general_expenses' => number_format(
                    (float) $generalTotal, 2, '.', ''
                ),
                'room_expenses' => number_format(
                    (float) $roomTotal, 2, '.', ''
                ),
                'total_expenses' => number_format(
                    (float) $total, 2, '.', ''
                ),
            ],
            'room_totals' => $roomTotals,
            'expenses' => $expenses,
        ]);
    }
}