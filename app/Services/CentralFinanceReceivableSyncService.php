<?php
namespace App\Services;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceStudentProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Safely projects tenant compulsory fee assignments; it never alters tenant fees. */
final class CentralFinanceReceivableSyncService {
    public function __construct(private readonly CentralFinanceTenantFeeAssignmentSource $source) {}
    /** @return list<CentralFinanceReceivable> */
    public function syncProfile(CentralFinanceStudentProfile $profile): array {
        $profile=CentralFinanceStudentProfile::on('mysql')->findOrFail($profile->id);
        return array_map(fn(array $row): CentralFinanceReceivable => $this->syncOne($profile,$row),$this->source->forProfile($profile));
    }
    private function syncOne(CentralFinanceStudentProfile $profile,array $row): CentralFinanceReceivable {
        if ($row['amount'] < 0 || !preg_match('/^[A-Z]{3}$/',$row['currency'])) throw new RuntimeException('Tenant fee assignment is not valid for Central Finance.');
        return DB::connection('mysql')->transaction(function() use($profile,$row): CentralFinanceReceivable {
            $r=CentralFinanceReceivable::on('mysql')->where(['school_id'=>$profile->school_id,'student_profile_id'=>$profile->id,'source_type'=>'tenant_fee_assignment','source_id'=>$row['source_id']])->lockForUpdate()->first();
            if (!$r) return CentralFinanceReceivable::on('mysql')->create(['receivable_uuid'=>(string)Str::uuid(),'school_id'=>$profile->school_id,'student_profile_id'=>$profile->id,'source_type'=>'tenant_fee_assignment','source_id'=>$row['source_id'],'description'=>$row['description'],'due_date'=>$row['due_date'],'currency'=>$row['currency'],'amount_due'=>$row['amount'],'amount_paid'=>0,'status'=>CentralFinanceReceivable::OPEN,'source_updated_at'=>$row['updated_at'],'last_synced_at'=>now()]);
            if ((float)$r->amount_paid > (float)$row['amount']) throw new RuntimeException('Tenant fee assignment cannot reduce a Central receivable below paid amount.');
            $paid=(float)$r->amount_paid; $status=$paid==0?CentralFinanceReceivable::OPEN:($paid>=$row['amount']?CentralFinanceReceivable::PAID:CentralFinanceReceivable::PARTIAL);
            $r->update(['description'=>$row['description'],'due_date'=>$row['due_date'],'currency'=>$row['currency'],'amount_due'=>$row['amount'],'status'=>$status,'source_updated_at'=>$row['updated_at'],'last_synced_at'=>now()]); return $r;
        });
    }
}
