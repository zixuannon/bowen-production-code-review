<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceGroup extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'code',
        'name',
        'status',
        'reporting_currency',
        'fiscal_year_start_month',
    ];

    protected $casts = [
        'fiscal_year_start_month' => 'integer',
    ];

    public function schools(): HasMany
    {
        return $this->hasMany(FinanceGroupSchool::class, 'group_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(FinanceGroupUser::class, 'group_id');
    }

    public function hqAccounts(): HasMany
    {
        return $this->hasMany(FinanceGroupHqAccount::class, 'group_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(FinanceGroupTransfer::class, 'group_id');
    }
}
