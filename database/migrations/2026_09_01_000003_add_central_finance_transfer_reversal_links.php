<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $sqlite = Schema::connection('mysql')->getConnection()->getDriverName() === 'sqlite';
        Schema::connection('mysql')->table('central_finance_internal_transfers', function (Blueprint $table) use ($sqlite): void {
            $table->unsignedBigInteger('reversal_of_transfer_id')->nullable()->after('confirmed_at');
            $table->text('reversal_reason')->nullable()->after('reversal_of_transfer_id');
            $table->timestamp('reversed_at')->nullable()->after('reversal_reason');
            $table->unsignedBigInteger('reversed_by_central_user_id')->nullable()->after('reversed_at');
            $table->unique('reversal_of_transfer_id', 'cfit_reversal_of_unique');
            $table->index('reversed_by_central_user_id', 'cfit_reversed_by_user_index');
            if (!$sqlite) $table->foreign('reversal_of_transfer_id', 'cfit_reversal_of_fk')->references('id')->on('central_finance_internal_transfers');
        });

        foreach (['central_finance_hq_funding_requests', 'central_finance_fund_handovers'] as $tableName) {
            $index = $tableName === 'central_finance_hq_funding_requests' ? 'cfhfr_reversal_transfer_unique' : 'cfh_reversal_transfer_unique';
            Schema::connection('mysql')->table($tableName, function (Blueprint $table) use ($sqlite, $index): void {
                $table->unsignedBigInteger('reversal_internal_transfer_id')->nullable();
                $table->unique('reversal_internal_transfer_id', $index);
                if (!$sqlite) $table->foreign('reversal_internal_transfer_id')->references('id')->on('central_finance_internal_transfers');
            });
        }
    }

    public function down(): void
    {
        // SQLite's schema builder recreates tables for dropped columns and
        // cannot issue DROP FOREIGN KEY. Production MySQL drops constraints
        // explicitly before the additive columns.
        if (Schema::connection('mysql')->getConnection()->getDriverName() === 'sqlite') {
            foreach (['central_finance_hq_funding_requests' => 'cfhfr_reversal_transfer_unique', 'central_finance_fund_handovers' => 'cfh_reversal_transfer_unique'] as $tableName => $index) {
                \Illuminate\Support\Facades\DB::connection('mysql')->statement('DROP INDEX IF EXISTS '.$index);
                Schema::connection('mysql')->table($tableName, fn (Blueprint $table) => $table->dropColumn('reversal_internal_transfer_id'));
            }
            \Illuminate\Support\Facades\DB::connection('mysql')->statement('DROP INDEX IF EXISTS cfit_reversal_of_unique');
            \Illuminate\Support\Facades\DB::connection('mysql')->statement('DROP INDEX IF EXISTS cfit_reversed_by_user_index');
            Schema::connection('mysql')->table('central_finance_internal_transfers', fn (Blueprint $table) => $table->dropColumn(['reversal_of_transfer_id', 'reversal_reason', 'reversed_at', 'reversed_by_central_user_id']));
            return;
        }
        foreach (['central_finance_hq_funding_requests' => 'cfhfr_reversal_transfer_unique', 'central_finance_fund_handovers' => 'cfh_reversal_transfer_unique'] as $tableName => $index) {
            Schema::connection('mysql')->table($tableName, function (Blueprint $table) use ($index): void {
                $table->dropForeign(['reversal_internal_transfer_id']);
                $table->dropUnique($index);
                $table->dropColumn('reversal_internal_transfer_id');
            });
        }
        Schema::connection('mysql')->table('central_finance_internal_transfers', function (Blueprint $table): void {
            $table->dropForeign('cfit_reversal_of_fk');
            $table->dropUnique('cfit_reversal_of_unique');
            $table->dropIndex('cfit_reversed_by_user_index');
            $table->dropColumn(['reversal_of_transfer_id', 'reversal_reason', 'reversed_at', 'reversed_by_central_user_id']);
        });
    }
};
