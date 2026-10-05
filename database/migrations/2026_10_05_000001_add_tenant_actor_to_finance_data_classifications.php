<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLES = [
        'central_finance_data_classifications' => 'classified_by',
        'central_finance_data_classification_audits' => 'actor_id',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name => $legacyActor) {
            Schema::connection('mysql')->table($name, function (Blueprint $table) use ($legacyActor): void {
                // Keep the existing Central users foreign key; tenant IDs never enter it.
                $table->unsignedBigInteger($legacyActor)->nullable()->change();
                $table->string('actor_scope', 20)->nullable();
                $table->unsignedBigInteger('actor_school_id')->nullable();
                $table->unsignedBigInteger('actor_tenant_user_id')->nullable();
                if ($legacyActor === 'actor_id') {
                    $table->string('action', 64)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Check BOTH tables before any DDL. Once new attribution is used, preserve
        // its history and forward-fix instead of dropping audit information.
        foreach (self::TABLES as $name => $legacyActor) {
            $query = DB::connection('mysql')->table($name)
                ->whereNull($legacyActor)
                ->orWhereNotNull('actor_scope')
                ->orWhereNotNull('actor_school_id')
                ->orWhereNotNull('actor_tenant_user_id');
            if ($legacyActor === 'actor_id') {
                $query->orWhereNotNull('action');
            }
            if ($query->exists()) {
                throw new RuntimeException('Classification actor history is in use; preserve the schema and forward-fix.');
            }
        }

        $connection = DB::connection('mysql');
        $checks = $connection->getDriverName() === 'mysql'
            ? (int) $connection->selectOne('SELECT @@SESSION.foreign_key_checks AS checks')->checks : null;
        try {
            // MySQL requires this session-only switch for nullable -> NOT NULL.
            // The FK definitions are never dropped, and no data is rewritten.
            if ($checks !== null) {
                $connection->statement('SET SESSION FOREIGN_KEY_CHECKS=0');
            }
            foreach (self::TABLES as $name => $legacyActor) {
                Schema::connection('mysql')->table($name, function (Blueprint $table) use ($legacyActor): void {
                    $table->unsignedBigInteger($legacyActor)->nullable(false)->change();
                    $columns = ['actor_scope', 'actor_school_id', 'actor_tenant_user_id'];
                    if ($legacyActor === 'actor_id') {
                        $columns[] = 'action';
                    }
                    $table->dropColumn($columns);
                });
            }
        } finally {
            if ($checks !== null) {
                $connection->statement('SET SESSION FOREIGN_KEY_CHECKS='.$checks);
            }
        }
    }
};
