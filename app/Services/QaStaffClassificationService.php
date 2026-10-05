<?php

namespace App\Services;

use App\Exceptions\StaffClassificationException;
use App\Models\CentralFinanceDataClassification;
use App\Models\CentralFinanceUser;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/** Staff metadata only. Never changes role grants, credentials, or money. */
final class QaStaffClassificationService
{
    public function inherit(User $target, User $actor): void
    {
        $this->guarded(function () use ($target, $actor): void {
            [$db, $central, $schoolId] = $this->context($target);
            if (!$this->isQa($db, $central, $schoolId)) return;

            $trusted = app(TrustedTenantContextService::class)->trustedTenantUserForCurrentRequest(request());
            if (!$trusted || (int)$trusted->getKey() !== (int)$actor->getKey()
                || (int)$actor->getRawOriginal('school_id') !== $schoolId
                || (int)$trusted->getRawOriginal('school_id') !== $schoolId
                || (int)Auth::id() !== (int)$actor->getKey()
                || $actor instanceof CentralFinanceUser
                || !$db->table('users')->where('id', $actor->getKey())->where('school_id', $schoolId)
                    ->where('email', $actor->getRawOriginal('email'))->where('status', 1)->whereNull('deleted_at')->exists()) {
                throw new StaffClassificationException('Untrusted tenant Staff classification actor.');
            }
            $this->writeMissing($db, $central, $schoolId, (int)$target->getKey(), [
                'actor_scope'=>'tenant', 'actor_school_id'=>$schoolId,
                'actor_tenant_user_id'=>(int)$actor->getKey(), 'central_actor_id'=>null,
            ], 'staff_created', 'New Staff inherited classification from the trusted QA/Test School.');
        });
    }

    /** Explicit maintenance callers supply a real authorized Central actor, never a numeric tenant impersonation. */
    public function reconcileMissing(User $target, CentralFinanceUser $actor, string $reason): void
    {
        $this->writeCentral($target, $actor, $reason, 'staff_classification_reconciled');
    }

    public function inheritCentral(User $target, CentralFinanceUser $actor): void
    {
        $this->writeCentral($target, $actor, 'New Staff inherited the trusted QA/Test School classification during Central provisioning.', 'staff_created');
    }

    private function writeCentral(User $target, CentralFinanceUser $actor, string $reason, string $action): void
    {
        $this->guarded(function () use ($target, $actor, $reason, $action): void {
            [$db, $central, $schoolId] = $this->context($target);
            if (!$this->isQa($db, $central, $schoolId)) {
                if ($action === 'staff_created') return;
                throw new StaffClassificationException('Official Staff reconciliation is prohibited.');
            }
            if (trim($reason) === '' || !$db->table($central.'.users')->where('id', $actor->getKey())
                ->where('email', $actor->getRawOriginal('email'))->whereNull('school_id')->whereNull('deleted_at')->where('status',1)->exists()
                || !$db->table($central.'.model_has_roles as pivots')
                    ->join($central.'.roles as roles','roles.id','=','pivots.role_id')
                    ->where('pivots.model_id',$actor->getKey())->where('pivots.model_type',User::class)
                    ->whereNull('roles.school_id')->whereIn('roles.name',['Head Finance','Super Admin'])->exists()) {
                throw new StaffClassificationException('Authorized Central reconciliation actor is required.');
            }
            $this->writeMissing($db, $central, $schoolId, (int)$target->getKey(), [
                'actor_scope'=>'central', 'actor_school_id'=>null,
                'actor_tenant_user_id'=>null, 'central_actor_id'=>(int)$actor->getKey(),
            ], $action, trim($reason));
        });
    }

    private function guarded(callable $operation): void
    {
        try { $operation(); }
        catch (StaffClassificationException $e) { throw $e; }
        catch (Throwable $e) {
            // A typed failure cannot enter legacy mail-error commit heuristics.
            throw new StaffClassificationException('Required Staff classification could not be recorded.', 0, $e);
        }
    }

