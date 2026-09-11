<?php

namespace App\Services;

use App\Helpers\MoneyDecimal;
use App\Models\CompulsoryFee;
use App\Models\Fee;
use App\Models\FeesClassType;
use App\Models\FeesInstallment;
use App\Models\FeesPaid;
use App\Models\OptionalFee;
use App\Models\Students;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeAssignmentItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Rebuilds legacy offline payment amounts from tenant Fee Setup rows.
 * Must be called inside the controller's tenant transaction.
 */
final class OfflineFeePaymentAuthorityService
{
    /** @return array{fee:Fee,data:array<string,mixed>} */
    public function compulsory(array $input, int $schoolId): array
    {
        $this->requireTransaction();
        $fee = $this->lockedFee($input, $schoolId);
        $student = $this->studentForFee($input, $fee, $schoolId);
        $setupRows = FeesClassType::query()
            ->where('fees_id', $fee->id)
            ->where('class_id', $student->class_section->class_id)
            ->where('school_id', $schoolId)
            ->where('optional', false)
            ->lockForUpdate()
            ->get();
        if ($setupRows->isEmpty()) {
            throw new InvalidArgumentException('Compulsory Fee Setup is missing for this student.');
        }
        $snapshots = $this->confirmedSnapshots($student, $fee, false);
        $pricingRows = $snapshots->isNotEmpty() ? $snapshots : $setupRows;

        $configuredTotal = $this->sum($pricingRows->map(fn ($row) => $this->authoritativeMmkAmount($row)));
        $this->assertPositive($configuredTotal, 'Compulsory Fee Setup amount');
        $this->assertSameMoneyIfPresent($input, 'total_amount', $configuredTotal, 'Compulsory total');

        $feesPaid = FeesPaid::query()
            ->where('fees_id', $fee->id)
            ->where('student_id', $student->user_id)
            ->where('school_id', $schoolId)
            ->lockForUpdate()
            ->first();
        $paid = $feesPaid ? $this->money($feesPaid->amount) : '0.00';
        $remaining = MoneyDecimal::fromMinorUnits(max(
            0,
            MoneyDecimal::toMinorUnits($configuredTotal) - MoneyDecimal::toMinorUnits($paid)
        ));
        $this->assertPositive($remaining, 'Outstanding compulsory amount');

        $canonical = $input;
        $canonical['fees_id'] = $fee->id;
        $canonical['student_id'] = $student->user_id;
        $canonical['total_amount'] = $configuredTotal;
        $canonical['parent_id'] = $student->guardian_id;

        if (!empty($input['installment_mode'])) {
            $canonical = $this->canonicalInstallments($canonical, $fee, $student->user_id, $schoolId, $remaining);
        } else {
            $due = $this->fullPaymentDueCharge($fee);
            $expected = MoneyDecimal::add($remaining, $due);
            $this->assertPositive($expected, 'Compulsory payment');
            $this->assertSameMoneyIfPresent($input, 'enter_amount', $expected, 'Compulsory payment amount');
            $this->assertSameMoneyIfPresent($input, 'due_charges_amount', $due, 'Due charge');
            if ((float) ($input['advance'] ?? 0) !== 0.0) {
                throw new InvalidArgumentException('Advance is not valid for a full compulsory payment.');
            }
            $canonical['enter_amount'] = $expected;
            $canonical['due_charges_amount'] = $due;
            $canonical['advance'] = 0;
            $canonical['installment_fees'] = [];
        }

        $paymentAmount = !empty($canonical['installment_mode'])
            ? $this->sum(collect($canonical['installment_fees'])->pluck('amount')->push($canonical['advance'] ?? 0))
            : $this->money($canonical['enter_amount']);
        $canonical = $this->canonicalCurrency($canonical, $pricingRows, $paymentAmount);

        return ['fee' => $fee, 'data' => $canonical];
    }

