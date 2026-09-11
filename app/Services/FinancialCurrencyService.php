<?php

namespace App\Services;

use App\Models\BankAccount;
use Illuminate\Validation\ValidationException;

final class FinancialCurrencyService
{
    /** @return array{transaction_currency:string,original_amount:float,exchange_rate_snapshot:float,amount_mmk:float} */
    public function expenseSnapshot(BankAccount $account, array $data): array
    {
        $currency = strtoupper((string) ($data['transaction_currency'] ?? 'MMK'));
        $amountMmk = (float) ($data['amount'] ?? 0);
        $rate = (float) ($data['exchange_rate_snapshot'] ?? 0);
        $original = (float) ($data['original_amount'] ?? 0);

        $this->assertAccountCurrency($account, $currency);
        if ($currency === 'MMK') {
            $original = $amountMmk;
            $rate = 1.0;
        }
        if ($amountMmk <= 0 || $original <= 0 || $rate <= 0) {
            $this->fail('amount', 'Amount, original amount, and FX rate must be greater than zero.');
        }
        if ($currency !== 'MMK' && abs(($original * $rate) - $amountMmk) > 0.01) {
            $this->fail('amount', 'MMK equivalent must equal original amount multiplied by the FX snapshot.');
        }

        return [
            'transaction_currency' => $currency,
            'original_amount' => $original,
            'exchange_rate_snapshot' => $rate,
            'amount_mmk' => $amountMmk,
        ];
    }

    /** @return array{transaction_currency:string,original_amount:float,exchange_rate_snapshot:float,amount_mmk:float} */
    public function receiptSnapshot(BankAccount $account, array $data): array
    {
        $currency = strtoupper((string) ($data['transaction_currency'] ?? $account->currency));
        $original = (float) ($data['original_amount'] ?? $data['amount'] ?? 0);
        $rate = $currency === 'MMK' ? 1.0 : (float) ($data['exchange_rate_snapshot'] ?? 0);

        $this->assertAccountCurrency($account, $currency);
        if ($original <= 0 || $rate <= 0) {
            $this->fail('amount', 'Amount and FX rate must be greater than zero.');
        }

        return [
            'transaction_currency' => $currency,
            'original_amount' => $original,
            'exchange_rate_snapshot' => $rate,
            'amount_mmk' => $original * $rate,
        ];
    }

    public function assertAccountCurrency(BankAccount $account, string $transactionCurrency): void
    {
        if (strtoupper((string) $account->currency) !== strtoupper($transactionCurrency)) {
            $this->fail('bank_account_id', 'Fund Account currency must match the transaction currency.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [__($message)]]);
    }
}
