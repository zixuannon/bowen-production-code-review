<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('school')->create('student_fee_assignment_source_locks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->string('source_type', 80);
            $table->string('source_id', 100);
            $table->unsignedBigInteger('student_fee_assignment_item_id');
            $table->timestamps();

            $table->unique(
                ['school_id', 'student_id', 'academic_year_id', 'source_type', 'source_id'],
                'student_fee_assignment_source_lock_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::connection('school')->dropIfExists('student_fee_assignment_source_locks');
    }
};
