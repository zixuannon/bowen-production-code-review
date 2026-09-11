<?php

namespace App\Console\Commands;

use App\Models\FinanceCategory;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class EnsureFinanceCategories extends Command
{
    protected $signature = 'finance:ensure-categories
                            {--school_code= : Only process a specific school code}
                            {--all : Process all schools}';

    protected $description = 'Ensure finance_categories table, FK columns, and default categories exist in each school database';

    private const DEFAULT_CATEGORIES = [
        'income' => [
            ['code' => 'TUITION_FEE',        'name' => 'Tuition Fee',        'local_name' => '学费'],
            ['code' => 'REGISTRATION_FEE',   'name' => 'Registration Fee',   'local_name' => '注册费'],
            ['code' => 'MATERIAL_FEE',        'name' => 'Material Fee',        'local_name' => '教材费'],
            ['code' => 'UNIFORM_FEE',         'name' => 'Uniform Fee',         'local_name' => '校服费'],
            ['code' => 'ACTIVITY_FEE',        'name' => 'Activity Fee',        'local_name' => '活动费'],
            ['code' => 'EXAM_FEE',            'name' => 'Exam Fee',            'local_name' => '考试费'],
            ['code' => 'TRANSPORTATION_FEE',  'name' => 'Transportation Fee',  'local_name' => '校车费'],
            ['code' => 'OTHER_INCOME',        'name' => 'Other Income',        'local_name' => '其他收入'],
        ],
        'expense' => [
            ['code' => 'SALARY',              'name' => 'Salary',              'local_name' => '工资'],
            ['code' => 'RENT',                'name' => 'Rent',                'local_name' => '房租'],
            ['code' => 'UTILITIES',            'name' => 'Utilities',            'local_name' => '水电网'],
            ['code' => 'TEACHING_MATERIALS',   'name' => 'Teaching Materials',   'local_name' => '教材教具'],
            ['code' => 'MARKETING',            'name' => 'Marketing',            'local_name' => '宣传'],
            ['code' => 'MAINTENANCE',          'name' => 'Maintenance',          'local_name' => '维修'],
            ['code' => 'TRANSPORTATION',       'name' => 'Transportation',       'local_name' => '交通'],
            ['code' => 'OFFICE_SUPPLIES',      'name' => 'Office Supplies',      'local_name' => '办公用品'],
            ['code' => 'OTHER_EXPENSES',       'name' => 'Other Expenses',       'local_name' => '其他支出'],
        ],
    ];

    public function handle(): int
    {
        $schoolCode = $this->option('school_code');
        $all = $this->option('all');

        if (!$schoolCode && !$all) {
            $this->error('Please use --school_code=SCHxxxx or --all');
            return 1;
        }

        if ($schoolCode && $all) {
            $this->error('Cannot use both --school_code and --all');
            return 1;
        }

        $schools = $this->resolveSchoolList($schoolCode);

        if (empty($schools)) {
            $this->warn('No schools found.');
            return 0;
        }

        $this->info(sprintf("Processing %d school(s)...\n", count($schools)));

        $headers = ['School Code', 'Database', 'Table', 'Columns', 'Categories'];
        $rows = [];

        foreach ($schools as $school) {
            $result = $this->processSchool($school);
            $rows[] = [
                $result['school_code'],
                $result['database'],
                $result['table_status'],
                $result['column_status'],
                $result['category_status'],
            ];
        }

        $this->newLine();
        $this->table($headers, $rows);

        $this->info('Done.');
        return 0;
    }

    private function resolveSchoolList(?string $schoolCode): array
    {
        try {
            $query = \App\Models\School::on('mysql')->select(['id', 'name', 'code', 'database_name']);

            if ($schoolCode) {
                $query->whereCanonicalCode($schoolCode);
            }

            $schools = $query->get()->toArray();

            if ($schoolCode && empty($schools)) {
                $this->warn("School code {$schoolCode} does not exist.");
                return [];
            }

            return $schools;
        } catch (Throwable $e) {
            $this->error('Cannot connect to schools table (mysql): ' . $this->firstLine($e->getMessage()));
            return [];
        }
    }

    private function processSchool(object $school): array
    {
        $schoolCode = $school->code;
        $database   = $school->database_name ?? '';

        if (empty($database)) {
            $this->warn("[{$schoolCode}] database_name is empty, skipping.");
            return [
                'school_code'     => $schoolCode,
                'database'        => '(empty)',
                'table_status'    => 'SKIPPED',
                'column_status'   => 'SKIPPED',
                'category_status' => 'SKIPPED',
            ];
        }

        // Test database connection
        try {
            Config::set('database.connections.school.database', $database);
            DB::purge('school');
            DB::connection('school')->getPdo();
        } catch (Throwable $e) {
            $this->warn("[{$schoolCode}] Connection failed, skipping. (" . $this->firstLine($e->getMessage()) . ")");
            return [
                'school_code'     => $schoolCode,
                'database'        => $database,
                'table_status'    => 'SKIPPED',
                'column_status'   => 'SKIPPED',
                'category_status' => 'SKIPPED',
            ];
        }

        $tableStatus  = $this->ensureFinanceCategoriesTable($schoolCode);
        $columnStatus = $this->ensureForeignKeyColumns($schoolCode);
        $categoryStatus = $this->seedDefaultCategories($schoolCode);

        return [
            'school_code'     => $schoolCode,
            'database'        => $database,
            'table_status'    => $tableStatus,
            'column_status'   => $columnStatus,
            'category_status' => $categoryStatus,
        ];
    }

    private function ensureFinanceCategoriesTable(string $schoolCode): string
    {
        if (Schema::connection('school')->hasTable('finance_categories')) {
            return 'OK';
        }

        try {
            // Run the migration's up logic inline
            Schema::connection('school')->create('finance_categories', function ($table) {
                $table->id();
                $table->string('type', 20);
                $table->string('category_code', 100);
                $table->string('name', 255);
                $table->string('local_name', 255)->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['category_code'], 'finance_categories_code_unique');
                $table->index(['type', 'is_active']);
            });

            $this->line("[{$schoolCode}] Created finance_categories table ✓");
            return 'CREATED';
        } catch (Throwable $e) {
            $this->error("[{$schoolCode}] Failed to create finance_categories: " . $this->firstLine($e->getMessage()));
            return 'FAILED';
        }
    }

    private function ensureForeignKeyColumns(string $schoolCode): string
    {
        $added = [];

        // expenses.finance_category_id
        if (Schema::connection('school')->hasTable('expenses')) {
            if (!Schema::connection('school')->hasColumn('expenses', 'finance_category_id')) {
                try {
                    Schema::connection('school')->table('expenses', function ($table) {
                        $table->unsignedBigInteger('finance_category_id')->nullable()->after('category_id');
                    });
                    $added[] = 'expenses';
                    $this->line("[{$schoolCode}] Added expenses.finance_category_id ✓");
                } catch (Throwable $e) {
                    $this->error("[{$schoolCode}] Failed expenses.finance_category_id: " . $this->firstLine($e->getMessage()));
                }
            }

            // Ensure index on finance_category_id (idempotent)
            try {
                $this->ensureIndex('expenses', 'finance_category_id', $schoolCode);
            } catch (Throwable $e) {
                $this->warn("[{$schoolCode}] Index check for expenses.finance_category_id: " . $this->firstLine($e->getMessage()));
            }
        }

        // fees_class_types.finance_category_id
        if (Schema::connection('school')->hasTable('fees_class_types')) {
            if (!Schema::connection('school')->hasColumn('fees_class_types', 'finance_category_id')) {
                try {
                    Schema::connection('school')->table('fees_class_types', function ($table) {
                        $table->unsignedBigInteger('finance_category_id')->nullable()->after('fees_type_id');
                    });
                    $added[] = 'fees_class_types';
                    $this->line("[{$schoolCode}] Added fees_class_types.finance_category_id ✓");
                } catch (Throwable $e) {
                    $this->error("[{$schoolCode}] Failed fees_class_types.finance_category_id: " . $this->firstLine($e->getMessage()));
                }
            }

            // Ensure index on finance_category_id (idempotent)
            try {
                $this->ensureIndex('fees_class_types', 'finance_category_id', $schoolCode);
            } catch (Throwable $e) {
                $this->warn("[{$schoolCode}] Index check for fees_class_types.finance_category_id: " . $this->firstLine($e->getMessage()));
            }
        }

        return !empty($added) ? 'ADDED: ' . implode(', ', $added) : 'OK';
    }

    /**
     * Ensure an index exists on a column (idempotent).
     */
    private function ensureIndex(string $table, string $column, string $schoolCode): void
    {
        $indexName = "{$table}_{$column}_index";

        $rows = DB::connection('school')->select(
            "SHOW INDEX FROM `{$table}` WHERE Column_name = ? AND Key_name = ?",
            [$column, $indexName]
        );

        if (empty($rows)) {
            Schema::connection('school')->table($table, function (Blueprint $table) use ($column, $indexName) {
                $table->index($column, $indexName);
            });
            $this->line("[{$schoolCode}] Added index {$indexName} on {$table}.{$column} ✓");
        }
    }

    private function seedDefaultCategories(string $schoolCode): string
    {
        if (!Schema::connection('school')->hasTable('finance_categories')) {
            return 'SKIPPED (no table)';
        }

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $sortOrder = 0;

        foreach (self::DEFAULT_CATEGORIES as $type => $categories) {
            foreach ($categories as $cat) {
                $sortOrder++;

                $existing = DB::connection('school')->table('finance_categories')
                    ->where('category_code', $cat['code'])
                    ->first();

                if ($existing) {
                    // If updated_by is null, the record has never been manually edited,
                    // so it is safe to refresh name/local_name/sort_order from defaults.
                    // If updated_by is not null, the school admin has customized it,
                    // so preserve their name/local_name/description.
                    if (empty($existing->updated_by)) {
                        DB::connection('school')->table('finance_categories')
                            ->where('category_code', $cat['code'])
                            ->update([
                                'type'       => $type,
                                'name'       => $cat['name'],
                                'local_name' => $cat['local_name'],
                                'sort_order' => $sortOrder,
                                'updated_at' => now(),
                            ]);
                        $updated++;
                    } else {
                        // Only update structural fields, preserve user-edited text fields
                        DB::connection('school')->table('finance_categories')
                            ->where('category_code', $cat['code'])
                            ->update([
                                'type'       => $type,
                                'sort_order' => $sortOrder,
                                'updated_at' => now(),
                            ]);
                        $skipped++;
                    }
                } else {
                    DB::connection('school')->table('finance_categories')->insert([
                        'type'          => $type,
                        'category_code' => $cat['code'],
                        'name'          => $cat['name'],
                        'local_name'    => $cat['local_name'],
                        'is_default'    => true,
                        'is_active'     => true,
                        'sort_order'    => $sortOrder,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                    $inserted++;
                }
            }
        }

        $parts = [];
        if ($inserted > 0) $parts[] = "{$inserted} new";
        if ($updated > 0) $parts[] = "{$updated} updated";
        if ($skipped > 0) $parts[] = "{$skipped} preserved (customized)";
        $status = !empty($parts) ? implode(', ', $parts) : 'OK';

        $this->line("[{$schoolCode}] Categories: {$status}");
        return $status;
    }

    private function firstLine(string $msg): string
    {
        return explode("\n", trim($msg))[0] ?? $msg;
    }
}
