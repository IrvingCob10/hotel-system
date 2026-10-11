<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Module extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'active',
    ];

    /**
     * Un módulo puede pertenecer a varios planes.
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class);
    }
}