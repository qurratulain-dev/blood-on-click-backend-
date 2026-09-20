<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalAssessment extends Model
{
    protected $table = 'medical_assessments';

    protected $fillable = [
        'user_id',
        'assessment_date',
        'blood_pressure',
        'hemoglobin',
        'temperature',
        'pulse',
        'weight',
        'is_eligible',
        'notes',
        'assessed_by',
    ];

    protected function casts(): array
    {
        return [
            'assessment_date' => 'date',
            'is_eligible' => 'boolean',
            'hemoglobin' => 'decimal:1',
            'temperature' => 'decimal:1',
            'pulse' => 'integer',
            'weight' => 'decimal:1',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
