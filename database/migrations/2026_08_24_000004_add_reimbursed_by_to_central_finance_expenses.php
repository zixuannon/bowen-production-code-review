<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** Expense-import history metadata only; it never creates a reimbursement. */
    public function up(): void
    {
        Schema::connection('mysql')->table('central_finance_expenses', function (Blueprint $table): void {
            $table->string('reimbursed_by', 191)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('central_finance_expenses', function (Blueprint $table): void {
            $table->dropColumn('reimbursed_by');
        });
    }
};
