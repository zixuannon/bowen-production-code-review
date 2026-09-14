<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->create('central_finance_data_classifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('classification_uuid')->unique('cf_data_classification_uuid_unique');
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('subject_scope', 64)->default('central');
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('classification', 20)->default('production');
            $table->text('reason');
            $table->unsignedBigInteger('classified_by');
            $table->timestamps();

            $table->unique(['subject_scope', 'subject_type', 'subject_id'], 'cf_data_classification_subject_unique');
            $table->index(['school_id', 'classification'], 'cf_data_classification_school_index');
            $table->index(['classification', 'subject_scope', 'subject_type'], 'cf_data_classification_state_index');
            $table->foreign('school_id', 'cf_data_classification_school_fk')->references('id')->on('schools')->restrictOnDelete();
            $table->foreign('classified_by', 'cf_data_classification_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::connection('mysql')->create('central_finance_data_classification_audits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('audit_uuid')->unique('cf_data_classification_audit_uuid_unique');
            $table->unsignedBigInteger('classification_id');
            $table->unsignedBigInteger('school_id')->nullable();
            $table->string('subject_scope', 64)->default('central');
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->string('before_classification', 20)->nullable();
            $table->string('after_classification', 20);
            $table->text('reason');
            $table->unsignedBigInteger('actor_id');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['school_id', 'created_at'], 'cf_data_classification_audit_school_index');
            $table->index(['subject_scope', 'subject_type', 'subject_id', 'created_at'], 'cf_data_classification_audit_subject_index');
            $table->foreign('classification_id', 'cf_data_classification_audit_record_fk')->references('id')->on('central_finance_data_classifications')->restrictOnDelete();
            $table->foreign('school_id', 'cf_data_classification_audit_school_fk')->references('id')->on('schools')->restrictOnDelete();
            $table->foreign('actor_id', 'cf_data_classification_audit_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_data_classification_audits');
        Schema::connection('mysql')->dropIfExists('central_finance_data_classifications');
    }
};
