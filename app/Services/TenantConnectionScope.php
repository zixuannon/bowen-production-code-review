<?php

namespace App\Services;

use App\Models\School;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Temporarily selects one trusted tenant connection and always restores the
 * process-local Laravel database state afterwards.  This is intentionally a
 * narrow infrastructure primitive: it does not authenticate, authorize, or
 * mutate the session.
 */
final class TenantConnectionScope
{
    /** @template T @param Closure():T $callback @return T */
    public function preserve(Closure $callback): mixed
    {
        $snapshot = $this->snapshot();

        try {
            return $callback();
        } finally {
            $this->restore($snapshot);
        }
    }

    /** @template T @param Closure():T $callback @return T */
    public function forSchool(School $school, Closure $callback): mixed
    {
        return $this->preserve(function () use ($school, $callback): mixed {
            $database = trim((string) $school->database_name);
            if ($database === '') {
                throw new \LogicException('The trusted School has no tenant database.');
            }

            Config::set('database.connections.school.database', $database);
            DB::purge('school');
            DB::connection('school')->reconnect();
            DB::setDefaultConnection('school');

            return $callback();
        });
    }

    /** @return array{default:string,school_database:mixed} */
    private function snapshot(): array
    {
        return [
            'default' => DB::getDefaultConnection(),
            'school_database' => config('database.connections.school.database'),
        ];
    }

    /** @param array{default:string,school_database:mixed} $snapshot */
    private function restore(array $snapshot): void
    {
        Config::set('database.connections.school.database', $snapshot['school_database']);
        DB::purge('school');
        DB::setDefaultConnection($snapshot['default']);

        if ($snapshot['default'] === 'school') {
            DB::connection('school')->reconnect();
        }
    }
}
