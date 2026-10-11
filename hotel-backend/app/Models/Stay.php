<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Stay extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_id',
        'room_id',
        'guest_id',
        'room_rate_id',
        'rate_name',
        'rate_price',
        'check_in_at',
        'expected_check_out_at',
        'actual_check_out_at',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'rate_price' => 'decimal:2',
            'check_in_at' => 'datetime',
            'expected_check_out_at' => 'datetime',
            'actual_check_out_at' => 'datetime',
        ];
    }

    /**
     * Hotel de la estancia.
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * Habitación ocupada durante la estancia.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Huésped de la estancia.
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * Tarifa utilizada en la estancia.
     */
    public function roomRate(): BelongsTo
    {
        return $this->belongsTo(RoomRate::class);
    }

    public function charges(): HasMany
{
    return $this->hasMany(StayCharge::class);
}
public function payments(): HasMany
{
    return $this->hasMany(Payment::class);
}

}