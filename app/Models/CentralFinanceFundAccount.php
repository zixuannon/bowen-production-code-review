<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class CentralFinanceFundAccount extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const OWNER_HQ = 'hq';
    public const OWNER_SCHOOL = 'school';
    public const TYPE_CASH = 'cash';
    public const TYPE_BANK = 'bank';
    public const TYPE_OTHER = 'other';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    protected $connection = 'mysql';

    protected $fillable = [
        'account_uuid', 'group_id', 'school_id', 'owner_type', 'account_code',
        'account_name', 'currency', 'opening_balance', 'is_active', 'account_type',
        'bank_name', 'masked_account_identifier', 'custodian_user_id', 'status',
        'notes', 'status_reason', 'status_changed_by', 'status_changed_at',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:4',
        'is_active' => 'boolean',
        'status_changed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $account): void {
            $account->account_uuid ??= (string) Str::uuid();
            $account->account_code = strtoupper(trim((string) $account->account_code));
            $account->currency = strtoupper(trim((string) $account->currency));
            if (!preg_match('/^[A-Z0-9_.-]{2,80}$/', $account->account_code)
                || !preg_match('/^[A-Z]{3}$/', $account->currency)
                || !in_array($account->owner_type, [self::OWNER_HQ, self::OWNER_SCHOOL], true)
                || !in_array($account->account_type ?? self::TYPE_OTHER, [self::TYPE_CASH, self::TYPE_BANK, self::TYPE_OTHER], true)
                || !in_array($account->status ?? self::STATUS_ACTIVE, [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_ARCHIVED], true)) {
                throw new InvalidArgumentException('Central Fund Account identity or ownership is invalid.');
            }
            if (($account->owner_type === self::OWNER_HQ && $account->school_id !== null)
                || ($account->owner_type === self::OWNER_SCHOOL && empty($account->school_id))) {
                throw new InvalidArgumentException('Central Fund Account owner and School scope are inconsistent.');
            }
        });
    }

    public function authorizedUsers(): BelongsToMany
    {
        return $this->belongsToMany(CentralFinanceUser::class, 'central_finance_fund_account_users', 'fund_account_id', 'user_id')
            ->withPivot(['can_view', 'can_operate'])->withTimestamps();
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(CentralFinanceUser::class, 'custodian_user_id');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        $query->where('is_active', true);
        // A few isolated legacy SQLite characterization fixtures intentionally
        // exercise the pre-master-data schema. Production/new Central schema
        // always has status and therefore fails closed for inactive/archived.
        if (Schema::connection($this->getConnectionName())->hasColumn($this->getTable(), 'status')) {
            $query->where('status', self::STATUS_ACTIVE);
        }
        return $query;
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CentralFinanceLedgerEntry::class, 'fund_account_id');
    }
}
