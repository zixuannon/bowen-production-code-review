<?php

namespace App\Support;

/**
 * Business-facing Paid Fee import columns. These are deliberately not database
 * primary keys; backend matching resolves all internal IDs during preview.
 */
final class FeesPaidImportTemplate
{
    public const HEADINGS = [
        'Student Admission No',
        'Academic Year',
        'Class Name',
        'Fee Structure Name',
        'Fund Account Name',
        'Installment Name',
        'Date',
        'Amount (MMK)',
        'Payment Method',
        'Cheque No',
        'Receipt / Reference No',
    ];
}
