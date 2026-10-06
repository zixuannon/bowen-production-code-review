<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('mysql')->create('central_finance_qa_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('run_uuid')->unique('cf_qa_runs_uuid_uq');
            $table->unsignedBigInteger('school_id');
            $table->unsignedInteger('run_number');
            $table->string('label', 191);
            $table->string('status', 24);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->json('completion_summary')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->string('archive_reason', 2000)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'run_number'], 'cf_qa_runs_school_number_uq');
            $table->index(['school_id', 'status'], 'cf_qa_runs_school_status_ix');
            $table->foreign('school_id', 'cf_qa_runs_school_fk')->references('id')->on('schools')->restrictOnDelete();
            $table->foreign('created_by', 'cf_qa_runs_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('completed_by', 'cf_qa_runs_completer_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('archived_by', 'cf_qa_runs_archiver_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::connection('mysql')->create('central_finance_qa_run_records', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('qa_run_id');
            $table->unsignedBigInteger('school_id');
            $table->string('subject_scope', 80);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('source_identity', 191)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['subject_scope', 'subject_type', 'subject_id'], 'cf_qa_run_records_subject_uq');
            $table->unique(['school_id', 'subject_type', 'source_identity'], 'cf_qa_run_records_source_uq');
            $table->index(['qa_run_id', 'subject_type', 'subject_id'], 'cf_qa_run_records_run_subject_ix');
            $table->index(['school_id', 'subject_type', 'subject_id'], 'cf_qa_run_records_school_subject_ix');
            $table->foreign('qa_run_id', 'cf_qa_run_records_run_fk')->references('id')->on('central_finance_qa_runs')->restrictOnDelete();
            $table->foreign('school_id', 'cf_qa_run_records_school_fk')->references('id')->on('schools')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_qa_run_records');
        Schema::connection('mysql')->dropIfExists('central_finance_qa_runs');
    }
};
