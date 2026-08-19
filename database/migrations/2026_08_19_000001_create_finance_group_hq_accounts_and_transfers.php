<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HQ accounts and inter-school funding are central control-plane records.
     * They intentionally do not live in, or add a table to, any school tenant.
     */
    public function up(): void
    {
        if (!Schema::connection('mysql')->hasTable('finance_group_hq_accounts')) {
            Schema::connection('mysql')->create('finance_group_hq_accounts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('group_id')->constrained('finance_groups')->cascadeOnDelete();
                $table->string('account_name', 191);
                $table->string('account_number', 128)->nullable();
                $table->string('account_type', 32)->default('bank');
                $table->string('currency', 3);
                $table->decimal('opening_balance', 18, 2)->default(0);
                $table->date('opening_balance_date')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['group_id', 'account_name']);
            });
        }

        if (!Schema::connection('mysql')->hasTable('finance_group_hq_account_users')) {
            Schema::connection('mysql')->create('finance_group_hq_account_users', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('hq_account_id')->constrained('finance_group_hq_accounts')->cascadeOnDelete();
                $table->foreignId('group_user_id')->constrained('finance_group_users')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['hq_account_id', 'group_user_id']);
            });
        }

        if (!Schema::connection('mysql')->hasTable('finance_group_hq_account_adjustments')) {
            Schema::connection('mysql')->create('finance_group_hq_account_adjustments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('hq_account_id')->constrained('finance_group_hq_accounts')->restrictOnDelete();
                $table->decimal('amount', 18, 2);
                $table->decimal('balance_before', 18, 2);
                $table->decimal('balance_after', 18, 2);
                $table->date('adjustment_date');
                $table->text('reason');
                $table->foreignId('created_by_group_user_id')->constrained('finance_group_users')->restrictOnDelete();
                $table->timestamps();
                $table->index(['hq_account_id', 'adjustment_date']);
            });
        }

        if (!Schema::connection('mysql')->hasTable('finance_group_transfers')) {
            Schema::connection('mysql')->create('finance_group_transfers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('group_id')->constrained('finance_groups')->restrictOnDelete();
                $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
                // A School request may leave the HQ account for Head Finance
                // to select at confirmation; it is then immutable.
                $table->foreignId('hq_account_id')->nullable()->constrained('finance_group_hq_accounts')->restrictOnDelete();
                // This is an ID in the selected school's isolated tenant DB,
                // so it deliberately has no central foreign key.
                $table->unsignedBigInteger('tenant_bank_account_id');
                $table->string('direction', 32); // HQ_TO_SCHOOL / SCHOOL_TO_HQ
                $table->string('purpose', 64);
                $table->decimal('amount', 18, 2);
                $table->date('transfer_date');
                $table->string('reference_no', 128)->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 32)->default('pending');
                $table->foreignId('requested_by_group_user_id')->constrained('finance_group_users')->restrictOnDelete();
                $table->timestamp('requested_at');
                $table->foreignId('confirmed_by_group_user_id')->nullable()->constrained('finance_group_users')->restrictOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->foreignId('rejected_by_group_user_id')->nullable()->constrained('finance_group_users')->restrictOnDelete();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->foreignId('cancelled_by_group_user_id')->nullable()->constrained('finance_group_users')->restrictOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->timestamps();
                $table->index(['group_id', 'school_id', 'status']);
                $table->index(['tenant_bank_account_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('finance_group_transfers');
        Schema::connection('mysql')->dropIfExists('finance_group_hq_account_adjustments');
        Schema::connection('mysql')->dropIfExists('finance_group_hq_account_users');
        Schema::connection('mysql')->dropIfExists('finance_group_hq_accounts');
    }
};
