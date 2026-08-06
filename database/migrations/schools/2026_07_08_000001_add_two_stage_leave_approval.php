<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'school';

    /**
     * Run the migrations.
     *
     * Adds two-stage approval fields for staff leave:
     *   1. staffs.supervisor_user_id — 直属主管
     *   2. leaves.* — supervisor / HR / withdrawn 相关字段
     *
     * IMPORTANT: Does NOT update historical data.
     *   Old status=0 records remain NULL in new columns.
     *   A separate data script will handle legacy migration.
     */
    public function up(): void
    {
        // ---------------------------------------------------------------
        // 1. staffs — 直属主管
        // ---------------------------------------------------------------
        if (!Schema::hasColumn('staffs', 'supervisor_user_id')) {
            Schema::table('staffs', function (Blueprint $table) {
                $table->foreignId('supervisor_user_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('users')
                    ->onDelete('set null');
            });
        }

        // ---------------------------------------------------------------
        // 2. leaves — 两级审批 + 撤回
        // ---------------------------------------------------------------
        Schema::table('leaves', function (Blueprint $table) {

            // ---- 直属主管审批 ----
            if (!Schema::hasColumn('leaves', 'supervisor_status')) {
                $table->tinyInteger('supervisor_status')
                    ->nullable()
                    ->after('status')
                    ->comment('0=pending, 1=approved, 2=rejected');
            }

            if (!Schema::hasColumn('leaves', 'supervisor_comment')) {
                $table->text('supervisor_comment')
                    ->nullable()
                    ->after('supervisor_status');
            }

            if (!Schema::hasColumn('leaves', 'supervisor_user_id')) {
                $table->foreignId('supervisor_user_id')
                    ->nullable()
                    ->after('supervisor_comment')
                    ->constrained('users')
                    ->onDelete('set null');
            }

            if (!Schema::hasColumn('leaves', 'supervisor_reviewed_at')) {
                $table->timestamp('supervisor_reviewed_at')
                    ->nullable()
                    ->after('supervisor_user_id');
            }

            // ---- HR 终审 ----
            if (!Schema::hasColumn('leaves', 'hr_status')) {
                $table->tinyInteger('hr_status')
                    ->nullable()
                    ->after('supervisor_reviewed_at')
                    ->comment('0=pending, 1=approved, 2=rejected');
            }

            if (!Schema::hasColumn('leaves', 'hr_comment')) {
                $table->text('hr_comment')
                    ->nullable()
                    ->after('hr_status');
            }

            if (!Schema::hasColumn('leaves', 'hr_user_id')) {
                $table->foreignId('hr_user_id')
                    ->nullable()
                    ->after('hr_comment')
                    ->constrained('users')
                    ->onDelete('set null');
            }

            if (!Schema::hasColumn('leaves', 'hr_reviewed_at')) {
                $table->timestamp('hr_reviewed_at')
                    ->nullable()
                    ->after('hr_user_id');
            }

            // ---- 员工撤回 ----
            if (!Schema::hasColumn('leaves', 'withdrawn_at')) {
                $table->timestamp('withdrawn_at')
                    ->nullable()
                    ->after('hr_reviewed_at');
            }
        });

        // NOTE: 历史数据不在此 migration 中处理。
        // 旧数据兼容方案（独立脚本）：
        //   - status=1 → supervisor_status=1, hr_status=1
        //   - status=2 → supervisor_status=2, hr_status=NULL
        //   - status=0 → supervisor_status=NULL, hr_status=NULL (视为 legacy pending)
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ---- 1. Remove leaves columns ----
        Schema::table('leaves', function (Blueprint $table) {
            $columns = [
                'supervisor_status',
                'supervisor_comment',
                'supervisor_user_id',
                'supervisor_reviewed_at',
                'hr_status',
                'hr_comment',
                'hr_user_id',
                'hr_reviewed_at',
                'withdrawn_at',
            ];

            foreach ($columns as $col) {
                if (Schema::hasColumn('leaves', $col)) {
                    // Drop foreign if exists (nullable FK may not be auto-named)
                    if (in_array($col, ['supervisor_user_id', 'hr_user_id'])) {
                        $fkName = 'leaves_' . $col . '_foreign';
                        try {
                            Schema::table('leaves', function (Blueprint $table) use ($fkName) {
                                $table->dropForeign($fkName);
                            });
                        } catch (\Throwable) {
                            // FK may not exist, continue
                        }
                    }
                    $table->dropColumn($col);
                }
            }
        });

        // ---- 2. Remove staffs.supervisor_user_id ----
        Schema::table('staffs', function (Blueprint $table) {
            if (Schema::hasColumn('staffs', 'supervisor_user_id')) {
                try {
                    $table->dropForeign(['supervisor_user_id']);
                } catch (\Throwable) {
                    // FK may not exist
                }
                $table->dropColumn('supervisor_user_id');
            }
        });
    }
};
