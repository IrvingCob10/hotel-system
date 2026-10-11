<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class License extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_id',
        'plan_id',
        'license_key',
        'status',
        'starts_at',
        'expires_at',
        'grace_until',
        'auto_renew',
        'is_trial',
        'cancelled_at',
    ];

    /**
     * La licencia pertenece a un hotel.
     */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    /**
     * La licencia pertenece a un plan.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Determina si la licencia está vigente.
     */
    public function isActive(): bool
    {
        return $this->status === 'active'
            && now()->between(
                $this->starts_at,
                $this->expires_at
            );
    }

    /**
     * Determina si la licencia está dentro del periodo de gracia.
     */
    public function isInGracePeriod(): bool
    {
        return $this->status === 'active'
            && $this->expires_at < now()
            && $this->grace_until !== null
            && now()->lte($this->grace_until);
    }

    protected function casts(): array
{
    return [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'grace_until' => 'datetime',
        'cancelled_at' => 'datetime',
        'auto_renew' => 'boolean',
        'is_trial' => 'boolean',
    ];
}
}