    /** @return array{fee:Fee,student:Students,items:Collection<int,FeesClassType>,data:array<string,mixed>} */
    public function optional(array $input, int $schoolId): array
    {
        $this->requireTransaction();
        $fee = $this->lockedFee($input, $schoolId);
        $student = $this->studentForFee($input, $fee, $schoolId);
        $submitted = collect($input['fees_class_type'] ?? []);
        $ids = $submitted->pluck('id')->map(static fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty() || $ids->count() !== $submitted->count()) {
            throw new InvalidArgumentException('Optional Fee Setup selection is invalid or duplicated.');
        }

        $items = FeesClassType::query()
            ->whereIn('id', $ids)
            ->where('fees_id', $fee->id)
            ->where('class_id', $student->class_section->class_id)
            ->where('school_id', $schoolId)
            ->where('optional', true)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($items->count() !== $ids->count()) {
            throw new InvalidArgumentException('Optional Fee Setup selection does not belong to this receivable.');
        }
        $snapshots = $this->confirmedSnapshots($student, $fee, true)
            ->whereIn('source_id', $ids->map(fn ($id) => (string) $id));
        $pricingRows = $snapshots->count() === $ids->count() ? $snapshots->keyBy(fn ($item) => (int) $item->source_id) : $items;

        // Preserve the existing audited-delete flow: an active successful item
        // blocks duplicate collection, while a deliberately voided row does not.
        $alreadyPaid = OptionalFee::query()
            ->where('student_id', $student->user_id)
            ->whereIn('fees_class_id', $ids)
            ->whereRaw('LOWER(status) = ?', ['success'])
            ->lockForUpdate()
            ->first();
        if ($alreadyPaid) {
            throw new InvalidArgumentException('One or more optional fee items are already paid.');
        }

        $canonicalItems = [];
        foreach ($submitted as $row) {
            $item = $pricingRows->get((int) $row['id']);
            $amount = $this->money($this->authoritativeMmkAmount($item));
            $this->assertPositive($amount, 'Optional Fee Setup item amount');
            $this->assertSameMoneyIfPresent($row, 'amount', $amount, 'Optional item amount');
            // The persisted OptionalFee foreign key must remain the original
            // FeesClassType id even when price/currency came from an immutable
            // StudentFeeAssignmentItem snapshot.
            $canonicalItems[] = ['id' => (int) $row['id'], 'amount' => $amount];
        }
        $total = $this->sum(collect($canonicalItems)->pluck('amount'));
        $this->assertPositive($total, 'Optional payment total');
        $this->assertSameMoneyIfPresent($input, 'total_amount', $total, 'Optional payment total');

        $canonical = $input;
        $canonical['fees_id'] = $fee->id;
        $canonical['student_id'] = $student->user_id;
        $canonical['class_id'] = $student->class_section->class_id;
        $canonical['fees_class_type'] = $canonicalItems;
        $canonical['total_amount'] = $total;
        $canonical = $this->canonicalCurrency($canonical, $pricingRows->values(), $total);

        return ['fee' => $fee, 'student' => $student, 'items' => $items->values(), 'data' => $canonical];
    }

