<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Allocation is an additive access/baseline dimension.  The legacy
     * school_id remains the original owner and every finance document/ledger
     * entry continues to carry its own immutable school attribution.
     */
    public function up(): void
    {
        Schema::connection('mysql')->create('central_finance_fund_account_school_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fund_account_id');
            $table->unsignedBigInteger('school_id');
            $table->decimal('opening_allocation_amount', 20, 4)->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('active');
            $table->boolean('is_active')->default(true);
            // Central Finance principal identity.  Historical backfill has no
            // invented actor and therefore remains null.
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->text('assignment_reason');
            $table->timestamps();

            $table->unique(['fund_account_id', 'school_id'], 'cffasa_account_school_unique');
            $table->index(['school_id', 'is_active', 'status'], 'cffasa_school_active_index');
            $table->index(['fund_account_id', 'is_active', 'status'], 'cffasa_account_active_index');
            $table->foreign('fund_account_id', 'cffasa_account_fk')
                ->references('id')->on('central_finance_fund_accounts')->restrictOnDelete();
        });

        // Preserve the permanent legacy owner allocation without rewriting
        // opening balances or financial history.  A soft-deleted account is
        // still represented so its historic School identity remains stable.
        $accounts = DB::connection('mysql')->table('central_finance_fund_accounts')
            ->where('owner_type', 'school')->whereNotNull('school_id')
            ->orderBy('id')->get(['id', 'school_id', 'opening_balance', 'created_at']);
        foreach ($accounts as $account) {
            DB::connection('mysql')->table('central_finance_fund_account_school_allocations')->insertOrIgnore([
                'fund_account_id' => $account->id,
                'school_id' => $account->school_id,
                'opening_allocation_amount' => $account->opening_balance,
                'effective_from' => substr((string) ($account->created_at ?: now()), 0, 10),
                'effective_to' => null,
                'status' => 'active',
                'is_active' => true,
                'assigned_by' => null,
                'assignment_reason' => 'Legacy School owner allocation backfill.',
                'created_at' => $account->created_at ?: now(),
                'updated_at' => $account->created_at ?: now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_fund_account_school_allocations');
    }
};
