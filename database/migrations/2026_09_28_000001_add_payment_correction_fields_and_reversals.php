<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive, append-only Phase 2A correction documents. */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->table('central_finance_payment_refunds', function (Blueprint $table): void {
            $table->string('refund_method', 40)->nullable()->after('amount');
            $table->date('effective_date')->nullable()->after('reason');
        });

        Schema::connection('mysql')->create('central_finance_payment_reversals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reversal_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('payment_id')->unique();
            $table->unsignedBigInteger('original_receipt_id')->nullable()->index();
            $table->unsignedBigInteger('fund_account_id')->index();
            $table->string('idempotency_key', 64)->unique();
            $table->string('reversal_reference', 100)->nullable();
            $table->decimal('amount', 20, 4);
            $table->string('currency', 3);
            $table->string('reason', 2000);
            $table->date('effective_date');
            $table->timestamp('reversed_at');
            $table->unsignedBigInteger('reversed_by');
            $table->timestamps();
            $table->unique(['school_id', 'reversal_reference'], 'cfprv_school_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_payment_reversals');
        Schema::connection('mysql')->table('central_finance_payment_refunds', function (Blueprint $table): void {
            $table->dropColumn(['refund_method', 'effective_date']);
        });
    }
};
