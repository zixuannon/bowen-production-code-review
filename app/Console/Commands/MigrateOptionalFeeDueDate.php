<?php

namespace App\Console\Commands;

use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MigrateOptionalFeeDueDate extends Command
{
    private const MIGRATION = '2026_10_05_000001_make_fee_due_date_nullable';

    protected $signature = 'fees:due-date-schema
        {--tenant=* : Exact active School Code(s); defaults to every active School in the central registry}
        {--execute : Apply only the nullable Fee due-date tenant migration}';

    protected $description = 'Preflight or apply the exact optional Fee due-date migration to active registered tenants';

    public function handle(): int
    {
        $originalDatabase = config('database.connections.school.database');
        $originalDefaultConnection = DB::getDefaultConnection();

        try {
            $tenants = $this->discoverActiveTenants();
            if ($tenants === null) {
                return self::FAILURE;
            }

            $selected = $this->selectTenants($tenants);
            if ($selected === null) {
                return self::FAILURE;
            }

            $states = [];
            foreach ($selected as $code => $database) {
                if (!$this->connect($database)) {
                    return self::FAILURE;
                }

                $state = $this->state();
                $this->line("[{$code}] {$database}: {$state}");
                if ($state === 'partial' || $state === 'inconsistent') {
                    $this->reject("[{$code}] refuses partial or inconsistent due-date migration state.");
                    return self::FAILURE;
                }
                $states[$code] = $state;
            }

            if (!$this->option('execute')) {
                $this->info('Read-only preflight complete; no tenant schema changed.');
                return self::SUCCESS;
            }

            foreach ($selected as $code => $database) {
                if (($states[$code] ?? null) === 'complete') {
                    continue;
                }

                if (!$this->connect($database) || $this->state() !== 'eligible') {
                    $this->reject("[{$code}] changed after preflight; no migration was run for this tenant.");
                    return self::FAILURE;
                }

                $exit = Artisan::call('migrate', [
                    '--database' => 'school',
                    '--path' => database_path('migrations/schools/' . self::MIGRATION . '.php'),
                    '--realpath' => true,
                    '--force' => true,
                ]);
                $this->output->write(Artisan::output());
                if ($exit !== self::SUCCESS || $this->state() !== 'complete') {
                    $this->reject("[{$code}] exact migration or schema-history verification failed.");
                    return self::FAILURE;
                }
                $this->info("[{$code}] due_date is nullable; existing Fee rows were not rewritten.");
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->reject('Preflight stopped safely: ' . get_class($exception) . ': ' . $exception->getMessage());
            return self::FAILURE;
        } finally {
            Config::set('database.connections.school.database', $originalDatabase);
            DB::purge('school');
            DB::setDefaultConnection($originalDefaultConnection);
        }
    }

    /** @return array<string,string>|null */
    private function discoverActiveTenants(): ?array
    {
        try {
            $rows = School::on('mysql')->where('status', 1)->whereNull('deleted_at')
                ->whereNotNull('code')->whereNotNull('database_name')->orderBy('code')
                ->get(['id', 'code', 'database_name']);
        } catch (Throwable $exception) {
            $this->error('The central active School registry could not be read: ' . $exception->getMessage());
            return null;
        }

        if ($rows->isEmpty()) {
            $this->error('The central registry returned no active tenants.');
            return null;
        }

        $tenants = [];
        $databases = [];
        foreach ($rows as $school) {
            $code = strtoupper(trim((string) $school->code));
            $database = (string) $school->database_name;
            if ($code === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $database)) {
                $this->error('The active School registry contains an invalid code or database name.');
                return null;
            }
            if (isset($tenants[$code]) || isset($databases[$database])) {
                $this->error('The active School registry maps a School Code or tenant database more than once.');
                return null;
            }
            $tenants[$code] = $database;
            $databases[$database] = true;
        }

        return $tenants;
    }

    /** @param array<string,string> $tenants @return array<string,string>|null */
    private function selectTenants(array $tenants): ?array
    {
        $requested = array_values(array_unique(array_map(static fn ($code) => strtoupper(trim((string) $code)), (array) $this->option('tenant'))));
        if ($requested === []) {
            return $tenants;
        }

        $selected = [];
        foreach ($requested as $code) {
            if (!isset($tenants[$code])) {
                $this->error("[{$code}] is not an active School in the central registry.");
                return null;
            }
            $selected[$code] = $tenants[$code];
        }

        return $selected;
    }

    private function connect(string $database): bool
    {
        Config::set('database.connections.school.database', $database);
        DB::purge('school');
        DB::connection('school')->getPdo();
        DB::setDefaultConnection('school');

        if (!Schema::connection('school')->hasTable('migrations') || !Schema::connection('school')->hasTable('fees')) {
            return $this->reject("[{$database}] required tenant migration history or fees table is missing.");
        }

        return true;
    }

    private function state(): string
    {
        $recorded = DB::connection('school')->table('migrations')->where('migration', self::MIGRATION)->exists();
        $column = DB::connection('school')->selectOne(
            'SELECT IS_NULLABLE AS is_nullable, DATA_TYPE AS data_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['fees', 'due_date']
        );
        if (!$column || strtolower((string) $column->data_type) !== 'date') {
            return 'partial';
        }

        $nullable = strtoupper((string) $column->is_nullable) === 'YES';
        if ($recorded && $nullable) {
            return 'complete';
        }
        if (!$recorded && !$nullable) {
            return 'eligible';
        }

        return $recorded || $nullable ? 'inconsistent' : 'partial';
    }

    private function reject(string $message): bool
    {
        $this->error($message);
        return false;
    }
}
