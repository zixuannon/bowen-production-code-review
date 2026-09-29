<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\TenantFinanceOnboardingRoleProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final class ProvisionFrontDeskRole extends Command
{
    protected $signature = 'school:provision-front-desk-role {school_code} {--dry-run}';
    protected $description = 'Provision the constrained Front Desk tenant fee-setup role for one School';

    public function handle(): int
    {
        $school = School::query()->whereCanonicalCode($this->argument('school_code'))->first();
        if (!$school) {
            $this->error('No matching School.');
            return self::FAILURE;
        }

        $this->line(($this->option('dry-run') ? '[DRY RUN] ' : '').$school->code);
        if ($this->option('dry-run')) return self::SUCCESS;

        Config::set('database.connections.school.database', $school->database_name);
        DB::purge('school');
        DB::connection('school')->reconnect();
        app(TenantFinanceOnboardingRoleProvisioner::class)->provision(
            (int) $school->id,
            (string) $school->code,
            (string) $school->database_name,
            'front_desk_workspace_provisioning',
        );

        return self::SUCCESS;
    }
}
