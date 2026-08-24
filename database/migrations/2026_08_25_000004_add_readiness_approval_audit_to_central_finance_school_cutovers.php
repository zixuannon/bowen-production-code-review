<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->table('central_finance_school_cutovers', function (Blueprint $table): void {
            $table->unsignedBigInteger('ready_by')->nullable()->after('status');
            $table->timestamp('ready_at')->nullable()->after('ready_by');
            $table->unsignedBigInteger('approved_by')->nullable()->after('cutover_at');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('central_finance_school_cutovers', function (Blueprint $table): void {
            $table->dropColumn(['ready_by', 'ready_at', 'approved_by']);
        });
    }
};
