<?php

namespace App\Listeners;

use App\Events\PaymentSuccessful;
use App\Services\NotificationCampaignService;
use Illuminate\Support\Facades\Log;


class QueueBookingNotification
{
    protected $notificationService;

    public function __construct(NotificationCampaignService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function handle(PaymentSuccessful $event)
    {
        Log::info('QueueBookingNotification listener started', [
            'booking_id' => $event->booking->id
        ]);

        $result = $this->notificationService
            ->queueBookingNotifications($event->booking->id);

        Log::info('QueueBookingNotification listener finished', [
            'booking_id' => $event->booking->id,
            'result' => $result
        ]);
    }
}
