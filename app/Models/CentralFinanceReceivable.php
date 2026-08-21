<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CentralFinanceReceivable extends Model {
    public const OPEN='open'; public const PARTIAL='partial'; public const PAID='paid';
    protected $connection='mysql';
    protected $fillable=['receivable_uuid','school_id','student_profile_id','source_type','source_id','description','due_date','currency','amount_due','amount_paid','status','source_updated_at','last_synced_at'];
    protected $casts=['due_date'=>'date','source_updated_at'=>'datetime','last_synced_at'=>'datetime','amount_due'=>'decimal:4','amount_paid'=>'decimal:4'];
}
