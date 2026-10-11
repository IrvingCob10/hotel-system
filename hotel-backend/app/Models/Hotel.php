<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Hotel extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'name',
        'commercial_name',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'timezone',
        'logo',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * Customer propietario del hotel.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Usuarios que tienen acceso a este hotel.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /**
     * Licencias contratadas para este hotel.
     */
    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    /**
     * Tipos de habitación configurados por el hotel.
     */
    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class);
    }

    public function rooms(): HasMany
{
    return $this->hasMany(Room::class);
}

public function guests(): HasMany
{
    return $this->hasMany(Guest::class);
}
public function stays(): HasMany
{
    return $this->hasMany(Stay::class);
}

public function products(): HasMany
{
    return $this->hasMany(Product::class);
}

public function services(): HasMany
{
    return $this->hasMany(Service::class);
}
public function expenses(): HasMany
{
    return $this->hasMany(Expense::class);
}
}