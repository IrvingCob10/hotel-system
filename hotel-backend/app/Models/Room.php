<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_id',
        'room_type_id',
        'number',
        'floor',
        'status',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'floor' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * Hotel al que pertenece la habitación.
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * Tipo de habitación.
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Tarifas manuales e históricas vinculadas a esta habitación.
     * Las tarifas normales del catálogo global usan room_id = NULL.
     */
    public function rates(): HasMany
    {
        return $this->hasMany(RoomRate::class);
    }

    /**
     * Historial de estancias de la habitación.
     */
    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }

    public function expenses(): HasMany
{
    return $this->hasMany(Expense::class);
}
}