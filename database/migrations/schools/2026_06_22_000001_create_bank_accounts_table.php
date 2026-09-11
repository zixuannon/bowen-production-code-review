<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $complete = static function (): bool {
            $schema = Schema::connection('school');
            if (!$schema->hasTable('bank_accounts')) return false;
            foreach (['id','school_id','account_name','account_number','bank_name','account_type','currency','opening_balance','opening_balance_date','is_active','is_default','notes','created_at','updated_at','deleted_at'] as $column) {
                if (!$schema->hasColumn('bank_accounts', $column)) return false;
            }
            $indexes = collect($schema->getIndexes('bank_accounts'));
            return $indexes->contains(fn (array $index): bool => ($index['columns'] ?? []) === ['school_id'])
                && $indexes->contains(fn (array $index): bool => ($index['columns'] ?? []) === ['is_active']);
        };
        if (Schema::connection('school')->hasTable('bank_accounts')) {
            if (!$complete()) throw new RuntimeException('bank_accounts exists with a partial schema; refusing to record migration success.');
            return;
        }

        Schema::connection('school')->create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('account_name', 255);
            $table->string('account_number', 100)->nullable();
            $table->string('bank_name', 255)->nullable();
            $table->string('account_type', 50)->default('bank')->comment('cash, bank, mobile_wallet');
            $table->string('currency', 3)->default('MMK');
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('school_id');
            $table->index('is_active');
        });
        if (!$complete()) throw new RuntimeException('bank_accounts creation failed exact schema verification.');
    }

    public function down(): void
    {
        Schema::connection('school')->dropIfExists('bank_accounts');
    }
};
