<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                if (!Schema::hasColumn('expenses', 'deleted_at')) {
                    $table->softDeletes();
                }
                if (!Schema::hasColumn('expenses', 'deleted_by')) {
                    $table->foreignId('deleted_by')->nullable()->constrained('users')->onDelete('set null');
                }
                if (!Schema::hasColumn('expenses', 'delete_reason')) {
                    $table->string('delete_reason', 255)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                if (Schema::hasColumn('expenses', 'deleted_by')) {
                    $table->dropForeign(['deleted_by']);
                }
                $columns = [];
                if (Schema::hasColumn('expenses', 'delete_reason')) {
                    $columns[] = 'delete_reason';
                }
                if (Schema::hasColumn('expenses', 'deleted_by')) {
                    $columns[] = 'deleted_by';
                }
                if (Schema::hasColumn('expenses', 'deleted_at')) {
                    $columns[] = 'deleted_at';
                }
                if (!empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
