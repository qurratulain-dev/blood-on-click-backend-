<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DonationHistory extends Model
{
    protected $table = 'donation_history';

    protected $fillable = [
        'user_id', 'bank_id', 'blood_group', 'quantity',
        'status', 'donation_date', 'notes', 'medical_report_path', 'admin_message',
    ];

    protected function casts(): array
    {
        return [
            'donation_date' => 'datetime',
            'quantity' => 'integer',
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
