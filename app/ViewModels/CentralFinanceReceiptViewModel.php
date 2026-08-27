<?php

namespace App\ViewModels;

/**
 * Presentation-neutral receipt data.  The immutable Central Payment, Receipt,
 * Refund and Ledger records remain the accounting source of truth; templates
 * consume this snapshot and never calculate or mutate accounting values.
 */
final readonly class CentralFinanceReceiptViewModel
{
    /**
     * @param array<string,mixed> $school
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $student
     * @param array<string,mixed> $payment
     * @param array<string,mixed> $fundAccount
     * @param list<array<string,mixed>> $refunds
     */
    public function __construct(
        public int $paymentId,
        public array $school,
        public array $receipt,
        public array $student,
        public array $payment,
        public array $fundAccount,
        public array $refunds,
    ) {}
}
