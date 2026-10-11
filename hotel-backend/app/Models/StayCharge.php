<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class StayCharge extends Model
{
    use HasFactory;

    protected $fillable = [
        'stay_id',
        'type',
        'description',
        'quantity',
        'unit_price',
        'total',
        'notes',
        'product_id',
        'service_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * Estancia a la que pertenece el cargo.
     */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function product(): BelongsTo
{
    return $this->belongsTo(Product::class);
}
public function service(): BelongsTo
{
    return $this->belongsTo(Service::class);
}
}