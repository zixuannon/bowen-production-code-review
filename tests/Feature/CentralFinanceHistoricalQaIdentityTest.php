<?php

namespace Tests\Feature;

use App\Models\CentralFinanceUser;
use App\Models\User;
use App\Services\CentralFinanceBankTransactionIdentityService;
use App\Services\CentralFinanceHistoricalQaIdentityService as Historical;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Synthetic in-memory Central schema only; no shared/Production connections. */
final class CentralFinanceHistoricalQaIdentityTest extends TestCase
{
    private array $scope;
    private CentralFinanceUser $head;
    private const OLD = '2026-09-20 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.mysql', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('mysql'); DB::setDefaultConnection('mysql');
        Config::set('finance_release.p31_p32_tenants.MMBOWEN01', 'synthetic_historical_qa');
        $this->table('schools', ['name', 'code', 'database_name', 'status'], ['installed']);
        $this->table('users', ['first_name', 'last_name', 'status'], ['school_id']);
        $this->table('roles', ['name', 'guard_name']);
        $this->table('model_has_roles', ['model_type'], ['role_id', 'model_id']);
        (require database_path('migrations/2026_08_18_000001_create_finance_group_scope_tables.php'))->up();
        $this->table('central_finance_user_school_scopes', [], ['user_id', 'school_id', 'can_view', 'can_operate']);
        $this->table('central_finance_fund_accounts', ['account_type', 'currency', 'status', 'account_uuid'], ['group_id', 'school_id', 'is_active'], ['opening_balance']);
        $this->table('central_finance_student_profiles', ['source_uuid'], ['school_id']);
        $this->table('central_finance_receivables', ['currency', 'receivable_uuid'], ['school_id', 'student_profile_id'], ['amount_paid', 'amount_due']);
        $this->table('central_finance_payments', ['payment_uuid', 'payment_reference', 'payment_method', 'currency', 'paid_at', 'note', 'idempotency_key'], ['school_id', 'fund_account_id', 'receivable_id', 'received_by'], ['amount']);
        $this->table('central_finance_pending_collections', ['pending_collection_uuid', 'status', 'payment_reference', 'payment_method', 'currency', 'collected_at', 'confirmed_at', 'note'], ['school_id', 'student_profile_id', 'receivable_id', 'intended_fund_account_id', 'confirmed_payment_id', 'confirmed_by'], ['amount']);
        $this->table('central_finance_payment_allocations', ['currency'], ['school_id', 'student_profile_id', 'payment_id', 'receivable_id'], ['amount']);
        $this->table('central_finance_receipts', ['receipt_uuid', 'receipt_no'], ['school_id', 'payment_id']);
        $this->table('central_finance_ledger_entries', ['source_type', 'source_id', 'source_line', 'reference_no', 'transaction_type', 'currency', 'occurred_at'], ['school_id', 'fund_account_id'], ['money_in', 'money_out', 'operating_income']);
        foreach (['refunds', 'reversals'] as $suffix) $this->table('central_finance_payment_'.$suffix, [], ['payment_id']);
        $this->table('central_finance_qa_run_records', ['subject_scope', 'subject_type'], ['subject_id', 'school_id', 'qa_run_id']);
        Schema::connection('mysql')->create('central_finance_document_audits', function (Blueprint $t): void {
            $t->id(); $t->uuid('audit_uuid')->unique(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('group_id')->nullable();
            $t->string('document_type'); $t->unsignedBigInteger('document_id'); $t->string('action'); $t->unsignedBigInteger('actor_id');
            $t->text('reason')->nullable(); $t->json('before_values')->nullable(); $t->json('after_values')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_09_14_000003_create_central_finance_data_classifications.php'))->up();
        $this->table('central_finance_unidentified_deposits', ['currency', 'bank_reference', 'idempotency_key'], ['fund_account_id'], ['amount']);
        Schema::connection('mysql')->create('central_finance_unidentified_deposit_allocations', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('unidentified_deposit_id'); $t->unsignedBigInteger('receivable_id');
            $t->unique(['unidentified_deposit_id', 'receivable_id'], 'cfuda_deposit_receivable_unique');
        });
        $this->insert('schools', ['id' => 8, 'name' => 'Synthetic permanent QA', 'code' => 'MMBOWEN01', 'database_name' => 'synthetic_historical_qa', 'installed' => 1, 'status' => 'active']);
        $this->insert('users', ['id' => 90, 'first_name' => 'Synthetic', 'last_name' => 'Head', 'school_id' => null, 'status' => 1]);
        $this->insert('roles', ['id' => 1, 'name' => 'Head Finance', 'guard_name' => 'web']);
        $this->insert('model_has_roles', ['role_id' => 1, 'model_type' => User::class, 'model_id' => 90]);
        $this->insert('finance_groups', ['id' => 3, 'name' => 'Synthetic Group', 'status' => 'active']);
        $this->insert('finance_group_schools', ['group_id' => 3, 'school_id' => 8, 'status' => 'active']);
        $this->insert('finance_group_users', ['id' => 5, 'group_id' => 3, 'central_user_id' => 90, 'status' => 'active']);
        foreach (['operate_finance', 'manage_hq_accounts'] as $capability) $this->insert('finance_group_user_scopes', ['group_user_id' => 5, 'scope_type' => 'GROUP', 'capability' => $capability, 'scope_key' => 'GROUP:3', 'status' => 'active']);
        $this->insert('central_finance_user_school_scopes', ['user_id' => 90, 'school_id' => 8, 'can_view' => 1, 'can_operate' => 1]);
        $this->insert('central_finance_fund_accounts', ['id' => 20, 'account_type' => 'bank', 'currency' => 'MMK', 'status' => 'active', 'is_active' => 1, 'group_id' => 3, 'opening_balance' => 0, 'account_uuid' => (string) Str::uuid()]);
        $this->insert('central_finance_student_profiles', ['id' => 30, 'school_id' => 8, 'source_uuid' => (string) Str::uuid()]);
        $this->insert('central_finance_receivables', ['id' => 40, 'school_id' => 8, 'student_profile_id' => 30, 'currency' => 'MMK', 'amount_due' => '80000', 'amount_paid' => '25000']);
        $pendingUuid = (string) Str::uuid(); $paymentUuid = (string) Str::uuid();
        $this->insert('central_finance_payments', ['id' => 50, 'payment_uuid' => $paymentUuid, 'school_id' => 8, 'receivable_id' => 40, 'fund_account_id' => 20, 'received_by' => 90, 'payment_method' => 'Bank Transfer', 'currency' => 'MMK', 'amount' => '25000', 'paid_at' => self::OLD, 'idempotency_key' => hash('sha256', '8|40|pending-collection:'.$pendingUuid)]);
        $this->insert('central_finance_pending_collections', ['id' => 60, 'pending_collection_uuid' => $pendingUuid, 'school_id' => 8, 'student_profile_id' => 30, 'receivable_id' => 40, 'intended_fund_account_id' => 20, 'confirmed_payment_id' => 50, 'confirmed_by' => 90, 'status' => 'confirmed', 'payment_method' => 'Bank Transfer', 'currency' => 'MMK', 'amount' => '25000', 'collected_at' => self::OLD, 'confirmed_at' => self::OLD]);
        $this->insert('central_finance_payment_allocations', ['id' => 70, 'school_id' => 8, 'student_profile_id' => 30, 'payment_id' => 50, 'receivable_id' => 40, 'currency' => 'MMK', 'amount' => '25000', 'created_at' => '2026-10-06 10:00:00']);
        $this->insert('central_finance_receipts', ['id' => 80, 'school_id' => 8, 'payment_id' => 50, 'receipt_uuid' => (string) Str::uuid(), 'receipt_no' => 'CFR-SYNTHETIC']);
        $this->insert('central_finance_ledger_entries', ['id' => 100, 'school_id' => 8, 'fund_account_id' => 20, 'source_type' => 'central_payment', 'source_id' => $paymentUuid, 'source_line' => 'primary', 'reference_no' => 'CFR-SYNTHETIC', 'transaction_type' => 'operating_income', 'currency' => 'MMK', 'occurred_at' => self::OLD, 'money_in' => '25000', 'money_out' => 0, 'operating_income' => '25000']);
        foreach (['school' => 8, 'fund_account' => 20, 'student_profile' => 30, 'receivable' => 40, 'payment' => 50, 'pending_collection' => 60, 'receipt' => 80, 'ledger' => 100] as $type => $id) $this->classify($type, $id);
        foreach ([['pending_collection', 60, 'submitted'], ['pending_collection', 60, 'confirmed'], ['central_payment', 50, 'collected']] as [$type, $id, $action]) {
            $after = match ($action) {
                'submitted' => ['status' => 'submitted', 'payment_reference' => null, 'student_profile_id' => 30, 'receivable_id' => 40, 'intended_fund_account_id' => 20, 'currency' => 'MMK', 'payment_method' => 'Bank Transfer', 'submitted_by' => 90, 'amount' => '25000.0000'],
                'confirmed' => ['status' => 'confirmed', 'confirmed_payment_id' => 50, 'confirmed_by' => 90],
                'collected' => ['receipt_id' => 80, 'receivable_id' => 40, 'amount' => 25000, 'fund_account_id' => 20],
            };
            $this->insert('central_finance_document_audits', ['audit_uuid' => (string) Str::uuid(), 'school_id' => 8, 'document_type' => $type, 'document_id' => $id, 'action' => $action, 'actor_id' => 90, 'after_values' => json_encode($after)]);
        }
        $this->scope = ['payment_id' => 50, 'pending_id' => 60, 'allocation_id' => 70, 'receipt_id' => 80, 'ledger_id' => 100, 'receivable_id' => 40, 'student_profile_id' => 30, 'school_id' => 8, 'fund_account_id' => 20, 'amount' => '25000', 'currency' => 'MMK', 'operator_confirmation' => Historical::CONFIRMATION];
        $this->head = CentralFinanceUser::on('mysql')->findOrFail(90);
    }

    protected function tearDown(): void { DB::purge('mysql'); parent::tearDown(); }

    public function test_explicit_simulated_history_is_metadata_only_exactly_once_and_migrates_in_its_own_namespace(): void
    {
        $service = app(Historical::class); $before = $this->financialSnapshot();
        $preview = $service->preview($this->scope);
        $this->assertNull($service->verifiedIdentityForPayment(50));
        $first = $service->reconcile($this->head, $this->scope, $preview['evidence_hash']);
        $second = $service->reconcile($this->head, $this->scope, $preview['evidence_hash']);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertSame(4, DB::connection('mysql')->table('central_finance_document_audits')->count());
        $identity = $service->verifiedIdentityForPayment(50);
        $this->assertSame('historical_qa', $identity['identity_namespace']);
        $this->assertNotSame(CentralFinanceBankTransactionIdentityService::identity($identity['normalized_identity'])['identity_hash'], $identity['identity_hash']);
        (require database_path('migrations/2026_10_07_000001_close_unidentified_deposit_p0.php'))->up();
        $stored = DB::connection('mysql')->table('central_finance_bank_transaction_identities')->sole();
        $this->assertSame('historical_qa', $stored->identity_namespace);
        $this->assertNull(DB::connection('mysql')->table('central_finance_payments')->where('id', 50)->value('payment_reference'));
        $this->assertSame($identity, $service->verifiedIdentityForPayment(50));
        $this->assertSame(0, DB::connection('mysql')->table('central_finance_qa_run_records')->count());
    }

    public function test_qa_missing_reference_without_an_individual_audit_still_blocks_before_ddl(): void
    {
        try { (require database_path('migrations/2026_10_07_000001_close_unidentified_deposit_p0.php'))->up(); $this->fail(); }
        catch (RuntimeException $e) { $this->assertStringContainsString('no Bank Reference', $e->getMessage()); }
        $this->assertFalse(Schema::connection('mysql')->hasTable('central_finance_bank_transaction_identities'));
    }

    #[DataProvider('unsafeEvidence')]
    public function test_unsafe_or_unapproved_history_is_denied(string $case): void
    {
        $db = DB::connection('mysql');
        match ($case) {
            'official' => $db->table('central_finance_data_classifications')->where('subject_type', 'payment')->update(['classification' => 'production']),
            'no_audit' => $db->table('central_finance_data_classification_audits')->where('subject_type', 'payment')->delete(),
            'fake_audit' => $db->table('central_finance_data_classification_audits')->where('subject_type', 'payment')->update(['after_classification' => 'production']),
            'real_reference' => $db->table('central_finance_payments')->where('id', 50)->update(['payment_reference' => 'REAL-EVIDENCE']),
            'blank_reference' => $db->table('central_finance_payments')->where('id', 50)->update(['payment_reference' => '']),
            'recent_backdated' => $db->table('central_finance_payments')->where('id', 50)->update(['created_at' => '2026-10-06 01:00:00']),
            'run_member' => $this->insert('central_finance_qa_run_records', ['subject_scope' => 'central', 'subject_type' => 'payment', 'subject_id' => 50, 'school_id' => 8, 'qa_run_id' => 1]),
            'untrusted_school' => $db->table('schools')->where('id', 8)->update(['database_name' => 'wrong_registry']),
            'amount_change' => $db->table('central_finance_payments')->where('id', 50)->update(['amount' => 25001]),
            'source_forgery' => $db->table('central_finance_payments')->where('id', 50)->update(['idempotency_key' => hash('sha256', 'foreign')]),
            'scope_forgery' => $this->scope['operator_confirmation'] = 'automatic_qa_fallback',
            'wrong_student' => $db->table('central_finance_receivables')->where('id', 40)->update(['student_profile_id' => 999]),
            'correction' => $this->insert('central_finance_payment_refunds', ['payment_id' => 50]),
            'duplicate_cash' => $this->duplicateLedger(),
            'duplicate_payment' => $this->duplicatePayment(),
            'fake_source_audit' => $db->table('central_finance_document_audits')->where('action', 'confirmed')->update(['after_values' => json_encode(['status' => 'confirmed', 'confirmed_payment_id' => 999, 'confirmed_by' => 90])]),
        };
        $before = $this->financialSnapshot();
        try { app(Historical::class)->preview($this->scope); $this->fail('Unsafe historical evidence accepted.'); }
        catch (RuntimeException $e) { $this->assertNotSame('', $e->getMessage()); }
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertSame(3, $db->table('central_finance_document_audits')->count());
    }

    public static function unsafeEvidence(): array
    {
        return array_map(fn ($case) => [$case], ['official', 'no_audit', 'fake_audit', 'real_reference', 'blank_reference', 'recent_backdated', 'run_member', 'untrusted_school', 'amount_change', 'source_forgery', 'scope_forgery', 'wrong_student', 'correction', 'duplicate_cash', 'duplicate_payment', 'fake_source_audit']);
    }

    public function test_real_reference_keeps_real_bank_migration_contract(): void
    {
        DB::connection('mysql')->table('central_finance_payments')->where('id', 50)->update(['payment_reference' => ' REAL-REF ']);
        (require database_path('migrations/2026_10_07_000001_close_unidentified_deposit_p0.php'))->up();
        $this->assertSame('bank_reference', DB::connection('mysql')->table('central_finance_bank_transaction_identities')->sole()->identity_namespace);
        $this->assertSame(' REAL-REF ', DB::connection('mysql')->table('central_finance_payments')->where('id', 50)->value('payment_reference'));
    }

    public function test_actor_requires_real_head_finance_and_explicit_operating_and_group_scope(): void
    {
        $preview = app(Historical::class)->preview($this->scope);
        DB::connection('mysql')->table('finance_group_user_scopes')->where('capability', 'manage_hq_accounts')->update(['status' => 'inactive']);
        $this->expectException(AuthorizationException::class);
        app(Historical::class)->reconcile($this->head, $this->scope, $preview['evidence_hash']);
    }

    public function test_changed_snapshot_cannot_be_reconciled_after_preview(): void
    {
        $preview = app(Historical::class)->preview($this->scope);
        DB::connection('mysql')->table('central_finance_receivables')->where('id', 40)->update(['amount_paid' => 50000]);
        $this->expectException(RuntimeException::class);
        app(Historical::class)->reconcile($this->head, $this->scope, $preview['evidence_hash']);
    }

    public function test_inactive_actor_is_denied_without_writing_an_audit(): void
    {
        $preview = app(Historical::class)->preview($this->scope);
        DB::connection('mysql')->table('users')->where('id', 90)->update(['status' => 0]);
        try { app(Historical::class)->reconcile($this->head, $this->scope, $preview['evidence_hash']); $this->fail(); }
        catch (RuntimeException $e) { $this->assertStringContainsString('active Central', $e->getMessage()); }
        $this->assertSame(3, DB::connection('mysql')->table('central_finance_document_audits')->count());
    }

    public function test_added_null_columns_are_compatible_but_subsequent_relinking_is_denied(): void
    {
        $service = app(Historical::class); $preview = $service->preview($this->scope);
        $service->reconcile($this->head, $this->scope, $preview['evidence_hash']);
        Schema::connection('mysql')->table('central_finance_payments', function (Blueprint $t): void { $t->unsignedBigInteger('unidentified_deposit_id')->nullable(); $t->string('request_hash')->nullable(); });
        $this->assertNotNull($service->verifiedIdentityForPayment(50));
        DB::connection('mysql')->table('central_finance_payments')->where('id', 50)->update(['unidentified_deposit_id' => 9]);
        $this->expectException(RuntimeException::class);
        $service->verifiedIdentityForPayment(50);
    }

    public function test_readonly_verified_identity_emits_no_locking_queries(): void
    {
        $service = app(Historical::class); $preview = $service->preview($this->scope);
        $service->reconcile($this->head, $this->scope, $preview['evidence_hash']);
        DB::connection('mysql')->enableQueryLog();
        $this->assertNotNull($service->verifiedIdentityForPayment(50));
        foreach (DB::connection('mysql')->getQueryLog() as $query) {
            $this->assertStringNotContainsString('for update', strtolower($query['query']));
            $this->assertStringNotContainsString('insert ', strtolower($query['query']));
        }
    }

    #[DataProvider('corruptAudits')]
    public function test_migration_revalidates_metadata_and_rejects_corrupt_foreign_or_duplicate_audits(string $case): void
    {
        $service = app(Historical::class); $preview = $service->preview($this->scope);
        $audit = $service->reconcile($this->head, $this->scope, $preview['evidence_hash']);
        $db = DB::connection('mysql');
        if ($case === 'duplicate') {
            $copy = (array) $db->table('central_finance_document_audits')->where('id', $audit->id)->first(); unset($copy['id']);
            $copy['audit_uuid'] = (string) Str::uuid(); $db->table('central_finance_document_audits')->insert($copy);
        } elseif ($case === 'foreign') {
            $payload = $audit->after_values; $payload['scope']['payment_id'] = 999;
            $db->table('central_finance_document_audits')->where('id', $audit->id)->update(['after_values' => json_encode($payload)]);
        } elseif ($case === 'changed') {
            $db->table('central_finance_receivables')->where('id', 40)->update(['amount_paid' => 50000]);
        } else {
            $db->table('central_finance_document_audits')->where('id', $audit->id)->update(['reason' => 'forged']);
        }
        $this->expectException(RuntimeException::class);
        $service->verifiedIdentityForPayment(50);
    }

    public static function corruptAudits(): array { return [['duplicate'], ['foreign'], ['changed'], ['reason']]; }

    private function table(string $name, array $strings, array $integers = [], array $amounts = []): void
    {
        Schema::connection('mysql')->create($name, function (Blueprint $t) use ($strings, $integers, $amounts): void {
            $t->id(); foreach ($strings as $field) $t->string($field)->nullable();
            foreach ($integers as $field) $t->unsignedBigInteger($field)->nullable();
            foreach ($amounts as $field) $t->decimal($field, 20, 4)->nullable(); $t->softDeletes(); $t->timestamps();
        });
    }

    private function insert(string $table, array $row): void
    {
        DB::connection('mysql')->table($table)->insert($row + ['created_at' => self::OLD, 'updated_at' => self::OLD]);
    }

    private function classify(string $type, int $id): void
    {
        $row = ['school_id' => 8, 'subject_scope' => 'central', 'subject_type' => $type, 'subject_id' => $id, 'reason' => 'Synthetic historical simulation'];
        $classificationId = DB::connection('mysql')->table('central_finance_data_classifications')->insertGetId($row + ['classification_uuid' => (string) Str::uuid(), 'classification' => 'qa_test', 'classified_by' => 90, 'created_at' => self::OLD, 'updated_at' => self::OLD]);
        DB::connection('mysql')->table('central_finance_data_classification_audits')->insert($row + ['audit_uuid' => (string) Str::uuid(), 'classification_id' => $classificationId, 'after_classification' => 'qa_test', 'actor_id' => 90, 'created_at' => self::OLD]);
    }

    private function duplicateLedger(): void
    {
        $copy = (array) DB::connection('mysql')->table('central_finance_ledger_entries')->where('id', 100)->first(); unset($copy['id']);
        DB::connection('mysql')->table('central_finance_ledger_entries')->insert($copy);
    }

    private function duplicatePayment(): void
    {
        $copy = (array) DB::connection('mysql')->table('central_finance_payments')->where('id', 50)->first(); unset($copy['id']);
        $copy['payment_uuid'] = (string) Str::uuid();
        DB::connection('mysql')->table('central_finance_payments')->insert($copy);
    }

    private function financialSnapshot(): array
    {
        $out = [];
        foreach (['payments', 'pending_collections', 'payment_allocations', 'receipts', 'ledger_entries', 'receivables', 'fund_accounts', 'data_classifications', 'data_classification_audits', 'qa_run_records'] as $table) $out[$table] = DB::connection('mysql')->table('central_finance_'.$table)->orderBy('id')->get()->toJson();
        return $out;
    }
}
