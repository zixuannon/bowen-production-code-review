<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class CentralChartOfAccountsSchema
{
    public static function assertComplete(): void
    {
        $schema=Schema::connection('mysql');
        $required=[
            'central_finance_categories'=>['id','category_uuid','group_id','school_id','category_code','type','name','is_active'],
            'central_finance_category_school_allocations'=>['id','category_id','school_id','is_active','created_at','updated_at'],
            'central_finance_category_audits'=>['id','category_id','group_id','actor_id','action','reason','before','after','created_at'],
        ];
        foreach ($required as $table=>$columns) if (!$schema->hasTable($table) || !$schema->hasColumns($table,$columns)) throw new RuntimeException('Incomplete Chart of Accounts schema: '.$table);
        foreach (['central_finance_categories'=>['group_id','category_code'],'central_finance_category_school_allocations'=>['category_id','school_id']] as $table=>$columns) {
            if (!collect($schema->getIndexes($table))->contains(fn ($index)=>$index['unique'] && $index['columns']===$columns)) throw new RuntimeException('Missing Chart of Accounts unique constraint: '.$table);
        }
        foreach ([['central_finance_categories','group_id','finance_groups'],['central_finance_category_school_allocations','category_id','central_finance_categories'],['central_finance_category_school_allocations','school_id','schools'],['central_finance_category_audits','category_id','central_finance_categories']] as [$table,$column,$target]) {
            if (!collect($schema->getForeignKeys($table))->contains(fn ($key)=>$key['columns']===[$column] && $key['foreign_table']===$target && $key['foreign_columns']===['id'] && in_array(strtolower($key['on_delete']),['restrict','no action'],true))) throw new RuntimeException('Missing or unsafe Chart of Accounts foreign key: '.$table.'.'.$column);
        }
        $school=collect($schema->getColumns('central_finance_categories'))->firstWhere('name','school_id');
        if (!$school['nullable']) throw new RuntimeException('Chart of Accounts school_id must allow group-owned definitions.');
    }
}
