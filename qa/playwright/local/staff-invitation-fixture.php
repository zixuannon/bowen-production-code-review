<?php

// Disposable browser assertions only. Never a Production seed or recovery tool.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\LocalBowenQaGuard;
use Illuminate\Support\Facades\DB;

LocalBowenQaGuard::assertEnvironment(app()->environment(), config('app.url'), config('database.connections.mysql.database'));
if (config('database.connections.mysql.database') !== 'eschool_local_layer4_browser'
    || config('mail.default') !== 'array') {
    throw new LogicException('Requires the isolated browser database and array mail transport.');
}
$school = DB::connection('mysql')->table('schools')->where('code', 'BOWEN_QA')->first();
LocalBowenQaGuard::assertTenant($school->code, $school->database_name);
config(['database.connections.school.database' => $school->database_name]);
DB::purge('school');
$db = DB::connection('school');
$email = 'invitation-principal@bowen-qa.test';

if (($argv[1] ?? '') === 'prepare') {
    if ($db->table('users')->where('email', $email)->exists()) {
        throw new LogicException('Reset the disposable fixture before the invitation E2E.');
    }
    $db->table('roles')->updateOrInsert([
        'name' => 'Principal', 'school_id' => $school->id, 'guard_name' => 'web',
    ], ['custom_role' => 1, 'editable' => 1]);
    $db->table('schools')->where('id', $school->id)->update(['code' => 'STALE-LOCAL-REPLICA']);
    $db->table('school_settings')->updateOrInsert([
        'school_id' => $school->id, 'name' => 'email-template-staff',
    ], ['data' => '{full_name} {code} {reset_link}', 'type' => 'string']);
    echo "Disposable invitation fixture prepared; transport=array.\n";
} elseif (($argv[1] ?? '') === 'verify') {
    $users = $db->table('users')->where('email', $email)->get();
    if ($users->count() !== 1 || (int) $users[0]->school_id !== (int) $school->id
        || (int) $users[0]->status !== 1
        || $db->table('staffs')->where('user_id', $users[0]->id)->count() !== 1
        || $db->table('staff_invitation_tokens')->where('email', $email)->count() !== 1
        || $db->table('model_has_roles')->where('model_id', $users[0]->id)->count() !== 1) {
        throw new LogicException('Invitation E2E identity/token cardinality mismatch.');
    }
    echo "Exactly one active tenant user, Staff profile, role and invitation token: PASS.\n";
} elseif (($argv[1] ?? '') === 'verify-clean') {
    if ($db->table('users')->where('email', $email)->exists()
        || $db->table('staff_invitation_tokens')->where('email', $email)->exists()
        || $db->table('schools')->where('id', $school->id)->value('code') !== 'BOWEN_QA') {
        throw new LogicException('Disposable invitation fixture cleanup incomplete.');
    }
    echo "Disposable invitation user/token removed and replica restored: PASS.\n";
} else {
    throw new LogicException('Expected prepare, verify or verify-clean. Cleanup uses local:bowen-qa reset.');
}
