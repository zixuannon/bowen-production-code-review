<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('school')->create('finance_operating_audits', function (Blueprint $table): void {
            $table->id();
            // These central ids intentionally have no tenant-local foreign
            // keys. The source record and audit live together in this tenant
            // transaction; central configuration stays in the main database.
            $table->unsignedBigInteger('central_actor_id')->index();
            $table->unsignedBigInteger('finance_group_id')->index();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('tenant_user_id')->index();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('action', 80);
            $table->string('request_source', 80);
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'action'], 'finance_operating_audit_source_action_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('school')->dropIfExists('finance_operating_audits');
    }
};
