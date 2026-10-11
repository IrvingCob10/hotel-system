<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_id',
        'room_id',
        'name',
        'duration',
        'unit',
        'price',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'hotel_id' => 'integer',
            'room_id' => 'integer',
            'duration' => 'integer',
            'price' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    /** Hotel dueño de una tarifa global; también aplica a tarifas manuales históricas. */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /** Habitación de una tarifa manual/histórica. Las tarifas del catálogo global tienen room_id nulo. */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** Estancias que utilizaron esta tarifa. */
    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }
}
