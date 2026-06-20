<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_id')->constrained('donors')->cascadeOnDelete();
            $table->date('assessment_date');
            $table->string('blood_pressure', 20)->nullable();
            $table->decimal('hemoglobin', 4, 1)->nullable();
            $table->decimal('temperature', 4, 1)->nullable()->after('hemoglobin');
            $table->integer('pulse')->nullable()->after('temperature');
            $table->decimal('weight', 5, 1)->nullable()->after('pulse');
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete()->after('weight');
            $table->boolean('is_eligible')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_assessments');
    }
};
