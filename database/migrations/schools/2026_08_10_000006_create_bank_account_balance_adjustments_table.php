<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_account_balance_adjustments')) {
            return;
        }

        Schema::create('bank_account_balance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->onDelete('cascade');
            $table->decimal('old_opening_balance', 12, 2)->nullable();
            $table->decimal('new_opening_balance', 12, 2)->nullable();
            $table->date('old_opening_balance_date')->nullable();
            $table->date('new_opening_balance_date')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->string('reason', 255);
            $table->timestamps();

            $table->index('bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_account_balance_adjustments');
    }
};
