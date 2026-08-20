<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-local attribution for a Scheme B Central Finance operation.
 *
 * This table intentionally stores no amount, balance, or duplicate Ledger
 * data. Canonical Finance sources remain the financial source of truth.
 */
class FinanceOperatingAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'central_actor_id',
        'finance_group_id',
        'school_id',
        'tenant_user_id',
        'source_type',
        'source_id',
        'action',
        'request_source',
    ];
}
