<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseImportBatch;
use App\Models\SessionYear;
use App\Models\User;
use App\Services\ExpenseImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpenseImportServiceTest extends TestCase
{
    private int $schoolId = 1;
    private User $admin;
    private User $cashier;
    private ExpenseCategory $category;
    private SessionYear $year;
    private BankAccount $account;
    private string $previousConnection;
    private string $tenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = DB::getDefaultConnection();
        $this->tenantDatabase = tempnam(sys_get_temp_dir(), 'expense_import_tenant_');
        Config::set('database.connections.school', [
            'driver' => 'sqlite',
            'database' => $this->tenantDatabase,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('school');
        DB::setDefaultConnection('school');
        $this->ensureSchema();
        $suffix = Str::lower(Str::random(8));
        $this->admin = $this->user('expense-import-admin-' . $suffix, 'School Admin');
        $this->cashier = $this->user('expense-import-cashier-' . $suffix, 'Cashier');
        $this->category = ExpenseCategory::create(['name' => 'Office ' . $suffix, 'school_id' => $this->schoolId]);
        $this->year = SessionYear::create(['name' => 'Expense Import ' . $suffix, 'school_id' => $this->schoolId, 'default' => 0, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->account = BankAccount::create(['school_id' => $this->schoolId, 'account_name' => 'Expense Import Account ' . $suffix, 'account_type' => 'cash', 'currency' => 'MMK', 'opening_balance' => 0, 'is_active' => true]);
        $this->cashier->authorized_bank_accounts()->sync([$this->account->id]);
    }

    protected function tearDown(): void
    {
        DB::purge('school');
        DB::setDefaultConnection($this->previousConnection);
        @unlink($this->tenantDatabase);
        parent::tearDown();
    }

    public function test_preview_writes_only_a_batch_and_confirm_inserts_new_expenses_without_updating_existing_rows(): void
    {
        $existing = Expense::create($this->expenseData('Existing Expense', 'EXP-EXISTING-' . Str::random(6)));
        $before = Expense::count();
        $result = app(ExpenseImportService::class)->preview($this->file([$this->row('Imported Expense', 'EXP-NEW-' . Str::random(6))]), $this->schoolId, $this->admin->id);

        $this->assertSame($before, Expense::count(), 'Preview must never create Expenses.');
        $this->assertSame(1, ExpenseImportBatch::where('token', $result['token'])->count());
        $this->assertSame('valid', $result['rows'][0]['status']);

        $confirmed = app(ExpenseImportService::class)->confirm($result['token'], $this->schoolId, $this->admin->id);
        $this->assertSame(1, $confirmed['imported']);
        $this->assertSame($before + 1, Expense::count());
        $this->assertSame('Existing Expense', $existing->fresh()->title, 'Excel row numbers must never update existing Expense IDs.');
        $this->assertNotSame($existing->id, $confirmed['expense_ids'][0]);
        $this->assertSame(ExpenseImportBatch::STATUS_COMPLETED, ExpenseImportBatch::where('token', $result['token'])->value('status'));
    }

    public function test_duplicate_file_and_duplicate_confirm_cannot_create_second_expense(): void
    {
        $file = $this->file([$this->row('One-time import', 'EXP-ONE-' . Str::random(6))]);
        $service = app(ExpenseImportService::class);
        $preview = $service->preview($file, $this->schoolId, $this->admin->id);
        $service->confirm($preview['token'], $this->schoolId, $this->admin->id);
        $count = Expense::count();

        try { $service->confirm($preview['token'], $this->schoolId, $this->admin->id); $this->fail('A confirmed batch must not confirm twice.'); }
        catch (\InvalidArgumentException) { $this->assertSame($count, Expense::count()); }

        try { $service->preview($file, $this->schoolId, $this->admin->id); $this->fail('The same file hash must not create another batch.'); }
        catch (\InvalidArgumentException) { $this->assertSame($count, Expense::count()); }
    }

    public function test_cashier_preview_rejects_unassigned_or_cross_school_fund_account_without_writing_expenses(): void
    {
        $unassigned = BankAccount::create(['school_id' => $this->schoolId, 'account_name' => 'Unassigned ' . Str::random(6), 'account_type' => 'cash', 'currency' => 'MMK', 'opening_balance' => 0, 'is_active' => true]);
        $row = $this->row('Rejected', 'EXP-REJECT-' . Str::random(6));
        $row[7] = $unassigned->account_name;
        $result = app(ExpenseImportService::class)->preview($this->file([$row]), $this->schoolId, $this->cashier->id);

        $this->assertSame(0, $result['summary']['valid']);
        $this->assertSame('error', $result['rows'][0]['status']);
        $this->assertSame(0, Expense::where('ref_no', $row[4])->count());
        $this->assertStringContainsString('Fund Account', implode('; ', $result['rows'][0]['errors']));
    }

    public function test_preview_rejects_wrong_headings_and_invalid_rows_without_financial_writes(): void
    {
        $before = Expense::count();
        $beforeBatches = ExpenseImportBatch::count();
        $wrongHeadings = $this->file([$this->row('Wrong headings', 'EXP-HEAD-' . Str::random(6))], ['Wrong column']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('headings do not match');
        try {
            app(ExpenseImportService::class)->preview($wrongHeadings, $this->schoolId, $this->admin->id);
        } finally {
            $this->assertSame($before, Expense::count());
            $this->assertSame($beforeBatches, ExpenseImportBatch::count());
        }
    }

    public function test_invalid_rows_and_revalidation_failure_are_whole_batch_atomic(): void
    {
        $duplicateReference = 'EXP-DUP-' . Str::random(6);
        $badAmount = $this->row('Bad amount', 'EXP-AMOUNT-' . Str::random(6));
        $badAmount[5] = '0';
        $badCategory = $this->row('Bad category', 'EXP-CATEGORY-' . Str::random(6));
        $badCategory[1] = 'No such category';
        $firstDuplicate = $this->row('First duplicate', $duplicateReference);
        $secondDuplicate = $this->row('Second duplicate', $duplicateReference);
        $before = Expense::count();

        $preview = app(ExpenseImportService::class)->preview($this->file([$badAmount, $badCategory, $firstDuplicate, $secondDuplicate]), $this->schoolId, $this->admin->id);
        $this->assertSame(1, $preview['summary']['valid']);
        $this->assertSame(3, $preview['summary']['error']);
        $this->assertSame($before, Expense::count());
        $this->expectException(\InvalidArgumentException::class);
        try {
            app(ExpenseImportService::class)->confirm($preview['token'], $this->schoolId, $this->admin->id);
        } finally {
            $this->assertSame($before, Expense::count());
        }
    }

    public function test_confirm_revalidation_rolls_back_the_whole_batch_when_a_reference_is_claimed_after_preview(): void
    {
        $reference = 'EXP-RACE-' . Str::random(6);
        $preview = app(ExpenseImportService::class)->preview($this->file([$this->row('Will be blocked', $reference)]), $this->schoolId, $this->admin->id);
        Expense::create($this->expenseData('Existing after preview', $reference));
        $beforeConfirm = Expense::count();

        $this->expectException(\InvalidArgumentException::class);
        try {
            app(ExpenseImportService::class)->confirm($preview['token'], $this->schoolId, $this->admin->id);
        } finally {
            $this->assertSame($beforeConfirm, Expense::count());
            $this->assertSame(ExpenseImportBatch::STATUS_FAILED, ExpenseImportBatch::where('token', $preview['token'])->value('status'));
        }
    }

    /** @return array<int,string> */
    private function row(string $title, string $reference): array
    {
        return ['2026-08-13', $this->category->name, '', $title, $reference, '1250.00', 'Cash', $this->account->account_name, 'Synthetic import', $this->year->name];
    }

    private function file(array $rows, ?array $headings = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'expense-import-') . '.csv';
        $content = implode(',', $headings ?? \App\Support\ExpenseImportTemplate::HEADINGS) . "\n";
        foreach ($rows as $row) $content .= implode(',', $row) . "\n";
        file_put_contents($path, $content);
        return new UploadedFile($path, 'expense-import.csv', 'text/csv', null, true);
    }

    private function expenseData(string $title, string $reference): array
    {
        return ['school_id' => $this->schoolId, 'category_id' => $this->category->id, 'session_year_id' => $this->year->id, 'bank_account_id' => $this->account->id, 'title' => $title, 'ref_no' => $reference, 'amount' => 1, 'amount_mmk' => 1, 'original_amount' => 1, 'transaction_currency' => 'MMK', 'exchange_rate_snapshot' => 1, 'payment_method' => 'Cash', 'date' => '2026-08-13', 'created_by' => $this->admin->id];
    }

    private function user(string $prefix, string $role): User
    {
        $user = User::create(['first_name' => 'Expense', 'last_name' => 'Import', 'email' => $prefix . '@test.local', 'password' => bcrypt('local-only'), 'school_id' => $this->schoolId, 'status' => 1]);
        $roleId = DB::table('roles')->where('name', $role)->where('school_id', $this->schoolId)->value('id');
        DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $user->id]);
        return $user;
    }

    private function ensureSchema(): void
    {
        Schema::connection('school')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('school')->create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->unsignedBigInteger('school_id')->nullable();
            $table->boolean('custom_role')->default(false);
            $table->boolean('editable')->default(true);
            $table->timestamps();
            $table->unique(['name', 'guard_name', 'school_id']);
        });
        Schema::connection('school')->create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });
        Schema::connection('school')->create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('account_name');
            $table->string('account_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_type');
            $table->string('currency', 3);
            $table->decimal('opening_balance', 18, 4)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('school')->create('session_years', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('school_id');
            $table->boolean('default')->default(false);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::connection('school')->create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('finance_category_id')->nullable();
            $table->unsignedBigInteger('session_year_id')->nullable();
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->string('title');
            $table->string('ref_no')->nullable();
            $table->decimal('amount', 18, 4)->default(0);
            $table->decimal('amount_mmk', 18, 4)->default(0);
            $table->decimal('original_amount', 18, 4)->default(0);
            $table->string('transaction_currency', 3)->nullable();
            $table->decimal('exchange_rate_snapshot', 18, 4)->nullable();
            $table->string('payment_method')->nullable();
            $table->text('description')->nullable();
            $table->date('date')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        foreach (['School Admin', 'Cashier'] as $role) DB::table('roles')->updateOrInsert(['name' => $role, 'guard_name' => 'web', 'school_id' => $this->schoolId], ['custom_role' => 1, 'editable' => 1, 'created_at' => now(), 'updated_at' => now()]);
        if (!Schema::hasTable('bank_account_user')) Schema::create('bank_account_user', function (Blueprint $table) { $table->unsignedBigInteger('bank_account_id'); $table->unsignedBigInteger('user_id'); $table->timestamps(); $table->unique(['bank_account_id', 'user_id']); });
        if (!Schema::hasTable('expense_categories')) Schema::create('expense_categories', function (Blueprint $table) { $table->id(); $table->string('name'); $table->string('description')->nullable(); $table->unsignedBigInteger('school_id'); $table->timestamps(); $table->softDeletes(); });
        if (!Schema::hasTable('expense_import_batches')) Schema::create('expense_import_batches', function (Blueprint $table) { $table->id(); $table->uuid('token')->unique(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('imported_by'); $table->string('file_name'); $table->string('file_hash', 64); $table->json('preview_data')->nullable(); $table->json('imported_expense_ids')->nullable(); $table->string('status'); $table->unsignedInteger('total_rows')->default(0); $table->unsignedInteger('valid_rows')->default(0); $table->unsignedInteger('error_rows')->default(0); $table->unsignedInteger('imported_rows')->default(0); $table->timestamp('expired_at')->nullable(); $table->timestamp('consumed_at')->nullable(); $table->text('last_error')->nullable(); $table->timestamps(); $table->unique(['school_id', 'file_hash']); });
        if (!Schema::hasColumn('expenses', 'payment_method')) Schema::table('expenses', fn (Blueprint $table) => $table->string('payment_method')->nullable());
    }
}
