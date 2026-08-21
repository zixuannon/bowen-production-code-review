<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Central Finance owns this account directory and its append-only ledger.
     * It deliberately does not import or replace tenant bank_accounts,
     * transactions, or historic Finance rows.
     */
    public function up(): void
    {
        Schema::connection('mysql')->create('central_finance_fund_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('account_uuid')->unique();
            $table->unsignedBigInteger('group_id')->nullable()->index();
            $table->unsignedBigInteger('school_id')->nullable()->index();
            $table->string('owner_type', 16); // hq | school
            $table->string('account_code', 80)->unique();
            $table->string('account_name', 191);
            $table->string('currency', 3);
            $table->decimal('opening_balance', 20, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['school_id', 'is_active'], 'cffa_school_active_index');
        });

        Schema::connection('mysql')->create('central_finance_fund_account_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fund_account_id');
            // Central identity only. This is deliberately not a tenant user
            // ID, and it cannot select a tenant database.
            $table->unsignedBigInteger('user_id');
            $table->boolean('can_view')->default(true);
            $table->boolean('can_operate')->default(false);
            $table->timestamps();

            $table->unique(['fund_account_id', 'user_id'], 'cffau_account_user_unique');
            $table->index(['user_id', 'can_operate'], 'cffau_user_operate_index');
        });

        Schema::connection('mysql')->create('central_finance_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('entry_uuid')->unique();
            // Even an HQ-held amount must identify its beneficiary/operating
            // School. HQ custody is an account property, not a missing
            // reporting dimension.
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('fund_account_id')->index();
            $table->date('entry_date');
            $table->timestamp('occurred_at');
            $table->string('source_type', 80);
            $table->string('source_id', 100);
            $table->string('source_line', 64);
            $table->string('reference_no', 100)->nullable();
            $table->string('transaction_type', 32); // operating_income | operating_expense | internal_transfer
            $table->string('currency', 3);
            $table->decimal('money_in', 20, 4)->default(0);
            $table->decimal('money_out', 20, 4)->default(0);
            $table->decimal('operating_income', 20, 4)->default(0);
            $table->decimal('operating_expense', 20, 4)->default(0);
            $table->string('memo', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // A canonical source can be retried, but may not create the same
            // account movement twice.
            $table->unique(['source_type', 'source_id', 'fund_account_id', 'source_line'], 'cfle_source_account_line_unique');
            $table->index(['school_id', 'entry_date'], 'cfle_school_date_index');
            $table->index(['fund_account_id', 'entry_date'], 'cfle_account_date_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_ledger_entries');
        Schema::connection('mysql')->dropIfExists('central_finance_fund_account_users');
        Schema::connection('mysql')->dropIfExists('central_finance_fund_accounts');
    }
};
