<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{

    protected $commands = [
        \App\Console\Commands\ProvisionFrontDeskRole::class,
        Commands\SubscriptionBillCron::class,
        Commands\DeleteNotifications::class
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();
        $schedule->command('subscriptionBill:cron')->daily();
        $schedule->command('notifications:delete')->monthly();
        $schedule->command('transport:expiry-reminder')->daily();
        // XIAOBAILONG-INTEGRATION: repair missing teacher permission links in every tenant.
        $schedule->command('xiaobailong:reconcile-permissions')->dailyAt('02:15')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
