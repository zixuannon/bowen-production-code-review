<?php

namespace App\Services;

use App\Exports\CentralFinanceGroupImportTemplateV2Export;
use App\Imports\CentralFinanceGroupImportFormulaReader;
use App\Models\CentralFinanceCategory;
use App\Models\CentralFinanceExpense;
use App\Models\CentralFinanceDocumentAudit;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceGroupImportBatch;
use App\Models\CentralFinanceGroupImportPreviewRow;
use App\Models\CentralFinanceImportBatch;
use App\Models\CentralFinanceOtherIncome;
use App\Models\CentralFinanceUser;
use App\Models\FinanceGroup;
use App\Models\FinanceGroupSchool;
use App\Models\FinanceGroupUser;
use App\Models\School;
use App\Support\CentralFinanceCurrency;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Group Finance Import V2 deliberately stops at a persisted preview.  It
 * never invokes an operating-document service and therefore never posts a
 * ledger line, alters a balance, or creates a financial document.
 */
final class CentralFinanceGroupImportService
{
    public const SCHEMA_VERSION = 'group-finance-v2';
    private const MAX_FILE_SIZE = 5_242_880;

    public function __construct(
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceFundAccountScopeService $accountScopes,
        private readonly CentralFinanceFundAccountBalanceService $balances,
        private readonly FinanceGroupScopeService $groups,
        private readonly CentralFinanceSchoolCutoverService $cutovers,
        private readonly CentralFinanceOperatingDocumentService $documents,
    ) {}

    /** @return Collection<int, FinanceGroup> */
    public function authorizedGroups(CentralFinanceUser $actor): Collection
    {
        return FinanceGroup::on('mysql')->where('status', 'active')->get()
            ->filter(function (FinanceGroup $group) use ($actor): bool {
                try {
                    $this->assertCanOperateGroup($actor, $group);
                    return true;
                } catch (AuthorizationException) {
                    return false;
                }
            })
            ->values();
    }

    public function assertCanOperateGroup(CentralFinanceUser $actor, FinanceGroup $group): FinanceGroupUser
    {
        $groupUser = FinanceGroupUser::on('mysql')->where([
            'group_id' => $group->id,
            'central_user_id' => $actor->id,
            'status' => 'active',
        ])->first();

        if (!$groupUser || $group->status !== 'active' || !$this->groups->hasActiveGroupScope($groupUser, 'operate_finance')) {
            throw new AuthorizationException('An active group-level operate_finance scope is required for Group Import.');
        }

        return $groupUser;
    }

