<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dingtalk_bindings', function (Blueprint $table) {
            $table->id();
            $table->string('dingtalk_open_id')->unique();
            $table->string('dingtalk_union_id')->nullable()->index();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('school_code')->nullable()->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('dingtalk_nick')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            // 同一 eSchool 用户不能被多个钉钉账号重复绑定
            $table->unique(['school_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dingtalk_bindings');
    }
};
