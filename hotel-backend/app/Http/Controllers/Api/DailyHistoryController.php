<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Stay;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DailyHistoryController extends Controller
{
    /**
     * Consultar actividad del hotel para una fecha, agrupada por estancia.
     * La paginación se aplica a las estancias con movimientos del día,
     * no a todo el historial acumulado del hotel.
     */
    public function index(Request $request, Hotel $hotel): JsonResponse
    {
        if (! $this->userHasHotelAccess($request, $hotel)) {
            return response()->json([
                'message' => 'No tienes acceso a este hotel.',
            ], 403);
        }

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'room' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $timezone = $hotel->timezone ?: 'America/Merida';
        $date = $validated['date'] ?? now($timezone)->toDateString();

        // Interpretar el día en la zona horaria del hotel y comparar en UTC,
        // que es como se almacenan los timestamps de la aplicación.
        $startUtc = CarbonImmutable::parse($date, $timezone)->startOfDay()->utc();
        $endUtc = CarbonImmutable::parse($date, $timezone)
            ->addDay()
            ->startOfDay()
            ->utc();

        // Cuando se consulta una habitación, los indicadores superiores deben
        // usar exactamente el mismo filtro que la lista de estancias.
        $applyRoomFilter = function ($query) use ($validated): void {
            if (! empty($validated['room'])) {
                $roomFilter = trim($validated['room']);
                $query->whereHas('room', function ($roomQuery) use ($roomFilter) {
                    $roomQuery->where('number', '=', $roomFilter);
                });
            }
        };

        $checkInQuery = Stay::query()->where('hotel_id', $hotel->id);
        $applyRoomFilter($checkInQuery);
        $checkIns = $checkInQuery
            ->where('check_in_at', '>=', $startUtc)
            ->where('check_in_at', '<', $endUtc)
            ->count();

        $checkOutQuery = Stay::query()->where('hotel_id', $hotel->id);
        $applyRoomFilter($checkOutQuery);
        $checkOuts = $checkOutQuery
            ->whereNotNull('actual_check_out_at')
            ->where('actual_check_out_at', '>=', $startUtc)
            ->where('actual_check_out_at', '<', $endUtc)
            ->count();

        $chargeDateFilter = function ($query) use ($startUtc, $endUtc): void {
            $query->where('created_at', '>=', $startUtc)
                ->where('created_at', '<', $endUtc);
        };

        $paymentDateFilter = function ($query) use ($startUtc, $endUtc): void {
            $query->where('received_at', '>=', $startUtc)
                ->where('received_at', '<', $endUtc);
        };

        $chargesQuery = Stay::query()->where('hotel_id', $hotel->id);
        $applyRoomFilter($chargesQuery);
        $chargesToday = (float) $chargesQuery
            ->whereHas('charges', $chargeDateFilter)
            ->withSum(['charges as total_charged_today' => $chargeDateFilter], 'total')
            ->get(['id'])
            ->sum(fn (Stay $stay) => (float) ($stay->total_charged_today ?? 0));

        $paymentsQuery = Stay::query()->where('hotel_id', $hotel->id);
        $applyRoomFilter($paymentsQuery);
        $paymentsToday = (float) $paymentsQuery
            ->whereHas('payments', $paymentDateFilter)
            ->withSum(['payments as total_paid_today' => $paymentDateFilter], 'amount')
            ->get(['id'])
            ->sum(fn (Stay $stay) => (float) ($stay->total_paid_today ?? 0));

        // Efectivo bruto entregado y cambio devuelto. Se usa COALESCE para que
        // los pagos históricos anteriores a esta mejora sigan conciliando.
        $cashFlowQuery = DB::table('payments')
            ->join('stays', 'payments.stay_id', '=', 'stays.id')
            ->where('stays.hotel_id', $hotel->id)
            ->where('payments.method', 'cash')
            ->where('payments.received_at', '>=', $startUtc)
            ->where('payments.received_at', '<', $endUtc);

        if (! empty($validated['room'])) {
            $roomFilter = trim($validated['room']);
            $cashFlowQuery->whereIn(
                'payments.stay_id',
                Stay::query()
                    ->where('hotel_id', $hotel->id)
                    ->whereHas('room', function ($roomQuery) use ($roomFilter) {
                        $roomQuery->where('number', '=', $roomFilter);
                    })
                    ->select('id')
            );
        }

        $cashFlowToday = $cashFlowQuery
            ->selectRaw('COALESCE(SUM(COALESCE(payments.amount_received, payments.amount)), 0) as cash_received_total')
            ->selectRaw('COALESCE(SUM(COALESCE(payments.change_given, 0)), 0) as change_given_total')
            ->first();

        $query = Stay::query()
            ->where('hotel_id', $hotel->id)
            ->where(function ($activityQuery) use ($startUtc, $endUtc, $chargeDateFilter, $paymentDateFilter) {
                $activityQuery
                    ->where(function ($query) use ($startUtc, $endUtc) {
                        $query->where('check_in_at', '>=', $startUtc)
                            ->where('check_in_at', '<', $endUtc);
                    })
                    ->orWhere(function ($query) use ($startUtc, $endUtc) {
                        $query->whereNotNull('actual_check_out_at')
                            ->where('actual_check_out_at', '>=', $startUtc)
                            ->where('actual_check_out_at', '<', $endUtc);
                    })
                    ->orWhereHas('charges', $chargeDateFilter)
                    ->orWhereHas('payments', $paymentDateFilter);
            })
            ->with([
                'room:id,hotel_id,number',
                'guest:id,first_name,last_name',
                'charges' => function ($query) use ($chargeDateFilter) {
                    $chargeDateFilter($query);
                    $query->select(
                        'id', 'stay_id', 'type', 'description',
                        'quantity', 'unit_price', 'total', 'created_at'
                    );
                },
                'payments' => function ($query) use ($paymentDateFilter) {
                    $paymentDateFilter($query);
                    $query->select(
                        'id', 'stay_id', 'amount', 'method',
                        'reference', 'received_at', 'notes', 'amount_received', 'change_given'
                    );
                },
            ])
            ->withSum('charges as account_charges_total', 'total')
            ->withSum('payments as account_paid_total', 'amount')
            ->withSum(['charges as day_charges_total' => $chargeDateFilter], 'total')
            ->withSum(['payments as day_paid_total' => $paymentDateFilter], 'amount');

        $applyRoomFilter($query);

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery
                    ->whereHas('guest', function ($guestQuery) use ($search) {
                        $guestQuery->where('first_name', 'like', '%' . $search . '%')
                            ->orWhere('last_name', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('room', function ($roomQuery) use ($search) {
                        $roomQuery->where('number', 'like', '%' . $search . '%');
                    });
            });
        }

        $paginator = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20))
            ->withQueryString();

        $rows = $paginator->getCollection()->map(function (Stay $stay) use ($startUtc, $endUtc) {
            $activities = [];

            $isInDay = function ($value) use ($startUtc, $endUtc): bool {
                if (! $value) {
                    return false;
                }

                $timestamp = CarbonImmutable::parse($value)->utc();
                return $timestamp->greaterThanOrEqualTo($startUtc)
                    && $timestamp->lessThan($endUtc);
            };

            if ($isInDay($stay->check_in_at)) {
                $activities[] = [
                    'id' => 'check-in-' . $stay->id,
                    'type' => 'check_in',
                    'title' => 'Check-in',
                    'description' => 'Inicio de estancia',
                    'occurred_at' => CarbonImmutable::parse($stay->check_in_at)->toIso8601String(),
                    'amount' => null,
                ];
            }

            if ($isInDay($stay->actual_check_out_at)) {
                $activities[] = [
                    'id' => 'check-out-' . $stay->id,
                    'type' => 'check_out',
                    'title' => 'Check-out',
                    'description' => 'Cierre de estancia',
                    'occurred_at' => CarbonImmutable::parse($stay->actual_check_out_at)->toIso8601String(),
                    'amount' => null,
                ];
            }

            foreach ($stay->charges as $charge) {
                $type = match ((string) $charge->type) {
                    'room' => 'Cargo de habitación',
                    'product' => 'Producto',
                    'service' => 'Servicio',
                    default => 'Cargo',
                };

                $activities[] = [
                    'id' => 'charge-' . $charge->id,
                    'type' => 'charge',
                    'title' => $type . ': ' . (string) $charge->description,
                    'description' => (float) $charge->quantity . ' × $' . number_format((float) $charge->unit_price, 2, '.', ','),
                    'occurred_at' => CarbonImmutable::parse($charge->created_at)->toIso8601String(),
                    'amount' => (float) $charge->total,
                ];
            }

            foreach ($stay->payments as $payment) {
                $method = match ((string) $payment->method) {
                    'cash' => 'Efectivo',
                    'card' => 'Tarjeta',
                    'transfer' => 'Transferencia',
                    default => 'Otro',
                };

                $descriptionParts = [];
                if ($payment->reference) {
                    $descriptionParts[] = 'Referencia: ' . $payment->reference;
                }
                if ($payment->notes) {
                    $descriptionParts[] = (string) $payment->notes;
                }

                if ((string) $payment->method === 'cash') {
                    $cashReceived = round((float) ($payment->amount_received ?? $payment->amount), 2);
                    $changeGiven = round((float) ($payment->change_given ?? 0), 2);
                    $descriptionParts[] = 'Efectivo recibido: $' . number_format($cashReceived, 2, '.', ',');
                    $descriptionParts[] = 'Cambio entregado: $' . number_format($changeGiven, 2, '.', ',');
                    $descriptionParts[] = 'Aplicado a la cuenta: $' . number_format((float) $payment->amount, 2, '.', ',');
                }

                $activities[] = [
                    'id' => 'payment-' . $payment->id,
                    'type' => 'payment',
                    'title' => 'Pago recibido · ' . $method,
                    'description' => $descriptionParts ? implode(' · ', $descriptionParts) : 'Sin referencia',
                    'occurred_at' => CarbonImmutable::parse($payment->received_at)->toIso8601String(),
                    'amount' => (float) $payment->amount,
                ];
            }

            usort($activities, function (array $a, array $b): int {
                return strcmp($b['occurred_at'], $a['occurred_at']);
            });

            $guestName = trim(($stay->guest?->first_name ?? '') . ' ' . ($stay->guest?->last_name ?? ''));

            return [
                'id' => (int) $stay->id,
                'room_number' => (string) ($stay->room?->number ?? '—'),
                'guest_name' => $guestName !== '' ? $guestName : 'Huésped sin nombre',
                'status' => (string) $stay->status,
                'check_in_at' => $stay->check_in_at,
                'actual_check_out_at' => $stay->actual_check_out_at,
                'expected_check_out_at' => $stay->expected_check_out_at,
                'rate_name' => (string) $stay->rate_name,
                'rate_price' => (float) $stay->rate_price,
                'account' => [
                    'charges_total' => round((float) ($stay->account_charges_total ?? 0), 2),
                    'paid_total' => round((float) ($stay->account_paid_total ?? 0), 2),
                    'balance' => round(
                        (float) ($stay->account_charges_total ?? 0)
                        - (float) ($stay->account_paid_total ?? 0),
                        2
                    ),
                ],
                'day_charges_total' => round((float) ($stay->day_charges_total ?? 0), 2),
                'day_paid_total' => round((float) ($stay->day_paid_total ?? 0), 2),
                'activities' => $activities,
            ];
        })->values();

        return response()->json([
            'date' => $date,
            'timezone' => $timezone,
            'summary' => [
                'check_ins' => $checkIns,
                'check_outs' => $checkOuts,
                'charges_total' => round($chargesToday, 2),
                'payments_total' => round($paymentsToday, 2),
                'cash_received_total' => round((float) ($cashFlowToday->cash_received_total ?? 0), 2),
                'change_given_total' => round((float) ($cashFlowToday->change_given_total ?? 0), 2),
            ],
            'data' => $rows,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    private function userHasHotelAccess(Request $request, Hotel $hotel): bool
    {
        return $request->user()
            ->hotels()
            ->where('hotels.id', $hotel->id)
            ->exists();
    }
}
