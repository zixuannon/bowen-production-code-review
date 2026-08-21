<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CentralFinanceCategory extends Model
{
    public const INCOME = 'income';
    public const EXPENSE = 'expense';

    protected $connection = 'mysql';

    protected $fillable = ['category_uuid', 'school_id', 'type', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (self $category): void {
            $category->category_uuid ??= (string) Str::uuid();
            $category->name = trim((string) $category->name);
            if (!in_array($category->type, [self::INCOME, self::EXPENSE], true)
                || !$category->school_id || $category->name === '') {
                throw new InvalidArgumentException('Central Finance category is invalid.');
            }
        });
    }
}
