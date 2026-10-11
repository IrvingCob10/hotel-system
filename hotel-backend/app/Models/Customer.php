<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'legal_name',
        'tax_id',
        'email',
        'phone',
        'active',
    ];

    /**
     * Un cliente puede tener varios hoteles.
     */
    public function hotels(): HasMany
    {
        return $this->hasMany(Hotel::class);
    }
}