    private function canonicalInstallments(
        array $data,
        Fee $fee,
        int $studentId,
        int $schoolId,
        string $remaining
    ): array {
        $submitted = collect($data['installment_fees'] ?? []);
        $ids = $submitted->pluck('id')->map(static fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty() || $ids->count() !== $submitted->count()) {
            throw new InvalidArgumentException('Installment selection is invalid or duplicated.');
        }

        $all = FeesInstallment::query()
            ->where('fees_id', $fee->id)
            ->where('school_id', $schoolId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $selected = $all->whereIn('id', $ids)->keyBy('id');
        if ($selected->count() !== $ids->count()) {
            throw new InvalidArgumentException('Installment does not belong to this Fee Setup.');
        }
        if (CompulsoryFee::query()
            ->where('student_id', $studentId)
            ->whereIn('installment_id', $ids)
            ->whereRaw('LOWER(status) = ?', ['success'])
            ->lockForUpdate()
            ->first()) {
            throw new InvalidArgumentException('One or more selected installments are already paid.');
        }

        $fallback = MoneyDecimal::fromMinorUnits(intdiv(
            MoneyDecimal::toMinorUnits($this->money($fee->total_compulsory_fees)),
            max(1, $all->count())
        ));
        $canonicalRows = [];
        foreach ($submitted as $row) {
            $installment = $selected->get((int) $row['id']);
            $base = (float) ($installment->getAttribute('installment_amount') ?? 0) > 0
                ? $this->money($installment->getAttribute('installment_amount'))
                : $fallback;
            $due = $this->installmentDueCharge($installment, $base);
            $this->assertPositive($base, 'Installment amount');
            $this->assertSameMoneyIfPresent($row, 'amount', $base, 'Installment amount');
            $this->assertSameMoneyIfPresent($row, 'due_charges', $due, 'Installment due charge');
            $canonicalRows[] = ['id' => $installment->id, 'amount' => $base, 'due_charges' => $due];
        }

        $advance = $this->money($data['advance'] ?? 0);
        if (MoneyDecimal::compare($advance, '0.00') < 0) {
            throw new InvalidArgumentException('Advance amount must not be negative.');
        }
        $baseTotal = $this->sum(collect($canonicalRows)->pluck('amount')->push($advance));
        $this->assertPositive($baseTotal, 'Installment payment');
        if (MoneyDecimal::compare($baseTotal, $remaining) > 0) {
            throw new InvalidArgumentException('Installment payment exceeds the outstanding receivable.');
        }

        $data['installment_fees'] = $canonicalRows;
        $data['advance'] = $advance;
        return $data;
    }

    private function lockedFee(array $input, int $schoolId): Fee
    {
        $feeId = (int) ($input['fees_id'] ?? 0);
        $fee = Fee::query()->where('id', $feeId)->where('school_id', $schoolId)->lockForUpdate()->first();
        if (!$fee) {
            throw new InvalidArgumentException('Fee Setup does not belong to the current School.');
        }
        $fee->load(['fees_class_type', 'installments']);
        return $fee;
    }

    private function studentForFee(array $input, Fee $fee, int $schoolId): Students
    {
        $student = Students::query()
            ->with('class_section')
            ->where('user_id', (int) ($input['student_id'] ?? 0))
            ->where('school_id', $schoolId)
            ->lockForUpdate()
            ->first();
        if (!$student || !$student->class_section || (int) $student->class_section->class_id !== (int) $fee->class_id) {
            throw new InvalidArgumentException('Student does not belong to this Fee Setup receivable.');
        }
        return $student;
    }

    private function canonicalCurrency(array $data, Collection $rows, string $amountMmk): array
    {
        $currencies = $rows->map(static fn ($row) => strtoupper((string) ($row->currency_snapshot ?? $row->fee_currency ?? 'MMK')))->unique()->values();
        if ($currencies->count() !== 1) {
            throw new InvalidArgumentException('Selected Fee Setup rows do not have one authoritative currency.');
        }
        $currency = $currencies->first();
        $rates = $rows->map(static function ($row) use ($currency): string {
            $snapshotRate = $row->exchange_rate_snapshot ?? $row->fee_exchange_rate_snapshot ?? null;
            return $currency === 'MMK' ? '1.00' : number_format((float) $snapshotRate, 2, '.', '');
        })->unique()->values();
        if ($rates->count() !== 1 || MoneyDecimal::compare($rates->first(), '0.00') <= 0) {
            throw new InvalidArgumentException('Fee Setup exchange rate is missing or inconsistent.');
        }
        $rate = (float) $rates->first();
        $original = number_format((float) $amountMmk / $rate, 2, '.', '');

        if (isset($data['transaction_currency']) && strtoupper((string) $data['transaction_currency']) !== $currency) {
            throw new InvalidArgumentException('Payment currency does not match Fee Setup.');
        }
        $this->assertSameMoneyIfPresent($data, 'exchange_rate_snapshot', (string) $rate, 'Exchange rate');
        $this->assertSameMoneyIfPresent($data, 'original_amount', $original, 'Original amount');

        $data['transaction_currency'] = $currency;
        $data['exchange_rate_snapshot'] = $rate;
        $data['original_amount'] = $original;
        $data['amount_mmk'] = $amountMmk;
        return $data;
    }

    /** @return Collection<int,StudentFeeAssignmentItem> */
    private function confirmedSnapshots(Students $student, Fee $fee, bool $optional): Collection
    {
        return StudentFeeAssignmentItem::query()
            ->where('fee_id', $fee->id)
            ->where('optional_snapshot', $optional)
            ->where('status', StudentFeeAssignmentItem::ACTIVE)
            ->whereHas('assignment', fn ($query) => $query
                ->where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->where('academic_year_id', $student->session_year_id)
                ->where('status', StudentFeeAssignment::CONFIRMED))
            ->lockForUpdate()
            ->get();
    }

    private function authoritativeMmkAmount(object $row): float
    {
        if ($row instanceof StudentFeeAssignmentItem) {
            if ($row->amount_mmk_snapshot !== null) return (float) $row->amount_mmk_snapshot;
            if (strtoupper((string) $row->currency_snapshot) === 'MMK') return (float) $row->amount_snapshot;
            throw new InvalidArgumentException('Confirmed receivable snapshot is missing its historical FX conversion.');
        }
        return (float) ($row->fee_amount_mmk > 0 ? $row->fee_amount_mmk : $row->amount);
    }

    private function fullPaymentDueCharge(Fee $fee): string
    {
        $dueDate = $fee->getRawOriginal('due_date');
        if (!$dueDate || !Carbon::parse($dueDate)->startOfDay()->lt(now()->startOfDay())) {
            return '0.00';
        }
        return $this->money($fee->due_charges_amount ?? 0);
    }

    private function installmentDueCharge(FeesInstallment $installment, string $base): string
    {
        $dueDate = $installment->getRawOriginal('due_date');
        if (!$dueDate || !Carbon::parse($dueDate)->startOfDay()->lt(now()->startOfDay())) {
            return '0.00';
        }
        $charge = $this->money($installment->due_charges ?? 0);
        if ($installment->getAttribute('due_charges_type') === 'percentage') {
            return number_format(((float) $base * (float) $charge) / 100, 2, '.', '');
        }
        return $charge;
    }

    private function assertSameMoneyIfPresent(array $data, string $key, string $expected, string $label): void
    {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return;
        }
        $actual = $this->money($data[$key]);
        if (MoneyDecimal::compare($actual, $expected) !== 0) {
            throw new InvalidArgumentException("{$label} does not match server Fee Setup.");
        }
    }

    private function assertPositive(string $amount, string $label): void
    {
        if (MoneyDecimal::compare($amount, '0.00') <= 0) {
            throw new InvalidArgumentException("{$label} must be greater than zero.");
        }
    }

    private function sum(iterable $values): string
    {
        $minor = 0;
        foreach ($values as $value) {
            $minor += MoneyDecimal::toMinorUnits($this->money($value));
        }
        return MoneyDecimal::fromMinorUnits($minor);
    }

    private function money(mixed $value): string
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }
        return MoneyDecimal::normalize((string) $value);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new InvalidArgumentException('Offline payment authority requires an active database transaction.');
        }
    }
}
