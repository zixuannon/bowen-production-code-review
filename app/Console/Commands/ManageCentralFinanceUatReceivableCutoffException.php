<?php

namespace App\Console\Commands;

use App\Models\CentralFinanceReceivableSyncUatException;
use App\Models\CentralFinanceStudentProfile;
use App\Models\CentralFinanceUser;
use App\Models\School;
use App\Services\CentralFinanceConfigurationAuthorizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One deliberately narrow Production-only UAT switch. It refuses raw database
 * names, non-Zixuan Schools, non-synthetic profiles, future timestamps, and
 * any reason other than the approved Finance E2E UAT audit reason.
 */
final class ManageCentralFinanceUatReceivableCutoffException extends Command
{
    private const SCHOOL_CODE = 'MMBOWEN01';
    private const REASON = 'Production Finance E2E UAT';

    protected $signature = 'central-finance:uat-receivable-cutoff-exception
        {action : enable, disable, or verify}
        {--school-code= : Trusted School code; only MMBOWEN01 is accepted}
        {--student-source-uuid= : Exact Central Student Profile UUID}
        {--authorized-by= : Existing Central Finance user id}
        {--reason= : Required exact approved audit reason}
        {--execute : Persist enable or disable; omit for read-only verification}';

    protected $description = 'Manage the one audited UUID-scoped Production Finance E2E UAT cutoff exception.';

    public function handle(CentralFinanceConfigurationAuthorizationService $authorization): int
    {
        $action = (string) $this->argument('action');
        if (!in_array($action, ['enable', 'disable', 'verify'], true)) return $this->fail('Action must be enable, disable, or verify.');
        if (!Schema::connection('mysql')->hasTable('central_finance_receivable_sync_uat_exceptions')) return $this->fail('The UAT cutoff exception schema is not installed.');
        if ((string) $this->option('school-code') !== self::SCHOOL_CODE) return $this->fail('Only the approved Zixuan School code is accepted.');
        $uuid = strtolower(trim((string) $this->option('student-source-uuid')));
        if (!Str::isUuid($uuid)) return $this->fail('A stable Student UUID is required.');

        $school = School::on('mysql')->where('code', self::SCHOOL_CODE)->first();
        $profile = $school ? CentralFinanceStudentProfile::on('mysql')->where(['school_id' => $school->id, 'source_uuid' => $uuid])->first() : null;
        if (!$profile || !Str::startsWith(str_replace(' ', '-', (string) $profile->student_name), 'CENTRAL-MANUAL-UAT-')) {
            return $this->fail('The requested Student is not the approved synthetic UAT profile.');
        }

        $row = CentralFinanceReceivableSyncUatException::on('mysql')->where([
            'school_id' => $school->id,
            'student_profile_id' => $profile->id,
            'context' => CentralFinanceReceivableSyncUatException::CONTEXT,
        ])->first();
        if ($action === 'verify' || !$this->option('execute')) {
            $this->line(json_encode(['active' => $row?->status === CentralFinanceReceivableSyncUatException::ACTIVE, 'school_id' => $school->id, 'profile_id' => $profile->id, 'source_uuid_matches' => $row ? strtolower((string) $row->student_source_uuid) === $uuid : false]));
            return self::SUCCESS;
        }

        if ((string) $this->option('reason') !== self::REASON) return $this->fail('The exact approved audit reason is required.');
        $actor = CentralFinanceUser::on('mysql')->find((int) $this->option('authorized-by'));
        if (!$actor) return $this->fail('An existing Central Finance actor is required.');
        $authorization->assertHeadFinanceCanConfigureSchool($actor, $school);

        return DB::connection('mysql')->transaction(function () use ($action, $school, $profile, $uuid, $actor): int {
            $row = CentralFinanceReceivableSyncUatException::on('mysql')->where([
                'school_id' => $school->id, 'student_profile_id' => $profile->id,
                'context' => CentralFinanceReceivableSyncUatException::CONTEXT,
            ])->lockForUpdate()->first();
            if ($action === 'enable') {
                if ($row?->status === CentralFinanceReceivableSyncUatException::ACTIVE) return self::SUCCESS;
                if ($row !== null) return $this->fail('A disabled UAT cutoff exception cannot be re-enabled.');
                CentralFinanceReceivableSyncUatException::on('mysql')->create([
                    'school_id' => $school->id, 'student_profile_id' => $profile->id, 'student_source_uuid' => $uuid,
                    'context' => CentralFinanceReceivableSyncUatException::CONTEXT, 'status' => CentralFinanceReceivableSyncUatException::ACTIVE,
                    'reason' => self::REASON, 'authorized_by' => $actor->id, 'enabled_at' => now(),
                ]);
                return self::SUCCESS;
            }
            if (!$row || $row->status !== CentralFinanceReceivableSyncUatException::ACTIVE) return $this->fail('No active UAT cutoff exception can be disabled.');
            $row->update(['status' => CentralFinanceReceivableSyncUatException::DISABLED, 'disabled_at' => now(), 'disabled_by' => $actor->id, 'disabled_reason' => self::REASON]);
            return self::SUCCESS;
        });
    }

    private function fail(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }
}
