<?php
namespace App\Services;
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
    /** @return list<array{source_id:string,description:string,due_date:?string,currency:string,amount:float,updated_at:CarbonImmutable}> */
    public function forProfile(CentralFinanceStudentProfile $profile): array {
        $fresh=CentralFinanceStudentProfile::on('mysql')->findOrFail($profile->id);
        $school=School::on('mysql')->findOrFail($fresh->school_id);
        $db=(string)$school->getRawOriginal('database_name');
        if (!$this->safe($db) || !$fresh->class_id) return [];
        return $this->onSchool($db,function() use($fresh): array {
            $hasCurrency = Schema::connection('school')->hasColumn('fees_class_types', 'fee_currency');
            $select = ['fees_class_types.id','fees_class_types.amount','fees_class_types.updated_at','fees.name','fees.due_date'];
            if ($hasCurrency) $select[] = 'fees_class_types.fee_currency';
            $rows=DB::connection('school')->table('fees_class_types')->leftJoin('fees','fees.id','=','fees_class_types.fees_id')
                ->where('fees_class_types.class_id',$fresh->class_id)->where('fees_class_types.optional',0)
                ->select($select)->orderBy('fees_class_types.id')->get();
            return $rows->map(fn(object $r): array => [
                'source_id'=>(string)$r->id,'description'=>(string)($r->name ?: 'Assigned fee'),
                'due_date'=>$r->due_date ? (string)$r->due_date : null,'currency'=>strtoupper((string)(($hasCurrency ? $r->fee_currency : null) ?: 'MMK')),
                'amount'=>(float)$r->amount,'updated_at'=>CarbonImmutable::parse($r->updated_at ?? now()),
            ])->all();
        });
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
