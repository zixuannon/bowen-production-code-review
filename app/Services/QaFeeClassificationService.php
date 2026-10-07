<?php

namespace App\Services;

use App\Models\CentralFinanceUser;
use App\Models\FinanceGroupUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/** Creation-only metadata, committed atomically with its trusted tenant record. */
final class QaFeeClassificationService
{
    private const TABLES = [
        'fee' => 'fees', 'fee_type' => 'fees_types', 'fee_item' => 'fees_class_types',
        'student_fee_assignment' => 'student_fee_assignments',
        'student_fee_assignment_item' => 'student_fee_assignment_items',
    ];

    public function inheritCreated(string $subjectType, Model $target, User $actor, ?CentralFinanceUser $principal = null): void
    {
        $db = DB::connection('school');
        $centralDb = DB::connection('mysql');
        $central = (string) $centralDb->getDatabaseName();
        $schoolId = (int) $target->getRawOriginal('school_id');
        if ($subjectType === 'student_fee_assignment_item') {
            $schoolId = (int) $db->table('student_fee_assignments')->where('id', $target->getRawOriginal('student_fee_assignment_id'))->whereNull('deleted_at')->value('school_id');
        }
        if (!isset(self::TABLES[$subjectType]) || $target->getTable() !== self::TABLES[$subjectType]
            || !$target->exists || !$target->wasRecentlyCreated || $target->getConnection()->getPdo() !== $db->getPdo()
            || DB::getDefaultConnection() !== 'school' || $db->transactionLevel() < 1
            || !preg_match('/^[A-Za-z0-9_]+$/D', $central) || $schoolId <= 0) {
            throw new RuntimeException('Trusted new Fee creation and its active tenant transaction are required.');
        }
        foreach (['driver', 'host', 'port', 'unix_socket'] as $key) {
            if ((string) $db->getConfig($key) !== (string) $centralDb->getConfig($key)) {
                throw new RuntimeException('Atomic Fee classification requires co-located databases.');
            }
        }
        if ($db->getDriverName() !== 'mysql') {
            throw new RuntimeException('Atomic Fee classification requires MySQL/InnoDB.');
        }
        $live = $db->selectOne('SELECT DATABASE() AS db, @@hostname AS host, @@port AS port, @@server_id AS server_id');
        $centralLive = $centralDb->selectOne('SELECT DATABASE() AS db, @@hostname AS host, @@port AS port, @@server_id AS server_id');
        if ($live->db !== $db->getDatabaseName() || $centralLive->db !== $central
            || [$live->host, (string) $live->port, (string) $live->server_id] !== [$centralLive->host, (string) $centralLive->port, (string) $centralLive->server_id]) {
            throw new RuntimeException('Live Fee classification database identity mismatch.');
        }
        $school = $db->table($central.'.schools')->where('id', $schoolId)->where('installed', 1)
            ->where('status', 1)->whereNull('deleted_at')->lockForUpdate()->first();
        if (!$school || $school->database_name !== $db->getDatabaseName()
            || config('database.connections.school.database') !== $db->getDatabaseName()) {
            throw new RuntimeException('Fee classification tenant registry mismatch.');
        }
        $classification = $db->table($central.'.'.CentralFinanceDataIsolationService::TABLE)
            ->where('subject_scope', 'central')->where('subject_type', 'school')->where('subject_id', $schoolId)
            ->lockForUpdate()->value('classification') ?? 'production';
        if (!in_array($classification, ['production', 'qa_test'], true)
            || (app(CentralFinanceQaSchoolIdentity::class)->isSchool($schoolId) && $classification !== 'qa_test')) {
            throw new RuntimeException('Canonical School classification is inconsistent.');
        }
        $targetQuery = $db->table(self::TABLES[$subjectType])->where('id', $target->id);
        if ($subjectType !== 'student_fee_assignment_item') {
            $targetQuery->where('school_id', $schoolId);
            if (Schema::connection('school')->hasColumn(self::TABLES[$subjectType], 'deleted_at')) $targetQuery->whereNull('deleted_at');
        }
        $targetRow = $targetQuery->lockForUpdate()->first();
        if (!$targetRow) {
            throw new RuntimeException('Canonical Fee classification target is missing.');
        }
        if ($subjectType === 'fee_item') {
            foreach (['fee' => ['fees', $targetRow->fees_id], 'fee_type' => ['fees_types', $targetRow->fees_type_id]] as $type => [$table, $id]) {
                $parentClass = $db->table($central.'.'.CentralFinanceDataIsolationService::TABLE)
                    ->where('subject_scope', 'tenant:'.$schoolId)->where('subject_type', $type)->where('subject_id', $id)
                    ->lockForUpdate()->value('classification') ?? 'production';
                if ($parentClass !== $classification || !$db->table($table)->where('id', $id)->where('school_id', $schoolId)->whereNull('deleted_at')->exists()) {
                    throw new RuntimeException('Fee Item requires active parents in the same School workflow.');
                }
            }
        }
        foreach ([$db->getDatabaseName() => [self::TABLES[$subjectType]], $central => [CentralFinanceDataIsolationService::TABLE, CentralFinanceDataIsolationService::AUDIT_TABLE]] as $database => $tables) {
            $engines = $db->table('information_schema.TABLES')->where('TABLE_SCHEMA', $database)->whereIn('TABLE_NAME', $tables)->pluck('ENGINE');
            if ($engines->count() !== count($tables) || $engines->contains(fn ($engine) => strtolower((string) $engine) !== 'innodb')) {
                throw new RuntimeException('Fee classification tables must all be transactional.');
            }
        }
        foreach ([CentralFinanceDataIsolationService::TABLE, CentralFinanceDataIsolationService::AUDIT_TABLE] as $table) {
            if (!Schema::connection('mysql')->hasColumns($table, ['actor_scope', 'actor_school_id', 'actor_tenant_user_id'])) {
                throw new RuntimeException('Fee classification actor migration is required.');
            }
        }
        $identity = ['school_id' => $schoolId, 'subject_scope' => 'tenant:'.$schoolId, 'subject_type' => $subjectType, 'subject_id' => $target->id];
        $query = $db->table($central.'.'.CentralFinanceDataIsolationService::TABLE);
        $existing = (clone $query)->where($identity)->lockForUpdate()->first();
        $centralActorId = null;
        if ($principal !== null) {
            $authenticated = Auth::user();
            $schoolStaff = $principal->getRawOriginal('central_finance_principal_type') === CentralFinanceSchoolStaffIdentityService::PRINCIPAL_TYPE;
            $principalQuery = $db->table($central.'.users')->where('id', $principal->id)
                ->where('email', $principal->getRawOriginal('email'))->where('school_id', $principal->getRawOriginal('school_id'))->whereNull('deleted_at');
            // School Staff principals deliberately cannot log in (status=0).
            // Their authority comes from an active UUID mapping and tenant
            // Staff permission, never from enabling that Central login.
            if ($schoolStaff) {
                $principalQuery->where('central_finance_principal_type', CentralFinanceSchoolStaffIdentityService::PRINCIPAL_TYPE);
            } else {
                $principalQuery->whereNull('school_id')->where('status', 1);
            }
            $authenticatedPrincipal = $authenticated
                && (int) $authenticated->id === (int) $principal->id
                && $authenticated->getRawOriginal('email') === $principal->getRawOriginal('email')
                && $authenticated->getRawOriginal('school_id') === $principal->getRawOriginal('school_id')
                && ($authenticated instanceof CentralFinanceUser || $principal->getRawOriginal('school_id') === null);
            if (!$authenticatedPrincipal && $schoolStaff) {
                $trusted = app(TrustedTenantContextService::class)->trustedTenantUserForCurrentRequest(request());
                $context = session(CentralFinanceSchoolStaffIdentityService::SESSION_KEY);
                $resolved = is_array($context) ? app(CentralFinanceSchoolStaffIdentityService::class)->resolveTrustedSession($context) : null;
                $authenticatedPrincipal = $trusted && !$trusted instanceof CentralFinanceUser
                    && (int) $trusted->id === (int) $actor->id
                    && (int) $trusted->getRawOriginal('school_id') === $schoolId
                    && $trusted->getRawOriginal('email') === $actor->getRawOriginal('email')
                    && (string) $actor->getRawOriginal('central_finance_source_uuid') !== ''
                    && hash_equals((string) ($context['user_uuid'] ?? ''), (string) $actor->getRawOriginal('central_finance_source_uuid'))
                    && $resolved && (int) $resolved->id === (int) $principal->id;
            }
            if (!in_array($subjectType, ['student_fee_assignment', 'student_fee_assignment_item'], true)
                || !$authenticated || $actor instanceof CentralFinanceUser
                || !$authenticatedPrincipal || !$principalQuery->exists()
                || !$db->table('users')->where('id', $actor->id)->where('school_id', $schoolId)
                    ->where('email', $actor->getRawOriginal('email'))->where('status', 1)->whereNull('deleted_at')->exists()) {
                throw new RuntimeException('Authorized Central Fee assignment actor is required.');
            }
            $workspace = app(CentralFinanceWorkspaceService::class);
            try {
                $workspace->assertCanOperateSchool($principal, $schoolId);
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                $workspace->assertCanSubmitCollectionsSchool($principal, $schoolId);
            }
            if ($workspace->isSchoolStaffPrincipal($principal)) {
                $mapped = (int) $principal->getRawOriginal('school_id') === $schoolId
                    && $actor->getRawOriginal('central_finance_source_uuid')
                    && app(CentralFinanceSchoolStaffIdentityService::class)->isActivePrincipal($principal)
                    && $db->table('users')->where('id', $actor->id)->where('school_id', $schoolId)
                        ->where('central_finance_source_uuid', $actor->getRawOriginal('central_finance_source_uuid'))->exists()
                    && $db->table($central.'.central_finance_school_staff_identities')
                        ->where('central_user_id', $principal->id)->where('school_id', $schoolId)->where('status', 'active')
                        ->where('tenant_user_uuid', $actor->getRawOriginal('central_finance_source_uuid'))->exists();
                $roles = $db->table('model_has_roles as grants')->join('roles', 'roles.id', '=', 'grants.role_id')
                    ->where('grants.model_type', User::class)->where('grants.model_id', $actor->id)
                    ->where('roles.school_id', $schoolId)->where('roles.guard_name', 'web');
                $eligibleRole = (clone $roles)->whereIn('roles.name', ['Front Desk', 'Admissions & Collection', 'Front Desk / Admissions & Collection', 'School Accountant', 'Accountant', 'Cashier'])->exists();
                $feePermission = (clone $roles)->join('role_has_permissions as rp', 'rp.role_id', '=', 'roles.id')
                    ->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('p.name', 'fees-create')->where('p.guard_name', 'web')->exists();
                if (!$mapped || !$eligibleRole || !$feePermission) {
                    throw new \Illuminate\Auth\Access\AuthorizationException('The mapped School Staff requires an active Fee Setup role and permission.');
                }
            } else {
                $mapped = FinanceGroupUser::on('mysql')->where('central_user_id', $principal->id)->where('status', 'active')->get()
                    ->contains(fn (FinanceGroupUser $groupUser): bool =>
                        app(FinanceGroupScopeService::class)->canAccessSchool($groupUser, $schoolId, 'operate_finance')
                        && $db->table($central.'.finance_group_user_tenant_identities')->where('group_user_id', $groupUser->id)
                            ->where('school_id', $schoolId)->where('tenant_user_id', $actor->id)->where('status', 'active')->exists());
            }
            if (!$mapped) throw new RuntimeException('The Central Fee assignment actor has no matching active tenant identity.');
            $centralActorId = (int) $principal->id;
            $actorFields = ['actor_scope' => 'central', 'actor_school_id' => null, 'actor_tenant_user_id' => null];
        } else {
            $trusted = app(TrustedTenantContextService::class)->trustedTenantUserForCurrentRequest(request());
            if (!$trusted || $actor instanceof CentralFinanceUser || (int) Auth::id() !== (int) $actor->id || (int) $trusted->id !== (int) $actor->id
                || (int) $actor->getRawOriginal('school_id') !== $schoolId || (int) $trusted->getRawOriginal('school_id') !== $schoolId
                || !$db->table('users')->where('id', $actor->id)->where('school_id', $schoolId)
                    ->where('email', $actor->getRawOriginal('email'))->where('status', 1)->whereNull('deleted_at')->exists()) {
                throw new RuntimeException('Trusted tenant Fee classification actor is required.');
            }
            $actorFields = ['actor_scope' => 'tenant', 'actor_school_id' => $schoolId, 'actor_tenant_user_id' => $actor->id];
        }
        if ($existing) {
            if ($existing->classification !== $classification) throw new RuntimeException('Existing Fee classification cannot be overwritten.');
            if (!$db->table($central.'.'.CentralFinanceDataIsolationService::AUDIT_TABLE)->where($identity)
                ->where('classification_id', $existing->id)->where('after_classification', $classification)->exists()) {
                throw new RuntimeException('Existing Fee classification has no matching audit.');
            }
            return;
        }
        $reason = 'New '.$subjectType.' inherited '.$classification.' classification from the trusted School.';
        $classificationId = $query->insertGetId(array_merge($identity, $actorFields, [
            'classification_uuid' => (string) Str::uuid(), 'classification' => $classification, 'classified_by' => $centralActorId,
            'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $db->table($central.'.'.CentralFinanceDataIsolationService::AUDIT_TABLE)->insert(array_merge($identity, $actorFields, [
            'audit_uuid' => (string) Str::uuid(), 'classification_id' => $classificationId,
            'before_classification' => null, 'after_classification' => $classification, 'actor_id' => $centralActorId,
            'action' => $subjectType.'_created', 'reason' => $reason, 'created_at' => now(),
        ]));
    }
}
