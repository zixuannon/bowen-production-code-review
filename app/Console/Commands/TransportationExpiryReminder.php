<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Staff;
use App\Models\Students;
use App\Models\TransportationPayment;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TransportationExpiryReminder extends Command
{
    private mixed $originalSchoolDatabase = null;

    protected $signature = 'transport:expiry-reminder
                            {--dry-run : Check every school without sending notifications}';

    protected $description = 'Send reminders 7 days before transportation plan expiry for every active school';

    public function handle(): int
    {
        $this->originalSchoolDatabase = Config::get('database.connections.school.database');
        $targetDate = Carbon::today()->addDays(7)->toDateString();
        $dryRun = (bool) $this->option('dry-run');
        $processed = 0;
        $plansFound = 0;
        $failures = 0;

        try {
            foreach ($this->activeSchools() as $school) {
                try {
                    $plansFound += $this->processSchool($school, $targetDate, $dryRun, $processed);
                } catch (Throwable $exception) {
                    $failures++;
                    $this->error("School {$school->id}: {$exception->getMessage()}");
                    Log::error('Transportation reminder failed for school', [
                        'school_id' => $school->id,
                        'database' => $school->database_name,
                        'error' => $exception->getMessage(),
                    ]);
                } finally {
                    // The next tenant must never inherit a failed tenant's connection.
                    $this->restoreCentralConnection();
                }
            }
        } finally {
            $this->restoreCentralConnection();
        }

        $this->info("Transportation reminder checked {$processed} school(s); {$plansFound} plan(s) matched.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return Collection<int, School> */
    protected function activeSchools(): Collection
    {
        return School::on('mysql')
            ->where('status', 1)
            ->whereNotNull('database_name')
            ->orderBy('id')
            ->get();
    }

    protected function processSchool(School $school, string $targetDate, bool $dryRun, int &$processed): int
    {
        // database_name comes only from the central School registry; this command
        // accepts no tenant/database input from its CLI caller.
        Config::set('database.connections.school.database', $school->database_name);
        DB::purge('school');
        DB::connection('school')->reconnect();
        DB::setDefaultConnection('school');

        if (!Schema::connection('school')->hasTable('transportation_payments')) {
            $this->warn("School {$school->id}: transportation_payments table is not installed; skipped.");
            Log::warning('Transportation reminder skipped: table missing', [
                'school_id' => $school->id,
                'database' => $school->database_name,
            ]);

            return 0;
        }

        $plans = TransportationPayment::on('school')
            ->whereDate('expiry_date', $targetDate)
            ->where('status', 'paid')
            ->get();

        $processed++;

        if ($dryRun) {
            $this->line("School {$school->id}: {$plans->count()} expiring plan(s).");

            return $plans->count();
        }

        foreach ($plans as $plan) {
            $this->sendPlanReminders($plan);
        }

        return $plans->count();
    }

    protected function sendPlanReminders(TransportationPayment $plan): void
    {
        $student = Students::on('school')->with('user')->where('user_id', $plan->user_id)->first();
        $staff = Staff::on('school')->with('user')->where('user_id', $plan->user_id)->first();
        $expiryFormatted = Carbon::parse($plan->expiry_date)->format('F jS, Y');
        $title = 'Transportation Plan Expiring Soon';
        $body = "Your transportation plan expires on {$expiryFormatted}";

        if ($student) {
            $childId = $student->id;
            $studentUserId = $student->user_id;
            $guardianId = $student->guardian_id;
            $childName = $student->user->full_name ?? "Student #{$childId}";

            send_notification([$studentUserId], $title, $body, 'Transportation', ['user_id' => $studentUserId]);

            if ($guardianId) {
                send_notification(
                    [$guardianId],
                    $title,
                    "Your child {$childName}'s transportation plan expires on {$expiryFormatted}.",
                    'Transportation',
                    ['guardian_id' => $guardianId, 'child_id' => $childId]
                );
            }
        }

        if ($staff) {
            send_notification([$staff->user_id], $title, $body, 'Transportation', ['user_id' => $staff->user_id]);
        }
    }

    protected function restoreCentralConnection(): void
    {
        Config::set('database.connections.school.database', $this->originalSchoolDatabase);
        DB::purge('school');
        DB::connection('mysql')->reconnect();
        DB::setDefaultConnection('mysql');
    }
}
