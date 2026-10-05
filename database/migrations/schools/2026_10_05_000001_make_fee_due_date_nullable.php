<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::connection('school')->hasTable('fees') || !Schema::connection('school')->hasColumn('fees', 'due_date')) {
            throw new LogicException('Cannot make fees.due_date nullable because the tenant fee schema is incomplete.');
        }

        if ($this->isNullable()) {
            return;
        }

        Schema::connection('school')->table('fees', static function (Blueprint $table): void {
            $table->date('due_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!$this->isNullable()) {
            return;
        }

        if (DB::connection('school')->table('fees')->whereNull('due_date')->exists()) {
            throw new LogicException('Cannot restore fees.due_date NOT NULL while undated Fee records exist.');
        }

        Schema::connection('school')->table('fees', static function (Blueprint $table): void {
            $table->date('due_date')->nullable(false)->change();
        });
    }

    private function isNullable(): bool
    {
        $column = DB::connection('school')->selectOne(
            'SELECT IS_NULLABLE AS is_nullable, DATA_TYPE AS data_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['fees', 'due_date']
        );

        if (!$column || strtolower((string) $column->data_type) !== 'date') {
            throw new LogicException('Tenant information_schema does not contain fees.due_date.');
        }

        return strtoupper((string) $column->is_nullable) === 'YES';
    }
};
