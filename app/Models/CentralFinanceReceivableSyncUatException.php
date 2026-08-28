<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A temporary, explicitly audited exception to the Fresh Start source cutoff. */
final class CentralFinanceReceivableSyncUatException extends Model
{
    public const CONTEXT = 'production_finance_e2e_uat';
    public const ACTIVE = 'active';
    public const DISABLED = 'disabled';

    protected $connection = 'mysql';

    protected $fillable = [
        'school_id', 'student_profile_id', 'student_source_uuid', 'context', 'status',
        'reason', 'authorized_by', 'enabled_at', 'disabled_at', 'disabled_by', 'disabled_reason',
    ];

    protected $casts = [
        'enabled_at' => 'immutable_datetime',
        'disabled_at' => 'immutable_datetime',
    ];
}
