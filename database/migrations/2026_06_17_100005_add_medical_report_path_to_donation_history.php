<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_history', function (Blueprint $table) {
            $table->string('medical_report_path')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('donation_history', function (Blueprint $table) {
            $table->dropColumn('medical_report_path');
        });
    }
};
