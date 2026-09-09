<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Msg91Service
{
    public function Documents_never_uploaded($data)
    {
        Log::info('Started');
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

        Log::info('Done');

        return json_decode($response, true);
    }
}
