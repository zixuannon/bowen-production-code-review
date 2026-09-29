<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->create('central_finance_promotions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('promotion_uuid')->unique();
            $table->unsignedBigInteger('group_id')->index();
            $table->string('name', 191);
            $table->string('code', 80);
            $table->text('description')->nullable();
            $table->string('discount_type', 16); // percentage | fixed
            $table->decimal('discount_value', 20, 4);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('status', 16)->default('draft'); // draft | active | inactive | expired
            $table->string('fee_scope', 32)->default('all_approved_fees');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['group_id', 'code'], 'cf_promotion_group_code_unique');
            $table->index(['group_id', 'status', 'valid_from'], 'cf_promotion_group_status_date_index');
        });

        Schema::connection('mysql')->create('central_finance_promotion_school_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('promotion_id')->index();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['promotion_id', 'school_id'], 'cf_promotion_school_unique');
        });

        Schema::connection('mysql')->create('central_finance_promotion_applications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('application_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('receivable_id')->unique();
            $table->unsignedBigInteger('promotion_id')->index();
            $table->unsignedBigInteger('adjustment_id')->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->string('promotion_name_snapshot', 191);
            $table->string('promotion_code_snapshot', 80);
            $table->string('discount_type_snapshot', 16);
            $table->decimal('discount_value_snapshot', 20, 4);
            $table->decimal('gross_amount_snapshot', 20, 4);
            $table->decimal('discount_amount', 20, 4);
            $table->decimal('net_amount_snapshot', 20, 4);
            $table->date('effective_date');
            $table->string('reason', 2000)->nullable();
            $table->unsignedBigInteger('applied_by');
            $table->timestamp('applied_at');
            $table->timestamps();
        });

        Schema::connection('mysql')->table('central_finance_receivable_adjustments', function (Blueprint $table): void {
            $table->date('effective_date')->nullable()->after('adjusted_at');
            $table->decimal('amount_before', 20, 4)->nullable()->after('amount_delta');
            $table->decimal('amount_after', 20, 4)->nullable()->after('amount_before');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('central_finance_receivable_adjustments', function (Blueprint $table): void {
            $table->dropColumn(['effective_date', 'amount_before', 'amount_after']);
        });
        Schema::connection('mysql')->dropIfExists('central_finance_promotion_applications');
        Schema::connection('mysql')->dropIfExists('central_finance_promotion_school_allocations');
        Schema::connection('mysql')->dropIfExists('central_finance_promotions');
    }
};
