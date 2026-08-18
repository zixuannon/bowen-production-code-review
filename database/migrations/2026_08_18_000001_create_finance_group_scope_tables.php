<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Group finance configuration belongs only to the trusted central control
     * plane. It must never be created in a tenant database merely because a
     * tenant connection happens to be the request default.
     */
    public function up(): void
    {
        Schema::connection('mysql')->create('finance_groups', function (Blueprint $table): void {
            $table->id();
            // The business code is optional until an administrator configures
            // a draft Group. A unique index still protects every configured
            // non-null code without hard-coding a Bowen value.
            $table->string('code', 64)->nullable()->unique();
            $table->string('name', 191);
            $table->string('status', 32)->default('draft');
            $table->string('reporting_currency', 3)->default('MMK');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->timestamps();
        });

        Schema::connection('mysql')->create('finance_group_schools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('finance_groups')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('status', 32)->default('active');
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->timestamps();

            // A membership is updated/revoked in place. This prevents a
            // historical duplicate from becoming a second active authority.
            $table->unique(['group_id', 'school_id']);
        });

        Schema::connection('mysql')->create('finance_group_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('finance_groups')->cascadeOnDelete();
            $table->foreignId('central_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['group_id', 'central_user_id']);
        });

        Schema::connection('mysql')->create('finance_group_user_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_user_id')->constrained('finance_group_users')->cascadeOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('scope_type', 32);
            $table->string('capability', 64);
            // `school_id` is nullable for Group/HQ scope. This normalized key
            // keeps the uniqueness guarantee valid on MySQL, where a unique
            // index otherwise permits multiple NULL values.
            $table->string('scope_key', 80);
            $table->string('status', 32)->default('active');
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->timestamps();

            $table->unique(['group_user_id', 'scope_key', 'capability']);
        });

        Schema::connection('mysql')->create('finance_group_user_tenant_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_user_id')->constrained('finance_group_users')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            // This intentionally has no foreign key: the user exists in the
            // separately selected tenant database and is validated by the
            // service before this mapping is stored.
            $table->unsignedBigInteger('tenant_user_id');
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['group_user_id', 'school_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('finance_group_user_tenant_identities');
        Schema::connection('mysql')->dropIfExists('finance_group_user_scopes');
        Schema::connection('mysql')->dropIfExists('finance_group_users');
        Schema::connection('mysql')->dropIfExists('finance_group_schools');
        Schema::connection('mysql')->dropIfExists('finance_groups');
    }
};
