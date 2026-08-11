<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseChangeLog extends Model
{
    protected $fillable = [
        'expense_id',
        'field_name',
        'old_value',
        'new_value',
        'changed_by',
        'reason',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
