<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions keep one engine while making the two supported scopes explicit:
 * a Group/School-wide definition, or a definition for one Student.  The
 * latter can be created from a trusted School workflow only when that
 * workflow also supplies a specific Fee Item and an auditable reason.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->table('central_finance_promotions', function (Blueprint $table): void {
            $table->unsignedBigInteger('student_profile_id')->nullable()->after('group_id');
            $table->string('scope', 32)->default('general')->after('student_profile_id');
            $table->string('creation_idempotency_key', 64)->nullable()->unique('cf_promotion_creation_idempotency_unique')->after('code');
            $table->string('student_discount_reason', 2000)->nullable()->after('description');
            $table->index(['scope', 'student_profile_id', 'status', 'valid_from'], 'cf_promo_scope_student_status_date_idx');
        });

        Schema::connection('mysql')->table('central_finance_promotion_applications', function (Blueprint $table): void {
            $table->string('promotion_scope_snapshot', 32)->default('general')->after('promotion_id');
            $table->unsignedBigInteger('student_profile_id_snapshot')->nullable()->after('promotion_scope_snapshot');
            $table->unsignedBigInteger('fees_class_type_id_snapshot')->nullable()->after('student_profile_id_snapshot');
            $table->string('applied_by_role_snapshot', 32)->nullable()->after('applied_by');
        });

        Schema::connection('mysql')->table('central_finance_user_school_scopes', function (Blueprint $table): void {
            $table->boolean('can_create_student_specific_discounts')->default(false)->after('can_submit_collections');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('central_finance_promotions', function (Blueprint $table): void {
            $table->dropUnique('cf_promotion_creation_idempotency_unique');
            $table->dropIndex('cf_promo_scope_student_status_date_idx');
            $table->dropColumn(['student_profile_id', 'scope', 'creation_idempotency_key', 'student_discount_reason']);
        });
        Schema::connection('mysql')->table('central_finance_promotion_applications', function (Blueprint $table): void {
            $table->dropColumn(['promotion_scope_snapshot', 'student_profile_id_snapshot', 'fees_class_type_id_snapshot', 'applied_by_role_snapshot']);
        });
        Schema::connection('mysql')->table('central_finance_user_school_scopes', function (Blueprint $table): void {
            $table->dropColumn('can_create_student_specific_discounts');
        });
    }
};
