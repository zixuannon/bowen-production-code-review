<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only legacy Finance cutover inventory for Gate A. Historical import is
 * deliberately absent: this service can inspect and reconcile source records,
 * but cannot create a manifest, Central document, balance, or Ledger entry.
 */
final class CentralFinanceLegacyCutoverService
{
    /** @var array<string,string> */
    private const SOURCES = [
        'bank_account' => 'bank_accounts', 'fee_payment' => 'fees_paids',
        'expense' => 'expenses', 'other_income' => 'other_incomes',
        'bank_transfer' => 'bank_transfers', 'fund_handover' => 'fund_handovers',
    ];

    /** @return array{school:array<string,mixed>,sources:array<string,array<string,mixed>>,hash:string} */
    public function dryRun(School $requestedSchool): array
    {
        $school = School::on('mysql')->findOrFail($requestedSchool->id);
        $database = (string) $school->getRawOriginal('database_name');
        if (!$this->safeDatabase($database)) {
            throw new AuthorizationException('Legacy cutover can use only the trusted School registry database.');
        }

        $sources = $this->withSchool($database, function () use ($school): array {
            $result = [];
            foreach (self::SOURCES as $type => $table) {
                if (!Schema::connection('school')->hasTable($table)) {
                    $result[$type] = ['table' => $table, 'state' => 'missing_table', 'rows' => []];
                    continue;
                }
                $columns = Schema::connection('school')->getColumnListing($table);
                $rows = DB::connection('school')->table($table)->orderBy('id')->get()->map(function (object $row) use ($school, $type, $columns): array {
                    $id = (string) $row->id;
                    $deleted = in_array('deleted_at', $columns, true) && $row->deleted_at !== null;
                    $status = $deleted ? 'soft_deleted' : $this->status($type, $row, $columns);
                    return [
                        'id' => $id, 'status' => $status,
                        'migration_key' => $this->key((string) $school->code, $type, $id),
                        'source_hash' => hash('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR)),
                    ];
                })->all();
                $result[$type] = ['table' => $table, 'state' => 'available', 'rows' => $rows];
            }
            return $result;
        });

        return ['school' => ['id' => $school->id, 'code' => $school->code], 'sources' => $sources,
            'hash' => hash('sha256', json_encode($sources, JSON_THROW_ON_ERROR))];
    }

    /** @return array{expected:int,matched:int,missing:list<string>,stale:list<string>,hash:string} */
    public function reconcile(School $school): array
    {
        $plan = $this->dryRun($school); $expected = []; $hashes = [];
        foreach ($plan['sources'] as $source) foreach ($source['rows'] as $row) { $expected[] = $row['migration_key']; $hashes[$row['migration_key']] = $row['source_hash']; }
        $records = DB::connection('mysql')->table('central_finance_legacy_migration_records')->where('school_id', $plan['school']['id'])->get()->keyBy('migration_key');
        $missing = array_values(array_filter($expected, fn (string $key): bool => !$records->has($key)));
        $stale = array_values(array_filter($expected, fn (string $key): bool => $records->has($key) && $records[$key]->source_hash !== $hashes[$key]));
        return ['expected' => count($expected), 'matched' => count($expected) - count($missing) - count($stale), 'missing' => $missing, 'stale' => $stale, 'hash' => $plan['hash']];
    }

    private function key(string $schoolCode, string $type, string $id): string { return hash('sha256', 'central-finance-v1|'.$schoolCode.'|'.$type.'|'.$id); }
    private function status(string $type, object $row, array $columns): string
    {
        if (in_array('status', $columns, true) && in_array((string) $row->status, ['cancelled', 'rejected', 'pending'], true)) return (string) $row->status;
        return $type === 'fund_handover' && !in_array('bank_transfer_id', $columns, true) ? 'unresolved' : 'eligible';
    }
    private function safeDatabase(string $database): bool
    {
        // A local/test rehearsal must never connect to a production-shaped
        // database. Production dry-run remains registry-only, never CLI input.
        if (app()->environment(['local', 'testing']) && preg_match('/^eschool_saas_/i', $database)) return false;
        return preg_match('/^[A-Za-z0-9_.-]+$/', $database) === 1 || (config('database.connections.school.driver') === 'sqlite' && is_file($database));
    }
    private function withSchool(string $database, callable $callback): mixed
    {
        $original = config('database.connections.school.database');
        try { Config::set('database.connections.school.database', $database); DB::purge('school'); return $callback(); }
        finally { DB::purge('school'); Config::set('database.connections.school.database', $original); }
    }
}
