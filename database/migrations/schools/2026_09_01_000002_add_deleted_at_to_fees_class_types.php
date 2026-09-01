<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add only lifecycle state. Existing fee templates and every confirmed
     * assignment snapshot remain byte-for-byte untouched.
     */
    public function up(): void
    {
        $schema = Schema::connection('school');

        if (!$schema->hasTable('fees_class_types') || $schema->hasColumn('fees_class_types', 'deleted_at')) {
            return;
        }

        $schema->table('fees_class_types', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('school');

        if (!$schema->hasTable('fees_class_types') || !$schema->hasColumn('fees_class_types', 'deleted_at')) {
            return;
        }

        $schema->table('fees_class_types', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
