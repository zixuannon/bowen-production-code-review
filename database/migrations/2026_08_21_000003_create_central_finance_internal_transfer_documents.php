<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->table('central_finance_user_school_scopes', function (Blueprint $table): void {
            $table->boolean('can_confirm_funding')->default(false)->after('can_approve_reimbursements');
        });

        Schema::connection('mysql')->create('central_finance_internal_transfers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('transfer_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('source_account_id')->index();
            $table->unsignedBigInteger('destination_account_id')->index();
            $table->string('source_type', 40);
            $table->string('source_id', 100);
            $table->string('reference_no', 100)->nullable();
            $table->date('transfer_date');
            $table->string('currency', 3);
            $table->decimal('amount', 20, 4);
            $table->string('status', 16); // confirmed only; source document owns pending lifecycle
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('confirmed_by');
            $table->timestamp('confirmed_at');
            $table->timestamps();
            $table->unique(['source_type', 'source_id'], 'cfit_source_unique');
        });

        Schema::connection('mysql')->create('central_finance_fund_handovers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('handover_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('source_account_id')->index();
            $table->unsignedBigInteger('destination_account_id')->index();
            $table->unsignedBigInteger('sender_user_id')->index();
            $table->unsignedBigInteger('receiver_user_id')->index();
            $table->string('idempotency_key', 64)->unique();
            $table->string('reference_no', 100)->nullable();
            $table->date('handover_date');
            $table->string('currency', 3);
            $table->decimal('amount', 20, 4);
            $table->string('status', 16); // pending | confirmed | rejected | cancelled
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_reason', 255)->nullable();
            $table->unsignedBigInteger('internal_transfer_id')->nullable()->unique();
            $table->timestamps();
            $table->unique(['school_id', 'reference_no'], 'cfh_school_reference_unique');
        });

        Schema::connection('mysql')->create('central_finance_hq_funding_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('funding_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('source_account_id')->index();
            $table->unsignedBigInteger('destination_account_id')->index();
            $table->string('direction', 20); // hq_to_school | school_to_hq
            $table->string('idempotency_key', 64)->unique();
            $table->string('reference_no', 100)->nullable();
            $table->date('funding_date');
            $table->string('currency', 3);
            $table->decimal('amount', 20, 4);
            $table->string('status', 16); // pending | confirmed | rejected | cancelled
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_reason', 255)->nullable();
            $table->unsignedBigInteger('internal_transfer_id')->nullable()->unique();
            $table->timestamps();
            $table->unique(['school_id', 'reference_no'], 'cfhfr_school_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_hq_funding_requests');
        Schema::connection('mysql')->dropIfExists('central_finance_fund_handovers');
        Schema::connection('mysql')->dropIfExists('central_finance_internal_transfers');
        Schema::connection('mysql')->table('central_finance_user_school_scopes', function (Blueprint $table): void {
            $table->dropColumn('can_confirm_funding');
        });
    }
};
