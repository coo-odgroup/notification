<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Msg91Service
{
    public function Documents_never_uploaded($data)
    {
        $smsData = [
            "var1" => "9583918888"
        ];

        $postData = array_merge([
            "flow_id" => config('msg91.templates.Documents_never_uploaded'),
            "mobiles" => "91" . $data['phone']
        ], $smsData);

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.msg91.com/api/v5/flow/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => array(
                'authkey: ' . config('msg91.MSG91_AUTH_KEY'),
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);

        return json_decode($response, true);
    }

    public function sendEmail($msg91Arr, $sentArr)
    {
        $url = 'https://control.msg91.com/api/v5/email/send';

        $payload = [
            'recipients' => [
                [
                    'to' => [
                        [
                            'name' => $sentArr['name'],
                            'email' => $sentArr['to']
                        ],
                    ],
                    'variables' => $msg91Arr,
                ],
            ],
            'from' => [
                'name' => 'ODBUS Agent',
                'email' => config('msg91.FROM_EMAIL'),
            ],
            'domain' => 'mail.odbus.in',
            'template_id' => $sentArr['template_id']
        ];

        try {
            $response = Http::withHeaders([
                'accept' => 'application/json',
                'authkey' => config('msg91.MSG91_AUTH_KEY'),
                'content-type' => 'application/json',
            ])->post($url, $payload);

            if ($response->successful()) {
                Log::info('Email sent successfully', [
                    'response' => $response->json(),
                ]);

                return $response->json();
            }

            Log::error('MSG91 Email failed', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return $response->json();
        } catch (\Exception $e) {
            Log::error('MSG91 email API exception', [
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
