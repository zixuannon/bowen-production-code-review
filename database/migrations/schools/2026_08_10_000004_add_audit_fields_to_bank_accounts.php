<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_accounts')) {
            Schema::table('bank_accounts', function (Blueprint $table) {
                if (!Schema::hasColumn('bank_accounts', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
                }
                if (!Schema::hasColumn('bank_accounts', 'updated_by')) {
                    $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bank_accounts')) {
            Schema::table('bank_accounts', function (Blueprint $table) {
                if (Schema::hasColumn('bank_accounts', 'created_by')) {
                    $table->dropForeign(['created_by']);
                }
                if (Schema::hasColumn('bank_accounts', 'updated_by')) {
                    $table->dropForeign(['updated_by']);
                }
                $columns = [];
                if (Schema::hasColumn('bank_accounts', 'created_by')) {
                    $columns[] = 'created_by';
                }
                if (Schema::hasColumn('bank_accounts', 'updated_by')) {
                    $columns[] = 'updated_by';
                }
                if (!empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
