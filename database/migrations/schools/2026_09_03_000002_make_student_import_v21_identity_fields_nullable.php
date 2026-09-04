<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Student Import V2.1 accepts a single cultural full name and an optional
     * Guardian email. Existing user records are intentionally not rewritten.
     */
    public function up(): void
    {
        $schema = Schema::connection('school');
        if (!$schema->hasTable('users') || !$schema->hasTable('students')) {
            throw new LogicException('Student Import V2.1 requires existing users and students tables.');
        }

        $schema->table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->string('last_name', 128)->nullable()->change();
        });

        if (!$schema->hasColumn('students', 'notes')) {
            $schema->table('students', function (Blueprint $table): void {
                $table->text('notes')->nullable()->after('admission_date');
            });
        }
    }

    /**
     * This is a forward-only compatibility migration. Reintroducing NOT NULL
     * or dropping notes after V2.1 records exist would destroy valid data.
     */
    public function down(): void
    {
        throw new LogicException('Student Import V2.1 nullable identity migration is forward-only.');
    }
};
