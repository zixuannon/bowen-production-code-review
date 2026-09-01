<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $sqlite = Schema::connection('mysql')->getConnection()->getDriverName() === 'sqlite';
        $schema = Schema::connection('mysql');

        // MySQL DDL is not transactional. These guards also allow this additive
        // migration to recover safely from an interrupted deployment before the
        // migration history row was recorded.
        if (!$schema->hasColumn('central_finance_internal_transfers', 'reversal_of_transfer_id')) {
            $schema->table('central_finance_internal_transfers', function (Blueprint $table): void {
                $table->unsignedBigInteger('reversal_of_transfer_id')->nullable()->after('confirmed_at');
                $table->text('reversal_reason')->nullable()->after('reversal_of_transfer_id');
                $table->timestamp('reversed_at')->nullable()->after('reversal_reason');
                $table->unsignedBigInteger('reversed_by_central_user_id')->nullable()->after('reversed_at');
                $table->unique('reversal_of_transfer_id', 'cfit_reversal_of_unique');
                $table->index('reversed_by_central_user_id', 'cfit_reversed_by_user_index');
            });
        }
        if (!$sqlite && !$this->hasForeignKey('central_finance_internal_transfers', 'cfit_reversal_of_fk')) {
            $schema->table('central_finance_internal_transfers', fn (Blueprint $table) => $table->foreign('reversal_of_transfer_id', 'cfit_reversal_of_fk')->references('id')->on('central_finance_internal_transfers'));
        }

        foreach (['central_finance_hq_funding_requests', 'central_finance_fund_handovers'] as $tableName) {
            $index = $tableName === 'central_finance_hq_funding_requests' ? 'cfhfr_reversal_transfer_unique' : 'cfh_reversal_transfer_unique';
            $foreign = $tableName === 'central_finance_hq_funding_requests' ? 'cfhfr_reversal_transfer_fk' : 'cfh_reversal_transfer_fk';
            if (!$schema->hasColumn($tableName, 'reversal_internal_transfer_id')) {
                $schema->table($tableName, function (Blueprint $table) use ($index): void {
                    $table->unsignedBigInteger('reversal_internal_transfer_id')->nullable();
                    $table->unique('reversal_internal_transfer_id', $index);
                });
            }
            if (!$sqlite && !$this->hasForeignKey($tableName, $foreign)) {
                $schema->table($tableName, fn (Blueprint $table) => $table->foreign('reversal_internal_transfer_id', $foreign)->references('id')->on('central_finance_internal_transfers'));
            }
        }
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        foreach (Schema::connection('mysql')->getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
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
        foreach (['central_finance_hq_funding_requests' => ['cfhfr_reversal_transfer_unique', 'cfhfr_reversal_transfer_fk'], 'central_finance_fund_handovers' => ['cfh_reversal_transfer_unique', 'cfh_reversal_transfer_fk']] as $tableName => [$index, $foreign]) {
            Schema::connection('mysql')->table($tableName, function (Blueprint $table) use ($index, $foreign): void {
                $table->dropForeign($foreign);
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
