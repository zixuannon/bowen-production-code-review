<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $requiredTables = [
            'fees_paids', 'bank_accounts', 'compulsory_fees', 'optional_fees',
            'other_incomes', 'student_fee_assignment_items',
        ];
        foreach ($requiredTables as $requiredTable) {
            if (!Schema::connection('school')->hasTable($requiredTable)) {
                throw new RuntimeException(
                    "Round 4 financial history migration requires tenant table [{$requiredTable}] before any schema change."
                );
            }
        }

        if (!Schema::connection('school')->hasTable('fee_payment_fx_snapshots')) {
            Schema::connection('school')->create('fee_payment_fx_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('fees_paid_id')->index();
                $table->unsignedBigInteger('bank_account_id')->index();
                $table->string('payment_type', 20);
                $table->string('transaction_currency', 3);
                $table->decimal('original_amount', 20, 4);
                $table->decimal('exchange_rate_snapshot', 20, 8);
                $table->decimal('amount_mmk', 20, 4);
                $table->date('paid_at');
                $table->timestamps();

                $table->foreign('fees_paid_id')->references('id')->on('fees_paids')->restrictOnDelete();
                $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->restrictOnDelete();
            });
        }

        foreach (['compulsory_fees', 'optional_fees'] as $tableName) {
            if (Schema::connection('school')->hasColumn($tableName, 'fee_payment_fx_snapshot_id')) continue;
            Schema::connection('school')->table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('fee_payment_fx_snapshot_id')->nullable()->index()->after('bank_account_id');
                $table->string('transaction_currency', 3)->nullable()->after('fee_payment_fx_snapshot_id');
                $table->decimal('original_amount', 20, 4)->nullable()->after('transaction_currency');
                $table->decimal('exchange_rate_snapshot', 20, 8)->nullable()->after('original_amount');
                $table->decimal('amount_mmk', 20, 4)->nullable()->after('exchange_rate_snapshot');
                $table->foreign('fee_payment_fx_snapshot_id')->references('id')->on('fee_payment_fx_snapshots')->restrictOnDelete();
            });
        }

        if (!Schema::connection('school')->hasColumn('other_incomes', 'transaction_currency')) {
            Schema::connection('school')->table('other_incomes', function (Blueprint $table): void {
                $table->string('transaction_currency', 3)->nullable()->after('amount');
                $table->decimal('original_amount', 20, 4)->nullable()->after('transaction_currency');
                $table->decimal('exchange_rate_snapshot', 20, 8)->nullable()->after('original_amount');
                $table->decimal('amount_mmk', 20, 4)->nullable()->after('exchange_rate_snapshot');
            });
        }

        if (!Schema::connection('school')->hasColumn('student_fee_assignment_items', 'exchange_rate_snapshot')) {
            Schema::connection('school')->table('student_fee_assignment_items', function (Blueprint $table): void {
                $table->decimal('exchange_rate_snapshot', 20, 8)->nullable()->after('currency_snapshot');
                $table->decimal('amount_mmk_snapshot', 20, 4)->nullable()->after('exchange_rate_snapshot');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('school')->table('student_fee_assignment_items', function (Blueprint $table): void {
            $table->dropColumn(['exchange_rate_snapshot', 'amount_mmk_snapshot']);
        });
        Schema::connection('school')->table('other_incomes', function (Blueprint $table): void {
            $table->dropColumn(['transaction_currency', 'original_amount', 'exchange_rate_snapshot', 'amount_mmk']);
        });

        foreach (['optional_fees', 'compulsory_fees'] as $tableName) {
            Schema::connection('school')->table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['fee_payment_fx_snapshot_id']);
                $table->dropColumn(['fee_payment_fx_snapshot_id', 'transaction_currency', 'original_amount', 'exchange_rate_snapshot', 'amount_mmk']);
            });
        }

        Schema::connection('school')->dropIfExists('fee_payment_fx_snapshots');
    }
};
