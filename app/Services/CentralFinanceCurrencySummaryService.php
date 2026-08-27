<?php

namespace App\Services;

use App\Support\CentralFinanceCurrency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read-model-only currency grouping.  Central Finance intentionally has no
 * implicit FX conversion, so callers must render these buckets separately.
 */
final class CentralFinanceCurrencySummaryService
{
    /** @param iterable<object> $entries @return array<string,array<string,float>> */
    public function ledger(iterable $entries): array
    {
        $totals = $this->ledgerZero();
        foreach ($entries as $entry) {
            $currency = CentralFinanceCurrency::normalize((string) $entry->currency);
            $totals[$currency]['money_in'] += (float) $entry->money_in;
            $totals[$currency]['money_out'] += (float) $entry->money_out;
            $totals[$currency]['operating_income'] += (float) $entry->operating_income;
            $totals[$currency]['operating_expense'] += (float) $entry->operating_expense;
        }
        foreach ($totals as &$row) {
            $row['operating_net'] = round($row['operating_income'] - $row['operating_expense'], 4);
        }
        unset($row);
        return $totals;
    }

    /** @param iterable<object> $receivables @return array<string,array<string,float>> */
    public function receivables(iterable $receivables): array
    {
        $totals = $this->receivableZero();
        foreach ($receivables as $receivable) {
            $currency = CentralFinanceCurrency::normalize((string) $receivable->currency);
            $due = (float) $receivable->amount_due;
            $paid = (float) $receivable->amount_paid;
            $totals[$currency]['due'] += $due;
            $totals[$currency]['paid'] += $paid;
            $totals[$currency]['outstanding'] += max(0, $due - $paid);
        }
        return $totals;
    }

    /** @param iterable<object> $receivables @return array<string,array<string,float>> */
    public function aging(iterable $receivables, CarbonImmutable $today): array
    {
        $aging = [];
        foreach (CentralFinanceCurrency::ALLOWED as $currency) {
            $aging[$currency] = array_fill_keys(['current', '1_30', '31_60', '61_90', 'over_90'], 0.0);
        }
        foreach ($receivables as $receivable) {
            $currency = CentralFinanceCurrency::normalize((string) $receivable->currency);
            $outstanding = max(0.0, (float) $receivable->amount_due - (float) $receivable->amount_paid);
            $days = $receivable->due_date ? $receivable->due_date->diffInDays($today, false) : 0;
            $bucket = $days <= 0 ? 'current' : ($days <= 30 ? '1_30' : ($days <= 60 ? '31_60' : ($days <= 90 ? '61_90' : 'over_90')));
            $aging[$currency][$bucket] += $outstanding;
        }
        return $aging;
    }

    /** @return array<string,array<string,float>> */
    private function ledgerZero(): array
    {
        return collect(CentralFinanceCurrency::ALLOWED)->mapWithKeys(fn (string $currency) => [$currency => [
            'money_in' => 0.0, 'money_out' => 0.0, 'operating_income' => 0.0,
            'operating_expense' => 0.0, 'operating_net' => 0.0,
        ]])->all();
    }

    /** @return array<string,array<string,float>> */
    private function receivableZero(): array
    {
        return collect(CentralFinanceCurrency::ALLOWED)->mapWithKeys(fn (string $currency) => [$currency => [
            'due' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0,
        ]])->all();
    }
}
