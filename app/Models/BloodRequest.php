<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BloodRequest extends Model
{
    protected $table = 'blood_requests';

    /**
     * The only legitimate status transitions in the product. Active rows are
     * created as 'pending' by the requesting user and then follow the normal
     * lifecycle (pending -> approved/rejected, approved -> fulfilled). There
     * is intentionally no transition back into 'pending': approval reserves
     * Stock via BloodStock::reserveForRequest(), so reopening an approved or
     * fulfilled request would silently re-deduct or corrupt inventory.
     */
    public const TRANSITIONS = [
        'approved' => ['pending'],
        'rejected' => ['pending'],
        'fulfilled' => ['approved'],
    ];

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

    public function canTransitionTo(string $target): bool
    {
        return in_array($this->status, self::TRANSITIONS[$target] ?? [], true);
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
