<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Canonical create-only Expense write path shared by tenant and Scheme B flows. */
class ExpenseCreationService
{
    public function __construct(private readonly CachingService $cache) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, array $data, ?callable $afterCreate = null): Expense
    {
        app(CentralFinanceSchoolCutoverService::class)->assertTenantFinanceWritesAllowed($actor);
        $account = app(FinanceAccountAccessService::class)->authorize($actor, (int) $data['bank_account_id']);
        $snapshot = app(FinancialCurrencyService::class)->expenseSnapshot($account, $data);

        return DB::connection('school')->transaction(function () use ($actor, $data, $snapshot, $afterCreate): Expense {
            $settings = $this->cache->getSchoolSettings('*', $actor->school_id);

            $date = (string) ($data['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = Carbon::createFromFormat((string) ($settings['date_format'] ?? 'Y-m-d'), $date)->format('Y-m-d');
            }

            $expense = Expense::query()->create([
                'school_id' => $actor->school_id, 'category_id' => $data['category_id'] ?? null,
                'finance_category_id' => ($data['finance_category_id'] ?? null) ?: null, 'title' => $data['title'] ?? null,
                'ref_no' => $data['ref_no'] ?? null, 'amount' => $snapshot['amount_mmk'], 'date' => $date,
                'description' => $data['description'] ?? null, 'session_year_id' => $data['session_year_id'] ?? null,
                'transaction_currency' => $snapshot['transaction_currency'], 'original_amount' => $snapshot['original_amount'],
                'exchange_rate_snapshot' => $snapshot['exchange_rate_snapshot'], 'amount_mmk' => $snapshot['amount_mmk'],
                'bank_account_id' => $data['bank_account_id'],
            ]);
            $sessionYear = $this->cache->getDefaultSessionYear($actor->school_id);
            if ($sessionYear) {
                SessionYearsTrackingsService::storeSessionYearsTracking(Expense::class, $expense->id, $actor->id, $sessionYear->id, $actor->school_id);
            }
            if ($afterCreate) { $afterCreate($expense); }
            return $expense;
        });
    }
}
