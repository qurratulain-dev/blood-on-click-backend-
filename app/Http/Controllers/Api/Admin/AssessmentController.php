<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Donor;
use App\Models\MedicalAssessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssessmentController extends Controller
{
    public function index(): JsonResponse
    {
        $assessments = MedicalAssessment::with('donor')
            ->orderBy('assessment_date', 'desc')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'donor_id' => $a->donor_id,
                'donor_name' => $a->donor->full_name ?? 'N/A',
                'blood_group' => $a->donor->blood_group ?? 'N/A',
                'assessment_date' => $a->assessment_date?->format('Y-m-d'),
                'blood_pressure' => $a->blood_pressure,
                'hemoglobin' => $a->hemoglobin,
                'temperature' => $a->temperature,
                'pulse' => $a->pulse,
                'weight' => $a->weight,
                'is_eligible' => $a->is_eligible,
                'notes' => $a->notes,
                'created_at' => $a->created_at?->format('Y-m-d H:i:s'),
            ]);

        return response()->json($assessments);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'donor_id' => 'required|exists:donors,id',
            'assessment_date' => 'required|date',
            'blood_pressure' => 'nullable|string|max:20',
            'hemoglobin' => 'nullable|numeric|min:0|max:20',
            'temperature' => 'nullable|numeric|min:34|max:42',
            'pulse' => 'nullable|integer|min:30|max:250',
            'weight' => 'nullable|numeric|min:30|max:300',
            'is_eligible' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $validated['assessed_by'] = $request->user()->id;

        $assessment = MedicalAssessment::create($validated);

        return response()->json([
            'message' => 'Assessment created successfully',
            'assessment' => $assessment->load('donor'),
        ], 201);
    }

    public function show(MedicalAssessment $assessment): JsonResponse
    {
        $assessment->load('donor', 'assessedBy');
        return response()->json([
            'id' => $assessment->id,
            'donor_id' => $assessment->donor_id,
            'donor_name' => $assessment->donor->full_name ?? 'N/A',
            'blood_group' => $assessment->donor->blood_group ?? 'N/A',
            'age' => $assessment->donor->age,
            'gender' => $assessment->donor->gender,
            'assessment_date' => $assessment->assessment_date?->format('Y-m-d'),
            'blood_pressure' => $assessment->blood_pressure,
            'hemoglobin' => $assessment->hemoglobin,
            'temperature' => $assessment->temperature,
            'pulse' => $assessment->pulse,
            'weight' => $assessment->weight,
            'is_eligible' => $assessment->is_eligible,
            'notes' => $assessment->notes,
            'assessed_by' => $assessment->assessedBy?->name ?? 'Admin',
            'created_at' => $assessment->created_at?->format('Y-m-d H:i:s'),
        ]);
    }

    public function donors(): JsonResponse
    {
        $donors = Donor::select('id', 'full_name', 'blood_group')->orderBy('full_name')->get();
        return response()->json($donors);
    }
}
