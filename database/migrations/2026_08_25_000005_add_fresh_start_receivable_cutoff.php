<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * A Fresh Start School must explicitly state when new tenant fee
     * assignments become Central receivable sources. Pre-cutoff assignments
     * remain tenant history unless a separately approved carry-forward flow
     * brings one across.
     */
    public function up(): void
    {
        Schema::connection('mysql')->table('central_finance_school_cutovers', function (Blueprint $table): void {
            $table->timestamp('receivable_sync_effective_at')->nullable()->after('status');
            $table->unsignedBigInteger('receivable_sync_effective_by')->nullable()->after('receivable_sync_effective_at');
            $table->string('receivable_sync_effective_reason', 2000)->nullable()->after('receivable_sync_effective_by');
        });

        Schema::connection('mysql')->table('central_finance_receivables', function (Blueprint $table): void {
            $table->timestamp('source_created_at')->nullable()->after('source_updated_at');
            $table->index(['school_id', 'source_created_at'], 'cfr_school_source_created_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('central_finance_receivables', function (Blueprint $table): void {
            $table->dropIndex('cfr_school_source_created_index');
            $table->dropColumn('source_created_at');
        });

        Schema::connection('mysql')->table('central_finance_school_cutovers', function (Blueprint $table): void {
            $table->dropColumn([
                'receivable_sync_effective_at',
                'receivable_sync_effective_by',
                'receivable_sync_effective_reason',
            ]);
        });
    }
};
