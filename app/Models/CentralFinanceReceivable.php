<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class CentralFinanceReceivable extends Model {
    public const OPEN='open'; public const PARTIAL='partial'; public const PAID='paid'; public const WAIVED='waived'; public const CANCELLED='cancelled';
    protected $connection='mysql';
    protected $fillable=['receivable_uuid','school_id','student_profile_id','source_type','source_id','description','due_date','currency','amount_due','source_amount_due','finance_adjustment_amount','amount_paid','status','source_updated_at','last_synced_at'];
    protected $casts=['due_date'=>'date','source_updated_at'=>'datetime','last_synced_at'=>'datetime','amount_due'=>'decimal:4','source_amount_due'=>'decimal:4','finance_adjustment_amount'=>'decimal:4','amount_paid'=>'decimal:4'];
    public function studentProfile(): BelongsTo { return $this->belongsTo(CentralFinanceStudentProfile::class, 'student_profile_id'); }
    public function payments(): HasMany { return $this->hasMany(CentralFinancePayment::class, 'receivable_id'); }
}
