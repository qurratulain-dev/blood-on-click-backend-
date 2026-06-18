<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BloodStock extends Model
{
    protected $table = 'blood_stock';

    protected $fillable = [
        'bank_id',
        'blood_group',
        'quantity',
    ];

    public function bloodBank(): BelongsTo
    {
        return $this->belongsTo(BloodBank::class, 'bank_id');
    }
}
