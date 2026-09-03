<?php

namespace App\Services;

use App\Exports\CentralPaymentImportTemplateExport;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceImportBatch;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceUser;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use App\Support\CentralFinanceCurrency;

/**
 * Maps a payment spreadsheet into existing Central identities only. Preview is
 * metadata-only; confirmation delegates every row to the canonical payment
 * service so receipt, ledger, balance, and receivable rules stay singular.
 */
final class CentralFinancePaymentImportService
{
    private const TYPE = 'payment';
    private const MAX_FILE_SIZE = 5_242_880;

    public function __construct(
        private readonly CentralFinanceImportBatchService $batches,
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceFundAccountScopeService $accounts,
        private readonly CentralFinanceFundAccountSchoolAvailabilityService $availability,
        private readonly CentralFinancePaymentService $payments,
    ) {}

    public function previewUploaded(CentralFinanceUser $actor, int $schoolId, UploadedFile $file): CentralFinanceImportBatch
    {
        if (!$file->isValid() || $file->getSize() > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('The Central payment import file is invalid or too large.');
        }

        return $this->previewRows(
            $actor,
            $schoolId,
            $file->getClientOriginalName(),
            hash_file('sha256', $file->getRealPath()),
            $this->rowsFromFile($file),
        );
    }

    /** @param list<array<string,mixed>> $rows */
    public function previewRows(CentralFinanceUser $actor, int $schoolId, string $fileName, string $fileHash, array $rows): CentralFinanceImportBatch
    {
        $this->workspace->assertCanOperateSchool($actor, $schoolId);
        $mapped = [];
        foreach ($rows as $offset => $row) {
            $data = $this->normaliseRow($row);
            // The School is server-selected from the current Central scope;
            // it is stored only so confirmation can revalidate the same scope.
            $data['school_id'] = $schoolId;
            $mapped[] = [
                'idempotency_key' => $this->rowKey($schoolId, $data, $offset + 2),
                'data' => $data,
                'errors' => $this->validateRow($actor, $schoolId, $data),
            ];
        }

        return $this->batches->preview(
            $actor, $schoolId, self::TYPE, CentralPaymentImportTemplateExport::VERSION,
            $fileName, $fileHash, $mapped,
        );
    }

    public function confirm(CentralFinanceUser $actor, string $token): CentralFinanceImportBatch
    {
        return $this->batches->confirm(
            $actor,
            $token,
            fn (array $row): array => $this->validateRow($actor, (int) ($row['data']['school_id'] ?? 0), (array) $row['data']),
            function (array $rows, CentralFinanceImportBatch $batch) use ($actor): void {
                foreach ($rows as $row) {
                    $data = (array) $row['data'];
                    $receivable = $this->receivable((int) $batch->school_id, $data);
                    $account = $this->account((int) $batch->school_id, $data);
                    $this->payments->collect(
                        $actor,
                        $receivable->id,
                        $account,
                        (float) $data['amount'],
                        (string) $data['payment_method'],
                        CarbonImmutable::parse((string) $data['payment_date'], 'Asia/Yangon'),
                        'import:'.$batch->token.':'.$row['row_number'],
                        (string) $data['payment_reference'],
                    );
                }
            },
        );
    }

