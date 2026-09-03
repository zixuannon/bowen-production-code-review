<?php

namespace App\Services;

use App\Exports\CentralExpenseImportTemplateExport;
use App\Models\CentralFinanceCategory;
use App\Models\CentralFinanceExpense;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceImportBatch;
use App\Models\CentralFinanceUser;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use App\Support\CentralFinanceCurrency;

/**
 * Direct historical-expense importer. `报销人` is immutable Expense metadata;
 * it never creates a Reimbursement request or an approval workflow record.
 */
final class CentralFinanceExpenseImportService
{
    private const TYPE = 'expense';
    private const MAX_FILE_SIZE = 5_242_880;

    public function __construct(
        private readonly CentralFinanceImportBatchService $batches,
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceFundAccountScopeService $accounts,
        private readonly CentralFinanceFundAccountSchoolAvailabilityService $availability,
        private readonly CentralFinanceFundAccountBalanceService $balances,
        private readonly CentralFinanceOperatingDocumentService $expenses,
    ) {}

    public function previewUploaded(CentralFinanceUser $actor, int $schoolId, UploadedFile $file): CentralFinanceImportBatch
    {
        if (!$file->isValid() || $file->getSize() > self::MAX_FILE_SIZE) throw new InvalidArgumentException('The Central Expense import file is invalid or too large.');
        return $this->previewRows($actor, $schoolId, $file->getClientOriginalName(), hash_file('sha256', $file->getRealPath()), $this->rowsFromFile($file));
    }

    /** @param list<array<string,mixed>> $rows */
    public function previewRows(CentralFinanceUser $actor, int $schoolId, string $fileName, string $fileHash, array $rows): CentralFinanceImportBatch
    {
        $school = $this->workspace->assertCanOperateSchool($actor, $schoolId);
        $projected = [];
        $mapped = [];
        foreach ($rows as $offset => $row) {
            $data = $this->normaliseRow($row); $data['school_id'] = $schoolId;
            $mapped[] = ['idempotency_key' => $this->rowKey($schoolId, $data, $offset + 2), 'data' => $data, 'errors' => $this->validateRow($actor, $school->name, $school->code, $data, $projected)];
        }
        return $this->batches->preview($actor, $schoolId, self::TYPE, CentralExpenseImportTemplateExport::VERSION, $fileName, $fileHash, $mapped);
    }

    public function confirm(CentralFinanceUser $actor, string $token): CentralFinanceImportBatch
    {
        $projected = [];
        return $this->batches->confirm(
            $actor,
            $token,
            function (array $row) use ($actor, &$projected): array {
                $school = $this->workspace->assertCanOperateSchool($actor, (int) ($row['data']['school_id'] ?? 0));
                return $this->validateRow($actor, $school->name, $school->code, (array) $row['data'], $projected);
            },
            function (array $rows, CentralFinanceImportBatch $batch) use ($actor): void {
                foreach ($rows as $row) {
                    $data = (array) $row['data']; $account = $this->account((int) $batch->school_id, $data);
                    $category = $this->category((int) $batch->school_id, $data);
                    $this->expenses->createExpense(
                        $actor, (int) $batch->school_id, $category->id, $account, (float) $data['amount'],
                        (string) $data['payment_method'], CarbonImmutable::parse((string) $data['expense_date'], 'Asia/Yangon'),
                        'import:'.$batch->token.':'.$row['row_number'], (string) $data['reference_no'],
                        $this->description($data), $data['reimbursed_by'] ?: null,
                    );
                }
            },
        );
    }

