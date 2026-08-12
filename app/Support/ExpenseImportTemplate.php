<?php

namespace App\Support;

/** Business columns only; no Expense ID and no row-to-record mapping. */
final class ExpenseImportTemplate
{
    public const HEADINGS = ['Date', 'Expense Category', 'Finance Category', 'Title', 'Reference No', 'Amount (MMK)', 'Payment Method', 'Fund Account Name', 'Remark', 'Academic Year'];
    public const PAYMENT_METHODS = ['Cash', 'Bank Transfer', 'Mobile Payment', 'Cheque', 'Online'];
}
