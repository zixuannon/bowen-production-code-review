<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->table('users', function (Blueprint $table): void {
            if (!Schema::connection('mysql')->hasColumn('users', 'central_finance_principal_type')) {
                $table->string('central_finance_principal_type', 40)->default('central_user')->after('school_id');
            }
        });

        Schema::connection('mysql')->create('central_finance_school_staff_identities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identity_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->uuid('tenant_user_uuid');
            // This is an opaque Central Finance principal, never a login account.
            $table->unsignedBigInteger('central_user_id')->unique();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['school_id', 'tenant_user_uuid'], 'cfsfi_school_tenant_uuid_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_school_staff_identities');
        if (Schema::connection('mysql')->hasColumn('users', 'central_finance_principal_type')) {
            Schema::connection('mysql')->table('users', fn (Blueprint $table) => $table->dropColumn('central_finance_principal_type'));
        }
    }
};
