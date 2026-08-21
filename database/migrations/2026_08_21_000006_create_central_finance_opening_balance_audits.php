<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->create('central_finance_fund_account_opening_balance_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fund_account_id');
            $table->string('change_type', 16); // initial | adjustment
            $table->decimal('old_opening_balance', 20, 4)->nullable();
            $table->decimal('new_opening_balance', 20, 4);
            $table->date('effective_date');
            $table->string('reason', 2000);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index('fund_account_id', 'cffoba_fund_account_index');
            $table->index(['fund_account_id', 'effective_date'], 'cffoba_account_date_index');
            $table->index('created_by', 'cffoba_created_by_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_fund_account_opening_balance_audits');
    }
};
