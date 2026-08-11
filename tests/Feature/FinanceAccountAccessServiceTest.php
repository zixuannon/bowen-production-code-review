<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\User;
use App\Services\FinanceAccountAccessService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class FinanceAccountAccessServiceTest extends TestCase
{
    use DatabaseTransactions;
    protected $connectionsToTransact = ['school'];

    public function test_cashier_scope_is_limited_to_explicit_current_school_assignments(): void
    {
        if (!Schema::hasTable('bank_account_user')) {
            Schema::create('bank_account_user', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('bank_account_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();
                $table->unique(['bank_account_id', 'user_id']);
            });
        }
        $schoolId = 1;
        $cashier = User::create(['first_name' => 'P2', 'last_name' => 'Cashier', 'email' => uniqid('cashier_', true) . '@test.local', 'password' => bcrypt('x'), 'school_id' => $schoolId]);
        $allowed = BankAccount::create(['school_id' => $schoolId, 'account_name' => 'Allowed', 'account_type' => 'cash', 'currency' => 'MMK', 'opening_balance' => 0, 'is_active' => 1]);
        $denied = BankAccount::create(['school_id' => $schoolId, 'account_name' => 'Denied', 'account_type' => 'cash', 'currency' => 'MMK', 'opening_balance' => 0, 'is_active' => 1]);
        DB::table('bank_account_user')->insert(['bank_account_id' => $allowed->id, 'user_id' => $cashier->id, 'created_at' => now(), 'updated_at' => now()]);

        $ids = app(FinanceAccountAccessService::class)->accessibleAccounts($cashier)->pluck('id')->all();
        $this->assertContains($allowed->id, $ids);
        $this->assertNotContains($denied->id, $ids);
        $this->assertFalse(app(FinanceAccountAccessService::class)->canModifyOpeningBalance($cashier));
    }
}
