<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
                $schema = Schema::connection('mysql');
        if (! $schema->hasColumn('central_finance_user_school_scopes', 'can_submit_collections')) {
            $schema->table('central_finance_user_school_scopes', function (Blueprint $table): void {
                $table->boolean('can_submit_collections')->default(false)->after('can_operate');
            });
        }

                if (! $schema->hasTable('central_finance_pending_collections')) {
            $schema->create('central_finance_pending_collections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('pending_collection_uuid');
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('student_profile_id')->index();
            $table->unsignedBigInteger('receivable_id')->index();
            $table->unsignedBigInteger('intended_fund_account_id')->nullable()->index();
            $table->unsignedBigInteger('confirmed_payment_id')->nullable();
            $table->string('idempotency_key', 64);
            $table->string('acknowledgement_no', 100);
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
            $table->unique('pending_collection_uuid', 'cfpc_uuid_unique');
            $table->unique('confirmed_payment_id', 'cfpc_payment_unique');
            $table->unique('idempotency_key', 'cfpc_idempotency_unique');
            $table->unique('acknowledgement_no', 'cfpc_ack_unique');
            });
        } else {
            $schema->table('central_finance_pending_collections', function (Blueprint $table): void {
                $table->unique('pending_collection_uuid', 'cfpc_uuid_unique');
                $table->unique('confirmed_payment_id', 'cfpc_payment_unique');
                $table->unique('idempotency_key', 'cfpc_idempotency_unique');
                $table->unique('acknowledgement_no', 'cfpc_ack_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_pending_collections');
        Schema::connection('mysql')->table('central_finance_user_school_scopes', function (Blueprint $table): void {
            $table->dropColumn('can_submit_collections');
        });
    }
};
