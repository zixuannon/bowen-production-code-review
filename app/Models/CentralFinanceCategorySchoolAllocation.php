<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CentralFinanceCategorySchoolAllocation extends Model
{
    protected $connection = 'mysql';
    protected $fillable = ['category_id', 'school_id', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];
}
