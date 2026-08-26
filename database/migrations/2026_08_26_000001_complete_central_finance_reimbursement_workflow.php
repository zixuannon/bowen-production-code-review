<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->table('central_finance_reimbursement_requests', function (Blueprint $table): void {
            $table->string('submission_reason', 255)->nullable()->after('description');
            $table->unsignedBigInteger('rejected_by')->nullable()->after('approval_reason');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->string('rejection_reason', 255)->nullable()->after('rejected_at');
            $table->unsignedBigInteger('withdrawn_by')->nullable()->after('rejection_reason');
            $table->timestamp('withdrawn_at')->nullable()->after('withdrawn_by');
            $table->string('withdrawal_reason', 255)->nullable()->after('withdrawn_at');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('withdrawal_reason');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->string('cancellation_reason', 255)->nullable()->after('cancelled_at');
            $table->index(['school_id', 'status'], 'cfrq_school_status_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('central_finance_reimbursement_requests', function (Blueprint $table): void {
            $table->dropIndex('cfrq_school_status_index');
            $table->dropColumn([
                'submission_reason', 'rejected_by', 'rejected_at', 'rejection_reason',
                'withdrawn_by', 'withdrawn_at', 'withdrawal_reason',
                'cancelled_by', 'cancelled_at', 'cancellation_reason',
            ]);
        });
    }
};
