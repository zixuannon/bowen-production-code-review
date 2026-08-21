<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->create('central_finance_school_cutovers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id')->unique();
            $table->string('status', 16)->default('legacy');
            $table->timestamp('cutover_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'school_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_school_cutovers');
    }
};
