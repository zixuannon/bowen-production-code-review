<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('school')->create('student_import_identities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            // Text identity: spreadsheet codes such as 00125 are never numeric.
            $table->string('student_code', 100);
            $table->unsignedBigInteger('student_id')->unique();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['school_id', 'student_code'], 'student_import_identity_school_code_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('school')->dropIfExists('student_import_identities');
    }
};
