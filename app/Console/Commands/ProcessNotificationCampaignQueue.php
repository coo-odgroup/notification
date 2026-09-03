<?php

namespace App\Console\Commands;

use App\Models\NotificationCampaignQueue;
use App\Models\NotificationCampaign;
use App\Traits\PushNotificationTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\NotificationLogs;

class ProcessNotificationCampaignQueue extends Command
{
    use PushNotificationTrait;

    protected $signature = 'notification:process-queue';
    protected $description = 'Process pending notification campaign queue items in batches of 200';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        Log::info('Notification Queue Job Started', [
            'time' => Carbon::now()->toDateTimeString()
        ]);

        $queueItems = NotificationCampaignQueue::where('status', 'PENDING')
            ->where('scheduled_time', '<=', Carbon::now())
            ->orderBy('scheduled_time')
            ->limit(200)
            ->get();

        if ($queueItems->isEmpty()) {
            Log::info('Notification campaign queue: no pending items to process');
            return 0;
        }

        Log::info('Pending notifications fetched', [
            'count' => $queueItems->count()
        ]);

        foreach ($queueItems as $item) {

            $campaign = $item->campaign;

            Log::info('Processing queue item', [
                'queue_id'       => $item->id,
                'campaign_id'    => $item->campaign_id,
                'user_id'        => $item->user_id,
                'booking_id'     => $item->booking_id,
                'scheduled_time' => $item->scheduled_time,
                'status'         => $item->status,
                'image_url'      => $item->image_url,
            ]);

            if (!$campaign) {

                Log::warning('Campaign not found', [
                    'queue_id'    => $item->id,
                    'campaign_id' => $item->campaign_id,
                ]);

                $item->update([
                    'status'        => 'FAILED',
                    'processed_at'  => Carbon::now(),
                    'error_message' => 'Missing campaign record',
                ]);

                continue;
            }

            if (empty($item->fcm_token)) {

                Log::warning('FCM token missing', [
                    'queue_id' => $item->id,
                    'user_id'  => $item->user_id,
                ]);

                $item->update([
                    'status'        => 'FAILED',
                    'processed_at'  => Carbon::now(),
                    'error_message' => 'Missing FCM token',
                ]);

                $campaign->increment('processed_users');
                $campaign->increment('failed_users');

                continue;
            }

            Log::info('Sending Push Notification', [
                'queue_id' => $item->id,
                'title'    => $item->title,
                'message'  => $item->message,
                'image_url' => $item->image_url,
                'token'    => substr($item->fcm_token, 0, 25) . '...'
            ]);

            try {

                $response = $this->sendPushNotification(
                    $item->fcm_token,
                    $item->title,
                    $item->message,
                    [
                        'campaign_id' => $item->campaign_id,
                        'booking_id'  => $item->booking_id,
                        'user_id'     => $item->user_id,
                    ],
                    $item->image_url
                );
            } catch (\Throwable $e) {

                Log::error('Push Notification Exception', [
                    'queue_id' => $item->id,
                    'error'    => $e->getMessage(),
                    'trace'    => $e->getTraceAsString(),
                ]);

                $item->update([
                    'status'        => 'FAILED',
                    'processed_at'  => now(),
                    'error_message' => $e->getMessage(),
                ]);

                $campaign->increment('processed_users');
                $campaign->increment('failed_users');

                continue;
            }

            $status = !empty($response['status']) ? 'SUCCESS' : 'FAILED';

            $errorMessage = !empty($response['status'])
                ? null
                : ($response['message'] ?? 'Push notification failed');

            if (!$response['status'] && $this->isInvalidTokenResponse($response)) {
                $status = 'INVALID_TOKEN';
            }

            Log::info('Push Notification Response', [
                'queue_id' => $item->id,
                'response' => $response
            ]);

            try {

                $firebaseMessageId = null;

                if (is_array($response)) {
                    $fullMessageName = data_get($response, 'response.name')
                        ?? data_get($response, 'name');

                    if ($fullMessageName) {
                        $firebaseMessageId = str_replace(
                            'projects/odbus-c581f/messages/',
                            '',
                            $fullMessageName
                        );
                    }
                }

                $logData = [
                    'campaign_id'       => $item->campaign_id,
                    'queue_id'          => $item->id,
                    'user_id'           => $item->user_id,
                    'fcm_token'         => $item->fcm_token,
                    'fcm_message_id'    => $firebaseMessageId,
                    'status'            => $status,
                    'error_code'        => null,
                    'error_message'     => $errorMessage,
                    'firebase_response' => json_encode($response),
                    'sent_at'           => $status === 'SUCCESS' ? now() : null,
                    'response_time_ms'  => null,
                    'created_at'        => now(),
                ];

                Log::info('NOTIFICATION LOG DATA BEFORE INSERT', [
                    'queue_id' => $item->id,
                    'log_data' => $logData
                ]);

                $notificationLog = NotificationLogs::create($logData);

                Log::info('NOTIFICATION LOG INSERTED SUCCESSFULLY', [
                    'log_id'   => $notificationLog->id,
                    'queue_id' => $item->id,
                    'status'   => $status
                ]);
            } catch (\Throwable $e) {

                Log::error('NOTIFICATION LOG INSERT FAILED', [
                    'queue_id'       => $item->id,
                    'campaign_id'    => $item->campaign_id,
                    'user_id'        => $item->user_id,
                    'error'          => $e->getMessage(),
                    'file'            => $e->getFile(),
                    'line'            => $e->getLine(),
                    'trace'           => $e->getTraceAsString(),
                ]);
            }

            $item->update([
                'status'        => $status,
                'processed_at'  => Carbon::now(),
                'error_message' => $errorMessage,
            ]);

            $campaign->increment('processed_users');

            if ($status === 'SUCCESS') {
                $campaign->increment('success_users');
            } else {
                $campaign->increment('failed_users');
            }

            Log::info('Queue Updated', [
                'queue_id' => $item->id,
                'status'   => $status
            ]);
        }

        Log::info('Notification Queue Job Finished');

        return 0;
    }

    protected function isInvalidTokenResponse(array $response)
    {
        $payload = json_encode($response['response'] ?? []);

        return (strpos($payload, 'UNREGISTERED') !== false)
            || (strpos($payload, 'INVALID_ARGUMENT') !== false)
            || (strpos($payload, 'Invalid registration token') !== false)
            || (strpos($payload, 'NOT_FOUND') !== false);
    }
}
