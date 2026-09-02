<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class CentralFinanceCategory extends Model
{
    public const INCOME = 'income';
    public const EXPENSE = 'expense';

    protected $connection = 'mysql';

    protected $fillable = ['category_uuid', 'school_id', 'type', 'name', 'category_code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (self $category): void {
            $category->category_uuid ??= (string) Str::uuid();
            $category->name = trim((string) $category->name);
            // Keep categories created after the additive V2 migration usable
            // by strict imports without changing the existing name contract.
            if (Schema::connection('mysql')->hasColumn($category->getTable(), 'category_code')) {
                $category->category_code = strtoupper(trim((string) ($category->category_code ?: 'CATEGORY-'.substr($category->category_uuid, 0, 12))));
            }
            if (!in_array($category->type, [self::INCOME, self::EXPENSE], true)
                || !$category->school_id || $category->name === '') {
                throw new InvalidArgumentException('Central Finance category is invalid.');
            }
        });
    }
}
