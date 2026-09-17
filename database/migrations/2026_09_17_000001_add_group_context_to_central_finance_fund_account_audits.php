<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('mysql')->hasTable('central_finance_document_audits')) {
            throw new \RuntimeException('Central Finance document audits must exist before Fund Account V2.');
        }

        $hasGroupId = Schema::connection('mysql')->hasColumn('central_finance_document_audits', 'group_id');
        Schema::connection('mysql')->table('central_finance_document_audits', function (Blueprint $table) use ($hasGroupId): void {
            $table->unsignedBigInteger('school_id')->nullable()->change();
            if (!$hasGroupId) {
                $table->unsignedBigInteger('group_id')->nullable()->after('school_id')->index('cfda_group_index');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('mysql')->hasTable('central_finance_document_audits')) {
            return;
        }
        if (DB::connection('mysql')->table('central_finance_document_audits')->whereNull('school_id')->exists()) {
            throw new \RuntimeException('Fund Account V2 group audits exist; use a reviewed forward fix instead of removing their context.');
        }

        $hasGroupId = Schema::connection('mysql')->hasColumn('central_finance_document_audits', 'group_id');
        Schema::connection('mysql')->table('central_finance_document_audits', function (Blueprint $table) use ($hasGroupId): void {
            if ($hasGroupId) {
                $table->dropIndex('cfda_group_index');
                $table->dropColumn('group_id');
            }
            $table->unsignedBigInteger('school_id')->nullable(false)->change();
        });
    }
};
