<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\SchoolDataService;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class InstallHrLeavePermissionForSchool extends Command
{
    protected $signature = 'school:install-hr-leave-view-permission
                            {--school-id= : Target school ID (required)}
                            {--dry-run : Show what would be done without writing}';

    protected $description = 'Install hr-view-leave permission and optional HR role for a single school (Supervisor Final Approval).';

    public function handle(): int
    {
        $schoolId = $this->option('school-id');

        if (!$schoolId) {
            $this->error('The --school-id option is required.');
            return 1;
        }

        $school = School::find($schoolId);

        if (!$school) {
            $this->error("School ID {$schoolId} not found.");
            return 1;
        }

        if ($school->deleted_at) {
            $this->error("School ID {$schoolId} is soft-deleted. Refusing to proceed.");
            return 1;
        }

        $this->info('========================================');
        $this->info('  Install HR View Leave Permission');
        $this->info('========================================');
        $this->info("  School ID      : {$school->id}");
        $this->info("  School Name    : {$school->name}");
        $this->info("  Database       : {$school->database_name}");
        $this->info("  Dry Run        : " . ($this->option('dry-run') ? 'YES' : 'NO'));
        $this->info('========================================');

        if ($this->option('dry-run')) {
            $this->info('[DRY RUN] Would create permission: hr-view-leave');
            $this->info('[DRY RUN] Would optionally create/update HR role with hr-view-leave');
            $this->info('[DRY RUN] No changes made.');
            return 0;
        }

        // Switch to school database
        SchoolDataService::switchToSchoolDatabase($school->id);

        // 1. Create or find hr-view-leave permission (idempotent)
        $permission = Permission::firstOrCreate(
            ['name' => 'hr-view-leave', 'guard_name' => 'web']
        );
        $this->info("Permission 'hr-view-leave': " . ($permission->wasRecentlyCreated ? 'CREATED' : 'ALREADY EXISTS'));

        // 2. Optionally create/update HR role (idempotent)
        $role = Role::updateOrCreate(
            ['name' => 'HR', 'school_id' => $school->id, 'custom_role' => 0, 'editable' => 1]
        );

        // Append hr-view-leave to HR role WITHOUT removing existing permissions.
        // givePermissionTo is idempotent — safe to run multiple times.
        $existingCount = $role->permissions()->count();
        $role->givePermissionTo('hr-view-leave');
        $newCount = $role->permissions()->count();

        $this->info("HR role: " . ($newCount > $existingCount ? 'ADDED' : 'ALREADY HAD') . " hr-view-leave (preserving {$existingCount} existing permissions)");

        // 3. Clear Spatie permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $this->info('Spatie permission cache cleared for current school connection.');

        // 4. Switch back to main database
        SchoolDataService::switchToMainDatabase();

        $this->info('');
        $this->info('Done. School ' . $school->id . ' (' . $school->name . ') is ready for HR view-only leave records.');
        $this->info('IMPORTANT: No users have been assigned the HR role. Admin must manually assign.');
        $this->info('IMPORTANT: School Admin does NOT automatically receive hr-view-leave.');
        $this->info('IMPORTANT: HR can only VIEW leave records. Approval is final at supervisor level.');

        return 0;
    }
}
