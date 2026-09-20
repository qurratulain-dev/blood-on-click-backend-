<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MedicalAssessment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssessmentController extends Controller
{
    public function index(): JsonResponse
    {
        $assessments = MedicalAssessment::with('user')
            ->orderBy('assessment_date', 'desc')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'user_id' => $a->user_id,
                'donor_name' => $a->user->full_name ?? 'N/A',
                'blood_group' => $a->user->blood_group ?? 'N/A',
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
            'user_id' => 'required|exists:users,id',
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
            'assessment' => $assessment->load('user'),
        ], 201);
    }

    public function show(MedicalAssessment $assessment): JsonResponse
    {
        $assessment->load('user', 'assessedBy');
        return response()->json([
            'id' => $assessment->id,
            'user_id' => $assessment->user_id,
            'donor_name' => $assessment->user->full_name ?? 'N/A',
            'blood_group' => $assessment->user->blood_group ?? 'N/A',
            'age' => $assessment->user->age,
            'gender' => $assessment->user->gender,
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
        $users = User::role('User')
            ->select('id', 'full_name', 'blood_group')
            ->orderBy('full_name')
            ->get();
        return response()->json(
            $users->map(fn ($u) => ['id' => $u->id, 'full_name' => $u->full_name, 'blood_group' => $u->blood_group])
        );
    }
}