<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('school')->create('other_incomes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('bank_account_id')->index();
            $table->date('date')->index();
            $table->string('payer', 255);
            $table->string('description', 1000);
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 50);
            $table->string('reference_no', 100)->nullable()->index();
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('bank_account_id')->references('id')->on('bank_accounts');
        });
    }

    public function down(): void
    {
        Schema::connection('school')->dropIfExists('other_incomes');
    }
};
