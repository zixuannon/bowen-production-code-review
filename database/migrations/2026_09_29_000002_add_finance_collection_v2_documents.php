<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection('mysql');
        foreach (['central_finance_payments', 'central_finance_pending_collections', 'central_finance_receivables', 'central_finance_ledger_entries', 'central_finance_document_audits'] as $table) {
            if (! $schema->hasTable($table)) throw new RuntimeException("Finance Collection V2 requires [{$table}].");
        }

        if (! $schema->hasTable('central_finance_payment_allocations')) {
            $schema->create('central_finance_payment_allocations', function (Blueprint $table): void {
                $table->id();
                $table->uuid('allocation_uuid')->unique('cfpa_alloc_uuid_unique');
                $table->unsignedBigInteger('payment_id')->index('cfpa_payment_index');
                $table->unsignedBigInteger('receivable_id')->index('cfpa_receivable_index');
                $table->unsignedBigInteger('school_id')->index('cfpa_school_index');
                $table->unsignedBigInteger('student_profile_id')->index('cfpa_profile_index');
                // A payment parent is immutable, so its allocation must keep
                // the line that the payer actually settled, not reconstruct it
                // from a mutable fee or receivable projection later.
                $table->string('description_snapshot', 191);
                $table->decimal('unit_price_snapshot', 20, 4)->nullable();
                $table->unsignedInteger('quantity_snapshot')->default(1);
                $table->decimal('gross_amount_snapshot', 20, 4);
                $table->decimal('promotion_amount_snapshot', 20, 4)->default(0);
                $table->decimal('net_due_snapshot', 20, 4);
                $table->decimal('paid_before_snapshot', 20, 4)->default(0);
                $table->decimal('outstanding_before_snapshot', 20, 4);
                $table->decimal('amount', 20, 4);
                $table->string('currency', 3);
                $table->timestamp('allocated_at');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['payment_id', 'receivable_id'], 'cfpa_payment_receivable_unique');
                $table->index(['receivable_id', 'currency'], 'cfpa_receivable_currency_index');
            });
        }

        if (! $schema->hasTable('central_finance_pending_collection_allocations')) {
            $schema->create('central_finance_pending_collection_allocations', function (Blueprint $table): void {
                $table->id();
                $table->uuid('allocation_uuid')->unique('cfpca_alloc_uuid_unique');
                $table->unsignedBigInteger('pending_collection_id')->index('cfpca_pending_index');
                $table->unsignedBigInteger('receivable_id')->index('cfpca_receivable_index');
                $table->unsignedBigInteger('school_id')->index('cfpca_school_index');
                $table->unsignedBigInteger('student_profile_id')->index('cfpca_profile_index');
                $table->string('description_snapshot', 191);
                $table->decimal('unit_price_snapshot', 20, 4)->nullable();
                $table->unsignedInteger('quantity_snapshot')->default(1);
                $table->decimal('gross_amount_snapshot', 20, 4);
                $table->decimal('promotion_amount_snapshot', 20, 4)->default(0);
                $table->decimal('net_due_snapshot', 20, 4);
                $table->decimal('paid_before_snapshot', 20, 4)->default(0);
                $table->decimal('outstanding_before_snapshot', 20, 4);
                $table->decimal('amount', 20, 4);
                $table->string('currency', 3);
                $table->timestamps();
                $table->unique(['pending_collection_id', 'receivable_id'], 'cfpca_pending_receivable_unique');
                $table->index(['receivable_id', 'currency'], 'cfpca_receivable_currency_index');
            });
        }

        if (! $schema->hasTable('central_finance_unidentified_deposits')) {
            $schema->create('central_finance_unidentified_deposits', function (Blueprint $table): void {
                $table->id();
                $table->uuid('deposit_uuid')->unique('cfud_deposit_uuid_unique');
                $table->unsignedBigInteger('group_id')->nullable()->index('cfud_group_index');
                $table->unsignedBigInteger('fund_account_id')->index('cfud_account_index');
                $table->string('idempotency_key', 64)->unique('cfud_idempotency_unique');
                $table->string('bank_reference', 100)->nullable();
                $table->text('description')->nullable();
                $table->string('known_payer', 191)->nullable();
                $table->decimal('amount', 20, 4);
                $table->string('currency', 3);
                $table->string('status', 24)->default('unidentified')->index('cfud_status_index');
                $table->date('received_date');
                $table->timestamp('recorded_at');
                $table->unsignedBigInteger('recorded_by');
                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->text('reversal_reason')->nullable();
                $table->timestamps();
                $table->unique(['fund_account_id', 'bank_reference'], 'cfud_account_reference_unique');
                $table->index(['fund_account_id', 'received_date'], 'cfud_account_received_index');
            });
        }

        if (! $schema->hasTable('central_finance_promotion_fee_allocations')) {
            $schema->create('central_finance_promotion_fee_allocations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('promotion_id')->index('cfpfa_promotion_index');
                $table->unsignedBigInteger('school_id')->index('cfpfa_school_index');
                // Tenant Fee Setup's immutable source identity. It is never
                // supplied as a monetary value by Front Desk.
                $table->unsignedBigInteger('fees_class_type_id')->index('cfpfa_fee_type_index');
                $table->string('status', 16)->default('active');
                $table->timestamps();
                $table->unique(['promotion_id', 'school_id', 'fees_class_type_id'], 'cfpfa_promotion_school_fee_unique');
            });
        }

        if (! $schema->hasTable('central_finance_unidentified_deposit_allocations')) {
            $schema->create('central_finance_unidentified_deposit_allocations', function (Blueprint $table): void {
                $table->id();
                $table->uuid('allocation_uuid')->unique('cfuda_alloc_uuid_unique');
                $table->unsignedBigInteger('unidentified_deposit_id')->index('cfuda_deposit_index');
                $table->unsignedBigInteger('school_id')->index('cfuda_school_index');
                $table->unsignedBigInteger('student_profile_id')->index('cfuda_profile_index');
                $table->unsignedBigInteger('receivable_id')->index('cfuda_receivable_index');
                $table->string('idempotency_key', 64)->unique('cfuda_idempotency_unique');
                $table->decimal('amount', 20, 4);
                $table->string('currency', 3);
                $table->timestamp('matched_at');
                $table->unsignedBigInteger('matched_by');
                $table->text('reason');
                $table->timestamps();
                $table->unique(['unidentified_deposit_id', 'receivable_id'], 'cfuda_deposit_receivable_unique');
            });
        }

        if (! $schema->hasColumn('central_finance_receivables', 'unit_price_snapshot')) {
            $schema->table('central_finance_receivables', function (Blueprint $table): void {
                $table->decimal('unit_price_snapshot', 20, 4)->nullable()->after('description');
                $table->unsignedInteger('quantity_snapshot')->nullable()->after('unit_price_snapshot');
            });
        }

        // Parent documents remain backward-compatible for every historic
        // one-receivable collection. New Collection V2 documents use their
        // immutable allocation rows as the canonical settlement source.
        $schema->table('central_finance_payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('receivable_id')->nullable()->change();
        });
        $schema->table('central_finance_pending_collections', function (Blueprint $table): void {
            $table->unsignedBigInteger('receivable_id')->nullable()->change();
        });

        // An Unidentified Deposit is real group-held physical money, not
        // School operating income. Its ledger entry must not fabricate a
        // School attribution merely to satisfy an old NOT NULL column.
        $schema->table('central_finance_ledger_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('school_id')->nullable()->change();
        });
        $schema->table('central_finance_document_audits', function (Blueprint $table): void {
            $table->unsignedBigInteger('school_id')->nullable()->change();
        });

        // Derived, one-to-one allocation rows preserve query compatibility
        // without changing historic amounts, payments, receipts, or ledger.
        $payments = DB::connection('mysql')->table('central_finance_payments')->whereNotNull('receivable_id')->orderBy('id')->get();
        foreach ($payments as $payment) {
            $exists = DB::connection('mysql')->table('central_finance_payment_allocations')->where(['payment_id' => $payment->id, 'receivable_id' => $payment->receivable_id])->exists();
            if (! $exists) {
                DB::connection('mysql')->table('central_finance_payment_allocations')->insert([
                    'allocation_uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'payment_id' => $payment->id,
                    'receivable_id' => $payment->receivable_id,
                    'school_id' => $payment->school_id,
                    'student_profile_id' => DB::connection('mysql')->table('central_finance_receivables')->where('id', $payment->receivable_id)->value('student_profile_id'),
                    'description_snapshot' => DB::connection('mysql')->table('central_finance_receivables')->where('id', $payment->receivable_id)->value('description') ?? 'Historical receivable',
                    'unit_price_snapshot' => DB::connection('mysql')->table('central_finance_receivables')->where('id', $payment->receivable_id)->value('unit_price_snapshot'),
                    'quantity_snapshot' => DB::connection('mysql')->table('central_finance_receivables')->where('id', $payment->receivable_id)->value('quantity_snapshot') ?? 1,
                    'gross_amount_snapshot' => DB::connection('mysql')->table('central_finance_receivables')->where('id', $payment->receivable_id)->value('source_amount_due') ?? $payment->amount,
                    'promotion_amount_snapshot' => '0.0000',
                    'net_due_snapshot' => DB::connection('mysql')->table('central_finance_receivables')->where('id', $payment->receivable_id)->value('amount_due') ?? $payment->amount,
                    // Legacy values are derived compatibility metadata only.
                    // They never alter the canonical historical Payment.
                    'paid_before_snapshot' => '0.0000',
                    'outstanding_before_snapshot' => DB::connection('mysql')->table('central_finance_receivables')->where('id', $payment->receivable_id)->value('amount_due') ?? $payment->amount,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'allocated_at' => $payment->paid_at,
                    'created_by' => $payment->received_by,
                    'created_at' => $payment->created_at,
                    'updated_at' => $payment->updated_at,
                ]);
            }
        }
        $pendingRows = DB::connection('mysql')->table('central_finance_pending_collections')->whereNotNull('receivable_id')->orderBy('id')->get();
        foreach ($pendingRows as $pending) {
            $receivable = DB::connection('mysql')->table('central_finance_receivables')->where('id', $pending->receivable_id)->first();
            if ($receivable === null) continue;
            $exists = DB::connection('mysql')->table('central_finance_pending_collection_allocations')->where(['pending_collection_id' => $pending->id, 'receivable_id' => $pending->receivable_id])->exists();
            if (! $exists) {
                DB::connection('mysql')->table('central_finance_pending_collection_allocations')->insert([
                    'allocation_uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'pending_collection_id' => $pending->id,
                    'receivable_id' => $pending->receivable_id,
                    'school_id' => $pending->school_id,
                    'student_profile_id' => $pending->student_profile_id,
                    'description_snapshot' => $receivable->description,
                    'unit_price_snapshot' => $receivable->unit_price_snapshot,
                    'quantity_snapshot' => $receivable->quantity_snapshot ?? 1,
                    'gross_amount_snapshot' => $receivable->source_amount_due ?? $receivable->amount_due,
                    'promotion_amount_snapshot' => bccomp((string) ($receivable->source_amount_due ?? $receivable->amount_due), (string) $receivable->amount_due, 4) > 0
                        ? bcsub((string) ($receivable->source_amount_due ?? $receivable->amount_due), (string) $receivable->amount_due, 4)
                        : '0.0000',
                    'net_due_snapshot' => $receivable->amount_due,
                    'paid_before_snapshot' => $receivable->amount_paid,
                    'outstanding_before_snapshot' => bccomp((string) $receivable->amount_due, (string) $receivable->amount_paid, 4) > 0
                        ? bcsub((string) $receivable->amount_due, (string) $receivable->amount_paid, 4)
                        : '0.0000',
                    'amount' => $pending->amount,
                    'currency' => $pending->currency,
                    'created_at' => $pending->created_at,
                    'updated_at' => $pending->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        $connection = DB::connection('mysql');
        // A rollback is safe only before a V2-only business document exists.
        // Never turn a multi-receivable parent or a group-level deposit into
        // malformed legacy data merely to make a migration command succeed.
        if ($connection->table('central_finance_payments')->whereNull('receivable_id')->exists()
            || $connection->table('central_finance_pending_collections')->whereNull('receivable_id')->exists()
            || $connection->table('central_finance_ledger_entries')->whereNull('school_id')->exists()
            || $connection->table('central_finance_document_audits')->whereNull('school_id')->exists()
            || $connection->table('central_finance_unidentified_deposits')->exists()) {
            throw new RuntimeException('Finance Collection V2 has created documents that cannot be represented safely by the prior schema. Use a forward repair, not rollback.');
        }

        $schema = Schema::connection('mysql');
        $schema->dropIfExists('central_finance_unidentified_deposit_allocations');
        $schema->dropIfExists('central_finance_unidentified_deposits');
        $schema->dropIfExists('central_finance_promotion_fee_allocations');
        $schema->dropIfExists('central_finance_pending_collection_allocations');
        $schema->dropIfExists('central_finance_payment_allocations');
        $schema->table('central_finance_payments', fn (Blueprint $table) => $table->unsignedBigInteger('receivable_id')->nullable(false)->change());
        $schema->table('central_finance_pending_collections', fn (Blueprint $table) => $table->unsignedBigInteger('receivable_id')->nullable(false)->change());
        $schema->table('central_finance_ledger_entries', fn (Blueprint $table) => $table->unsignedBigInteger('school_id')->nullable(false)->change());
        $schema->table('central_finance_document_audits', fn (Blueprint $table) => $table->unsignedBigInteger('school_id')->nullable(false)->change());
        if ($schema->hasColumn('central_finance_receivables', 'unit_price_snapshot')) {
            $schema->table('central_finance_receivables', fn (Blueprint $table) => $table->dropColumn(['unit_price_snapshot', 'quantity_snapshot']));
        }
    }
};
