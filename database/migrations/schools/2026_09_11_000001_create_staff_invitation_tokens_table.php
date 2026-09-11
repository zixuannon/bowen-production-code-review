<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('school')->hasTable('staff_invitation_tokens')) {
            throw new RuntimeException('staff_invitation_tokens already exists or is partially provisioned; migration refused.');
        }

        Schema::connection('school')->create('staff_invitation_tokens', function (Blueprint $table): void {
            // One row per identity makes "new invitation invalidates old" a
            // database-enforced invariant even when issuance races.
            $table->string('email')->unique('staff_invitation_tokens_email_unique');
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('school')->dropIfExists('staff_invitation_tokens');
    }
};