    /**
     * Build read-only, actor-scoped master-data lookup rows for the V2.1
     * workbook. These rows are convenience choices only: preview/confirm
     * keeps the existing canonical exact-match and authorization validation.
     *
     * @return array{schools:list<array{code:string,name:string}>,accounts:list<array{code:string,name:string,school_code:?string,account_type:string,owner_type:string,currency:string}>,categories:list<array{school_code:string,type:string,category_code:string,name:string}>}
     */
    public function templateLookups(CentralFinanceUser $actor, FinanceGroup $group): array
    {
        $groupUser = $this->assertCanOperateGroup($actor, $group);
        $today = now()->toDateString();
        $memberSchoolIds = FinanceGroupSchool::on('mysql')
            ->where('group_id', $group->id)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('active_from')->orWhereDate('active_from', '<=', $today))
            ->where(fn ($query) => $query->whereNull('active_to')->orWhereDate('active_to', '>=', $today))
            ->orderBy('school_id')
            ->pluck('school_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(fn (int $schoolId): bool => $this->groups->canAccessSchool($groupUser, $schoolId, 'operate_finance'))
            ->values()
            ->all();

        $schools = School::on('mysql')
            ->whereIn('id', $memberSchoolIds)
            ->where('installed', true)
            ->whereIn('status', ['1', 'active'])
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->filter(function (School $school) use ($actor): bool {
                try {
                    $this->workspace->assertCanOperateSchool($actor, (int) $school->id);
                    return true;
                } catch (AuthorizationException) {
                    return false;
                }
            })
            ->values();

        $schoolCodes = $schools->mapWithKeys(static fn (School $school): array => [(int) $school->id => strtoupper(trim((string) $school->code))]);
        $accounts = CentralFinanceFundAccount::on('mysql')->active()
            ->where(function ($query) use ($group, $schoolCodes): void {
                $query->where(fn ($schoolOwned) => $schoolOwned->where('owner_type', CentralFinanceFundAccount::OWNER_SCHOOL)->whereIn('school_id', $schoolCodes->keys()))
                    ->orWhere(fn ($hqOwned) => $hqOwned->where('owner_type', CentralFinanceFundAccount::OWNER_HQ)->where('group_id', $group->id));
            })
            ->orderBy('account_code')
            ->get()
            ->filter(function (CentralFinanceFundAccount $account) use ($actor): bool {
                try {
                    $this->accountScopes->assertCanOperate($actor, $account);
                    return true;
                } catch (AuthorizationException) {
                    return false;
                }
            })
            ->map(static fn (CentralFinanceFundAccount $account): array => [
                'code' => $account->account_code,
                'name' => $account->account_name,
                'school_code' => $account->owner_type === CentralFinanceFundAccount::OWNER_SCHOOL ? $schoolCodes->get((int) $account->school_id) : null,
                'account_type' => $account->account_type,
                'owner_type' => $account->owner_type,
                'currency' => $account->currency,
            ])
            ->values()
            ->all();

        $categories = CentralFinanceCategory::on('mysql')
            ->whereIn('school_id', $schoolCodes->keys())
            ->where('is_active', true)
            ->orderBy('school_id')
            ->orderBy('type')
            ->orderBy('category_code')
            ->get(['school_id', 'type', 'category_code', 'name'])
            ->map(static fn (CentralFinanceCategory $category): array => [
                'school_code' => $schoolCodes->get((int) $category->school_id),
                'type' => $category->type,
                'category_code' => $category->category_code,
                'name' => $category->name,
            ])
            ->values()
            ->all();

        return [
            'schools' => $schools->map(static fn (School $school): array => ['code' => strtoupper(trim((string) $school->code)), 'name' => $school->name])->all(),
            'accounts' => $accounts,
            'categories' => $categories,
        ];
    }

