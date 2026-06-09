<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class EnsureFinanceMulticurrencyColumns extends Command
{
    protected $signature = 'finance:ensure-multicurrency-columns
                            {--school_code= : 只处理指定学校代码}
                            {--all : 处理所有学校}';

    protected $description = '为每个学校数据库的 fees_paids 和 expenses 表补齐多币种字段';

    private const COLUMNS = [
        'transaction_currency'   => ['type' => 'string', 'length' => 3, 'default' => 'MMK'],
        'original_amount'         => ['type' => 'decimal', 'precision' => 12, 'scale' => 2, 'default' => 0],
        'exchange_rate_snapshot'  => ['type' => 'decimal', 'precision' => 12, 'scale' => 4, 'default' => 1],
        'amount_mmk'              => ['type' => 'decimal', 'precision' => 12, 'scale' => 2, 'default' => 0],
    ];

    public function handle(): int
    {
        $schoolCode = $this->option('school_code');
        $all = $this->option('all');

        if (!$schoolCode && !$all) {
            $this->error('请使用 --school_code=SCH202619 或 --all');
            return 1;
        }

        if ($schoolCode && $all) {
            $this->error('不能同时使用 --school_code 和 --all');
            return 1;
        }

        $schools = $this->resolveSchoolList($schoolCode);

        if (empty($schools)) {
            $this->warn('没有找到学校数据。');
            return 0;
        }

        $this->info(sprintf("共 %d 个学校待处理\n", count($schools)));

        $headers = ['School Code', 'Database', 'FeesPaid Status', 'Expense Status', 'Warnings'];
        $rows = [];

        foreach ($schools as $school) {
            $result = $this->processSchool($school);
            $rows[] = [
                $result['school_code'],
                $result['database'],
                $result['fees_paid_status'],
                $result['expense_status'],
                $result['warnings'] ?: '-',
            ];
        }

        $this->newLine();
        $this->table($headers, $rows);

        $totalFixed = count(array_filter($rows, fn($r) => str_contains($r[2], 'FIXED') || str_contains($r[3], 'FIXED')));

        $this->info("完成。{$totalFixed} 个学校有字段变更。");
        return 0;
    }

    private function resolveSchoolList(?string $schoolCode): array
    {
        try {
            $query = DB::connection('mysql')->table('schools')->select(['id', 'name', 'code', 'database_name']);

            if ($schoolCode) {
                $query->where('code', $schoolCode);
            }

            $schools = $query->get()->toArray();

            if ($schoolCode && empty($schools)) {
                $this->warn("学校代码 {$schoolCode} 不存在。");
                return [];
            }

            return $schools;
        } catch (Throwable $e) {
            $this->error('无法连接 schools 表（mysql 主库）: ' . $this->maskPassword($e->getMessage()));
            return [];
        }
    }

    private function processSchool(object $school): array
    {
        $schoolCode = $school->code;
        $database   = $school->database_name ?? '';
        $warnings   = [];

        if (empty($database)) {
            $msg = "database_name 为空";
            $this->warn("[{$schoolCode}] database_name 为空，跳过。");
            return [
                'school_code'      => $schoolCode,
                'database'         => '(空)',
                'fees_paid_status' => 'SKIPPED',
                'expense_status'   => 'SKIPPED',
                'warnings'         => $msg,
            ];
        }

        // 测试数据库连接
        try {
            Config::set('database.connections.school.database', $database);
            DB::purge('school');
            DB::connection('school')->getPdo();
        } catch (Throwable $e) {
            $msg = "连接失败";
            $this->warn("[{$schoolCode}] {$msg}，跳过。(" . $this->maskPassword($e->getMessage()) . ")");
            return [
                'school_code'      => $schoolCode,
                'database'         => $database,
                'fees_paid_status' => 'SKIPPED',
                'expense_status'   => 'SKIPPED',
                'warnings'         => $msg,
            ];
        }

        $feesPaidStatus = $this->ensureTableColumns($database, 'fees_paids', $schoolCode, $warnings);
        $expenseStatus  = $this->ensureTableColumns($database, 'expenses',   $schoolCode, $warnings);

        return [
            'school_code'      => $schoolCode,
            'database'         => $database,
            'fees_paid_status' => $feesPaidStatus,
            'expense_status'   => $expenseStatus,
            'warnings'         => implode('; ', $warnings),
        ];
    }

    private function ensureTableColumns(string $database, string $table, string $schoolCode, array &$warnings): string
    {
        // 表是否存在
        if (!Schema::connection('school')->hasTable($table)) {
            $warnings[] = "表 {$table} 不存在";
            $this->warn("[{$schoolCode}] 表 {$table} 不存在，跳过。");
            return 'SKIPPED';
        }

        $addedColumns = [];

        foreach (self::COLUMNS as $column => $def) {
            // 字段已存在，跳过
            if (Schema::connection('school')->hasColumn($table, $column)) {
                continue;
            }

            // 添加缺失字段
            try {
                Schema::connection('school')->table($table, function ($t) use ($column, $def, $table) {
                    $col = $t->{$def['type']}($column, $def['precision'] ?? null, $def['scale'] ?? null);

                    if ($column === 'transaction_currency') {
                        $col->default($def['default'])->after('amount');
                    } elseif ($column === 'original_amount') {
                        $col->default($def['default'])->nullable()->after('transaction_currency');
                    } elseif ($column === 'exchange_rate_snapshot') {
                        $col->default($def['default'])->nullable()->after('original_amount');
                    } elseif ($column === 'amount_mmk') {
                        $col->default($def['default'])->nullable()->after('exchange_rate_snapshot');
                    }
                });

                $addedColumns[] = $column;
                $this->line("[{$schoolCode}] {$table}: 新增字段 {$column} ✓");
            } catch (Throwable $e) {
                $warnings[] = "{$table}.{$column} 添加失败: " . $this->firstLine($e->getMessage());
                $this->error("[{$schoolCode}] {$table}: 新增 {$column} 失败 - " . $this->firstLine($e->getMessage()));
            }
        }

        if (!empty($addedColumns)) {
            $this->backfillOldData($table, $schoolCode);
            return 'FIXED';
        }

        // 字段全在，检查是否需回填
        $this->backfillOldData($table, $schoolCode);
        return 'OK';
    }

    private function backfillOldData(string $table, string $schoolCode): void
    {
        try {
            $affected = DB::connection('school')
                ->table($table)
                ->where(function ($q) {
                    $q->whereNull('original_amount')->orWhere('original_amount', 0);
                })
                ->orWhere(function ($q) {
                    $q->whereNull('amount_mmk')->orWhere('amount_mmk', 0);
                })
                ->update([
                    'transaction_currency'   => 'MMK',
                    'original_amount'        => DB::raw('COALESCE(NULLIF(original_amount,0), amount)'),
                    'exchange_rate_snapshot' => DB::raw('COALESCE(NULLIF(exchange_rate_snapshot,0), 1)'),
                    'amount_mmk'             => DB::raw('COALESCE(NULLIF(amount_mmk,0), amount)'),
                ]);

            if ($affected > 0) {
                $this->line("[{$schoolCode}] {$table}: 回填 {$affected} 条 ✓");
            }
        } catch (Throwable $e) {
            $this->error("[{$schoolCode}] {$table}: 回填失败 - " . $this->firstLine($e->getMessage()));
        }
    }

    private function maskPassword(string $msg): string
    {
        return preg_replace('/(password|PASSWORD)[^;]*;/i', '****;', $msg) ?? $msg;
    }

    private function firstLine(string $msg): string
    {
        return explode("\n", trim($msg))[0] ?? $msg;
    }
}
