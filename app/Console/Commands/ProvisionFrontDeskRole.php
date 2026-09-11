<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final class ProvisionFrontDeskRole extends Command
{
    protected $signature = 'school:provision-front-desk-role {school_code} {--dry-run}';
    protected $description = 'Provision the non-financial Front Desk tenant role for one School';

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
        Role::on('school')->withoutGlobalScopes()->updateOrCreate(
            ['name' => 'Front Desk / Admissions & Collection', 'school_id' => $school->id],
            ['custom_role' => 0, 'editable' => 0, 'guard_name' => 'web'],
        );

        return self::SUCCESS;
    }
}
