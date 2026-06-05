<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\School;
use App\Services\SchoolDataService;
use App\Services\SubscriptionService;
use App\Services\CachingService;
use App\Repositories\SystemSetting\SystemSettingInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Throwable;

final class SetupSchoolDatabase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes timeout
    public int $backoff = 120; // 2 minute between retries

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly int $schoolId,
        private readonly ?int $packageId = null,
        private readonly ?string $schoolCodePrefix = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        SchoolDataService $schoolService,
        SubscriptionService $subscriptionService,
        CachingService $cache,
        SystemSettingInterface $systemSettings
    ): void {
        try {
            DB::setDefaultConnection('mysql');

            // Get school data
            $school = School::findOrFail($this->schoolId);
            
            // Create database
            DB::statement("CREATE DATABASE IF NOT EXISTS {$school->database_name}");

            // Run migrations
            $schoolService->createDatabaseMigration($school);

            // Setup pre-settings
            $schoolService->preSettingsSetup($school);

            // Assign package if provided
            if ($this->packageId) {
                $subscriptionService->createSubscription($this->packageId, $school->id, null, 1);
                $cache->removeSchoolCache(config('constants.CACHE.SCHOOL.SETTINGS'), $school->id);
            }

            // Update school code prefix if provided
            if ($this->schoolCodePrefix) {
                $settings = $cache->getSystemSettings();
                if (($settings['school_prefix'] ?? '') != $this->schoolCodePrefix) {
                    $settingsData[] = [
                        "name" => 'school_prefix',
                        "data" => $this->schoolCodePrefix,
                        "type" => "text"
                    ];
                    $systemSettings->upsert($settingsData, ["name"], ["data"]);
                    $cache->removeSystemCache(config('constants.CACHE.SYSTEM.SETTINGS'));
                }
            }

            // Update school status to active
            $school->update(['status' => 1, 'installed' => 1]);

            DB::setDefaultConnection('school');
            Config::set('database.connections.school.database', $school->database_name);
            DB::purge('school');
            DB::connection('school')->reconnect();
            DB::setDefaultConnection('school');
            School::on('school')->where('id', $this->schoolId)->update(['status' => 1, 'installed' => 1]);

            $school = School::with('user')->findOrFail($this->schoolId);
            $settings = $cache->getSystemSettings();

            $email_body = $this->replacePlaceholders($school, $school->user, $settings, $school->code);
            
            $data = [
                'subject'     => 'Welcome to ' . ($settings['system_name'] ?? 'eSchool Saas'),
                'email'       => $school->support_email,
                'email_body'  => $email_body
            ];

            Mail::send('schools.email', $data, static function ($message) use ($data, $settings) {
                $message->to($data['email'])->from($settings['mail_username'] ?? 'eSchool Saas')->subject($data['subject']);
            });

            // Send email verification if not already verified
            if (!$school->user->hasVerifiedEmail()) {
                sleep(5);
                $school->user->sendEmailVerificationNotification();
            }

        } catch (Throwable $e) {
            Log::error("School database setup failed for school ID: {$this->schoolId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("School database setup job failed permanently for school ID: {$this->schoolId}", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }

    private function replacePlaceholders($school, $user, $settings, $schoolCode): string
    {
        $templateContent = $settings['email_template_school_registration'] ?? '';

        // Ensure database connection is switched to the school database so the token
        // is stored in the school's password_resets table (not the main database).
        // Order matters: Config::set before any DB call so reconnect picks up
        // the correct database name.
        $previousConnection = DB::getDefaultConnection();
        $switched = !empty($school->database_name);
        if ($switched) {
            Config::set('database.connections.school.database', $school->database_name);
            DB::purge('school');
            DB::reconnect('school');
            DB::setDefaultConnection('school');
        }

        try {
            // Clear password broker cache so its token repository uses the new
            // connection instead of a stale one cached by the queue worker.
            app('auth.password')->forgetDrivers();
            // Generate password reset token and reset link (instead of sending plain mobile as password)
            $token = Password::broker()->createToken($user);
            $resetUrl = url('/password/reset/' . $token)
                . '?email=' . urlencode($user->email)
                . '&school_code=' . $schoolCode;
        } finally {
            // Restore previous database connection even if token generation fails
            if ($switched && $previousConnection !== 'school') {
                DB::setDefaultConnection($previousConnection);
                DB::purge('school');
            }
            // Clear again so the next password operation doesn't use the
            // stale school-connection broker.
            app('auth.password')->forgetDrivers();
        }

        $placeholders = [
            '{school_admin_name}' => $user->full_name,
            '{code}' => $schoolCode,
            '{email}' => $user->email,
            '{password}' => "请点击以下链接设置您的登录密码（链接 60 分钟内有效）：\n{$resetUrl}",
            '{reset_link}' => $resetUrl,
            '{school_name}' => $school->name ?? '',
            '{super_admin_name}' => $settings['super_admin_name'] ?? 'Super Admin',
            '{support_email}' => $settings['mail_username'] ?? '',
            '{contact}' => $settings['mobile'] ?? '',
            '{system_name}' => $settings['system_name'] ?? 'eSchool Saas',
            '{url}' => url('/'),
        ];

        foreach ($placeholders as $placeholder => $replacement) {
            $templateContent = str_replace($placeholder, $replacement, $templateContent);
        }

        return $templateContent;
    }
} 