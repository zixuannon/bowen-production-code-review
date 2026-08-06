<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'school';

    /**
     * Create fee_import_batches on the SCHOOL connection.
     *
     * Unified table for preview tokens and import audit trails.
     */
    public function up(): void
    {
        if (!Schema::hasTable('fee_import_batches')) {
            Schema::create('fee_import_batches', function (Blueprint $table) {
                $table->id();
                $table->string('token', 64)->unique()->comment('UUID token for preview/confirm');
                $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
                // No FK on imported_by: users table may be in a different DB in multi-DB setups.
                // Application-layer checks ensure integrity.
                $table->unsignedBigInteger('imported_by')->nullable()->comment('User who uploaded');
                $table->string('file_name', 255)->comment('Original uploaded filename');
                $table->string('file_hash', 64)->comment('SHA-256 of uploaded file for dedup');
                $table->json('preview_data')->nullable()->comment('JSON preview rows');
                $table->string('status', 20)->default('pending')->comment('pending | processing | completed | failed');
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('success_rows')->default(0);
                $table->unsignedInteger('duplicate_rows')->default(0);
                $table->unsignedInteger('error_rows')->default(0);
                $table->unsignedInteger('imported_rows')->default(0);
                $table->unsignedInteger('skipped_rows')->default(0);
                $table->timestamp('expired_at')->nullable()->comment('Token expiration');
                $table->timestamp('consumed_at')->nullable()->comment('When batch was confirmed');
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->index(['school_id', 'status']);
                $table->index(['token']);
                $table->index(['imported_by']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_import_batches');
    }
};
