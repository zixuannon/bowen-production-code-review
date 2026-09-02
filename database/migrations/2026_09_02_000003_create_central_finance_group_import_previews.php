<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::connection('mysql')->create('central_finance_group_import_batches', function (Blueprint $table): void {
            $table->id(); $table->uuid('batch_uuid')->unique(); $table->string('token',64)->unique(); $table->unsignedBigInteger('finance_group_id')->index(); $table->unsignedBigInteger('uploaded_by')->index(); $table->string('file_name'); $table->string('file_hash',64); $table->string('schema_version',40); $table->string('status',24); $table->unsignedInteger('total_rows')->default(0); $table->unsignedInteger('new_rows')->default(0); $table->unsignedInteger('duplicate_rows')->default(0); $table->unsignedInteger('conflict_rows')->default(0); $table->unsignedInteger('error_rows')->default(0); $table->json('school_summary'); $table->timestamps();
            $table->foreign('finance_group_id', 'cfgib_group_fk')->references('id')->on('finance_groups')->restrictOnDelete();
            $table->foreign('uploaded_by', 'cfgib_uploader_fk')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::connection('mysql')->table('central_finance_import_batches', function (Blueprint $table): void { $table->unsignedBigInteger('group_import_batch_id')->nullable()->index()->after('id'); $table->foreign('group_import_batch_id', 'cfib_group_preview_fk')->references('id')->on('central_finance_group_import_batches')->restrictOnDelete(); });
        Schema::connection('mysql')->create('central_finance_group_import_preview_rows', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('group_batch_id')->index(); $table->unsignedBigInteger('child_batch_id')->nullable()->index(); $table->unsignedInteger('row_number'); $table->unsignedBigInteger('school_id')->nullable()->index(); $table->string('document_type',24)->nullable(); $table->string('reference_no',100)->nullable(); $table->string('result_status',24); $table->string('error_code',80)->nullable(); $table->text('error_message')->nullable(); $table->json('normalized_data'); $table->string('idempotency_key',128)->nullable(); $table->timestamps(); $table->unique(['group_batch_id','row_number'], 'cfgipr_batch_row_unique');
            $table->foreign('group_batch_id', 'cfgipr_parent_fk')->references('id')->on('central_finance_group_import_batches')->restrictOnDelete();
            $table->foreign('child_batch_id', 'cfgipr_child_fk')->references('id')->on('central_finance_import_batches')->nullOnDelete();
            $table->foreign('school_id', 'cfgipr_school_fk')->references('id')->on('schools')->nullOnDelete();
        });
    }
    public function down(): void { Schema::connection('mysql')->dropIfExists('central_finance_group_import_preview_rows'); Schema::connection('mysql')->table('central_finance_import_batches', function (Blueprint $table): void { $table->dropForeign('cfib_group_preview_fk'); $table->dropColumn('group_import_batch_id'); }); Schema::connection('mysql')->dropIfExists('central_finance_group_import_batches'); }
};
