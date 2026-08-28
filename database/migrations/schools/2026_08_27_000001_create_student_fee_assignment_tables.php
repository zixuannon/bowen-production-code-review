<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('school')->create('student_fee_assignments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedBigInteger('academic_year_id')->index();
            $table->unsignedBigInteger('class_id')->index();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['student_id', 'status'], 'student_fee_assignments_student_status_index');
        });

        Schema::connection('school')->create('student_fee_assignment_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('student_fee_assignment_id')->index();
            $table->unsignedBigInteger('fee_id')->nullable()->index();
            $table->unsignedBigInteger('fees_class_type_id')->nullable()->index();
            $table->unsignedBigInteger('fees_type_id')->nullable()->index();
            $table->string('description_snapshot');
            $table->date('due_date_snapshot')->nullable();
            $table->decimal('amount_snapshot', 20, 4);
            $table->string('currency_snapshot', 3);
            $table->boolean('optional_snapshot')->default(false);
            $table->string('source_type', 80);
            $table->string('source_id', 100);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['student_fee_assignment_id', 'source_type', 'source_id'], 'student_fee_assignment_item_source_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('school')->dropIfExists('student_fee_assignment_items');
        Schema::connection('school')->dropIfExists('student_fee_assignments');
    }
};
