<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('mysql');
        if (!$schema->hasTable('central_finance_fund_accounts')) {
            throw new RuntimeException('Fund Account holder migration requires the Central Fund Account schema.');
        }
        if (!$schema->hasColumn('central_finance_fund_accounts', 'owner_holder')) {
            $schema->table('central_finance_fund_accounts', function (Blueprint $table): void {
                $table->string('owner_holder', 191)->nullable();
            });
        }
        if (!$schema->hasColumn('central_finance_fund_accounts', 'owner_holder')
            || !in_array($schema->getColumnType('central_finance_fund_accounts', 'owner_holder'), ['string', 'varchar'], true)) {
            throw new RuntimeException('Fund Account owner_holder column is missing or has an incompatible type.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Fund Account holder metadata uses an additive forward-only migration.');
    }
};
