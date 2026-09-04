<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->table('central_finance_user_school_scopes', function (Blueprint $table): void {
            $table->boolean('can_submit_collections')->default(false)->after('can_operate');
        });

        Schema::connection('mysql')->create('central_finance_pending_collections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('pending_collection_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('student_profile_id')->index();
            $table->unsignedBigInteger('receivable_id')->index();
            $table->unsignedBigInteger('intended_fund_account_id')->nullable()->index();
            $table->unsignedBigInteger('confirmed_payment_id')->nullable()->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->string('acknowledgement_no', 100)->unique();
            $table->string('status', 16)->index(); // draft | submitted | held | rejected | cancelled | confirmed
            $table->decimal('amount', 20, 4);
            $table->string('currency', 3);
            $table->string('payment_method', 40);
            $table->string('payment_reference', 100)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('collected_at');
            $table->unsignedBigInteger('collected_by');
            $table->unsignedBigInteger('submitted_by');
            $table->timestamp('submitted_at');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status'], 'cfpc_school_status_index');
            $table->index(['receivable_id', 'status'], 'cfpc_receivable_status_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_pending_collections');
        Schema::connection('mysql')->table('central_finance_user_school_scopes', function (Blueprint $table): void {
            $table->dropColumn('can_submit_collections');
        });
    }
};