    /** Never purge/reconnect either connection, especially while creation is uncommitted. */
    private function context(User $target): array
    {
        $db = DB::connection('school');
        $central = (string) DB::connection('mysql')->getDatabaseName();
        $schoolId = (int)$target->getRawOriginal('school_id');
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $central) || DB::getDefaultConnection() !== 'school'
            || !$target->exists || $schoolId <= 0) {
            throw new StaffClassificationException('Explicit tenant Staff context is required.');
        }
        // Qualified tables must identify the very same registry/server used by Central.
        foreach (['driver','host','port','unix_socket'] as $key) {
            if ((string)$db->getConfig($key) !== (string)DB::connection('mysql')->getConfig($key)) {
                throw new StaffClassificationException('Atomic Staff classification requires co-located databases.');
            }
        }
        if ($db->getDriverName() !== 'mysql') {
            throw new StaffClassificationException('Atomic Staff classification requires MySQL/InnoDB.');
        }
        $live = $db->selectOne('SELECT DATABASE() AS db, @@hostname AS host, @@port AS port, @@server_id AS server_id');
        $centralLive = DB::connection('mysql')->selectOne('SELECT DATABASE() AS db, @@hostname AS host, @@port AS port, @@server_id AS server_id');
        if ($live->db !== $db->getDatabaseName() || $centralLive->db !== $central
            || [$live->host,(string)$live->port,(string)$live->server_id] !== [$centralLive->host,(string)$centralLive->port,(string)$centralLive->server_id]) {
            throw new StaffClassificationException('Live Staff classification database identity mismatch.');
        }
        $school = $db->table($central.'.schools')->where('id',$schoolId)->where('installed',1)->where('status',1)->whereNull('deleted_at')->first();
        if (!$school || $school->database_name !== $db->getDatabaseName()
            || config('database.connections.school.database') !== $db->getDatabaseName()) {
            throw new StaffClassificationException('Staff classification tenant registry mismatch.');
        }
        return [$db, $central, $schoolId];
    }

    private function isQa(Connection $db, string $central, int $schoolId): bool
    {
        $classification = $db->table($central.'.'.CentralFinanceDataIsolationService::TABLE)
            ->where('subject_scope','central')->where('subject_type','school')->where('subject_id',$schoolId)->value('classification') ?? 'production';
        $code = $db->table($central.'.schools')->where('id',$schoolId)->value('code');
        if ($classification === 'archived' || ($code === 'MMBOWEN01' && $classification !== 'qa_test')) {
            throw new StaffClassificationException('Canonical QA School classification is inconsistent.');
        }
        return $classification === 'qa_test';
    }

    private function writeMissing(Connection $db, string $central, int $schoolId, int $userId, array $actor, string $action, string $reason): void
    {
        if ($db->transactionLevel() < 1) {
            throw new StaffClassificationException('Staff classification must share the creation transaction.');
        }
        // Atomicity is not provided by two separately committed connections.
        // Use qualified Central tables on the existing tenant PDO/InnoDB transaction.
        $tables = [
            $db->getDatabaseName()=>['users','staffs','model_has_roles'],
            $central=>[CentralFinanceDataIsolationService::TABLE, CentralFinanceDataIsolationService::AUDIT_TABLE],
        ];
        foreach ($tables as $database=>$names) {
            $engines = $db->table('information_schema.TABLES')->where('TABLE_SCHEMA',$database)->whereIn('TABLE_NAME',$names)->pluck('ENGINE','TABLE_NAME');
            if ($engines->count() !== count($names) || $engines->contains(fn ($engine)=>strtolower((string)$engine)!=='innodb')) {
                throw new StaffClassificationException('Staff classification tables must all be transactional.');
            }
        }
        foreach ([CentralFinanceDataIsolationService::TABLE, CentralFinanceDataIsolationService::AUDIT_TABLE] as $table) {
            if (!Schema::connection('mysql')->hasColumns($table,['actor_scope','actor_school_id','actor_tenant_user_id'])) {
                throw new StaffClassificationException('Staff classification actor migration is required.');
            }
        }
        $target = $db->table('users')->where('id',$userId)->where('school_id',$schoolId)->lockForUpdate()->first();
        if (!$target || !$db->table('staffs')->where('user_id',$userId)->exists()
            || !$db->table('model_has_roles as pivots')->join('roles','roles.id','=','pivots.role_id')
                ->where('pivots.model_id',$userId)->where('pivots.model_type',User::class)
                ->where('roles.school_id',$schoolId)->exists()) {
            throw new StaffClassificationException('Canonical Staff target is missing.');
        }
        $identity = ['school_id'=>$schoolId,'subject_scope'=>'tenant:'.$schoolId,'subject_type'=>'staff','subject_id'=>$userId];
        $query = $db->table($central.'.'.CentralFinanceDataIsolationService::TABLE);
        $existing = (clone $query)->where($identity)->lockForUpdate()->first();
        if ($existing) {
            if ($existing->classification !== 'qa_test') throw new StaffClassificationException('Existing Staff classification cannot be overwritten.');
            return;
        }
        $actorFields = array_diff_key($actor,['central_actor_id'=>true]);
        $classificationId = $query->insertGetId(array_merge($identity, $actorFields, [
            'classification_uuid'=>(string)Str::uuid(), 'classification'=>CentralFinanceDataClassification::QA_TEST,
            'classified_by'=>$actor['central_actor_id'], 'reason'=>$reason, 'created_at'=>now(), 'updated_at'=>now(),
        ]));
        $db->table($central.'.'.CentralFinanceDataIsolationService::AUDIT_TABLE)->insert(array_merge($identity, $actorFields, [
            'audit_uuid'=>(string)Str::uuid(), 'classification_id'=>$classificationId,
            'before_classification'=>null, 'after_classification'=>'qa_test',
            'actor_id'=>$actor['central_actor_id'], 'action'=>$action, 'reason'=>$reason, 'created_at'=>now(),
        ]));
    }
}
