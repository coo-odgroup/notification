<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\Msg91Service;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        // $this->notification('AGENT_REGISTERED', 'Documents_never_uploaded', $agentResArr);

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
        $this->sendEmailNotification(
            'WALLET_WAITING',
            12,
            'hours',
            [
                'is_email_verified' => 1,
                'is_first_recharge_done' => 0
            ],
            config('msg91.EMAIL_TEMPLATE_IDS.WALLET_NOT_FUNDED'),
            'walletWaiting'
        );

        $this->sendEmailNotification(
            'WALLET_NOT_FUNDED',
            1,
            'minutes',
            [
                'is_email_verified' => 1,
                'is_first_recharge_done' => 0
            ],
            config('msg91.EMAIL_TEMPLATE_IDS.WALLET_NOT_FUNDED_OFFER_LIVE'),
            'walletNotFunded'
        );

        $this->sendEmailNotification(
            'AGENT_ACTIVATED',
            1,
            'minutes',
            [
                'is_email_verified' => 1,
                'is_first_recharge_done' => 0
            ],
            config('msg91.EMAIL_TEMPLATE_IDS.AGENT_ACTIVATED'),
            'agentActivated'
        );

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

    public function sendEmailold()
    {
        $agent = DB::table('user')->where('status', 1)->where('existing_agent', 1);

        // Your account is waiting.
        $agentWaiting = $agent
            ->select('name', 'email')
            ->where('is_first_recharge_done', 0)
            ->where('is_mobile_verified', 1)
            ->where('is_email_verified', 1)
            ->where('is_pan_verified', 1)
            ->where('is_aadhaar_verified', 1)
            ->where('is_password_changed', 1)
            ->get();

        log::info($agentWaiting);

        $sentArr = [];
        $msg91Arr = [];

        foreach ($agentWaiting as $key => $v) {
            $msg91Arr['agent_name'] = $v->name;
            $sentArr = [
                'template_id' => config('msg91.EMAIL_TEMPLATE_IDS.WALLET_NOT_FUNDED'),
                'name' => $v->name,
                'to' => $v->email,
            ];

            // $this->msg91Service->sendEmail($msg91Arr, $sentArr);
        }


        Log::info($sentArr);
    }

    public function sendEmailNotification($eventCode, $time, $unit, $conditions, $templateId, $fncName)
    {
        date_default_timezone_set('Asia/Kolkata');

        $now = date('Y-m-d H:i:s');

        $timeAgo = date(
            'Y-m-d H:i:s',
            strtotime("-{$time} {$unit}", strtotime($now))
        );

        $query = DB::table('notification_observation as no')
            ->join('user as u', 'u.id', '=', 'no.agent_id')
            ->where('no.event_code', $eventCode)
            ->where('no.status', 'PENDING')
            ->whereNotNull('no.due_at')
            ->where('no.due_at', '<=', $timeAgo)
            ->whereNotNull('u.email')
            ->where('u.email', '<>', '');

        foreach ($conditions as $column => $value) {
            $query->where("u.{$column}", $value);
        }

        $reminders = $query->select(
            'no.id as observation_id',
            'no.agent_id',
            'no.attempt_count',
            'u.name',
            'u.email',
            'u.phone',
            'u.organization_name',
            'u.location',
            'u.welcome_bonus_ends'
        )
            ->get();

        foreach ($reminders as $reminder) {
            try {
                $response = $this->$fncName([
                    $reminder,
                    'templateId' => $templateId
                ]);

                $updateData = [
                    'last_checked_at' => date('Y-m-d H:i:s'),
                    'updated_at'      => date('Y-m-d H:i:s'),
                ];

                if ($response) {
                    $updateData['status'] = 'SENT';
                    $updateData['sent_at'] = date('Y-m-d H:i:s');
                    $updateData['attempt_count'] = DB::raw('attempt_count + 1');
                } else {
                    $updateData['attempt_count'] = DB::raw('attempt_count + 1');
                }

                DB::table('notification_observation')
                    ->where('id', $reminder->observation_id)
                    ->where('status', 'PENDING')
                    ->update($updateData);
            } catch (\Throwable $e) {
                Log::error('Failed to send agent notification', [
                    'observation_id' => $reminder->observation_id,
                    'agent_id'       => $reminder->agent_id,
                    'error'          => $e->getMessage(),
                ]);

                DB::table('notification_observation')
                    ->where('id', $reminder->observation_id)
                    ->where('status', 'PENDING')
                    ->update([
                        'last_checked_at' => date('Y-m-d H:i:s'),
                        'attempt_count'   => DB::raw('attempt_count + 1'),
                        'updated_at'      => date('Y-m-d H:i:s'),
                    ]);
            }
        }

        $this->info('First recharge reminder processing completed.');
    }

    private function walletWaiting($reminder)
    {
        Log::info('walletWaiting : ', $reminder);
        $msg91Arr['agent_name'] = $reminder[0]->name;
        $sentArr = [
            'template_id' => $reminder['templateId'],
            'name' => $reminder[0]->name,
            'to' => $reminder[0]->email,
        ];

        $sent = $this->msg91Service->sendEmail($msg91Arr, $sentArr);

        if ($sent['status'] == 'success') {
            return true;
        }
    }

    private function walletNotFunded($reminder)
    {
        Log::info('walletNotFunded : ', $reminder);
        $msg91Arr['agent_name'] = $reminder[0]->name;
        $msg91Arr['offer_end_date'] = $reminder[0]->welcome_bonus_ends;
        $sentArr = [
            'template_id' => $reminder['templateId'],
            'name' => $reminder[0]->name,
            'to' => $reminder[0]->email,
        ];

        $sent = $this->msg91Service->sendEmail($msg91Arr, $sentArr);

        if ($sent['status'] == 'success') {
            return true;
        }
    }

    private function agentActivated($reminder)
    {
        Log::info('agentActivated : ', $reminder);
        $msg91Arr['agent_id'] = $reminder[0]->agent_id;
        $msg91Arr['agent_name'] = $reminder[0]->name;
        $msg91Arr['business_name'] = $reminder[0]->organization_name;
        $msg91Arr['operating_city'] = $reminder[0]->location;
        $msg91Arr['mobile_number'] = $reminder[0]->phone;
        $msg91Arr['registered_email'] = $reminder[0]->email;
        $msg91Arr['offer_end_date'] = $reminder[0]->welcome_bonus_ends;
        $sentArr = [
            'template_id' => $reminder['templateId'],
            'name' => $reminder[0]->name,
            'to' => $reminder[0]->email,
        ];

        $sent = $this->msg91Service->sendEmail($msg91Arr, $sentArr);

        if ($sent['status'] == 'success') {
            return true;
        }
    }
}
