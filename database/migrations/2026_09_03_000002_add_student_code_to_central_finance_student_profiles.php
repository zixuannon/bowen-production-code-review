<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->table('central_finance_student_profiles', function (Blueprint $table): void {
            $table->string('student_code', 100)->nullable()->after('admission_no')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('central_finance_student_profiles', function (Blueprint $table): void {
            $table->dropIndex(['student_code']);
            $table->dropColumn('student_code');
        });
    }
};
