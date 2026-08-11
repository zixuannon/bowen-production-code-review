<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // compulsory_fees
        if (Schema::hasTable('compulsory_fees')) {
            Schema::table('compulsory_fees', function (Blueprint $table) {
                if (!Schema::hasColumn('compulsory_fees', 'deleted_by')) {
                    $table->foreignId('deleted_by')->nullable()->constrained('users')->onDelete('set null');
                }
                if (!Schema::hasColumn('compulsory_fees', 'delete_reason')) {
                    $table->string('delete_reason', 255)->nullable();
                }
            });
        }

        // optional_fees
        if (Schema::hasTable('optional_fees')) {
            Schema::table('optional_fees', function (Blueprint $table) {
                if (!Schema::hasColumn('optional_fees', 'deleted_by')) {
                    $table->foreignId('deleted_by')->nullable()->constrained('users')->onDelete('set null');
                }
                if (!Schema::hasColumn('optional_fees', 'delete_reason')) {
                    $table->string('delete_reason', 255)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['compulsory_fees', 'optional_fees'] as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    $columns = [];
                    if (Schema::hasColumn($tableName, 'deleted_by')) {
                        $table->dropForeign(['deleted_by']);
                    }
                    if (Schema::hasColumn($tableName, 'delete_reason')) {
                        $columns[] = 'delete_reason';
                    }
                    if (Schema::hasColumn($tableName, 'deleted_by')) {
                        $columns[] = 'deleted_by';
                    }
                    if (!empty($columns)) {
                        $table->dropColumn($columns);
                    }
                });
            }
        }
    }
};
