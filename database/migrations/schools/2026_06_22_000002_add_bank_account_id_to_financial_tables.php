<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // compulsory_fees
        if (Schema::hasTable('compulsory_fees') && !Schema::hasColumn('compulsory_fees', 'bank_account_id')) {
            Schema::table('compulsory_fees', function (Blueprint $table) {
                $table->unsignedBigInteger('bank_account_id')->nullable()->after('mode');
                $table->index('bank_account_id');
            });
        }

        // optional_fees
        if (Schema::hasTable('optional_fees') && !Schema::hasColumn('optional_fees', 'bank_account_id')) {
            Schema::table('optional_fees', function (Blueprint $table) {
                $table->unsignedBigInteger('bank_account_id')->nullable()->after('mode');
                $table->index('bank_account_id');
            });
        }

        // expenses
        if (Schema::hasTable('expenses') && !Schema::hasColumn('expenses', 'bank_account_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->unsignedBigInteger('bank_account_id')->nullable()->after('finance_category_id');
                $table->index('bank_account_id');
            });
        }
    }

    public function down(): void
    {
        $tables = ['compulsory_fees', 'optional_fees', 'expenses'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'bank_account_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('bank_account_id');
                });
            }
        }
    }
};
