<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BloodBank extends Model
{
    protected $fillable = [
        'user_id',
        'bank_name',
        'phone',
        'address',
        'registration_number',
        'license_number',
        'status',
        'latitude',
        'longitude',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bloodStocks(): HasMany
    {
        return $this->hasMany(BloodStock::class, 'bank_id');
    }
}
