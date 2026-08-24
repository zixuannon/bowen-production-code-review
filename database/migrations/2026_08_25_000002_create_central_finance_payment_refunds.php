<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->create('central_finance_payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('refund_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('payment_id')->index();
            $table->unsignedBigInteger('original_receipt_id')->nullable()->index();
            $table->unsignedBigInteger('fund_account_id')->index();
            $table->string('idempotency_key', 64)->unique();
            $table->string('refund_reference', 100)->nullable();
            $table->decimal('amount', 20, 4);
            $table->string('currency', 3);
            $table->string('reason', 2000);
            $table->timestamp('refunded_at');
            $table->unsignedBigInteger('refunded_by');
            $table->timestamps();
            $table->unique(['school_id', 'refund_reference'], 'cfpr_school_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_payment_refunds');
    }
};
