<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blood_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('bank_id')->nullable()->constrained('blood_banks')->nullOnDelete();
            $table->string('blood_group');
            $table->integer('quantity');
            $table->string('patient_name')->nullable();
            $table->integer('patient_age')->nullable();
            $table->string('hospital_name')->nullable();
            $table->enum('urgency', ['normal', 'emergency'])->default('normal');
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'fulfilled'])->default('pending');
            $table->timestamp('requested_date')->nullable();
            $table->timestamp('fulfilled_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blood_requests');
    }
};