    /** @return list<array<string,mixed>> */
    private function rowsFromFile(UploadedFile $file): array
    {
        $sheet = Excel::toArray([], $file)[0] ?? [];
        if (count($sheet) < 2) {
            throw new InvalidArgumentException('The Central payment import must include a heading row and at least one payment row.');
        }
        $headings = array_map(fn ($heading) => trim((string) $heading), array_shift($sheet));
        if ($headings !== (new CentralPaymentImportTemplateExport())->headings()) {
            throw new InvalidArgumentException('Central payment import headings do not match Payment Import Template V1.');
        }

        return array_values(array_filter(array_map(function (array $values) use ($headings): array {
            return array_combine($headings, array_pad($values, count($headings), null));
        }, $sheet), fn (array $row): bool => collect($row)->filter(fn ($value) => $value !== null && trim((string) $value) !== '')->isNotEmpty()));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normaliseRow(array $row): array
    {
        return [
            'payment_date' => $this->normaliseDate($row['Payment Date'] ?? null),
            'student_uuid' => strtolower(trim((string) ($row['Student UUID'] ?? ''))),
            'student_code' => trim((string) ($row['Student Code'] ?? '')),
            'receivable_reference' => strtolower(trim((string) ($row['Receivable Reference'] ?? ''))),
            'fund_account_code' => strtoupper(trim((string) ($row['Fund Account Code'] ?? ''))),
            'currency' => trim((string) ($row['Currency'] ?? '')),
            'amount' => is_numeric($row['Amount'] ?? null) ? (float) $row['Amount'] : null,
            'payment_method' => trim((string) ($row['Payment Method'] ?? '')),
            'payment_reference' => trim((string) ($row['Payment Reference'] ?? '')),
            'remarks' => trim((string) ($row['Remarks'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $data @return list<string> */
    private function validateRow(CentralFinanceUser $actor, int $schoolId, array $data): array
    {
        $errors = [];
        try { CarbonImmutable::parse((string) $data['payment_date'], 'Asia/Yangon'); } catch (\Throwable) { $errors[] = 'Payment Date is invalid.'; }
        if (($data['student_uuid'] ?? '') === '' && ($data['student_code'] ?? '') === '') $errors[] = 'Student UUID or Student Code is required.';
        if (!empty($data['student_uuid']) && !Str::isUuid($data['student_uuid'])) $errors[] = 'Student UUID is invalid.';
        if (($data['receivable_reference'] ?? '') === '' || !Str::isUuid($data['receivable_reference'])) $errors[] = 'Receivable Reference must be an existing Central receivable UUID.';
        if (($data['fund_account_code'] ?? '') === '') $errors[] = 'Fund Account Code is required.';
        try { CentralFinanceCurrency::assertCanonical((string) ($data['currency'] ?? '')); } catch (InvalidArgumentException $error) { $errors[] = $error->getMessage(); }
        if (!is_numeric($data['amount'] ?? null) || (float) $data['amount'] <= 0 || !is_finite((float) $data['amount'])) $errors[] = 'Amount must be greater than zero.';
        if (!preg_match('/^[A-Za-z0-9 _.-]{2,40}$/', (string) ($data['payment_method'] ?? ''))) $errors[] = 'Payment Method is invalid.';
        if (!preg_match('/^[A-Za-z0-9_.:-]{2,100}$/', (string) ($data['payment_reference'] ?? ''))) $errors[] = 'Payment Reference is required and invalid.';
        if ($errors !== []) return $errors;

        try {
            $profile = $this->profile($schoolId, $data);
            $receivable = $this->receivable($schoolId, $data);
            if ($receivable->student_profile_id !== $profile->id || !in_array($receivable->status, [CentralFinanceReceivable::OPEN, CentralFinanceReceivable::PARTIAL], true)) {
                $errors[] = 'Receivable does not belong to this Student or is not outstanding.';
            } elseif ((float) $data['amount'] > (float) $receivable->amount_due - (float) $receivable->amount_paid) {
                $errors[] = 'Payment exceeds the outstanding receivable amount.';
            }
            $account = $this->account($schoolId, $data);
            $this->accounts->assertCanOperate($actor, $account);
            if (!CentralFinanceCurrency::same((string) $data['currency'], (string) $account->currency)
                || !CentralFinanceCurrency::same((string) $data['currency'], (string) $receivable->currency)) $errors[] = 'Currency must match both the Fund Account and receivable.';
        } catch (AuthorizationException) {
            $errors[] = 'Fund Account is not authorized for this actor.';
        } catch (\Throwable) {
            $errors[] = 'Student, receivable, or active Fund Account does not match the selected School.';
        }

        return $errors;
    }

    /** @param array<string,mixed> $data */
    private function profile(int $schoolId, array $data): CentralFinanceStudentProfile
    {
        $query = CentralFinanceStudentProfile::on('mysql')->where('school_id', $schoolId);
        if ($data['student_uuid'] !== '') $query->where('source_uuid', $data['student_uuid']);
        if ($data['student_code'] !== '') $query->where('admission_no', $data['student_code']);
        return $query->firstOrFail();
    }

    /** @param array<string,mixed> $data */
    private function receivable(int $schoolId, array $data): CentralFinanceReceivable
    {
        return CentralFinanceReceivable::on('mysql')->where('school_id', $schoolId)->where('receivable_uuid', $data['receivable_reference'])->firstOrFail();
    }

    /** @param array<string,mixed> $data */
    private function account(int $schoolId, array $data): CentralFinanceFundAccount
    {
        $account = CentralFinanceFundAccount::on('mysql')->active()->where('account_code', $data['fund_account_code'])->firstOrFail();
        $this->availability->assertAccountAvailableForSchool($account, $schoolId);
        return $account;
    }

    /** @param array<string,mixed> $data */
    private function rowKey(int $schoolId, array $data, int $rowNumber): string
    {
        $reference = (string) ($data['payment_reference'] ?: 'invalid-'.$rowNumber);
        return 'payment:'.$schoolId.':'.hash('sha256', $reference.'|'.$data['receivable_reference']);
    }

    private function normaliseDate(mixed $value): string
    {
        if (is_numeric($value) && (float) $value > 1_000) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        return trim((string) $value);
    }
}
