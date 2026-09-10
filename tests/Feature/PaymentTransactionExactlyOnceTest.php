<?php

namespace Tests\Feature;

use App\Models\PaymentTransaction;
use App\Services\PaymentTransactionExactlyOnceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentTransactionExactlyOnceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_replay_runs_financial_writer_once_and_uses_payment_status(): void
    {
        $transaction = $this->transaction('Stripe');
        $writes = 0;
        $service = app(PaymentTransactionExactlyOnceService::class);

        $first = $service->settle('mysql', 'Stripe', $transaction->order_id, static function (): void {
        }, static function () use (&$writes): void {
            $writes++;
        });
        $replay = $service->settle('mysql', 'Stripe', $transaction->order_id, static function (): void {
        }, static function () use (&$writes): void {
            $writes++;
        });

        $this->assertTrue($first);
        $this->assertFalse($replay);
        $this->assertSame(1, $writes);
        $this->assertSame('succeed', $transaction->fresh()->payment_status);
    }

    public function test_failed_delivery_cannot_overwrite_success(): void
    {
        $transaction = $this->transaction('Paystack');
        $service = app(PaymentTransactionExactlyOnceService::class);
        $service->settle('mysql', 'Paystack', $transaction->order_id, static function (): void {
        }, static function (): void {
        });

        $changed = $service->fail('mysql', 'Paystack', $transaction->order_id, static function (): void {
        });

        $this->assertFalse($changed);
        $this->assertSame('succeed', $transaction->fresh()->payment_status);
    }

    private function transaction(string $gateway): PaymentTransaction
    {
        $userId = (int) DB::connection('mysql')->table('users')->value('id');
        if (!$userId) {
            $userId = DB::connection('mysql')->table('users')->insertGetId([
                'first_name' => 'Webhook',
                'last_name' => 'Test',
                'email' => Str::uuid().'@test.local',
                'password' => bcrypt('password'),
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return PaymentTransaction::on('mysql')->create([
            'user_id' => $userId,
            'amount' => 100,
            'payment_gateway' => $gateway,
            'order_id' => 'p0b-'.Str::uuid(),
            'payment_status' => 'pending',
        ]);
    }
}
