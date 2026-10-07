<?php

namespace Tests\Feature;

use App\Models\CentralFinanceFundAccount;
use App\Services\CentralFinanceBankTransactionIdentityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/** Every connection in this fixture is disposable in-memory SQLite. */
final class CentralFinanceBankTransactionIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.mysql', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
        $schema = Schema::connection('mysql');
        $schema->create('central_finance_fund_accounts', function (Blueprint $t): void {
            $t->id(); $t->string('account_type'); $t->string('currency', 3); $t->boolean('is_active')->default(true); $t->string('status')->default('active'); $t->softDeletes();
        });
        foreach (['central_finance_payments' => 'payment_reference', 'central_finance_unidentified_deposits' => 'bank_reference'] as $name => $reference) {
            $schema->create($name, function (Blueprint $t) use ($reference): void {
                $t->id(); $t->unsignedBigInteger('fund_account_id'); $t->string('currency', 3); $t->decimal('amount', 20, 4);
                $t->string('idempotency_key', 64)->unique(); $t->string($reference)->nullable();
            });
        }
        $schema->create('central_finance_unidentified_deposit_allocations', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('unidentified_deposit_id'); $t->unsignedBigInteger('receivable_id');
            $t->unique(['unidentified_deposit_id', 'receivable_id'], 'cfuda_deposit_receivable_unique');
        });
        DB::connection('mysql')->table('central_finance_fund_accounts')->insert([
            ['id' => 1, 'account_type' => 'bank', 'currency' => 'MMK'],
            ['id' => 2, 'account_type' => 'bank', 'currency' => 'MMK'],
            ['id' => 3, 'account_type' => 'cash', 'currency' => 'MMK'],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_07_000001_close_unidentified_deposit_p0.php');
    }

    private function reserve(string $source, string $origin, ?string $reference, string $amount = '500000', ?string $manual = null, ?string $reason = null, ?string $payload = null, int $account = 1): \App\Models\CentralFinanceBankTransactionIdentity
    {
        return DB::connection('mysql')->transaction(fn () => app(CentralFinanceBankTransactionIdentityService::class)->reserve(
            CentralFinanceFundAccount::on('mysql')->findOrFail($account), 'MMK', $amount, $source, hash('sha256', $origin), $reference, $manual, $reason, $payload,
        ));
    }

    public function test_exact_origin_retry_returns_the_same_identity_and_normalizes_reference(): void
    {
        $this->migration()->up();
        $first = $this->reserve('unidentified_deposit', 'one', ' bank  001 ');
        $retry = $this->reserve('unidentified_deposit', 'one', "BANK\t001", '500000.0000');
        $this->assertSame($first->id, $retry->id);
        $this->assertSame('BANK 001', $first->normalized_identity);
        $this->assertSame(1, DB::connection('mysql')->table('central_finance_bank_transaction_identities')->count());
    }

    public function test_duplicate_cross_origin_amount_or_payload_is_rejected(): void
    {
        $this->migration()->up();
        $this->reserve('unidentified_deposit', 'one', 'BANK-001', payload: hash('sha256', 'facts'));
        foreach ([['payment', 'two', '500000', hash('sha256', 'facts')], ['unidentified_deposit', 'one', '499999', hash('sha256', 'facts')], ['unidentified_deposit', 'one', '500000', hash('sha256', 'changed')]] as [$type, $origin, $amount, $payload]) {
            try {
                $this->reserve($type, $origin, 'bank-001', $amount, payload: $payload);
                $this->fail('A conflicting bank transaction must be rejected.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('another origin or content', $e->getMessage());
            }
        }
        $this->assertSame(1, DB::connection('mysql')->table('central_finance_bank_transaction_identities')->count());
    }

    public function test_reference_is_scoped_by_physical_account_but_origin_cannot_change_reference(): void
    {
        $this->migration()->up();
        $one = $this->reserve('payment', 'one', 'BANK-001');
        $two = $this->reserve('payment', 'two', 'BANK-001', account: 2);
        $this->assertNotSame($one->id, $two->id);
        $this->expectException(InvalidArgumentException::class);
        $this->reserve('payment', 'one', 'BANK-002');
    }

    public function test_empty_reference_requires_manual_identity_and_reason_and_cannot_alias_bank_reference(): void
    {
        $this->migration()->up();
        foreach ([[null, null], ['manual-1', null], [null, 'Verified statement line.']] as [$manual, $reason]) {
            try { $this->reserve('payment', 'one', null, manual: $manual, reason: $reason); $this->fail(); }
            catch (InvalidArgumentException $e) { $this->assertStringContainsString('explicit manual', $e->getMessage()); }
        }
        $manual = $this->reserve('unidentified_deposit', 'one', null, manual: 'statement-15-line-2', reason: 'Verified original statement line with bank.');
        $this->assertSame('manual', $manual->identity_namespace);
        $this->assertSame('Verified original statement line with bank.', $manual->manual_reason);
        $this->expectException(InvalidArgumentException::class);
        $this->reserve('payment', 'two', 'STATEMENT-15-LINE-2');
    }

    public function test_rollback_releases_only_uncommitted_reservation(): void
    {
        $this->migration()->up();
        try {
            DB::connection('mysql')->transaction(function (): void {
                $this->reserve('unidentified_deposit', 'one', 'BANK-001');
                throw new RuntimeException('Simulated document posting failure.');
            });
        } catch (RuntimeException) {}
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_bank_transaction_identities')->count());
        $this->assertNotNull($this->reserve('payment', 'two', 'BANK-001')->id);
    }

    public function test_reservation_requires_atomic_posting_transaction(): void
    {
        $this->migration()->up();
        $this->expectException(LogicException::class);
        app(CentralFinanceBankTransactionIdentityService::class)->reserve(CentralFinanceFundAccount::on('mysql')->findOrFail(1), 'MMK', '500000', 'payment', hash('sha256', 'one'), 'BANK-001');
    }

    public function test_missing_schema_and_inactive_accounts_fail_closed(): void
    {
        try { $this->reserve('payment', 'one', 'BANK-001'); $this->fail(); }
        catch (LogicException $e) { $this->assertStringContainsString('migration is required', $e->getMessage()); }
        $this->migration()->up();
        DB::connection('mysql')->table('central_finance_fund_accounts')->where('id', 1)->update(['is_active' => false]);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->reserve('payment', 'one', 'BANK-001');
    }

    public function test_account_currency_mismatch_is_rejected(): void
    {
        $this->migration()->up();
        DB::connection('mysql')->table('central_finance_fund_accounts')->where('id', 1)->update(['currency' => 'USD']);
        $this->expectException(InvalidArgumentException::class);
        $this->reserve('payment', 'one', 'BANK-001');
    }

    public function test_unused_migration_rolls_back_and_reapplies(): void
    {
        $this->migration()->up();
        $this->migration()->down();
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_bank_transaction_identities'));
        $this->assertFalse(Schema::connection('mysql')->hasColumn('central_finance_unidentified_deposit_allocations', 'payment_id'));
        $this->migration()->up();
        $this->assertNotNull($this->reserve('payment', 'one', 'BANK-001')->id);
    }

    public function test_partial_schema_requires_forward_fix_before_other_changes(): void
    {
        Schema::connection('mysql')->table('central_finance_payments', fn (Blueprint $t) => $t->string('request_hash', 64)->nullable());
        try { $this->migration()->up(); $this->fail(); }
        catch (RuntimeException $e) { $this->assertStringContainsString('Partial Unidentified Deposit P0 schema', $e->getMessage()); }
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_bank_transaction_identities'));
        $this->assertFalse(Schema::connection('mysql')->hasColumn('central_finance_unidentified_deposits', 'request_hash'));
    }

    public function test_identity_model_is_immutable_and_used_migration_cannot_roll_back(): void
    {
        $this->migration()->up();
        $identity = $this->reserve('payment', 'one', 'BANK-001');
        foreach ([fn () => $identity->update(['amount' => '1']), fn () => $identity->delete(), fn () => $this->migration()->down()] as $operation) {
            try { $operation(); $this->fail('Committed bank identity history must be retained.'); }
            catch (RuntimeException) { $this->assertSame(1, DB::connection('mysql')->table('central_finance_bank_transaction_identities')->count()); }
        }
    }

    public function test_migration_backfills_bank_payment_and_deposit_references_without_rewriting_history(): void
    {
        $db = DB::connection('mysql');
        $db->table('central_finance_payments')->insert([
            ['fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '500000', 'idempotency_key' => hash('sha256', 'old-payment'), 'payment_reference' => ' ref001 '],
            ['fund_account_id' => 3, 'currency' => 'MMK', 'amount' => '1', 'idempotency_key' => hash('sha256', 'old-cash'), 'payment_reference' => null],
        ]);
        $db->table('central_finance_unidentified_deposits')->insert(['fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '400000', 'idempotency_key' => hash('sha256', 'old-deposit'), 'bank_reference' => 'REF002']);
        $this->migration()->up();
        $this->assertSame(2, $db->table('central_finance_bank_transaction_identities')->count());
        $this->assertSame(' ref001 ', $db->table('central_finance_payments')->where('id', 1)->value('payment_reference'));
        $this->expectException(InvalidArgumentException::class);
        $this->reserve('unidentified_deposit', 'new', 'REF001');
    }

    public function test_migration_rejects_cross_source_duplicates_before_any_ddl(): void
    {
        $db = DB::connection('mysql');
        $db->table('central_finance_payments')->insert(['fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '500000', 'idempotency_key' => hash('sha256', 'payment'), 'payment_reference' => 'REF001']);
        $db->table('central_finance_unidentified_deposits')->insert(['fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '500000', 'idempotency_key' => hash('sha256', 'deposit'), 'bank_reference' => ' ref001 ']);
        try { $this->migration()->up(); $this->fail(); }
        catch (RuntimeException $e) { $this->assertStringContainsString('Duplicate historical bank transaction', $e->getMessage()); }
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_bank_transaction_identities'));
        $this->assertFalse(Schema::connection('mysql')->hasColumn('central_finance_payments', 'request_hash'));
    }

    private function historicalIncomeTable(): void
    {
        Schema::connection('mysql')->create('central_finance_other_incomes', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('fund_account_id'); $t->string('currency', 3); $t->decimal('amount', 20, 4);
            $t->string('idempotency_key', 64)->unique(); $t->string('reference_no')->nullable(); $t->softDeletes();
        });
    }

    public function test_migration_reserves_voided_historical_bank_income_reference(): void
    {
        $this->historicalIncomeTable();
        DB::connection('mysql')->table('central_finance_other_incomes')->insert([
            'fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '500000', 'idempotency_key' => hash('sha256', 'old-income'),
            'reference_no' => ' old-income ', 'deleted_at' => now(),
        ]);
        $this->migration()->up();
        $identity = DB::connection('mysql')->table('central_finance_bank_transaction_identities')->first();
        $this->assertSame('other_income', $identity->source_type);
        $this->assertSame(hash('sha256', 'old-income'), $identity->source_id);
        $this->expectException(InvalidArgumentException::class);
        $this->reserve('unidentified_deposit', 'new', 'OLD-INCOME');
    }

    public function test_migration_rejects_historical_income_deposit_collision_before_ddl(): void
    {
        $this->historicalIncomeTable();
        DB::connection('mysql')->table('central_finance_other_incomes')->insert(['fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '500000', 'idempotency_key' => hash('sha256', 'old-income'), 'reference_no' => 'BANK-001']);
        DB::connection('mysql')->table('central_finance_unidentified_deposits')->insert(['fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '500000', 'idempotency_key' => hash('sha256', 'old-deposit'), 'bank_reference' => 'bank-001']);
        try { $this->migration()->up(); $this->fail(); }
        catch (RuntimeException $e) { $this->assertStringContainsString('Duplicate historical bank transaction', $e->getMessage()); }
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_bank_transaction_identities'));
    }

    public function test_migration_rejects_historical_bank_income_without_reference(): void
    {
        $this->historicalIncomeTable();
        DB::connection('mysql')->table('central_finance_other_incomes')->insert(['fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '500000', 'idempotency_key' => hash('sha256', 'old-income'), 'reference_no' => null]);
        try { $this->migration()->up(); $this->fail(); }
        catch (RuntimeException $e) { $this->assertStringContainsString('no Bank Reference', $e->getMessage()); }
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_bank_transaction_identities'));
    }

    public function test_migration_rejects_missing_historical_bank_reference_before_any_ddl(): void
    {
        DB::connection('mysql')->table('central_finance_payments')->insert(['fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '500000', 'idempotency_key' => hash('sha256', 'payment'), 'payment_reference' => null]);
        try { $this->migration()->up(); $this->fail(); }
        catch (RuntimeException $e) { $this->assertStringContainsString('no Bank Reference', $e->getMessage()); }
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_bank_transaction_identities'));
    }

    public function test_repeated_same_receivable_allocation_is_supported_and_payment_link_is_unique(): void
    {
        $this->migration()->up();
        $db = DB::connection('mysql');
        $db->table('central_finance_unidentified_deposit_allocations')->insert([
            ['unidentified_deposit_id' => 1, 'receivable_id' => 7],
            ['unidentified_deposit_id' => 1, 'receivable_id' => 7],
        ]);
        $this->assertSame(2, $db->table('central_finance_unidentified_deposit_allocations')->count());
        $db->table('central_finance_payments')->insert(['id' => 1, 'fund_account_id' => 1, 'currency' => 'MMK', 'amount' => '1', 'idempotency_key' => hash('sha256', 'payment'), 'payment_reference' => 'ONE']);
        $db->table('central_finance_unidentified_deposit_allocations')->where('id', 1)->update(['payment_id' => 1]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        $db->table('central_finance_unidentified_deposit_allocations')->where('id', 2)->update(['payment_id' => 1]);
    }
}
