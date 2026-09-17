<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use App\Services\FinanceGroupScopeService;

class CentralFinanceCategory extends Model
{
    public const INCOME = 'income';
    public const EXPENSE = 'expense';
    public const ASSET = 'asset';
    public const LIABILITY = 'liability';
    public const EQUITY = 'equity';
    public const TYPES = [self::ASSET, self::LIABILITY, self::EQUITY, self::INCOME, self::EXPENSE];

    protected $connection = 'mysql';

    protected $fillable = ['category_uuid', 'group_id', 'school_id', 'type', 'name', 'category_code', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'category_code' => 'string'];

    public function schoolAllocations(): HasMany { return $this->hasMany(CentralFinanceCategorySchoolAllocation::class, 'category_id'); }

    public function scopeAvailableForSchool(Builder $query, int $schoolId): Builder
    {
        return $this->scopeForSchools($query, [$schoolId])->where($this->qualifyColumn('is_active'), true);
    }

    /** Legacy rows retain their original school identity until explicitly mapped. */
    public function scopeForSchools(Builder $query, array $schoolIds): Builder
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $schoolIds), fn ($id) => $id > 0)));
        if (!$ids) return $query->whereRaw('1 = 0');
        if (!Schema::connection('mysql')->hasColumn($this->getTable(), 'group_id')) {
            return $query->whereIn($this->qualifyColumn('school_id'), $ids);
        }
        return $query->where(function (Builder $available) use ($ids): void {
            $available->where(fn (Builder $legacy) => $legacy->whereNull('group_id')->whereIn('school_id', $ids))
                ->orWhere(function (Builder $central) use ($ids): void {
                    $central->whereNotNull('group_id')->whereNull('school_id')->whereExists(function ($allocation) use ($ids): void {
                        $allocation->selectRaw('1')->from('central_finance_category_school_allocations as coa_alloc')
                            ->join('finance_group_schools as coa_school', function ($join): void {
                                $join->on('coa_school.school_id', '=', 'coa_alloc.school_id')->on('coa_school.group_id', '=', 'central_finance_categories.group_id');
                            })->join('finance_groups as coa_group', 'coa_group.id', '=', 'coa_school.group_id')
                            ->whereColumn('coa_alloc.category_id', 'central_finance_categories.id')->whereIn('coa_alloc.school_id', $ids)
                            ->where('coa_alloc.is_active', true)->where('coa_school.status', 'active')->where('coa_group.status', 'active')
                            ->where(fn ($dates) => $dates->whereNull('coa_school.active_from')->orWhereDate('coa_school.active_from', '<=', now()->toDateString()))
                            ->where(fn ($dates) => $dates->whereNull('coa_school.active_to')->orWhereDate('coa_school.active_to', '>=', now()->toDateString()));
                    });
                });
        });
    }

    public function scopeForCashDirection(Builder $query, string $direction): Builder
    {
        if (!in_array($direction, [self::INCOME, self::EXPENSE], true)) throw new InvalidArgumentException('Invalid cash direction.');
        return $query->whereIn($this->qualifyColumn('type'), [self::ASSET, self::LIABILITY, self::EQUITY, $direction]);
    }

    /** A School can join multiple groups; School access is not access to every group's definitions. */
    public function scopeForActor(Builder $query, User $actor, array $schoolIds, bool $operate = false): Builder
    {
        if (!Schema::connection('mysql')->hasColumn($this->getTable(), 'group_id')) return $this->scopeForSchools($query, $schoolIds);
        $allowedSchools=DB::connection('mysql')->table('central_finance_user_school_scopes')
            ->where('user_id',$actor->id)->whereIn('school_id',$schoolIds)->where('can_view',true)
            ->when($operate,fn ($scopes)=>$scopes->where('can_operate',true))->pluck('school_id')->map(fn ($id)=>(int)$id)->all();
        $groups=FinanceGroupUser::on('mysql')->where(['central_user_id'=>$actor->id,'status'=>'active'])->get();
        $service=app(FinanceGroupScopeService::class);
        return $query->where(function (Builder $visible) use ($groups,$service,$allowedSchools,$operate): void {
            $visible->where(fn (Builder $legacy)=>$legacy->whereNull('group_id')->whereIn('school_id',$allowedSchools));
            foreach ($groups as $groupUser) {
                $groupSchools=array_values(array_filter($allowedSchools,fn (int $schoolId)=>$service->canAccessSchool($groupUser,$schoolId,$operate ? 'operate_finance' : 'view_reports')));
                if ($groupSchools) $visible->orWhere(fn (Builder $central)=>$central->where('group_id',$groupUser->group_id)->forSchools($groupSchools));
            }
        });
    }

    public function availableToSchool(int $schoolId): bool { return static::on('mysql')->availableForSchool($schoolId)->whereKey($this->id)->exists(); }
    public function allowsCashDirection(string $direction): bool { return in_array($direction, [self::INCOME, self::EXPENSE], true) && in_array($this->type, [self::ASSET, self::LIABILITY, self::EQUITY, $direction], true); }

    protected static function booted(): void
    {
        static::creating(function (self $category): void {
            $category->category_uuid ??= (string) Str::uuid();
            $category->name = trim((string) $category->name);
            // Codes are business inputs, never generated from UUIDs. Unmapped
            // legacy definitions may have no code; V3 cannot publish them.
            if (Schema::connection('mysql')->hasColumn($category->getTable(), 'category_code')) {
                $category->category_code = trim((string) $category->category_code) === '' ? null : strtoupper(trim((string) $category->category_code));
            }
            if (!in_array($category->type, self::TYPES, true)
                || (!$category->school_id && !$category->group_id) || ($category->school_id && $category->group_id)
                || ($category->group_id && ($category->category_code === null || $category->category_code === '' || str_starts_with($category->category_code, 'CATEGORY-'))) || $category->name === '') {
                throw new InvalidArgumentException('Central Finance category is invalid.');
            }
        });
    }
}
