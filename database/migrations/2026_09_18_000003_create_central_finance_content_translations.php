<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection('mysql');
        if ($schema->hasTable('central_finance_content_translations')) return;
        $schema->create('central_finance_content_translations', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 80);
            $table->unsignedBigInteger('subject_id');
            // 0 denotes a Central/Group record. Tenant Fee IDs are unique
            // only inside a School DB, so their real School ID is required.
            $table->unsignedBigInteger('school_id')->default(0);
            $table->string('locale', 8);
            $table->text('translated_value');
            $table->string('source_hash', 64);
            $table->string('status', 16)->default('current'); // current | stale
            $table->unsignedBigInteger('updated_by');
            $table->string('reason', 1000);
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id', 'school_id', 'locale'], 'cfct_subject_locale_unique');
            $table->index(['subject_type', 'school_id', 'status'], 'cfct_subject_scope_status');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Business-content translations are audited records and are forward-only.');
    }
};
