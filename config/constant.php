<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Configuration
    |--------------------------------------------------------------------------
    */

    'firebase' => [

        'project_id' => env(
            'FIREBASE_PROJECT_ID',
            'odbus-c581f'
        ),

        'credential' => env(
            'FIREBASE_CREDENTIAL',
            'firebase.json'
        ),

    ],


    /*
    |--------------------------------------------------------------------------
    | Campaign Notification Configuration
    |--------------------------------------------------------------------------
    */

    'campaign_notification' => [

        'image_base_url' => env(
            'CAMPAIGN_NOTIFICATION_IMAGE_URL',
            'https://odapi.adglob.in/public/uploads/campaign_notifications/'
        ),

        'image_storage_path' => env(
            'CAMPAIGN_NOTIFICATION_IMAGE_STORAGE_PATH',
            'uploads/campaign_notifications'
        ),

    ],


    /*
    |--------------------------------------------------------------------------
    | Notification Configuration
    |--------------------------------------------------------------------------
    */

    'notification' => [

        'queue_limit' => env(
            'NOTIFICATION_QUEUE_LIMIT',
            200
        ),

    ],

];