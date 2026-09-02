<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
final class CentralFinanceGroupImportBatch extends Model {
    protected $connection='mysql';
    protected $fillable=['batch_uuid','token','finance_group_id','uploaded_by','confirmed_by','file_name','file_hash','schema_version','status','total_rows','new_rows','duplicate_rows','conflict_rows','error_rows','school_summary','confirmed_at','failure_reason'];
    protected $casts=['school_summary'=>'array','confirmed_at'=>'datetime'];
    protected static function booted(): void { static::creating(function(self $batch):void{$batch->batch_uuid??=(string)Str::uuid();$batch->token??=(string)Str::uuid();}); }

    public function rows(): HasMany { return $this->hasMany(CentralFinanceGroupImportPreviewRow::class, 'group_batch_id'); }
    public function confirmedBy(): BelongsTo { return $this->belongsTo(CentralFinanceUser::class, 'confirmed_by'); }
}
