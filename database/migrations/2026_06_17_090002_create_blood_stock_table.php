<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blood_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained('blood_banks')->cascadeOnDelete();
            $table->string('blood_group');
            $table->integer('quantity')->default(0);
            $table->unique(['bank_id', 'blood_group'], 'unique_bank_blood');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blood_stock');
    }
};
