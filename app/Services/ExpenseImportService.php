<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseImportBatch;
use App\Models\FinanceCategory;
use App\Models\SessionYear;
use App\Models\User;
use App\Support\ExpenseImportTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Preview is read-only with respect to Expenses. Confirm locks one tenant
 * batch, revalidates every business key and atomically INSERTs new Expenses.
 */
class ExpenseImportService
{
    private const MAX_FILE_SIZE = 5_242_880;
    private const MAX_ROWS = 500;
    private const EXPIRY_MINUTES = 30;

    public function __construct(private readonly FinanceAccountAccessService $accounts) {}

    /** @return array{token:string,rows:array<int,array<string,mixed>>,summary:array<string,int>} */
    public function preview(UploadedFile $file, int $schoolId, int $userId): array
    {
        $this->validateFile($file);
        $actor = User::whereKey($userId)->where('school_id', $schoolId)->firstOrFail();
        $fileHash = hash_file('sha256', $file->getRealPath());
        if (ExpenseImportBatch::where('school_id', $schoolId)->where('file_hash', $fileHash)->exists()) {
            throw new \InvalidArgumentException('This exact file was already uploaded for this school and cannot create a duplicate expense batch.');
        }

        $rows = $this->parse($file);
        if (count($rows) > self::MAX_ROWS) throw new \InvalidArgumentException('Maximum Expense import rows is ' . self::MAX_ROWS . '.');
        $references = [];
        $preview = [];
        foreach ($rows as $index => $row) $preview[] = $this->validateRow($row, $index + 2, $schoolId, $actor, $references);
        $validRows = collect($preview)->where('status', 'valid')->count();
        try {
            $batch = ExpenseImportBatch::create([
                'token' => (string) Str::uuid(), 'school_id' => $schoolId, 'imported_by' => $userId,
                'file_name' => $file->getClientOriginalName(), 'file_hash' => $fileHash,
                'preview_data' => $preview, 'status' => ExpenseImportBatch::STATUS_PENDING,
                'total_rows' => count($preview), 'valid_rows' => $validRows,
                'error_rows' => count($preview) - $validRows, 'expired_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            ]);
        } catch (QueryException $exception) {
            // Covers a second, concurrent upload after the initial lookup.
            throw new \InvalidArgumentException('This exact file was already uploaded for this school and cannot create a duplicate expense batch.', previous: $exception);
        }
        return ['token' => $batch->token, 'rows' => $preview, 'summary' => ['total' => count($preview), 'valid' => $validRows, 'error' => count($preview) - $validRows]];
    }

    /** @return array{batch_id:int,imported:int,expense_ids:array<int,int>} */
    public function confirm(string $token, int $schoolId, int $userId): array
    {
        try {
            return DB::connection('school')->transaction(function () use ($token, $schoolId, $userId) {
                $batch = ExpenseImportBatch::where('token', $token)->where('school_id', $schoolId)->lockForUpdate()->firstOrFail();
                if ($batch->imported_by !== $userId || $batch->status !== ExpenseImportBatch::STATUS_PENDING) throw new \InvalidArgumentException('This Expense import batch cannot be confirmed.');
                if ($batch->expired_at && now()->greaterThan($batch->expired_at)) throw new \InvalidArgumentException('Expense import preview expired; upload again.');
                if ($batch->error_rows > 0) throw new \InvalidArgumentException('Expense import has invalid rows. Correct them before confirming.');

                $actor = User::whereKey($userId)->where('school_id', $schoolId)->firstOrFail();
                $batch->update(['status' => ExpenseImportBatch::STATUS_PROCESSING]);
                $references = [];
                $ids = [];
                foreach ($batch->preview_data ?? [] as $preview) {
                    $record = $this->revalidate($preview, $schoolId, $actor, $references);
                    // create(), never update/upsert: Excel row numbers cannot target an existing Expense.
                    $ids[] = Expense::create($record + ['school_id' => $schoolId, 'created_by' => $userId])->id;
                }
                $batch->update(['status' => ExpenseImportBatch::STATUS_COMPLETED, 'imported_rows' => count($ids), 'imported_expense_ids' => $ids, 'preview_data' => null, 'consumed_at' => now()]);
                return ['batch_id' => $batch->id, 'imported' => count($ids), 'expense_ids' => $ids];
            });
        } catch (\Throwable $exception) {
            ExpenseImportBatch::where('token', $token)->where('school_id', $schoolId)
                ->whereIn('status', [ExpenseImportBatch::STATUS_PENDING, ExpenseImportBatch::STATUS_PROCESSING])
                ->update(['status' => ExpenseImportBatch::STATUS_FAILED, 'last_error' => Str::limit($exception->getMessage(), 2000)]);
            throw $exception;
        }
    }

    /** @param array<int,mixed> $raw @param array<int,string> $references @return array<string,mixed> */
    private function validateRow(array $raw, int $rowNumber, int $schoolId, User $actor, array &$references): array
    {
        $data = $this->normalise($raw);
        $errors = [];
        $category = $this->one(ExpenseCategory::where('school_id', $schoolId)->where('name', $data['expense_category']), 'Expense Category', $errors);
        $finance = $data['finance_category'] === '' ? null : $this->one(FinanceCategory::where('school_id', $schoolId)->where('type', 'expense')->where('name', $data['finance_category']), 'Finance Category', $errors);
        $year = $this->one(SessionYear::where('school_id', $schoolId)->where('name', $data['academic_year']), 'Academic Year', $errors);
        $account = $this->one($this->accounts->accessibleAccounts($actor)->active()->where('account_name', $data['fund_account_name']), 'Fund Account', $errors);
        if ($account && strtoupper((string) $account->currency) !== 'MMK') {
            $errors[] = 'The MMK Expense import template requires an MMK Fund Account';
        }
        if ($data['title'] === '') $errors[] = 'Title is required';
        if (!is_numeric($data['amount']) || (float) $data['amount'] <= 0) $errors[] = 'Amount must be positive';
        if (!in_array($data['payment_method'], ExpenseImportTemplate::PAYMENT_METHODS, true)) $errors[] = 'Payment Method is invalid';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date']) || !strtotime($data['date'])) $errors[] = 'Date must use YYYY-MM-DD';
        if ($data['reference_no'] === '') $errors[] = 'Reference No is required';
        if ($data['reference_no'] !== '') {
            if (in_array($data['reference_no'], $references, true)) $errors[] = 'Reference No is duplicated in this file';
            $references[] = $data['reference_no'];
            if (Expense::withTrashed()->where('school_id', $schoolId)->where('ref_no', $data['reference_no'])->exists()) $errors[] = 'Reference No already exists';
        }
        return ['row_number' => $rowNumber, 'status' => $errors === [] ? 'valid' : 'error', 'errors' => $errors, 'data' => $data, 'resolved' => ['category_id' => $category?->id, 'finance_category_id' => $finance?->id, 'session_year_id' => $year?->id, 'bank_account_id' => $account?->id]];
    }

    /** @param array<string,mixed> $preview @param array<int,string> $references @return array<string,mixed> */
    private function revalidate(array $preview, int $schoolId, User $actor, array &$references): array
    {
        $validated = $this->validateRow($preview['data'] ?? [], (int) ($preview['row_number'] ?? 0), $schoolId, $actor, $references);
        if ($validated['status'] !== 'valid') throw new \InvalidArgumentException('Row ' . $validated['row_number'] . ': ' . implode('; ', $validated['errors']));
        $resolved = $validated['resolved'];
        $this->accounts->authorize($actor, (int) $resolved['bank_account_id']);
        $amount = (float) $validated['data']['amount'];
        return ['category_id' => $resolved['category_id'], 'finance_category_id' => $resolved['finance_category_id'], 'session_year_id' => $resolved['session_year_id'], 'bank_account_id' => $resolved['bank_account_id'], 'title' => $validated['data']['title'], 'ref_no' => $validated['data']['reference_no'], 'amount' => $amount, 'amount_mmk' => $amount, 'original_amount' => $amount, 'transaction_currency' => 'MMK', 'exchange_rate_snapshot' => 1, 'payment_method' => $validated['data']['payment_method'], 'description' => $validated['data']['remark'] ?: null, 'date' => $validated['data']['date']];
    }

    /** @param array<int,mixed> $raw @return array<string,string> */
    private function normalise(array $raw): array
    {
        $keys = ['date', 'expense_category', 'finance_category', 'title', 'reference_no', 'amount', 'payment_method', 'fund_account_name', 'remark', 'academic_year'];
        if (!array_is_list($raw)) {
            return array_map(static fn (string $key) => trim((string) ($raw[$key] ?? '')), array_combine($keys, $keys));
        }
        $values = array_pad(array_map(static fn ($value) => trim((string) $value), $raw), count(ExpenseImportTemplate::HEADINGS), '');
        return array_combine($keys, $values);
    }

    private function one($query, string $label, array &$errors): mixed
    {
        $matches = $query->get();
        if ($matches->count() !== 1) $errors[] = $label . ($matches->isEmpty() ? ' was not found' : ' is ambiguous');
        return $matches->first();
    }

    /** @return array<int,array<int,mixed>> */
    private function parse(UploadedFile $file): array
    {
        $rows = Excel::toArray([], $file)[0] ?? [];
        $headings = array_shift($rows) ?? [];
        $headings = array_map(static fn ($value) => trim((string) $value), array_slice($headings, 0, count(ExpenseImportTemplate::HEADINGS)));
        if ($headings !== ExpenseImportTemplate::HEADINGS) {
            throw new \InvalidArgumentException('Expense import headings do not match the downloadable template.');
        }
        return array_values(array_filter($rows, static fn (array $row) => collect($row)->contains(static fn ($value) => trim((string) $value) !== '')));
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid() || $file->getSize() > self::MAX_FILE_SIZE) throw new \InvalidArgumentException('Upload a valid Excel file no larger than 5MB.');
        if (!in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'xls', 'csv'], true)) throw new \InvalidArgumentException('Only .xlsx, .xls, and .csv files are accepted.');
    }
}
