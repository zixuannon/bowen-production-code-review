<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** Additive master data only; no balance, Ledger, or legacy-tenant data is changed. */
    public function up(): void
    {
        Schema::connection('mysql')->table('central_finance_fund_accounts', function (Blueprint $table): void {
            $table->string('account_type', 16)->default('other')->after('account_name');
            $table->string('bank_name', 191)->nullable()->after('account_type');
            // Deliberately masked: Central Finance never stores a full bank account number here.
            $table->string('masked_account_identifier', 80)->nullable()->after('bank_name');
            $table->unsignedBigInteger('custodian_user_id')->nullable()->index()->after('masked_account_identifier');
            $table->string('status', 16)->default('active')->after('is_active');
            $table->text('notes')->nullable()->after('status');
            $table->text('status_reason')->nullable()->after('notes');
            $table->unsignedBigInteger('status_changed_by')->nullable()->after('status_reason');
            $table->timestamp('status_changed_at')->nullable()->after('status_changed_by');
            $table->index(['school_id', 'status'], 'cffa_school_status_index');
            $table->index(['owner_type', 'account_type'], 'cffa_owner_type_index');
        });

        // Existing accounts are compatible: their historical active flag becomes the new lifecycle state.
        \Illuminate\Support\Facades\DB::connection('mysql')->table('central_finance_fund_accounts')
            ->where('is_active', true)->update(['status' => 'active', 'account_type' => 'other']);
        \Illuminate\Support\Facades\DB::connection('mysql')->table('central_finance_fund_accounts')
            ->where('is_active', false)->update(['status' => 'inactive', 'account_type' => 'other']);
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('central_finance_fund_accounts', function (Blueprint $table): void {
            $table->dropIndex('cffa_school_status_index');
            $table->dropIndex('cffa_owner_type_index');
            $table->dropIndex(['custodian_user_id']);
            $table->dropColumn(['account_type', 'bank_name', 'masked_account_identifier', 'custodian_user_id', 'status', 'notes', 'status_reason', 'status_changed_by', 'status_changed_at']);
        });
    }
};
