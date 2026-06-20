<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('phone');
            $table->string('blood_group');
            $table->text('address');
            $table->integer('age');
            $table->decimal('weight', 5, 2);
            $table->date('last_donation_date')->nullable()->after('weight');
            $table->enum('gender', ['Male', 'Female', 'Other']);
            $table->enum('status', ['available', 'not_available'])->default('available');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donors');
    }
};
