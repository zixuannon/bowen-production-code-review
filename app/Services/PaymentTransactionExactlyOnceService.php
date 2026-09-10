<?php

namespace App\Services;

use App\Models\PaymentTransaction;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Serializes provider deliveries on the existing server-created transaction.
 *
 * The payment_transactions primary key/order reference is the idempotency
 * identity. A row lock makes the status transition and all settlement writes
 * one atomic unit, so no schema migration is required for this release.
 */
final class PaymentTransactionExactlyOnceService
{
    /**
     * @param callable(PaymentTransaction):void $verify
     * @param callable(PaymentTransaction):void $write
     * @return bool true when this delivery performed settlement; false on replay
     */
    public function settle(
        string $connection,
        string $gateway,
        string $reference,
        callable $verify,
        callable $write
    ): bool {
        if ($reference === '') {
            throw new RuntimeException('Payment reference is required.');
        }

        return $this->connection($connection)->transaction(function () use (
            $connection,
            $gateway,
            $reference,
            $verify,
            $write
        ): bool {
            $transaction = PaymentTransaction::on($connection)
                ->where('order_id', $reference)
                ->lockForUpdate()
                ->sole();

            if (strcasecmp((string) $transaction->payment_gateway, $gateway) !== 0) {
                throw new RuntimeException('Payment gateway does not match the transaction.');
            }

            $status = strtolower((string) $transaction->payment_status);
            if ($status === 'succeed') {
                return false;
            }
            if ($status !== 'pending') {
                throw new RuntimeException('Payment transaction is not pending.');
            }

            $verify($transaction);
            $write($transaction);

            // This is deliberately last: a failed write rolls the status back.
            $transaction->payment_status = 'succeed';
            $transaction->save();

            return true;
        }, 3);
    }

    /**
     * Mark a provider-declared failure without allowing it to overwrite success.
     *
     * @return bool true when pending changed to failed; false for a terminal replay
     */
    public function fail(string $connection, string $gateway, string $reference, callable $write): bool
    {
        if ($reference === '') {
            throw new RuntimeException('Payment reference is required.');
        }

        return $this->connection($connection)->transaction(function () use (
            $connection,
            $gateway,
            $reference,
            $write
        ): bool {
            $transaction = PaymentTransaction::on($connection)
                ->where('order_id', $reference)
                ->lockForUpdate()
                ->sole();

            if (strcasecmp((string) $transaction->payment_gateway, $gateway) !== 0) {
                throw new RuntimeException('Payment gateway does not match the transaction.');
            }

            if (strtolower((string) $transaction->payment_status) !== 'pending') {
                return false;
            }

            $write($transaction);
            $transaction->payment_status = 'failed';
            $transaction->save();

            return true;
        }, 3);
    }

    private function connection(string $name): ConnectionInterface
    {
        return DB::connection($name);
    }
}
