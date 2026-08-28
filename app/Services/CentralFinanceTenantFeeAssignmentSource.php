<?php
namespace App\Services;
use App\Models\CentralFinanceSchoolCutover;
use App\Models\CentralFinanceStudentProfile;
use App\Models\School;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Reads only compulsory tenant fee assignments through the trusted registry. */
final class CentralFinanceTenantFeeAssignmentSource {
    /** @return list<array{source_id:string,description:string,due_date:?string,currency:string,amount:float,created_at:CarbonImmutable,updated_at:CarbonImmutable}> */
    public function forProfile(CentralFinanceStudentProfile $profile): array {
        return array_values(array_filter(
            $this->allForProfile($profile),
            fn (array $row): bool => $this->isWithinFreshStartCutoff($profile, $row),
        ));
    }

    /**
     * Reconciliation needs the pre-cutoff rows too, so it can explain why
     * historic tenant assignments are intentionally absent from Central.
     *
     * @return list<array{source_id:string,description:string,due_date:?string,currency:string,amount:float,created_at:CarbonImmutable,updated_at:CarbonImmutable}>
     */
    public function allForProfile(CentralFinanceStudentProfile $profile): array {
        $fresh=CentralFinanceStudentProfile::on('mysql')->findOrFail($profile->id);
        $school=School::on('mysql')->findOrFail($fresh->school_id);
        $db=(string)$school->getRawOriginal('database_name');
        if (!$this->safe($db)) {
            throw new AuthorizationException('The School registry does not contain a valid tenant database.');
        }
        if (!$fresh->class_id) return [];
        return $this->onSchool($db,function() use($fresh): array {
            if (Schema::connection('school')->hasTable('student_fee_assignments')
                && Schema::connection('school')->hasTable('student_fee_assignment_items')) {
                $assigned = $this->confirmedStudentAssignmentRows($fresh);
                // Once a Student has a confirmed assignment, that immutable
                // item set replaces class-wide projection for this Student.
                if ($assigned['has_confirmed_assignment']) return $assigned['rows'];
            }
            $hasCurrency = Schema::connection('school')->hasColumn('fees_class_types', 'fee_currency');
            // The cutoff is based on source creation, never an incidental
            // later update. A tenant without this timestamp fails closed.
            if (!Schema::connection('school')->hasColumn('fees_class_types', 'created_at')) return [];
            $select = ['fees_class_types.id','fees_class_types.amount','fees_class_types.created_at as source_created_at','fees_class_types.updated_at','fees.name','fees.due_date'];
            if ($hasCurrency) $select[] = 'fees_class_types.fee_currency';
            $query = DB::connection('school')->table('fees_class_types')->leftJoin('fees','fees.id','=','fees_class_types.fees_id')
                ->where('fees_class_types.class_id',$fresh->class_id)->where('fees_class_types.optional',0);
            if (Schema::connection('school')->hasColumn('fees_class_types', 'deleted_at')) $query->whereNull('fees_class_types.deleted_at');
            if (Schema::connection('school')->hasColumn('fees', 'deleted_at')) $query->whereNull('fees.deleted_at');
            $rows=$query
                ->select($select)->orderBy('fees_class_types.id')->get();
            return $rows->map(fn(object $r): array => [
                'source_id'=>(string)$r->id,'description'=>(string)($r->name ?: 'Assigned fee'),
                'due_date'=>$r->due_date ? (string)$r->due_date : null,'currency'=>strtoupper((string)(($hasCurrency ? $r->fee_currency : null) ?: 'MMK')),
                'amount'=>(float)$r->amount,
                'created_at'=>CentralFinanceSchoolCutoverService::parseFreshStartBusinessTime((string) $r->source_created_at),
                'updated_at'=>CentralFinanceSchoolCutoverService::parseFreshStartBusinessTime((string) ($r->updated_at ?? $r->source_created_at)),
            ])->all();
        });
    }

    /** @return array{has_confirmed_assignment:bool,rows:list<array{source_id:string,description:string,due_date:?string,currency:string,amount:float,created_at:CarbonImmutable,updated_at:CarbonImmutable}>} */
    private function confirmedStudentAssignmentRows(CentralFinanceStudentProfile $profile): array
    {
        $assignments = DB::connection('school')->table('student_fee_assignments')
            ->where('student_id', $profile->tenant_student_id)
            ->where('class_id', $profile->class_id)
            ->where('status', 'confirmed')
            ->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'confirmed_at']);
        if ($assignments->isEmpty()) return ['has_confirmed_assignment' => false, 'rows' => []];
        $rows = DB::connection('school')->table('student_fee_assignment_items')
            ->whereIn('student_fee_assignment_id', $assignments->pluck('id'))
            ->where('status', 'active')
            ->where('source_type', 'fees_class_type')
            ->orderBy('id')->get();
        $confirmedAt = $assignments->keyBy('id');
        return ['has_confirmed_assignment' => true, 'rows' => $rows->map(function (object $row) use ($confirmedAt): array {
            $time = $confirmedAt->get($row->student_fee_assignment_id)->confirmed_at ?? $row->created_at;
            $at = CentralFinanceSchoolCutoverService::parseFreshStartBusinessTime((string) $time);
            return [
                // Intentionally use the legacy FeesClassType identity, never
                // the assignment-item UUID, so transition is no-op/safe.
                'source_id' => (string) $row->source_id,
                'description' => (string) $row->description_snapshot,
                'due_date' => $row->due_date_snapshot ? (string) $row->due_date_snapshot : null,
                'currency' => strtoupper((string) $row->currency_snapshot),
                'amount' => (float) $row->amount_snapshot,
                'created_at' => $at,
                'updated_at' => $at,
            ];
        })->all()];
    }

    /** @param array{created_at:CarbonImmutable} $row */
    public function isWithinFreshStartCutoff(CentralFinanceStudentProfile $profile, array $row): bool {
        $cutoff = $this->freshStartCutoff($profile->school_id);
        return $cutoff !== null && $row['created_at']->greaterThanOrEqualTo($cutoff);
    }

    public function freshStartCutoff(int $schoolId): ?CarbonImmutable {
        if ($schoolId < 1
            || !Schema::connection('mysql')->hasTable('central_finance_school_cutovers')
            || !Schema::connection('mysql')->hasColumn('central_finance_school_cutovers', 'receivable_sync_effective_at')) return null;
        $value = CentralFinanceSchoolCutover::on('mysql')->where('school_id', $schoolId)->value('receivable_sync_effective_at');
        return $value === null ? null : CentralFinanceSchoolCutoverService::parseFreshStartBusinessTime((string) $value);
    }
    private function safe(string $database): bool {
        if (preg_match('/^[A-Za-z0-9_.-]+$/',$database)) return true;
        return config('database.connections.school.driver')==='sqlite' && realpath(dirname($database))===realpath(sys_get_temp_dir()) && is_file($database);
    }
    private function onSchool(string $database, callable $callback): mixed {
        $original=config('database.connections.school.database'); $default=DB::getDefaultConnection();
        try { Config::set('database.connections.school.database',$database); DB::purge('school'); return $callback(); }
        finally { DB::purge('school'); Config::set('database.connections.school.database',$original); DB::setDefaultConnection($default); }
    }
}
