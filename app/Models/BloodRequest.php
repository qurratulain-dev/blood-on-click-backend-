<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BloodRequest extends Model
{
    protected $table = 'blood_requests';

    protected $fillable = [
        'user_id', 'bank_id', 'blood_group', 'quantity',
        'patient_name', 'patient_age', 'hospital_name', 'urgency',
        'reason', 'status', 'requested_date', 'fulfilled_date',
    ];

    protected function casts(): array
    {
        return [
            'requested_date' => 'datetime',
            'fulfilled_date' => 'datetime',
            'quantity' => 'integer',
            'patient_age' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(BloodBank::class, 'bank_id');
    }
}
