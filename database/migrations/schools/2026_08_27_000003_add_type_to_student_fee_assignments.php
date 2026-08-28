<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('school')->table('student_fee_assignments', function (Blueprint $table): void {
            $table->string('assignment_type', 20)->default('initial')->after('class_id');
        });
    }

    public function down(): void
    {
        Schema::connection('school')->table('student_fee_assignments', function (Blueprint $table): void {
            $table->dropColumn('assignment_type');
        });
    }
};
