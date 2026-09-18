<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection('mysql');
        if ($schema->hasTable('central_finance_pre_go_live_reset_manifests')) {
            return;
        }

        $schema->create('central_finance_pre_go_live_reset_manifests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reset_uuid')->unique();
            $table->unsignedBigInteger('actor_id');
            $table->string('approval_reference', 120);
            $table->string('reason', 1000);
            $table->json('pre_reset_counts');
            $table->json('deleted_counts');
            $table->string('protected_hash_before', 64);
            $table->string('protected_hash_after', 64);
            $table->timestamp('executed_at');
            $table->timestamps();
            $table->index(['actor_id', 'executed_at'], 'cf_reset_manifest_actor_date');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Pre-go-live reset manifests are immutable audit records.');
    }
};
