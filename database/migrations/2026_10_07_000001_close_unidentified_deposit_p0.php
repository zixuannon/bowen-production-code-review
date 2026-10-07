<?php

use App\Services\CentralFinanceBankTransactionIdentityService;
use App\Support\CentralFinanceCurrency;
use App\Support\CentralFinanceDecimal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Central only. Requires a quiescent posting window; never scans tenant DBs. */
return new class extends Migration {
    private const IDENTITIES = 'central_finance_bank_transaction_identities';
    private const DEPOSITS = 'central_finance_unidentified_deposits';
    private const ALLOCATIONS = 'central_finance_unidentified_deposit_allocations';
    private const PAYMENTS = 'central_finance_payments';

    public function up(): void
    {
        $schema = Schema::connection('mysql');
        foreach ([self::DEPOSITS, self::ALLOCATIONS, self::PAYMENTS, 'central_finance_fund_accounts'] as $table) {
            if (!$schema->hasTable($table)) throw new RuntimeException("Unidentified Deposit P0 requires [{$table}].");
        }
        // MySQL DDL is not transactional. Refuse partial state instead of
        // silently treating a half-applied rollout as a usable identity fence.
        if ($schema->hasTable(self::IDENTITIES)) throw new RuntimeException('Unidentified Deposit P0 schema already exists; verify migration history or use a reviewed forward fix.');
        foreach ([self::DEPOSITS => ['request_hash', 'manual_identity', 'manual_reason'], self::ALLOCATIONS => ['request_hash', 'payment_id'], self::PAYMENTS => ['request_hash', 'unidentified_deposit_id']] as $table => $columns) {
            foreach ($columns as $column) {
                if ($schema->hasColumn($table, $column)) throw new RuntimeException('Partial Unidentified Deposit P0 schema requires a reviewed forward fix.');
            }
        }

        // Complete this read-only inventory before the first DDL statement.
        // Existing ambiguous facts are never assigned invented identities.
        $historical = $this->historicalIdentities();

        $schema->create(self::IDENTITIES, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fund_account_id');
            $table->string('currency', 3);
            $table->string('identity_hash', 64);
            $table->string('identity_namespace', 24);
            $table->string('normalized_identity', 100);
            $table->text('manual_reason')->nullable();
            $table->string('source_type', 32);
            $table->string('source_id', 64);
            $table->decimal('amount', 20, 4);
            $table->string('payload_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['fund_account_id', 'currency', 'identity_hash'], 'cfbti_physical_identity_unique');
            $table->unique(['source_type', 'source_id'], 'cfbti_origin_unique');
            $table->foreign('fund_account_id', 'cfbti_account_fk')->references('id')->on('central_finance_fund_accounts')->restrictOnDelete();
        });
        $schema->table(self::DEPOSITS, function (Blueprint $table): void {
            $table->string('request_hash', 64)->nullable();
            $table->string('manual_identity', 100)->nullable();
            $table->text('manual_reason')->nullable();
        });
        $schema->table(self::PAYMENTS, function (Blueprint $table): void {
            $table->string('request_hash', 64)->nullable();
            $table->unsignedBigInteger('unidentified_deposit_id')->nullable();
            $table->foreign('unidentified_deposit_id', 'cfp_unidentified_deposit_fk')->references('id')->on(self::DEPOSITS)->restrictOnDelete();
        });
        $schema->table(self::ALLOCATIONS, function (Blueprint $table): void {
            $table->string('request_hash', 64)->nullable();
            // Nullable preserves historical noncanonical allocations as history.
            // New matching always supplies one canonical, immutable Payment.
            $table->unsignedBigInteger('payment_id')->nullable()->unique('cfuda_payment_unique');
            $table->foreign('payment_id', 'cfuda_payment_fk')->references('id')->on(self::PAYMENTS)->restrictOnDelete();
            $table->dropUnique('cfuda_deposit_receivable_unique');
        });
        DB::connection('mysql')->transaction(function () use ($historical): void {
            foreach (array_chunk($historical, 250) as $chunk) DB::connection('mysql')->table(self::IDENTITIES)->insert($chunk);
        });
    }

    /** @return list<array<string,mixed>> */
    private function historicalIdentities(): array
    {
        $db = DB::connection('mysql');
        $accounts = $db->table('central_finance_fund_accounts')->get(['id', 'account_type', 'currency'])->keyBy('id');
        $identities = [];
        $seen = [];
        $origins = [];
        $sources = [[self::DEPOSITS, 'unidentified_deposit', 'bank_reference'], [self::PAYMENTS, 'payment', 'payment_reference']];
        if (Schema::connection('mysql')->hasTable('central_finance_other_incomes')) {
            // Include soft-deleted/voided bank incomes: reversal never frees a
            // physical transaction reference for reuse through another source.
            $sources[] = ['central_finance_other_incomes', 'other_income', 'reference_no'];
        }
        foreach ($sources as [$table, $type, $referenceColumn]) {
            foreach ($db->table($table)->orderBy('id')->cursor() as $row) {
                $account = $accounts->get($row->fund_account_id);
                if ($account === null) throw new RuntimeException("Historical {$type} #{$row->id} has no physical Fund Account; reconcile before migration.");
                if ($type !== 'unidentified_deposit' && $account->account_type !== 'bank') continue;
                $reference = CentralFinanceBankTransactionIdentityService::normalizeReference($row->{$referenceColumn});
                $historicalQa = $type === 'payment' && $row->{$referenceColumn} === null
                    ? app(\App\Services\CentralFinanceHistoricalQaIdentityService::class)->verifiedIdentityForPayment((int) $row->id)
                    : null;
                if ($reference === '' && $historicalQa === null) throw new RuntimeException("Historical {$type} #{$row->id} has no Bank Reference; reconcile its physical identity before migration.");
                $currency = CentralFinanceCurrency::normalize((string) $row->currency);
                if ($currency !== CentralFinanceCurrency::normalize((string) $account->currency)) throw new RuntimeException("Historical {$type} #{$row->id} has a mismatched Fund Account currency.");
                $identity = $historicalQa ?? CentralFinanceBankTransactionIdentityService::identity($reference);
                $key = $account->id.'|'.$currency.'|'.$identity['identity_hash'];
                if (isset($seen[$key])) throw new RuntimeException("Duplicate historical bank transaction: {$seen[$key]} and {$type} #{$row->id}; reconcile before migration.");
                $source = (string) $row->idempotency_key;
                if (!preg_match('/^[a-f0-9]{64}$/D', $source) || isset($origins[$type.'|'.$source])) throw new RuntimeException("Historical {$type} #{$row->id} has an invalid or repeated receipt origin.");
                $amount = CentralFinanceDecimal::normalize((string) $row->amount);
                if (CentralFinanceDecimal::compare($amount, '0') <= 0) throw new RuntimeException("Historical {$type} #{$row->id} has an invalid receipt amount.");
                $seen[$key] = "{$type} #{$row->id}";
                $origins[$type.'|'.$source] = true;
                $identities[] = $identity + [
                    'fund_account_id' => $account->id, 'currency' => $currency,
                    'source_type' => $type, 'source_id' => $source, 'amount' => $amount,
                    'payload_hash' => null, 'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        return $identities;
    }

    public function down(): void
    {
        $db = DB::connection('mysql');
        // A claimed reference remains claimed even if the receipt was reversed.
        // Once identities exist, use a forward fix; never reopen bank money.
        if ($db->table(self::IDENTITIES)->exists()
            || $db->table(self::DEPOSITS)->whereNotNull('request_hash')->exists()
            || $db->table(self::PAYMENTS)->whereNotNull('request_hash')->orWhereNotNull('unidentified_deposit_id')->exists()
            || $db->table(self::ALLOCATIONS)->whereNotNull('request_hash')->orWhereNotNull('payment_id')->exists()
            || $db->table(self::ALLOCATIONS)->select('unidentified_deposit_id', 'receivable_id')->groupBy('unidentified_deposit_id', 'receivable_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Unidentified Deposit P0 is in use; preserve financial history and use a forward fix.');
        }
        $schema = Schema::connection('mysql');
        $sqlite = $db->getDriverName() === 'sqlite';
        $schema->table(self::ALLOCATIONS, function (Blueprint $table) use ($sqlite): void {
            // SQLite fixture ALTER TABLE cannot add/drop these foreign keys;
            // MySQL always installs and explicitly drops them.
            if (!$sqlite) $table->dropForeign('cfuda_payment_fk');
            $table->dropUnique('cfuda_payment_unique');
            $table->dropColumn(['request_hash', 'payment_id']);
            $table->unique(['unidentified_deposit_id', 'receivable_id'], 'cfuda_deposit_receivable_unique');
        });
        $schema->table(self::PAYMENTS, function (Blueprint $table) use ($sqlite): void {
            if (!$sqlite) $table->dropForeign('cfp_unidentified_deposit_fk');
            $table->dropColumn(['request_hash', 'unidentified_deposit_id']);
        });
        $schema->table(self::DEPOSITS, fn (Blueprint $table) => $table->dropColumn(['request_hash', 'manual_identity', 'manual_reason']));
        $schema->drop(self::IDENTITIES);
    }
};
