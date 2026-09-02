<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class CentralFinanceGroupImportPreviewRow extends Model { protected $connection='mysql'; protected $fillable=['group_batch_id','child_batch_id','row_number','school_id','document_type','reference_no','result_status','error_code','error_message','normalized_data','idempotency_key','canonical_source_type','canonical_source_id','canonical_source_uuid','confirmed_at']; protected $casts=['normalized_data'=>'array','confirmed_at'=>'datetime']; }
