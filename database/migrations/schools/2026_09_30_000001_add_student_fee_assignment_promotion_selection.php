<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection('school');
        if (!$schema->hasTable('student_fee_assignment_items')) {
            throw new RuntimeException('Student Fee Setup Promotion selection requires student_fee_assignment_items.');
        }

        if (!$schema->hasColumn('student_fee_assignment_items', 'selected_promotion_id')) {
            $schema->table('student_fee_assignment_items', function (Blueprint $table): void {
                // This is a Central Promotion identifier, deliberately not a
                // cross-database FK.  The immutable Central application owns
                // the financial snapshot and audit trail after confirmation.
                $table->unsignedBigInteger('selected_promotion_id')->nullable()->after('quantity_snapshot');
                $table->index('selected_promotion_id', 'sfa_item_selected_promotion_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('school');
        if ($schema->hasTable('student_fee_assignment_items') && $schema->hasColumn('student_fee_assignment_items', 'selected_promotion_id')) {
            $schema->table('student_fee_assignment_items', function (Blueprint $table): void {
                $table->dropIndex('sfa_item_selected_promotion_idx');
                $table->dropColumn('selected_promotion_id');
            });
        }
    }
};
