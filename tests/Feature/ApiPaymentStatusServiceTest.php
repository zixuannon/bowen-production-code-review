<?php

namespace Tests\Feature;

use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\ApiPaymentStatusService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiPaymentStatusServiceTest extends TestCase
{
    protected bool $tenantDbAsDefault = true;
    use DatabaseTransactions;
    protected $connectionsToTransact = ['school'];

    public function test_confirmation_is_owned_read_only_and_discards_provider_payload(): void
    {
        $actor = $this->user(1, 'Student');
        $other = $this->user(1, 'Student');
        $owned = $this->transaction($actor, 'pending');
        $foreign = $this->transaction($other, 'pending');
        $service = app(ApiPaymentStatusService::class);

        $result = $service->confirmation($actor, $owned->id, static fn () => [
            'status' => 'successful',
            'metadata' => ['secret' => 'must-not-leak'],
            'raw_provider_response' => ['card' => 'must-not-leak'],
        ]);

        $this->assertSame(['transaction_id', 'status', 'amount', 'payment_gateway', 'type'], array_keys($result));
        $this->assertSame('succeed', $result['status']);
        $this->assertSame('pending', $owned->fresh()->payment_status);

        $this->expectException(ModelNotFoundException::class);
        $service->confirmation($actor, $foreign->id, static fn () => ['status' => 'successful']);
    }

    public function test_listing_returns_only_owner_rows_and_never_calls_provider(): void
    {
        $actor = $this->user(1, 'Student');
        $other = $this->user(1, 'Student');
        $owned = $this->transaction($actor, 'pending');
        $this->transaction($other, 'succeed');

        $rows = app(ApiPaymentStatusService::class)->listing($actor);

        $this->assertCount(1, $rows);
        $this->assertSame($owned->id, $rows[0]['transaction_id']);
        $this->assertSame('pending', $owned->fresh()->payment_status);
    }

    public function test_terminal_confirmation_does_not_contact_provider(): void
    {
        $actor = $this->user(1, 'Student');
        $transaction = $this->transaction($actor, 'succeed');

        $result = app(ApiPaymentStatusService::class)->confirmation(
            $actor,
            $transaction->id,
            static fn () => throw new \RuntimeException('provider must not be called')
        );

        $this->assertSame('succeed', $result['status']);
    }

    private function user(int $schoolId, string $role): User
    {
        $user = User::query()->create([
            'first_name' => 'P1A', 'last_name' => $role,
            'email' => Str::uuid().'@test.local', 'password' => bcrypt('local-only'),
            'school_id' => $schoolId, 'status' => 1,
        ]);
        DB::table('roles')->updateOrInsert(
            ['name' => $role, 'school_id' => $schoolId],
            ['guard_name' => 'web', 'custom_role' => 1, 'editable' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('name', $role)->where('school_id', $schoolId)->value('id'),
            'model_id' => $user->id, 'model_type' => User::class,
        ]);
        return $user->fresh();
    }

    private function transaction(User $owner, string $status): PaymentTransaction
    {
        return PaymentTransaction::query()->create([
            'user_id' => $owner->id,
            'amount' => 100,
            'payment_gateway' => 'Stripe',
            'order_id' => (string) Str::uuid(),
            'payment_status' => $status,
            'school_id' => $owner->school_id,
            'type' => 'fees',
        ]);
    }
}
