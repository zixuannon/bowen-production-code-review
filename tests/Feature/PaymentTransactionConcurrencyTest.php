<?php

namespace Tests\Feature;

use App\Models\PaymentTransaction;
use App\Services\PaymentTransactionExactlyOnceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentTransactionConcurrencyTest extends TestCase
{
    public function test_concurrent_deliveries_execute_the_writer_once(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the database race test.');
        }

        $userId = (int) DB::connection('mysql')->table('users')->value('id');
        $this->assertGreaterThan(0, $userId, 'Local test database needs one central user.');
        $reference = 'p0b-concurrent-'.Str::uuid();
        $transaction = PaymentTransaction::on('mysql')->create([
            'user_id' => $userId,
            'amount' => 100,
            'payment_gateway' => 'Flutterwave',
            'order_id' => $reference,
            'payment_id' => '',
            'payment_status' => 'pending',
        ]);

        try {
            DB::disconnect('mysql');
            $children = [];
            for ($i = 0; $i < 2; $i++) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    try {
                        DB::purge('mysql');
                        app(PaymentTransactionExactlyOnceService::class)->settle(
                            'mysql',
                            'Flutterwave',
                            $reference,
                            static function (): void {
                            },
                            static function (PaymentTransaction $locked): void {
                                DB::connection('mysql')->table('payment_transactions')
                                    ->where('id', $locked->id)
                                    ->update(['payment_id' => DB::raw("CONCAT(COALESCE(payment_id, ''), 'W')")]);
                                usleep(250000);
                            }
                        );
                        exit(0);
                    } catch (\Throwable) {
                        exit(1);
                    }
                }
                $children[] = $pid;
            }

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            DB::purge('mysql');
            $fresh = PaymentTransaction::on('mysql')->where('order_id', $reference)->firstOrFail();
            $this->assertSame('W', $fresh->payment_id);
            $this->assertSame('succeed', $fresh->payment_status);
        } finally {
            DB::purge('mysql');
            PaymentTransaction::on('mysql')->where('order_id', $reference)->delete();
        }
    }
}
