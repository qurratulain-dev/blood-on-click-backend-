<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Donor extends Model
{
    protected $fillable = [
        'user_id',
        'full_name',
        'phone',
        'blood_group',
        'address',
        'age',
        'weight',
        'last_donation_date',
        'gender',
        'status',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'last_donation_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
