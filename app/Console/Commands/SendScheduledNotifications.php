<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\Msg91Service;
use Illuminate\Support\Facades\Log;

class SendScheduledNotifications extends Command
{
    protected $signature = 'app:send-scheduled-notifications';

    protected $description = 'Send Scheduled notifications';

    protected $msg91Service;

    public function __construct(Msg91Service $msg91Service)
    {
        parent::__construct();

        $this->msg91Service = $msg91Service;
    }

    public function handle()
    {
        // AGENT REGISTERED
        $agentResData = [];

        $this->notification('AGENT_REGISTERED', 'Documents_never_uploaded', $agentResData);
        // AGENT REGISTERED COMPLETED

        $this->info('Scheduled notification sent successfully.');

        return Command::SUCCESS;
    }

    public function notification($eventCode, $fncName, $varArr)
    {
        $notifications = DB::table('notification_observation')
            ->where('status', 'PENDING')
            ->where('event_code', $eventCode)
            ->where('due_at', '<=', now())
            ->get();

        foreach ($notifications as $notification) {

            // Update last checked time
            DB::table('notification_observation')
                ->where('id', $notification->id)
                ->update([
                    'last_checked_at' => now()
                ]);

            // Check if agent performed any other action after registration
            $otherAction = DB::table('notification_observation')
                ->where('agent_id', $notification->agent_id)
                ->where('event_code', '!=', $eventCode)
                ->where('observed_at', '>', $notification->observed_at)
                ->exists();

            // Agent has performed another action
            if ($otherAction) {
                continue;
            }

            // Send notification
            Log::info('Sending registration reminder', [
                'agent_id' => $notification->agent_id
            ]);

            $user = DB::table('user')
                ->where('id', $notification->agent_id)
                ->first();

            $varArr['phone'] = $user->phone;
            $varArr['name'] = $user->name;

            // $this->msg91Service->$fncName($varArr);

            // Mark notification as sent
            DB::table('notification_observation')
                ->where('id', $notification->id)
                ->update([
                    'status'  => 'SENT',
                    'sent_at' => now()
                ]);
        }
    }
}
