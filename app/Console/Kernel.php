<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        Commands\ProcessNotificationCampaignQueue::class,
        Commands\PrepareAbandonedBookingNotifications::class,
        Commands\ScheduleCampaignNotifications::class,
        Commands\DeleteOldNotificationCampaignQueue::class, // Delets the 3 month old notifiocation from notification_campaign_queue table
    ];

    protected function schedule(Schedule $schedule)
    {
        
        $schedule->command('notification:process-queue')->everyMinute()->withoutOverlapping();
        $schedule->command('notifications:schedule-campaigns')->everyMinute();
        $schedule->command('notification:prepare-abandoned')->everyMinute();
        $schedule->command('notifications:delete-old-queue')->dailyAt('00:00'); // Delets the 3 month old notifiocation from notification_campaign_queue table


        // Jagan
        $schedule->command('app:send-scheduled-notifications')->everyMinute();
        // Jagan
    }

    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
