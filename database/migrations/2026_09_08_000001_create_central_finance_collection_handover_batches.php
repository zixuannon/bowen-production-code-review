<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection('mysql');

        if (! $schema->hasTable('central_finance_collection_handover_batches')) {
            $schema->create('central_finance_collection_handover_batches', function (Blueprint $table): void {
                $table->id();
                $table->uuid('handover_batch_uuid');
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('collector_id')->index();
                $table->string('currency', 3);
                $table->string('payment_channel', 40);
                $table->string('handover_type', 40)->default('collection');
                $table->decimal('expected_amount', 20, 4);
                $table->decimal('actual_handed_over_amount', 20, 4)->nullable();
                $table->decimal('difference_amount', 20, 4)->nullable();
                $table->string('status', 20)->index();
                $table->string('idempotency_key', 100);
                $table->string('reference', 100);
                $table->unsignedBigInteger('submitted_by')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->unsignedBigInteger('held_by')->nullable();
                $table->timestamp('held_at')->nullable();
                $table->text('held_reason')->nullable();
                $table->unsignedBigInteger('rejected_by')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejected_reason')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancelled_reason')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
                $table->unique('handover_batch_uuid', 'cfchb_uuid_unique');
                $table->unique('idempotency_key', 'cfchb_idempotency_unique');
                $table->unique(['school_id', 'reference'], 'cfchb_school_reference_unique');
                $table->index(['school_id', 'status'], 'cfchb_school_status_index');
            });
        }

        if (! $schema->hasTable('central_finance_collection_handover_items')) {
            $schema->create('central_finance_collection_handover_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('handover_batch_id');
                $table->unsignedBigInteger('pending_collection_id');
                $table->decimal('expected_amount_snapshot', 20, 4);
                $table->string('currency_snapshot', 3);
                $table->string('status', 20)->default('pending');
                $table->unsignedBigInteger('confirmed_payment_id')->nullable();
                $table->text('failure_reason')->nullable();
                $table->timestamps();
                $table->unique(['handover_batch_id', 'pending_collection_id'], 'cfchi_batch_pending_unique');
                $table->unique('confirmed_payment_id', 'cfchi_payment_unique');
                $table->index('pending_collection_id', 'cfchi_pending_index');
                $table->foreign('handover_batch_id', 'cfchi_batch_fk')
                    ->references('id')->on('central_finance_collection_handover_batches');
                $table->foreign('pending_collection_id', 'cfchi_pending_fk')
                    ->references('id')->on('central_finance_pending_collections');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_collection_handover_items');
        Schema::connection('mysql')->dropIfExists('central_finance_collection_handover_batches');
    }
};
