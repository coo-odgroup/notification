<?php

namespace App\Traits;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\NotificationLogs;

trait PushNotificationTrait
{
    protected function sendPushNotification(
        string $deviceToken,
        string $title,
        string $body,
        array $data = [],
        ?string $imageUrl = null
    ) {
        try {

            $credentialsPath = public_path(
                env('FIREBASE_CREDENTIAL', 'firebase.json')
            );

            if (!file_exists($credentialsPath)) {
                throw new \Exception(
                    "Firebase credential file not found: {$credentialsPath}"
                );
            }

            $credentials = new ServiceAccountCredentials(
                [
                    'https://www.googleapis.com/auth/firebase.messaging'
                ],
                $credentialsPath
            );

            $accessToken = $credentials->fetchAuthToken();

            if (!isset($accessToken['access_token'])) {
                throw new \Exception(
                    'Unable to generate Firebase access token.'
                );
            }

            $token = $accessToken['access_token'];

            $projectId = env(
                'FIREBASE_PROJECT_ID',
                'odbus-c581f'
            );

            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

            /*
             * FCM data values MUST be strings
             */
            $data = array_map('strval', $data);

            /*
             * Add image URL to data as well.
             * This can be useful if the Android app handles
             * the notification itself.
             */
            if (!empty($imageUrl)) {
                $data['image_url'] = $imageUrl;
            }

            /*
             * Base FCM payload
             */
            $payload = [
                'message' => [
                    'token' => $deviceToken,

                    'notification' => [
                        'title' => $title,
                        'body'  => $body,
                    ],

                    'android' => [
                        'notification' => [
                            'sound' => 'default',
                        ],
                    ],

                    'data' => $data,
                ]
            ];

            /*
             * Add image to FCM notification
             */
            if (!empty($imageUrl)) {

                /*
                 * This is important for FCM notification messages
                 */
                $payload['message']['notification']['image'] = $imageUrl;

                /*
                 * Android notification image
                 */
                $payload['message']['android']['notification']['image'] = $imageUrl;
            }

            /*
             * Log the actual payload for testing
             */
            Log::info('FCM Payload', [
                'payload' => $payload
            ]);

            $response = Http::withToken($token)
                ->acceptJson()
                ->post($url, $payload);

            Log::info('FCM Notification Response', [
                'device_token' => substr($deviceToken, 0, 25) . '...',
                'title'        => $title,
                'body'         => $body,
                'image_url'    => $imageUrl,
                'status'       => $response->status(),
                'response'     => $response->json(),
            ]);

            if (!$response->successful()) {

                Log::error('FCM Notification Failed', [
                    'status'   => $response->status(),
                    'response' => $response->body(),
                ]);

                return [
                    'status'   => false,
                    'message'  => 'FCM notification failed.',
                    'response' => $response->json(),
                ];
            }

            $fcmResponse = [
                'status'   => true,
                'message'  => 'Notification sent successfully.',
                'response' => $response->json(),
            ];

            Log::info('FCM SEND RETURNING RESPONSE', [
                'response' => $fcmResponse
            ]);

            return $fcmResponse;
        } catch (\Throwable $e) {

            Log::error('Push Notification Exception', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return [
                'status'  => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
