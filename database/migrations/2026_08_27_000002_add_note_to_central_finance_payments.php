<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->table('central_finance_payments', function (Blueprint $table): void {
            $table->text('note')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('central_finance_payments', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }
};
