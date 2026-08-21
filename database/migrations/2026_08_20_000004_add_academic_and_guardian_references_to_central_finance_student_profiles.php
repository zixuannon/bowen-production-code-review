<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * These are tenant-owned reference projections only. They deliberately
     * exclude passwords, DOB, addresses, documents, and any Finance amount.
     */
    public function up(): void
    {
        Schema::connection('mysql')->table('central_finance_student_profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('class_id')->nullable()->after('tenant_student_id');
            $table->unsignedBigInteger('class_section_id')->nullable()->after('class_id');
            $table->string('class_name', 191)->nullable()->after('class_section_id');
            $table->string('section_name', 191)->nullable()->after('class_name');
            $table->string('guardian_name', 191)->nullable()->after('student_name');
            $table->string('guardian_email', 191)->nullable()->after('guardian_name');
            $table->string('guardian_mobile', 64)->nullable()->after('guardian_email');
            $table->string('tenant_user_status', 32)->nullable()->after('enrollment_status');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('central_finance_student_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'class_id', 'class_section_id', 'class_name', 'section_name',
                'guardian_name', 'guardian_email', 'guardian_mobile', 'tenant_user_status',
            ]);
        });
    }
};
