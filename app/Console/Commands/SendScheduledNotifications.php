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
        $agentResArr = [];

        $this->notification('AGENT_REGISTERED', 'Documents_never_uploaded', $agentResArr);

        // DOCUMENT VERIFIED
        $docVerifyArr = [];

        // $this->notification('DOCUMENT_VERIFIED', 'Documents_never_uploaded', $docVerifyArr);

        // EMAIL VERIFIED
        $emailVerifyArr = [
            'balance' => '2000',
            'days' => '10',
            'bonus' => '10%'
        ];

        // $this->notification('EMAIL_VERIFIED', 'Documents_never_uploaded', $emailVerifyArr);

        // EMAIL Notify
        $this->sendEmail();

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

    public function sendEmail()
    {
        $agent = DB::table('user')->where('status', 1)->where('existing_agent', 0);

        // Your account is waiting.
        $agentWaiting = $agent
            ->select('name', 'email')
            ->where('is_first_recharge_done', 0)
            ->where('is_mobile_verified', 0)
            ->where('is_email_verified', 0)
            ->where('is_pan_verified', 0)
            ->where('is_aadhaar_verified', 0)
            ->where('is_password_changed', 0)
            ->get();

        foreach ($agentWaiting as $key => $v) {
            $msg91Arr['agent_name'] = $v->name;
            $sentArr = [
                'template_id' => config('msg91.EMAIL_TEMPLATE_IDS.WALLET_NOT_FOUNDED'),
                'name' => $v->name,
                'to' => $v->email,
            ];

            $this->msg91Service->sendEmail($msg91Arr, $sentArr);
        }

        Log::info($agentWaiting);
    }
}
