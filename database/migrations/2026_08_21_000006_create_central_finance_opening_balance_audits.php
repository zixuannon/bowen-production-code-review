<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->create('central_finance_fund_account_opening_balance_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fund_account_id')->index();
            $table->string('change_type', 16); // initial | adjustment
            $table->decimal('old_opening_balance', 20, 4)->nullable();
            $table->decimal('new_opening_balance', 20, 4);
            $table->date('effective_date');
            $table->string('reason', 2000);
            $table->unsignedBigInteger('created_by')->index();
            $table->timestamps();

            $table->index(['fund_account_id', 'effective_date'], 'cffoba_account_date_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_fund_account_opening_balance_audits');
    }
};
