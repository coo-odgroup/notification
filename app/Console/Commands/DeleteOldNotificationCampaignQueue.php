<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DeleteOldNotificationCampaignQueue extends Command
{
    protected $signature = 'notifications:delete-old-queue';
    protected $description = 'Permanently delete notification campaign queue records older than 3 months';

    public function handle()
    {
        $now = Carbon::now();

        Log::info('Old Notification Queue Cleanup Started', [
            'time' => $now->toDateTimeString()
        ]);

        /*
        |--------------------------------------------------------------------------
        | Delete records older than 3 months
        |--------------------------------------------------------------------------
        */

        $cutoffDate = $now->copy()->subMonths(3);

        $deleted = DB::table('notification_campaign_queue')
            ->where('updated_at', '<', $cutoffDate)
            ->delete();

        Log::info('Old Notification Queue Cleanup Finished', [
            'cutoff_date' => $cutoffDate->toDateTimeString(),
            'deleted_rows' => $deleted
        ]);

        $this->info(
            "Deleted {$deleted} notification queue records older than 3 months."
        );

        return Command::SUCCESS;
    }
}