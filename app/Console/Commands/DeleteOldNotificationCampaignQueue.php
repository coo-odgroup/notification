<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DeleteOldNotificationCampaignQueue extends Command
{
    protected $signature = 'notifications:delete-old-queue';

    protected $description =
        'Permanently delete notification campaign queue records older than 7 days and notification logs older than 14 days';

    public function handle()
    {
        $now = Carbon::now();

        Log::info('Old Notification Queue Cleanup Started', [
            'time' => $now->toDateTimeString()
        ]);


        $cutoffDateQueue = $now->copy()->subDays(7);
        $cutoffDateLogs = $now->copy()->subDays(14);


        $deletedQueue = DB::table('notification_campaign_queue')
            ->where('updated_at', '<', $cutoffDateQueue)
            ->delete();

        $deletedLogs = DB::table('notification_logs')
            ->where('updated_at', '<', $cutoffDateLogs)
            ->delete();
     
        Log::info('Old Notification Queue Cleanup Finished', [
            'cutoff_date' => $cutoffDateQueue->toDateTimeString(),
            'deleted_rows' => $deletedQueue
        ]);

        Log::info('Old Notification Logs Cleanup Finished', [
            'cutoff_date' => $cutoffDateLogs->toDateTimeString(),
            'deleted_rows' => $deletedLogs
        ]);


        $this->info(
            "Deleted {$deletedQueue} notification queue records older than 7 days."
        );

        $this->info(
            "Deleted {$deletedLogs} notification log records older than 14 days."
        );


        return Command::SUCCESS;
    }
}