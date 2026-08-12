<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fund_handovers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('from_account_id')->index();
            $table->unsignedBigInteger('to_account_id')->index();
            $table->unsignedBigInteger('sender_id')->index();
            $table->unsignedBigInteger('receiver_id')->index();
            $table->decimal('amount', 14, 2);
            $table->date('handover_date');
            $table->string('reference_no', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('bank_transfer_id')->nullable()->unique();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'sender_id', 'status']);
            $table->index(['school_id', 'receiver_id', 'status']);
            $table->foreign('from_account_id')->references('id')->on('bank_accounts')->restrictOnDelete();
            $table->foreign('to_account_id')->references('id')->on('bank_accounts')->restrictOnDelete();
            $table->foreign('sender_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('receiver_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('bank_transfer_id')->references('id')->on('bank_transfers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_handovers');
    }
};
