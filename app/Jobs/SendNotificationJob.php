<?php

namespace App\Jobs;

use App\Models\NotificationCampaignQueue;
use App\Models\NotificationLogs;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Traits\PushNotificationTrait;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use PushNotificationTrait;

    public $tries = 3;

    public $timeout = 120;

    protected $queueId;

    public function __construct($queueId)
    {
        $this->queueId = $queueId;

        $this->onConnection('rabbitmq');
        $this->onQueue(config('queue.connections.rabbitmq.queue'));
    }

    public function handle()
    {
        Log::info('==============================================');
        Log::info('SendNotificationJob Started');
        Log::info('Queue ID: ' . $this->queueId);
        Log::info('==============================================');

        /*
        |--------------------------------------------------------------------------
        | Get Queue Record
        |--------------------------------------------------------------------------
        */

        $queue = NotificationCampaignQueue::find($this->queueId);


        if (!$queue) {

            Log::warning(
                'Notification queue record not found.',
                [
                    'queue_id' => $this->queueId,
                ]
            );

            return;
        }

        $campaignType = null;

        if ($queue && $queue->campaign_id) {
            $campaignType = NotificationCampaign::where(
                'id',
                $queue->campaign_id
            )->value('type');
        }


        /*
        |--------------------------------------------------------------------------
        | Prevent Duplicate Processing
        |--------------------------------------------------------------------------
        */

        if (in_array($queue->status, [
            'SUCCESS',
            'INVALID_TOKEN',
            'SKIPPED',
            'BOOKING_CANCELLED',
        ])) {

            Log::info(
                'Notification already completed/skipped.',
                [
                    'queue_id' => $queue->id,
                    'status' => $queue->status,
                ]
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Get Current User
        |--------------------------------------------------------------------------
        */

        $user = User::find($queue->user_id);

        if (!$user) {

            $queue->update([
                'status' => 'SKIPPED',
                'processed_at' => Carbon::now(),
                'error_message' => 'User not found',
            ]);

            Log::warning(
                'User not found.',
                [
                    'queue_id' => $queue->id,
                    'user_id' => $queue->user_id,
                ]
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Check Current Login Status / FCM Token
        |--------------------------------------------------------------------------
        */

        if (
            (int) $user->login_status !== 1 ||
            empty($user->fcm_id)
        ) {

            $queue->update([
                'status' => 'SKIPPED',
                'processed_at' => Carbon::now(),
                'error_message' => 'User is logged out or FCM token unavailable',
            ]);

            Log::info(
                'Notification skipped because user is not eligible.',
                [
                    'queue_id' => $queue->id,
                    'user_id' => $user->id,
                    'login_status' => $user->login_status,
                ]
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Update Latest FCM Token
        |--------------------------------------------------------------------------
        */

        $queue->update([
            'fcm_token' => $user->fcm_id,
            'status' => 'PROCESSING',
            'processing_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $title = $queue->title;
        $message = $queue->message;
        $imageUrl = $queue->image_url;

        $notificationData = [
            'image' => $imageUrl,
        ];

        try {

            Log::info(
                'Sending Firebase notification.',
                [
                    'queue_id' => $queue->id,
                    'user_id' => $user->id,
                    'fcm_token' => substr($user->fcm_id, 0, 15) . '...',
                ]
            );

            $response = $this->sendPushNotification(
                $user->fcm_id,
                $title,
                $message,
                [],
                $imageUrl
            );

            Log::info(
                'Firebase response received.',
                [
                    'queue_id' => $queue->id,
                    'response' => $response,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Firebase Success
            | --------------------------------------------------------------------------
            */

            if (
                isset($response['status']) &&
                $response['status'] === true
            ) {

                $firebaseMessageId = null;

                if (
                    isset($response['response']['name'])
                ) {
                    $firebaseMessageId = $response['response']['name'];
                }

                $queue->update([
                    'status' => 'SUCCESS',
                    'processed_at' => Carbon::now(),
                    'error_code' => null,
                    'error_message' => null,
                    'updated_at' => Carbon::now(),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Notification Log
                |--------------------------------------------------------------------------
                */

                NotificationLogs::create([
                    'campaign_id'       => $queue->campaign_id,
                    'queue_id'          => $queue->id,
                    'notification_type' => $campaignType,
                    'user_id'           => $queue->user_id,
                    'mobile_no'         => $queue->mobile,
                    'fcm_token'         => $user->fcm_id,
                    'fcm_message_id'    => $firebaseMessageId,
                    'status'            => 'SUCCESS',
                    'error_code'        => null,
                    'error_message'     => null,
                    'firebase_response' => json_encode($response),
                    'sent_at'           => Carbon::now(),
                    'response_time_ms'  => null,
                    'created_at'        => Carbon::now(),
                ]);

                Log::info(
                    'Notification successfully sent.',
                    [
                        'queue_id' => $queue->id,
                        'user_id' => $user->id,
                    ]
                );

                $this->updateCampaignCounter($queue->campaign_id);

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Firebase Failed
            |--------------------------------------------------------------------------
            */

            $errorMessage = 'Firebase notification failed';

            if (isset($response['message'])) {
                $errorMessage = $response['message'];
            }

            $isInvalidToken = false;

            if (
                stripos($errorMessage, 'INVALID_ARGUMENT') !== false ||
                stripos($errorMessage, 'UNREGISTERED') !== false ||
                stripos($errorMessage, 'invalid registration token') !== false
            ) {
                $isInvalidToken = true;
            }

            if ($isInvalidToken) {

                /*
                |--------------------------------------------------------------------------
                | Mark User Logged Out / Invalid Token
                |--------------------------------------------------------------------------
                */

                User::where('id', $user->id)
                    ->where('login_status', 1)
                    ->update([
                        'login_status' => 2,
                    ]);

                $queue->update([
                    'status' => 'INVALID_TOKEN',
                    'processed_at' => Carbon::now(),
                    'error_code' => 'INVALID_TOKEN',
                    'error_message' => $errorMessage,
                    'updated_at' => Carbon::now(),
                ]);

                NotificationLogs::create([
                    'campaign_id'       => $queue->campaign_id,
                    'queue_id'          => $queue->id,
                    'notification_type' => $campaignType,
                    'user_id'           => $queue->user_id,
                    'mobile_no'         => $queue->mobile,
                    'fcm_token'         => $user->fcm_id,
                    'fcm_message_id'    => null,
                    'status'            => 'INVALID_TOKEN',
                    'error_code'        => 'INVALID_TOKEN',
                    'error_message'     => $errorMessage,
                    'firebase_response' => json_encode($response),
                    'sent_at'           => null,
                    'response_time_ms'  => null,
                    'created_at'        => Carbon::now(),
                ]);
                Log::warning(
                    'Invalid FCM token detected.',
                    [
                        'queue_id' => $queue->id,
                        'user_id' => $user->id,
                    ]
                );

                $this->updateCampaignCounter($queue->campaign_id);

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Normal Firebase Failure
            |--------------------------------------------------------------------------
            */

            $queue->update([
                'status' => 'FAILED',
                'processed_at' => Carbon::now(),
                'error_message' => $errorMessage,
                'updated_at' => Carbon::now(),
            ]);

            NotificationLogs::create([
                'campaign_id'       => $queue->campaign_id,
                'queue_id'          => $queue->id,
                'notification_type' => $campaignType,
                'user_id'           => $queue->user_id,
                'mobile_no'         => $queue->mobile,
                'fcm_token'         => $user->fcm_id,
                'fcm_message_id'    => null,
                'status'            => 'FAILED',
                'error_code'        => null,
                'error_message'     => $errorMessage,
                'firebase_response' => json_encode($response),
                'sent_at'           => null,
                'response_time_ms'  => null,
                'created_at'        => Carbon::now(),
            ]);

            Log::error(
                'Firebase notification failed.',
                [
                    'queue_id' => $queue->id,
                    'user_id' => $user->id,
                    'error' => $errorMessage,
                ]
            );
        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | Unexpected Error
            |--------------------------------------------------------------------------
            */

            $queue->update([
                'status' => 'FAILED',
                'processed_at' => Carbon::now(),
                'error_message' => $e->getMessage(),
                'updated_at' => Carbon::now(),
            ]);

            Log::error(
                'SendNotificationJob exception.',
                [
                    'queue_id' => $queue->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            throw $e;
        }

        Log::info('SendNotificationJob Finished.');
    }

    /**
     * Update campaign counters.
     */
    protected function updateCampaignCounter($campaignId)
    {
        if (!$campaignId) {
            return;
        }

        try {

            $campaign = \App\Models\NotificationCampaign::find($campaignId);

            if (!$campaign) {
                return;
            }

            $total = NotificationCampaignQueue::where(
                'campaign_id',
                $campaignId
            )->count();

            $completed = NotificationCampaignQueue::where(
                'campaign_id',
                $campaignId
            )->whereIn('status', [
                'SUCCESS',
                'FAILED',
                'INVALID_TOKEN',
                'SKIPPED',
                'BOOKING_CANCELLED',
            ])->count();

            $campaign->update([
                'is_completed' => ($total > 0 && $completed >= $total) ? 1 : 0,
                'updated_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {

            Log::error(
                'Failed to update campaign counter.',
                [
                    'campaign_id' => $campaignId,
                    'error' => $e->getMessage(),
                ]
            );
        }
    }
}
