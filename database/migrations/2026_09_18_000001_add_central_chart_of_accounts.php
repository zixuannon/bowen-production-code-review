<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection('mysql');
        foreach (['central_finance_categories' => ['id','school_id','type','name','category_code','is_active'], 'finance_groups' => ['id'], 'schools' => ['id']] as $table => $columns) {
            if (!$schema->hasTable($table) || !$schema->hasColumns($table, $columns)) throw new RuntimeException('Chart of Accounts preflight failed: '.$table);
        }
        // A partial prior attempt must be investigated, never treated as success.
        if ($schema->hasColumn('central_finance_categories', 'group_id') || $schema->hasTable('central_finance_category_school_allocations') || $schema->hasTable('central_finance_category_audits')) {
            throw new RuntimeException('Chart of Accounts schema already exists or is partial; verify migration registry and constraints before continuing.');
        }
        $sqlite = DB::connection('mysql')->getDriverName() === 'sqlite';
        if ($sqlite) DB::connection('mysql')->statement('ALTER TABLE central_finance_categories ADD COLUMN group_id INTEGER NULL REFERENCES finance_groups(id) ON DELETE RESTRICT');
        $schema->table('central_finance_categories', function (Blueprint $table) use ($sqlite): void {
            $table->unsignedBigInteger('school_id')->nullable()->change();
            if (!$sqlite) {
                $table->unsignedBigInteger('group_id')->nullable();
                $table->foreign('group_id', 'coa_group_fk')->references('id')->on('finance_groups')->restrictOnDelete();
            }
            $table->unique(['group_id','category_code'], 'coa_group_code_unique');
        });
        $schema->create('central_finance_category_school_allocations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('category_id'); $table->unsignedBigInteger('school_id');
            $table->boolean('is_active')->default(true); $table->timestamps();
            $table->unique(['category_id','school_id'], 'coa_allocation_unique');
            $table->foreign('category_id', 'coa_allocation_category_fk')->references('id')->on('central_finance_categories')->restrictOnDelete();
            $table->foreign('school_id', 'coa_allocation_school_fk')->references('id')->on('schools')->restrictOnDelete();
        });
        $schema->create('central_finance_category_audits', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('category_id'); $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('actor_id'); $table->string('action', 40); $table->text('reason');
            $table->json('before')->nullable(); $table->json('after'); $table->timestamp('created_at');
            $table->foreign('category_id', 'coa_audit_category_fk')->references('id')->on('central_finance_categories')->restrictOnDelete();
            $table->index(['group_id','created_at'], 'coa_audit_group_date');
        });
        \App\Services\CentralChartOfAccountsSchema::assertComplete();
    }

    public function down(): void
    {
        throw new RuntimeException('Chart of Accounts preserves historical IDs and audit context; use a reviewed forward fix.');
    }
};
