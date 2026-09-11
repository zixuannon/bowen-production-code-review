<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\User;
use App\Services\BankTransferService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BankTransferConcurrencyTest extends TestCase
{
    protected bool $tenantDbAsDefault = true;

    public function test_two_concurrent_overbalance_transfers_allow_at_most_one_success(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the database race test.');
        }

        $schoolId = (int) DB::table('schools')->value('id');
        $this->assertGreaterThan(0, $schoolId, 'Local test database needs one School row.');
        $prefix = 'P1A-TRANSFER-'.Str::uuid();
        $actor = User::query()->create([
            'first_name' => 'P1A', 'last_name' => 'Head Finance',
            'email' => Str::uuid().'@test.local', 'password' => bcrypt('local-only'),
            'school_id' => $schoolId, 'status' => 1,
        ]);
        DB::table('roles')->updateOrInsert(
            ['name' => 'Head Finance', 'school_id' => $schoolId],
            ['guard_name' => 'web', 'custom_role' => 1, 'editable' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
        $roleId = (int) DB::table('roles')->where('name', 'Head Finance')->where('school_id', $schoolId)->value('id');
        DB::table('model_has_roles')->insert(['role_id' => $roleId, 'model_id' => $actor->id, 'model_type' => User::class]);
        $source = BankAccount::query()->create($this->account($schoolId, $prefix.'-SOURCE', 100));
        $destination = BankAccount::query()->create($this->account($schoolId, $prefix.'-DEST', 0));

        try {
            DB::disconnect('school');
            DB::disconnect('mysql');
            $children = [];
            for ($i = 0; $i < 2; $i++) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    try {
                        DB::purge('school');
                        DB::purge('mysql');
                        DB::setDefaultConnection('school');
                        session(['db_connection_name' => 'school']);
                        usleep(200000);
                        app(BankTransferService::class)->create(User::query()->findOrFail($actor->id), [
                            'from_account_id' => $source->id,
                            'to_account_id' => $destination->id,
                            'amount' => 80,
                            'transfer_date' => '2026-09-11',
                            'reference_no' => $prefix.'-'.$i,
                        ], static function (): void {
                            usleep(300000);
                        });
                        exit(0);
                    } catch (ValidationException) {
                        exit(2);
                    } catch (\Throwable $exception) {
                        fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
                        exit(1);
                    }
                }
                $children[] = $pid;
            }

            $statuses = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $statuses[] = pcntl_wexitstatus($status);
            }
            sort($statuses);
            $this->assertSame([0, 2], $statuses);

            DB::purge('school');
            DB::setDefaultConnection('school');
            $this->assertSame(1, BankTransfer::query()->where('reference_no', 'like', $prefix.'%')->count());
            $this->assertEqualsWithDelta(20.0, app(\App\Services\FundAccountBalanceService::class)->currentBalance($source->fresh()), 0.001);
        } finally {
            DB::purge('school');
            DB::setDefaultConnection('school');
            BankTransfer::withTrashed()->where('reference_no', 'like', $prefix.'%')->forceDelete();
            BankAccount::withTrashed()->whereIn('id', [$source->id, $destination->id])->forceDelete();
            DB::table('model_has_roles')->where('model_id', $actor->id)->where('model_type', User::class)->delete();
            User::query()->whereKey($actor->id)->forceDelete();
        }
    }

    private function account(int $schoolId, string $name, float $openingBalance): array
    {
        return [
            'school_id' => $schoolId, 'account_name' => $name,
            'account_type' => 'cash', 'currency' => 'MMK',
            'opening_balance' => $openingBalance, 'is_active' => true,
        ];
    }
}
