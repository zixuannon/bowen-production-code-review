<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use App\Models\Students;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class ApiPaymentStatusService
{
    /** @return array<string,mixed> */
    public function confirmation(User $actor, int $transactionId, ?callable $providerLookup = null): array
    {
        $transaction = $this->ownedQuery($actor)->whereKey($transactionId)->firstOrFail();
        $status = $this->normalizeStatus((string) $transaction->payment_status);

        // A read endpoint may inspect a still-pending provider payment, but it
        // never advances local state. Signed webhooks remain the sole writer.
        if ($status === 'pending' && $transaction->order_id) {
            $providerLookup ??= static function (PaymentTransaction $payment): mixed {
                $result = PaymentService::create($payment->payment_gateway, $payment->school_id)
                    ->retrievePaymentIntent($payment->order_id);
                return PaymentService::formatPaymentIntent($payment->payment_gateway, $result);
            };
            $status = $this->normalizeStatus((string) data_get($providerLookup($transaction), 'status', 'pending'));
        }

        return $this->present($transaction, $status);
    }

    /** @return array<int,array<string,mixed>> */
    public function listing(User $actor, bool $latestOnly = false): array
    {
        $query = $this->ownedQuery($actor)->orderByDesc('id');
        if ($latestOnly) {
            $query->where('created_at', '>', Carbon::now()->subMinutes(30));
        }

        return $query->get()->map(
            fn (PaymentTransaction $transaction): array => $this->present(
                $transaction,
                $this->normalizeStatus((string) $transaction->payment_status)
            )
        )->all();
    }

    private function ownedQuery(User $actor): Builder
    {
        $ownerIds = [(int) $actor->id];
        if ($actor->hasRole('Guardian')) {
            $ownerIds = Students::query()
                ->where('school_id', $actor->school_id)
                ->where('guardian_id', $actor->id)
                ->pluck('user_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        return PaymentTransaction::query()
            ->where('school_id', $actor->school_id)
            ->whereIn('user_id', $ownerIds);
    }

    /** @return array<string,mixed> */
    private function present(PaymentTransaction $transaction, string $status): array
    {
        return [
            'transaction_id' => (int) $transaction->id,
            'status' => $status,
            'amount' => (float) $transaction->amount,
            'payment_gateway' => (string) $transaction->payment_gateway,
            'type' => (string) $transaction->type,
        ];
    }

    private function normalizeStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'success', 'successful', 'succeed', 'succeeded', 'completed', 'paid' => 'succeed',
            'failed', 'cancelled', 'canceled', 'abandoned' => 'failed',
            default => 'pending',
        };
    }
}
