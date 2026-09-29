<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection('school');
        foreach (['fees_class_types', 'student_fee_assignment_items'] as $table) {
            if (! $schema->hasTable($table)) throw new RuntimeException("Finance Collection V2 requires tenant [{$table}].");
        }
        if (! $schema->hasColumn('fees_class_types', 'quantity_enabled')) {
            $schema->table('fees_class_types', function (Blueprint $table): void {
                $table->boolean('quantity_enabled')->default(false)->after('optional');
            });
        }
        if (! $schema->hasColumn('student_fee_assignment_items', 'unit_price_snapshot')) {
            $schema->table('student_fee_assignment_items', function (Blueprint $table): void {
                $table->decimal('unit_price_snapshot', 20, 4)->nullable()->after('amount_snapshot');
                $table->unsignedInteger('quantity_snapshot')->default(1)->after('unit_price_snapshot');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('school');
        if ($schema->hasColumn('student_fee_assignment_items', 'quantity_snapshot')) {
            $schema->table('student_fee_assignment_items', function (Blueprint $table): void {
                $table->dropColumn(['unit_price_snapshot', 'quantity_snapshot']);
            });
        }
        if ($schema->hasColumn('fees_class_types', 'quantity_enabled')) {
            $schema->table('fees_class_types', fn (Blueprint $table) => $table->dropColumn('quantity_enabled'));
        }
    }
};
