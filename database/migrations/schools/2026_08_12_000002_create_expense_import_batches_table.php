<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'school';

    public function up(): void
    {
        if (!Schema::hasColumn('expenses', 'payment_method')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->string('payment_method', 100)->nullable()->after('bank_account_id');
            });
        }

        if (!Schema::hasTable('expense_import_batches')) {
            Schema::create('expense_import_batches', function (Blueprint $table) {
                $table->id();
                $table->uuid('token')->unique();
                $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
                $table->unsignedBigInteger('imported_by');
                $table->string('file_name', 255);
                $table->string('file_hash', 64);
                $table->json('preview_data')->nullable();
                $table->json('imported_expense_ids')->nullable();
                $table->string('status', 20)->default('pending');
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('valid_rows')->default(0);
                $table->unsignedInteger('error_rows')->default(0);
                $table->unsignedInteger('imported_rows')->default(0);
                $table->timestamp('expired_at')->nullable();
                $table->timestamp('consumed_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                // First duplicate barrier: a same source file is one batch only.
                $table->unique(['school_id', 'file_hash'], 'expense_import_batches_school_file_hash_unique');
                $table->index(['school_id', 'status']);
                $table->index('imported_by');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_import_batches');
        if (Schema::hasTable('expenses') && Schema::hasColumn('expenses', 'payment_method')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropColumn('payment_method');
            });
        }
    }
};
