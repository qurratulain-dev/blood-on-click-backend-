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

    /**
     * Single source of truth for stock sufficiency on request approval.
     *
     * Atomically verify and deduct stock for a blood bank / group / quantity.
     * Returns ['reserved' => false, 'available' => N] when the bank has no
     * stock row for the group or fewer units than requested. On success the
     * stock is decremented and ['reserved' => true, 'available' => N] is
     * returned. Callers are responsible for running this inside a DB
     * transaction so the status update and stock deduction stay consistent.
     */
    public static function reserveForRequest(int $bankId, string $bloodGroup, int $quantity): array
    {
        $stock = static::where('bank_id', $bankId)
            ->where('blood_group', $bloodGroup)
            ->lockForUpdate()
            ->first();

        $available = (int) ($stock?->quantity ?? 0);

        if ($stock === null || $available < $quantity) {
            return ['reserved' => false, 'available' => $available];
        }

        $stock->decrement('quantity', $quantity);

        return ['reserved' => true, 'available' => $available];
    }
}
