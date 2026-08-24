<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Import batches contain only file/preview/audit metadata. They are never
     * a source of Finance documents, balances, or Standard Ledger entries.
     */
    public function up(): void
    {
        Schema::connection('mysql')->create('central_finance_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_uuid')->unique();
            $table->string('token', 64)->unique();
            $table->string('import_type', 40);
            $table->string('template_version', 40);
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('uploaded_by')->index();
            $table->unsignedBigInteger('confirmed_by')->nullable()->index();
            $table->string('file_name', 255);
            $table->string('file_hash', 64);
            $table->json('preview_data');
            $table->json('summary');
            $table->string('status', 24); // pending | processing | completed | failed | expired
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'import_type', 'file_hash'], 'cfib_school_type_file_unique');
            $table->index(['school_id', 'import_type', 'status'], 'cfib_school_type_status_index');
        });

        Schema::connection('mysql')->create('central_finance_import_row_reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('school_id');
            $table->string('import_type', 40);
            $table->unsignedInteger('row_number');
            $table->string('idempotency_key', 128);
            $table->string('status', 24); // reserved | confirmed | released
            $table->timestamps();

            $table->unique(['school_id', 'import_type', 'idempotency_key'], 'cfirr_school_type_key_unique');
            $table->unique(['batch_id', 'row_number'], 'cfirr_batch_row_unique');
            $table->index(['batch_id', 'status'], 'cfirr_batch_status_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_import_row_reservations');
        Schema::connection('mysql')->dropIfExists('central_finance_import_batches');
    }
};