    /**
     * The only financial write path for Group Import.  The outer transaction
     * deliberately spans every routed School; the canonical document service
     * remains the sole owner of operating-document and Ledger posting.
     */
    public function confirm(CentralFinanceUser $actor, string $token): CentralFinanceGroupImportBatch
    {
        try {
            return DB::connection('mysql')->transaction(function () use ($actor, $token): CentralFinanceGroupImportBatch {
                $batch = CentralFinanceGroupImportBatch::on('mysql')->where('token', $token)->lockForUpdate()->firstOrFail();
                if ($batch->status !== 'previewed' || $batch->error_rows > 0 || $batch->conflict_rows > 0) {
                    throw new InvalidArgumentException('This Group Import preview is not eligible for confirmation.');
                }
                $group = FinanceGroup::on('mysql')->findOrFail($batch->finance_group_id);
                $groupUser = $this->assertCanOperateGroup($actor, $group);
                $rows = CentralFinanceGroupImportPreviewRow::on('mysql')->where('group_batch_id', $batch->id)->orderBy('row_number')->lockForUpdate()->get();
                $batch->update(['status' => 'confirming', 'failure_reason' => null]);
                $this->audit($actor, $batch, $rows, 'group_import_confirm_started');

                $projected = [];
                $validated = [];
                foreach ($rows as $row) {
                    $data = (array) $row->normalized_data;
                    $result = $this->validate($actor, $groupUser, $data, $projected);
                    if (!in_array($result['result_status'], ['New', 'Duplicate'], true)) {
                        throw new GroupImportConfirmException($row, $result['error_code'] ?? 'REVALIDATION_FAILED', $result['error_message'] ?? 'Group Import row revalidation failed.');
                    }
                    $validated[$row->id] = ['data' => $data, 'status' => $result['result_status']];
                }

                foreach ($rows as $row) {
                    $entry = $validated[$row->id]; $data = $entry['data'];
                    // Only a revalidated Duplicate has a pre-existing canonical
                    // source. New rows obtain theirs exclusively from the
                    // canonical operating-document service below.
                    $source = $entry['status'] === 'Duplicate' ? $this->sourceFor($data) : null;
                    if ($entry['status'] === 'New') {
                        $account = CentralFinanceFundAccount::on('mysql')->active()->findOrFail((int) $data['fund_account_id']);
                        $occurredAt = CarbonImmutable::parse((string) $data['transaction_date'], 'Asia/Yangon');
                        try {
                            if ($data['document_type'] === 'expense') {
                                $source = $this->documents->createExpense($actor, (int) $data['school_id'], (int) $data['category_id'], $account, (float) $data['amount'], (string) $data['payment_method'], $occurredAt, (string) $data['idempotency_key'], (string) $data['reference_no'], $this->description($data), $data['claimant'] ?: null);
                            } else {
                                $source = $this->documents->createOtherIncome($actor, (int) $data['school_id'], (int) $data['category_id'], $account, (float) $data['amount'], (string) $data['payment_method'], $occurredAt, (string) $data['idempotency_key'], (string) $data['reference_no'], $data['claimant'] ?: null, $this->description($data));
                            }
                        } catch (\Throwable $error) {
                            throw new GroupImportConfirmException($row, 'CANONICAL_WRITE_FAILED', $error->getMessage());
                        }
                    }
                    if (!($source instanceof CentralFinanceExpense) && !($source instanceof CentralFinanceOtherIncome)) {
                        throw new GroupImportConfirmException($row, 'CANONICAL_SOURCE_MISSING', 'The canonical Group Import source could not be resolved.');
                    }
                    $this->linkRow($row, $source, $entry['status']);
                }
                CentralFinanceImportBatch::on('mysql')->where('group_import_batch_id', $batch->id)->update(['status' => CentralFinanceImportBatch::STATUS_COMPLETED, 'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
                $batch->update(['status' => 'completed', 'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
                $this->audit($actor, $batch, $rows, 'group_import_confirm_completed');
                return $batch->fresh();
            });
        } catch (GroupImportConfirmException $error) {
            $this->markFailed($actor, $token, $error->row, $error->errorCode, $error->getMessage());
            throw $error;
        } catch (AuthorizationException $error) {
            // Authorization denials must neither disclose nor mutate a
            // preview owned by another actor.
            throw $error;
        } catch (\Throwable $error) {
            $this->markFailed($actor, $token, null, 'CONFIRM_FAILED', $error->getMessage());
            throw $error;
        }
    }

    public function previewUploaded(CentralFinanceUser $actor, FinanceGroup $group, UploadedFile $file): CentralFinanceGroupImportBatch
    {
        if (!$file->isValid() || (int) $file->getSize() > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('The Group Finance Import file is invalid or too large.');
        }

        return $this->previewRows($actor, $group, $file->getClientOriginalName(), hash_file('sha256', $file->getRealPath()), $this->rowsFromFile($file));
    }

    /** @param list<array<string,mixed>> $rows */
    public function previewRows(CentralFinanceUser $actor, FinanceGroup $group, string $fileName, string $fileHash, array $rows): CentralFinanceGroupImportBatch
    {
        $groupUser = $this->assertCanOperateGroup($actor, $group);
        $projected = [];
        $prepared = [];

        foreach ($rows as $offset => $row) {
            $data = $this->normaliseRow($row);
            $result = $this->validate($actor, $groupUser, $data, $projected);
            $prepared[] = array_merge(['row_number' => $offset + 2, 'data' => $data], $result);
        }

        return DB::connection('mysql')->transaction(function () use ($actor, $group, $fileName, $fileHash, $prepared): CentralFinanceGroupImportBatch {
            $summary = $this->summary($prepared);
            $parent = CentralFinanceGroupImportBatch::on('mysql')->create([
                'finance_group_id' => $group->id,
                'uploaded_by' => $actor->id,
                'file_name' => $fileName,
                'file_hash' => $fileHash,
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'previewed',
                'total_rows' => count($prepared),
                'new_rows' => $summary['new'],
                'duplicate_rows' => $summary['duplicate'],
                'conflict_rows' => $summary['conflict'],
                'error_rows' => $summary['error'],
                // The persisted preview is the UI read model. Currency totals
                // remain deliberately separated; no cross-currency aggregate
                // is ever stored or displayed.
                'school_summary' => ['schools' => $summary['schools'], 'currency_totals' => $summary['currency_totals']],
            ]);

            $children = [];
            foreach (collect($prepared)->pluck('data.school_id')->filter()->unique() as $schoolId) {
                $children[(int) $schoolId] = CentralFinanceImportBatch::on('mysql')->create([
                    'import_type' => 'group_preview', 'template_version' => self::SCHEMA_VERSION,
                    'school_id' => (int) $schoolId, 'group_import_batch_id' => $parent->id, 'uploaded_by' => $actor->id, 'file_name' => $fileName,
                    // A child is a per-preview audit container.  It intentionally does not
                    // reserve the original file hash, so repeat previews remain classifiable.
                    'file_hash' => hash('sha256', $fileHash.'|'.$parent->token.'|'.$schoolId),
                    'status' => CentralFinanceImportBatch::STATUS_PENDING,
                    'total_rows' => collect($prepared)->where('data.school_id', (int) $schoolId)->count(),
                    'valid_rows' => collect($prepared)->where('data.school_id', (int) $schoolId)->whereIn('result_status', ['New', 'Duplicate'])->count(),
                    'error_rows' => collect($prepared)->where('data.school_id', (int) $schoolId)->whereIn('result_status', ['Conflict', 'Error'])->count(),
                    'summary' => ['preview_only' => true],
                    'preview_data' => [],
                ]);
            }
            foreach ($prepared as $result) {
                $schoolId = (int) ($result['data']['school_id'] ?? 0);
                CentralFinanceGroupImportPreviewRow::on('mysql')->create([
                    'group_batch_id' => $parent->id,
                    'child_batch_id' => $children[$schoolId]->id ?? null,
                    'row_number' => $result['row_number'], 'school_id' => $schoolId ?: null,
                    'document_type' => $result['data']['document_type'] ?? null,
                    'reference_no' => $result['data']['reference_no'] ?: null,
                    'result_status' => $result['result_status'], 'error_code' => $result['error_code'],
                    'error_message' => $result['error_message'], 'normalized_data' => $result['data'],
                    'idempotency_key' => $result['idempotency_key'],
                ]);
            }
            return $parent->fresh();
        });
    }

    /** @return list<array<string,mixed>> */
    private function rowsFromFile(UploadedFile $file): array
    {
        $sheet = Excel::toArray(new CentralFinanceGroupImportFormulaReader(), $file)[0] ?? [];
        if (count($sheet) < 2) throw new InvalidArgumentException('The Group Finance Import must contain a heading row and at least one data row.');
        $headings = array_map(static fn ($value) => trim((string) $value), array_shift($sheet));
        if ($headings !== (new CentralFinanceGroupImportTemplateV2Export())->headings()) throw new InvalidArgumentException('Group Finance Import headings do not match Template V2.');
        return array_values(array_filter(
            array_map(static fn (array $values): array => array_combine($headings, array_pad($values, count($headings), null)), $sheet),
            fn (array $row): bool => $this->containsUserSuppliedValue($row),
        ));
    }

    /**
     * V2.1 leaves formula-driven identity cells ready for the user. Laravel
     * Excel reads those untouched formulas as literal strings, whereas Excel
     * itself renders them blank. Treat only those generated placeholders as
     * empty; all user-entered canonical values continue through unchanged V2
     * validation below.
     *
     * @param array<string,mixed> $row
     */
    private function containsUserSuppliedValue(array $row): bool
    {
        foreach (['序号', 'School Code', 'Fund Account Type', 'Account Owner', 'Currency'] as $derivedHeading) {
            $value = $row[$derivedHeading] ?? null;
            if (is_string($value) && str_starts_with(trim($value), '=')) {
                $row[$derivedHeading] = null;
            }
        }

        return collect($row)->filter(static fn ($value): bool => $value !== null && trim((string) $value) !== '')->isNotEmpty();
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normaliseRow(array $row): array
    {
        $income = $this->decimal($row['收入'] ?? null); $expense = $this->decimal($row['支出'] ?? null);
        $type = $income !== null && $income > 0 && ($expense === null || $expense == 0.0) ? 'other_income' : (($expense !== null && $expense > 0 && ($income === null || $income == 0.0)) ? 'expense' : null);
        return [
            'school_code' => trim((string) ($row['School Code'] ?? '')), 'school_label' => trim((string) ($row['校区'] ?? '')),
            'transaction_date' => $this->date($row['日期'] ?? null), 'claimant' => trim((string) ($row['报销人'] ?? '')),
            'summary' => trim((string) ($row['摘要'] ?? '')), 'fund_account_code' => trim((string) ($row['Fund Account Code'] ?? '')),
            'fund_account_type' => trim((string) ($row['Fund Account Type'] ?? '')), 'account_owner' => trim((string) ($row['Account Owner'] ?? '')),
            'category_code' => trim((string) ($row['Category Code'] ?? '')), 'payment_method' => trim((string) ($row['付款方式'] ?? '')),
            'income' => $income, 'expense' => $expense, 'expected_balance' => $this->decimal($row['余款'] ?? null),
            'reference_no' => trim((string) ($row['Reference / 单据号'] ?? '')), 'currency' => trim((string) ($row['Currency'] ?? '')),
            'remarks' => trim((string) ($row['备注'] ?? '')), 'document_type' => $type,
        ];
    }

    /** @param array<string,mixed> $data @param array<string,float> $projected @return array{result_status:string,error_code:?string,error_message:?string,idempotency_key:?string} */
    private function validate(CentralFinanceUser $actor, FinanceGroupUser $groupUser, array &$data, array &$projected): array
    {
        $error = fn (string $code, string $message): array => ['result_status' => 'Error', 'error_code' => $code, 'error_message' => $message, 'idempotency_key' => null];
        $schools = School::on('mysql')->where('code', $data['school_code'])->get();
        if ($data['school_code'] === '' || $schools->count() !== 1) return $error('UNKNOWN_SCHOOL_CODE', 'School Code must exactly identify one registered School.');
        $school = $schools->sole(); $data['school_id'] = (int) $school->id;
        if (!$school->installed || !in_array((string) $school->status, ['1', 'active'], true)) return $error('SCHOOL_INACTIVE', 'The routed School is inactive or not installed.');
        if ($data['school_label'] === '' || !in_array(mb_strtolower($data['school_label']), [mb_strtolower($school->name), mb_strtolower($school->code)], true)) return $error('SCHOOL_LABEL_MISMATCH', '校区 must match the routed School display name or code.');
        try { $this->workspace->assertCanOperateSchool($actor, (int) $school->id); } catch (AuthorizationException) { return $error('SCHOOL_SCOPE_DENIED', 'The actor has no Central Finance operating scope for this School.'); }
        if (!$this->groups->canAccessSchool($groupUser, (int) $school->id, 'operate_finance')) return $error('GROUP_SCOPE_DENIED', 'The active Finance Group does not grant operate_finance for this School.');
        if (!$this->cutovers->allowsCentralWrites((int) $school->id)) return $error('CUTOVER_NOT_READY', 'Central Finance is not enabled for this School.');
        if ($data['summary'] === '') return $error('SUMMARY_REQUIRED', '摘要 is required.');
        if (!$data['document_type']) return $error('AMOUNT_ROUTING_INVALID', 'Exactly one of 收入 or 支出 must be greater than zero.');
        if (($data['income'] ?? 0) < 0 || ($data['expense'] ?? 0) < 0) return $error('AMOUNT_INVALID', '收入 and 支出 cannot be negative.');
        if (!preg_match('/^[A-Za-z0-9 _.-]{2,40}$/', $data['payment_method'])) return $error('PAYMENT_METHOD_INVALID', '付款方式 is invalid under the current Central Finance contract.');
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $data['reference_no'])) return $error('REFERENCE_INVALID', 'Reference / 单据号 is required and invalid.');
        try { CarbonImmutable::parse((string) $data['transaction_date'], 'Asia/Yangon'); CentralFinanceCurrency::assertCanonical($data['currency']); } catch (\Throwable) { return $error('DATE_OR_CURRENCY_INVALID', '日期 or Currency is invalid.'); }
        $account = CentralFinanceFundAccount::on('mysql')->active()->where('account_code', $data['fund_account_code'])->first();
        if (!$account || $data['fund_account_code'] !== $account->account_code) return $error('FUND_ACCOUNT_UNKNOWN', 'Fund Account Code must be an exact active canonical account code.');
        if ($data['fund_account_type'] !== $account->account_type || $data['account_owner'] !== $account->owner_type) return $error('FUND_ACCOUNT_IDENTITY_MISMATCH', 'Fund Account Type or Account Owner does not match the canonical Fund Account.');
        if (($account->owner_type === CentralFinanceFundAccount::OWNER_SCHOOL && (int) $account->school_id !== (int) $school->id) || ($account->owner_type === CentralFinanceFundAccount::OWNER_HQ && (int) $account->group_id !== (int) $groupUser->group_id)) return $error('FUND_ACCOUNT_SCOPE_MISMATCH', 'Fund Account does not belong to the routed School or active Finance Group HQ.');
        try { $this->accountScopes->assertCanOperate($actor, $account); } catch (AuthorizationException) { return $error('FUND_ACCOUNT_SCOPE_DENIED', 'Fund Account is not authorized for operation by this actor.'); }
        if (!CentralFinanceCurrency::same($data['currency'], $account->currency)) return $error('CURRENCY_MISMATCH', 'Currency must match the canonical Fund Account currency.');
        $category = CentralFinanceCategory::on('mysql')->where(['school_id' => $school->id, 'type' => $data['document_type'] === 'expense' ? CentralFinanceCategory::EXPENSE : CentralFinanceCategory::INCOME, 'category_code' => $data['category_code'], 'is_active' => true])->first();
        if (!$category) return $error('CATEGORY_UNKNOWN', 'Category Code must exactly identify an active Category for the routed School and document type.');
        $data['fund_account_id'] = (int) $account->id; $data['category_id'] = (int) $category->id; $data['amount'] = (float) ($data['document_type'] === 'expense' ? $data['expense'] : $data['income']);
        $key = 'group-import-v2:'.$school->code.':'.$data['document_type'].':'.$data['reference_no']; $data['idempotency_key'] = $key;
        $existing = $data['document_type'] === 'expense'
            ? CentralFinanceExpense::on('mysql')->withTrashed()->where(['school_id' => $school->id, 'reference_no' => $data['reference_no']])->first()
            : CentralFinanceOtherIncome::on('mysql')->withTrashed()->where(['school_id' => $school->id, 'reference_no' => $data['reference_no']])->first();
        if ($existing) {
            $same = (int) $existing->fund_account_id === $data['fund_account_id'] && (int) $existing->category_id === $data['category_id'] && CentralFinanceCurrency::same((string) $existing->currency, $data['currency']) && abs((float) $existing->amount - $data['amount']) < 0.0001;
            return ['result_status' => $same ? 'Duplicate' : 'Conflict', 'error_code' => $same ? null : 'REFERENCE_CONFLICT', 'error_message' => $same ? null : 'Reference exists with different immutable financial identity.', 'idempotency_key' => $key];
        }
        $projectionKey = $account->id.'|'.$account->currency; $projected[$projectionKey] ??= $this->balances->currentBalance($account);
        $projected[$projectionKey] += $data['document_type'] === 'other_income' ? $data['amount'] : -$data['amount'];
        if ($data['expected_balance'] !== null && abs($projected[$projectionKey] - $data['expected_balance']) > 0.0001) return $error('BALANCE_ASSERTION_MISMATCH', '余款 does not match the canonical projected account balance and cannot overwrite it.');
        return ['result_status' => 'New', 'error_code' => null, 'error_message' => null, 'idempotency_key' => $key];
    }


    /** @param list<array<string,mixed>> $rows @return array{new:int,duplicate:int,conflict:int,error:int,schools:array<int,array<string,mixed>>,currency_totals:array<string,array<string,array{count:int,amount:float}>>} */
    private function summary(array $rows): array
    {
        $out = ['new' => 0, 'duplicate' => 0, 'conflict' => 0, 'error' => 0, 'schools' => [], 'currency_totals' => []];
        foreach ($rows as $row) {
            $status = strtolower($row['result_status']);
            $out[$status]++;
            $data = (array) $row['data'];
            $schoolId = (int) ($data['school_id'] ?? 0);
            if (!$schoolId) continue;
            $out['schools'][$schoolId] ??= ['code' => (string) ($data['school_code'] ?? ''), 'label' => (string) ($data['school_label'] ?? ''), 'new'=>0, 'duplicate'=>0, 'conflict'=>0, 'error'=>0, 'currency_totals'=>[]];
            $out['schools'][$schoolId][$status]++;
            if (!in_array($status, ['new', 'duplicate'], true) || empty($data['document_type']) || empty($data['currency'])) continue;
            $currency = (string) $data['currency']; $type = (string) $data['document_type']; $amount = (float) ($data['amount'] ?? 0);
            foreach ([&$out['currency_totals'], &$out['schools'][$schoolId]['currency_totals']] as &$totals) {
                $totals[$currency] ??= []; $totals[$currency][$type] ??= ['count' => 0, 'amount' => 0.0];
                $totals[$currency][$type]['count']++; $totals[$currency][$type]['amount'] += $amount;
            }
            unset($totals);
        }
        return $out;
    }
    private function decimal(mixed $value): ?float { if ($value === null || trim((string) $value) === '') return null; return is_numeric($value) && is_finite((float) $value) ? (float) $value : -INF; }
    private function date(mixed $value): string { return is_numeric($value) && (float) $value > 1000 ? ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d') : trim((string) $value); }
    /** @param array<string,mixed> $data */
    private function sourceFor(array $data): CentralFinanceExpense|CentralFinanceOtherIncome
    {
        $source = $data['document_type'] === 'expense'
            ? CentralFinanceExpense::on('mysql')->withTrashed()->where(['school_id' => $data['school_id'], 'reference_no' => $data['reference_no']])->first()
            : CentralFinanceOtherIncome::on('mysql')->withTrashed()->where(['school_id' => $data['school_id'], 'reference_no' => $data['reference_no']])->first();
        if (!$source) throw new InvalidArgumentException('A duplicate Group Import source no longer exists.');
        return $source;
    }
    /** @param CentralFinanceExpense|CentralFinanceOtherIncome $source */
    private function linkRow(CentralFinanceGroupImportPreviewRow $row, CentralFinanceExpense|CentralFinanceOtherIncome $source, string $status): void
    {
        $row->update(['result_status' => $status === 'New' ? 'Created' : 'Duplicate', 'error_code' => null, 'error_message' => null, 'canonical_source_type' => $source instanceof CentralFinanceExpense ? 'expense' : 'other_income', 'canonical_source_id' => $source->id, 'canonical_source_uuid' => $source instanceof CentralFinanceExpense ? $source->expense_uuid : $source->income_uuid, 'confirmed_at' => now()]);
    }
    /** @param \Illuminate\Support\Collection<int,CentralFinanceGroupImportPreviewRow> $rows */
    private function audit(CentralFinanceUser $actor, CentralFinanceGroupImportBatch $batch, Collection $rows, string $action): void
    {
        foreach ($rows->pluck('school_id')->filter()->unique() as $schoolId) {
            CentralFinanceDocumentAudit::on('mysql')->create(['school_id' => $schoolId, 'document_type' => 'group_import_batch', 'document_id' => $batch->id, 'action' => $action, 'actor_id' => $actor->id, 'after_values' => ['batch_uuid' => $batch->batch_uuid, 'new' => $batch->new_rows, 'duplicate' => $batch->duplicate_rows, 'schools' => $rows->pluck('school_id')->filter()->unique()->values()->all()]]);
        }
    }
    private function markFailed(CentralFinanceUser $actor, string $token, ?CentralFinanceGroupImportPreviewRow $row, string $code, string $message): void
    {
        DB::connection('mysql')->transaction(function () use ($actor, $token, $row, $code, $message): void {
            $batch = CentralFinanceGroupImportBatch::on('mysql')->where('token', $token)->lockForUpdate()->first();
            if (!$batch || $batch->status === 'completed') return;
            if ($row) CentralFinanceGroupImportPreviewRow::on('mysql')->whereKey($row->id)->update(['result_status' => 'Error', 'error_code' => $code, 'error_message' => $message]);
            $batch->update(['status' => 'failed', 'failure_reason' => $message]);
            $this->audit($actor, $batch, CentralFinanceGroupImportPreviewRow::on('mysql')->where('group_batch_id', $batch->id)->get(), 'group_import_confirm_failed');
        });
    }
    /** @param array<string,mixed> $data */
    private function description(array $data): string { return trim((string) $data['summary'].($data['remarks'] === '' ? '' : "\n".$data['remarks'])); }
}

final class GroupImportConfirmException extends InvalidArgumentException
{
    public function __construct(public readonly CentralFinanceGroupImportPreviewRow $row, public readonly string $errorCode, string $message) { parent::__construct($message); }
}
