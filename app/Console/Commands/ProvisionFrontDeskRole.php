<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class ProvisionFrontDeskRole extends Command
{
    protected $signature = 'school:provision-front-desk-role {school_code} {--dry-run}';
    protected $description = 'Provision the non-financial Front Desk tenant role using the normal school connection';

    public function handle(): int
    {
        $schools = School::query()->when($this->argument('school_code'), fn ($q, $code) => $q->where('code', $code))->get();
        if ($schools->isEmpty()) {
            $this->error('No matching school');
            return self::FAILURE;
        }
        foreach ($schools as $school) {
            $this->line(($this->option('dry-run') ? '[DRY RUN] ' : '') . $school->code);
            if ($this->option('dry-run')) continue;
            Config::set('database.connections.school.database', $school->database_name);
            DB::purge('school');
            DB::connection('school')->reconnect();
            Role::on('school')->withoutGlobalScopes()->updateOrCreate([
                'name' => 'Front Desk / Admissions & Collection',
                'school_id' => $school->id,
            ], ['custom_role' => 0, 'editable' => 0, 'guard_name' => 'web']);
        }
        return self::SUCCESS;
    }
}
