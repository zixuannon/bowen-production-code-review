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
            if (!$schema->hasTable('bank_transfers')) return false;
            foreach (['id','school_id','from_account_id','to_account_id','amount','transfer_date','reference_no','notes','status','created_by','created_at','updated_at','deleted_at'] as $column) {
                if (!$schema->hasColumn('bank_transfers', $column)) return false;
            }
            $indexes = collect($schema->getIndexes('bank_transfers'));
            foreach ([['school_id'],['from_account_id'],['to_account_id'],['transfer_date'],['status']] as $columns) {
                if (!$indexes->contains(fn (array $index): bool => ($index['columns'] ?? []) === $columns)) return false;
            }
            return true;
        };
        if (Schema::connection('school')->hasTable('bank_transfers')) {
            if (!$complete()) throw new RuntimeException('bank_transfers exists with a partial schema; refusing to record migration success.');
            return;
        }

        Schema::connection('school')->create('bank_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('from_account_id');
            $table->unsignedBigInteger('to_account_id');
            $table->decimal('amount', 12, 2);
            $table->date('transfer_date');
            $table->string('reference_no', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('completed')->comment('completed, cancelled');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('school_id');
            $table->index('from_account_id');
            $table->index('to_account_id');
            $table->index('transfer_date');
            $table->index('status');
        });
        if (!$complete()) throw new RuntimeException('bank_transfers creation failed exact schema verification.');
    }

    public function down(): void
    {
        Schema::connection('school')->dropIfExists('bank_transfers');
    }
};
