<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('school')->create('school_record_lifecycle_audits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('subject_type', 80);
            $table->unsignedBigInteger('subject_id');
            $table->uuid('subject_uuid')->nullable();
            $table->string('action', 32);
            $table->text('reason');
            $table->unsignedBigInteger('actor_user_id');
            $table->uuid('actor_user_uuid')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id'], 'school_record_lifecycle_subject_index');
            $table->index(['actor_user_id', 'created_at'], 'school_record_lifecycle_actor_index');
            $table->index(['action', 'created_at'], 'school_record_lifecycle_action_index');
        });
    }

    public function down(): void
    {
        Schema::connection('school')->dropIfExists('school_record_lifecycle_audits');
    }
};
