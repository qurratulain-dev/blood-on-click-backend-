<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_assessments', function (Blueprint $table) {
            $table->decimal('temperature', 4, 1)->nullable()->after('hemoglobin');
            $table->integer('pulse')->nullable()->after('temperature');
            $table->decimal('weight', 5, 1)->nullable()->after('pulse');
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete()->after('weight');
        });
    }

    public function down(): void
    {
        Schema::table('medical_assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assessed_by');
            $table->dropColumn(['temperature', 'pulse', 'weight']);
        });
    }
};
