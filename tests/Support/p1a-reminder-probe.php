<?php

declare(strict_types=1);

foreach ([
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3318',
    'DB_DATABASE' => 'eschool_testing',
    'DB_SCHOOL_DATABASE' => 'school_testing',
] as $key => $value) {
    if (getenv($key) !== $value) {
        fwrite(STDERR, "Refusing P1-A reminder probe outside its disposable localhost databases: {$key}.\n");
        exit(2);
    }
}

$spyFile = getenv('P1A_REMINDER_SPY_FILE');
$temporaryDirectory = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
$spyDirectory = $spyFile ? (realpath(dirname($spyFile)) ?: dirname($spyFile)) : '';
if (!$spyFile || ($spyDirectory !== $temporaryDirectory && !str_starts_with($spyDirectory, $temporaryDirectory.DIRECTORY_SEPARATOR))) {
    fwrite(STDERR, "Refusing P1-A reminder probe without a temporary notification spy file.\n");
    exit(2);
}

require __DIR__.'/P1aReminderNotificationSpy.php';
require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\ApiController;
use App\Models\Fee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$GLOBALS['p1a_capture_due_date_reminders'] = true;
$GLOBALS['p1a_due_date_reminder_calls'] = [];
$previousSchoolDatabase = DB::connection('mysql')->table('schools')->where('id', 1)->value('database_name');
$feeId = null;

register_shutdown_function(static function () use (&$feeId, $previousSchoolDatabase): void {
    try {
        config(['database.connections.school.database' => 'school_testing']);
        DB::purge('school');
        if ($feeId !== null) {
            DB::connection('school')->table('fees')->where('id', $feeId)->delete();
        }
        DB::connection('mysql')->table('schools')->where('id', 1)->update(['database_name' => $previousSchoolDatabase]);
        if (!empty($GLOBALS['p1a_due_date_reminder_calls'])) {
            file_put_contents((string) getenv('P1A_REMINDER_SPY_FILE'), json_encode($GLOBALS['p1a_due_date_reminder_calls']).PHP_EOL, FILE_APPEND);
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'P1-A reminder probe cleanup failed: '.$exception::class.PHP_EOL);
    }
});

DB::connection('mysql')->table('schools')->where('id', 1)->update(['database_name' => 'school_testing']);
config(['database.connections.school.database' => 'school_testing']);
DB::purge('school');
DB::setDefaultConnection('school');
Carbon\Carbon::setTestNow('1970-01-01 12:00:00');

$fee = new Fee();
$fee->forceFill([
    'name' => 'P1-A undated reminder probe '.Str::random(8),
    'due_date' => null,
    'due_charges' => 0,
    'due_charges_amount' => 0,
    'class_id' => 1,
    'school_id' => 1,
    'session_year_id' => 1,
]);
$fee->save();
$feeId = $fee->id;

app(ApiController::class)->sendFeeNotification(Request::create('/api/fees-due-notification', 'GET', [], [], [], [
    'HTTP_school-code' => 'BOWEN_QA',
]));