    /** @return list<array<string,mixed>> */
    private function rowsFromFile(UploadedFile $file): array
    {
        $sheet = Excel::toArray([], $file)[0] ?? [];
        if (count($sheet) < 2) throw new InvalidArgumentException('The Central Expense import must include a heading row and at least one expense row.');
        $headings = array_map(fn ($heading) => trim((string) $heading), array_shift($sheet));
        if ($headings !== (new CentralExpenseImportTemplateExport())->headings()) throw new InvalidArgumentException('Central Expense import headings do not match Expense Import Template V1.');
        return array_values(array_filter(array_map(fn (array $values): array => array_combine($headings, array_pad($values, count($headings), null)), $sheet), fn (array $row): bool => collect($row)->filter(fn ($value) => $value !== null && trim((string) $value) !== '')->isNotEmpty()));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normaliseRow(array $row): array
    {
        return [
            'expense_date' => $this->normaliseDate($row['日期'] ?? null), 'reimbursed_by' => trim((string) ($row['报销人'] ?? '')),
            'summary' => trim((string) ($row['摘要'] ?? '')), 'school_label' => trim((string) ($row['校区'] ?? '')),
            'fund_account_code' => strtoupper(trim((string) ($row['Fund Account Code'] ?? ''))), 'account_type' => strtolower(trim((string) ($row['Account Type'] ?? ''))),
            'currency' => trim((string) ($row['Currency'] ?? '')),
            'category_name' => trim((string) ($row['费用类别'] ?? '')), 'payment_method' => trim((string) ($row['付款方式'] ?? '')),
            'amount' => is_numeric($row['支出'] ?? null) ? (float) $row['支出'] : null, 'reference_no' => trim((string) ($row['Reference No'] ?? '')),
            'remarks' => trim((string) ($row['备注'] ?? '')), 'expected_balance' => ($row['余款'] ?? '') === '' || $row['余款'] === null ? null : (is_numeric($row['余款']) ? (float) $row['余款'] : false),
        ];
    }

    /** @param array<string,float> $projected @param array<string,mixed> $data @return list<string> */
    private function validateRow(CentralFinanceUser $actor, string $schoolName, ?string $schoolCode, array $data, array &$projected): array
    {
        $errors = [];
        try { CarbonImmutable::parse((string) $data['expense_date'], 'Asia/Yangon'); } catch (\Throwable) { $errors[] = '日期 is invalid.'; }
        if ($data['summary'] === '') $errors[] = '摘要 is required.';
        if ($data['school_label'] === '' || !in_array(mb_strtolower($data['school_label']), array_filter([mb_strtolower($schoolName), mb_strtolower((string) $schoolCode)]), true)) $errors[] = '校区 must match the selected Central School.';
        if ($data['fund_account_code'] === '') $errors[] = 'Fund Account Code is required.';
        try { CentralFinanceCurrency::assertCanonical((string) ($data['currency'] ?? '')); } catch (InvalidArgumentException $error) { $errors[] = $error->getMessage(); }
        if (!in_array($data['account_type'], ['school', 'hq'], true)) $errors[] = 'Account Type must be School or HQ.';
        if ($data['category_name'] === '') $errors[] = '费用类别 is required.';
        if (!preg_match('/^[A-Za-z0-9 _.-]{2,40}$/', $data['payment_method'])) $errors[] = '付款方式 is invalid.';
        if (!is_numeric($data['amount']) || (float) $data['amount'] <= 0 || !is_finite((float) $data['amount'])) $errors[] = '支出 must be greater than zero.';
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $data['reference_no'])) $errors[] = 'Reference No is required and invalid.';
        if ($data['reimbursed_by'] !== '' && mb_strlen($data['reimbursed_by']) > 191) $errors[] = '报销人 is too long.';
        if ($data['expected_balance'] === false) $errors[] = '余款 must be numeric when supplied.';
        if ($errors !== []) return $errors;

        try {
            $account = $this->account((int) $data['school_id'], $data);
            $this->accounts->assertCanOperate($actor, $account);
            if (!CentralFinanceCurrency::same((string) $data['currency'], (string) $account->currency)) $errors[] = 'Currency must match the Central Fund Account.';
            if ($account->owner_type !== $data['account_type']) $errors[] = 'Account Type does not match the Central Fund Account.';
            $this->category((int) $data['school_id'], $data);
            if (CentralFinanceExpense::on('mysql')->withTrashed()->where(['school_id' => $data['school_id'], 'reference_no' => $data['reference_no']])->exists()) $errors[] = 'Reference No is already reserved for this School.';

            // A rejected row must never affect the optional running-balance
            // validation of a later row in the same preview/confirmation.
            if ($errors !== []) return $errors;

            $key = (string) $account->id; $projected[$key] ??= $this->balances->currentBalance($account);
            $projected[$key] -= (float) $data['amount'];
            if ($data['expected_balance'] !== null && abs($projected[$key] - (float) $data['expected_balance']) > 0.0001) $errors[] = '余款 does not match the calculated post-expense balance; it cannot overwrite Central balance.';
        } catch (AuthorizationException) { $errors[] = 'Fund Account is not authorized for this actor.'; }
        catch (\Throwable) { $errors[] = 'Expense category or active Fund Account does not match the selected School.'; }
        return $errors;
    }

    /** @param array<string,mixed> $data */
    private function account(int $schoolId, array $data): CentralFinanceFundAccount
    {
        $account = CentralFinanceFundAccount::on('mysql')->active()->where('account_code', $data['fund_account_code'])->firstOrFail();
        $this->availability->assertAccountAvailableForSchool($account, $schoolId);
        return $account;
    }

    /** @param array<string,mixed> $data */
    private function category(int $schoolId, array $data): CentralFinanceCategory
    {
        return CentralFinanceCategory::on('mysql')->where(['school_id' => $schoolId, 'type' => CentralFinanceCategory::EXPENSE, 'name' => $data['category_name'], 'is_active' => true])->firstOrFail();
    }

    /** @param array<string,mixed> $data */
    private function rowKey(int $schoolId, array $data, int $rowNumber): string { return 'expense:'.$schoolId.':'.hash('sha256', ($data['reference_no'] ?: 'invalid-'.$rowNumber)); }
    /** @param array<string,mixed> $data */
    private function description(array $data): string { return trim($data['summary'].($data['remarks'] === '' ? '' : "\n".$data['remarks'])); }
    private function normaliseDate(mixed $value): string { return is_numeric($value) && (float) $value > 1_000 ? ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d') : trim((string) $value); }
}
