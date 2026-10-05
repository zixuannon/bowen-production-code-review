<?php

// Disposable local browser assertions. No Production target or credentials.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Support\LocalBowenQaGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

LocalBowenQaGuard::assertEnvironment(app()->environment(), config('app.url'), config('database.connections.mysql.database'));
if (config('database.connections.mysql.database') !== 'eschool_local_layer4_browser' || config('mail.default') !== 'array') {
    throw new LogicException('Requires the disposable browser database and array mail transport.');
}
$central = DB::connection('mysql');
$school = $central->table('schools')->where('code', 'BOWEN_QA')->sole();
LocalBowenQaGuard::assertTenant($school->code, $school->database_name);
config(['database.connections.school.database' => $school->database_name]);
DB::purge('school');
$tenant = DB::connection('school');
$table = 'central_finance_data_classifications';
$auditTable = 'central_finance_data_classification_audits';
$emails = ['classification-principal@bowen-qa.test', 'classification-accountant@bowen-qa.test'];
$reason = 'Disposable QA Staff browser School fixture';
$command = $argv[1] ?? '';
$schoolSubject = fn () => $central->table($table)->where(['subject_scope' => 'central', 'subject_type' => 'school', 'subject_id' => $school->id]);

if (in_array($command, ['prepare-qa', 'prepare-official'], true)) {
    if (!Schema::connection('mysql')->hasColumn($auditTable, 'actor_tenant_user_id')) {
        throw new LogicException('Apply the exact additive classification actor migration to the disposable browser database first.');
    }
    if ($tenant->table('users')->whereIn('email', $emails)->exists() || $schoolSubject()->exists()) {
        throw new LogicException('Clean/reset the disposable Staff fixture before preparing.');
    }
    foreach (['Principal', 'School Accountant'] as $name) {
        $tenant->table('roles')->updateOrInsert([
            'name' => $name, 'school_id' => $school->id, 'guard_name' => 'web',
        ], ['custom_role' => 1, 'editable' => 1]);
    }
    $tenant->table('school_settings')->updateOrInsert([
        'school_id' => $school->id, 'name' => 'email-template-staff',
    ], ['data' => '{full_name} {code} {reset_link}', 'type' => 'string']);
    if ($command === 'prepare-qa') {
        $actorId = $central->table('users')->where('email', 'qa_head_finance@bowen-qa.test')->value('id');
        if (!$actorId) throw new LogicException('Missing disposable Central fixture actor.');
        $schoolSubject()->insert([
            'classification_uuid' => Str::uuid(), 'school_id' => $school->id,
            'subject_scope' => 'central', 'subject_type' => 'school', 'subject_id' => $school->id,
            'classification' => 'qa_test', 'classified_by' => $actorId, 'reason' => $reason,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    echo "$command complete; transport=array.\n";
} elseif (in_array($command, ['verify-qa', 'verify-official'], true)) {
    $actorId = $tenant->table('users')->where('email', 'qa_admin@bowen-qa.test')->value('id');
    foreach ($emails as $email) {
        $user = $tenant->table('users')->where('email', $email)->sole();
        if ((int) $user->school_id !== (int) $school->id || (int) $user->status !== 1
            || $tenant->table('staffs')->where('user_id', $user->id)->count() !== 1
            || $tenant->table('model_has_roles')->where('model_type', User::class)->where('model_id', $user->id)->count() !== 1
            || $tenant->table('staff_invitation_tokens')->where('email', $email)->count() !== 1) {
            throw new LogicException('Staff identity/profile/role/invitation cardinality mismatch.');
        }
        $subject = ['subject_scope' => 'tenant:'.$school->id, 'subject_type' => 'staff', 'subject_id' => $user->id];
        $classifications = $central->table($table)->where($subject);
        $audits = $central->table($auditTable)->where($subject);
        if ($command === 'verify-official') {
            if ($classifications->exists() || $audits->exists()) throw new LogicException('Official Staff unexpectedly classified.');
            continue;
        }
        $classification = $classifications->sole();
        $audit = $audits->sole();
        foreach ([$classification, $audit] as $row) {
            if ($row->actor_scope !== 'tenant' || (int) $row->actor_school_id !== (int) $school->id
                || (int) $row->actor_tenant_user_id !== (int) $actorId || trim($row->reason) === '') {
                throw new LogicException('Tenant actor attribution mismatch.');
            }
        }
        if ($classification->classification !== 'qa_test' || $classification->classified_by !== null
            || $audit->after_classification !== 'qa_test' || $audit->actor_id !== null
            || $audit->action !== 'staff_created' || (int) $audit->classification_id !== (int) $classification->id) {
            throw new LogicException('Classification/audit link mismatch.');
        }
    }
    echo "$command: two active Staff, one role and invitation each; classification/audit PASS.\n";
} elseif ($command === 'cleanup-metadata') {
    $ids = $tenant->table('users')->whereIn('email', $emails)->where('school_id', $school->id)->pluck('id');
    $central->transaction(function () use ($central, $table, $auditTable, $school, $ids, $schoolSubject, $reason): void {
        foreach ([$auditTable, $table] as $name) {
            $central->table($name)->where('subject_scope', 'tenant:'.$school->id)
                ->where('subject_type', 'staff')->whereIn('subject_id', $ids)->delete();
        }
        $schoolSubject()->where('reason', $reason)->delete();
    });
    echo "Disposable fixture metadata removed; now run local:bowen-qa reset for Staff/token cleanup.\n";
} elseif ($command === 'verify-clean') {
    if ($tenant->table('users')->whereIn('email', $emails)->exists()
        || $tenant->table('staff_invitation_tokens')->whereIn('email', $emails)->exists()
        || $schoolSubject()->where('reason', $reason)->exists()) {
        throw new LogicException('Disposable Staff fixture cleanup incomplete.');
    }
    echo "Disposable Staff fixture cleanup: PASS.\n";
} else {
    throw new LogicException('Expected prepare-qa, verify-qa, prepare-official, verify-official, cleanup-metadata or verify-clean.');
}
