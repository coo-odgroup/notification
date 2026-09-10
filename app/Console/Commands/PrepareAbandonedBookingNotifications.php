<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\NotificationCampaignService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PrepareAbandonedBookingNotifications extends Command
{
    protected $signature = 'notification:prepare-abandoned';

    protected $description = 'Prepare notification queue for abandoned bookings';

    protected $notificationCampaignService;

    public function __construct(NotificationCampaignService $notificationCampaignService)
    {
        parent::__construct();

        $this->notificationCampaignService = $notificationCampaignService;
    }

    public function handle()
    {
        Log::info('Abandoned Booking Notification Job Started', [
            'time' => Carbon::now()->toDateTimeString()
        ]);

        /*
         * Find bookings:
         *
         * status = 0
         * updated_at <= current time - 1 hour
         */
        $now = Carbon::now();

        $oneHourAgo = $now->copy()->subHour();

        $bookings = Booking::where('status', 0)
            ->where('app_type', 'ANDROID')
            ->whereDate('updated_at', $now->toDateString())
            ->whereBetween('updated_at', [$oneHourAgo, $now])
            ->orderBy('updated_at')
            ->limit(200)
            ->get();

        if ($bookings->isEmpty()) {

            Log::info('No abandoned bookings found');

            return 0;
        }

        Log::info('Potential abandoned bookings found', [
            'count' => $bookings->count()
        ]);

        foreach ($bookings as $booking) {

            Log::info('Checking abandoned booking', [
                'booking_id'    => $booking->id,
                'transaction_id' => $booking->transaction_id,
                'pnr'           => $booking->pnr,
                'user_id'       => $booking->users_id,
                'status'        => $booking->status,
                'updated_at'    => $booking->updated_at,
            ]);

            try {

                /*
                 * Re-check status before preparing notification.
                 *
                 * This protects against a booking becoming successful
                 * between the initial query and processing.
                 */
                $booking->refresh();

                if ((int) $booking->status !== 0) {

                    Log::info('Booking no longer abandoned', [
                        'booking_id' => $booking->id,
                        'status'     => $booking->status,
                    ]);

                    continue;
                }

                $created = $this->notificationCampaignService
                    ->scheduleBookingAbandonedNotification($booking);

                Log::info('Abandoned booking notification processed', [
                    'booking_id' => $booking->id,
                    'created'     => $created,
                ]);
            } catch (\Throwable $e) {

                Log::error('Abandoned Booking Notification Exception', [
                    'booking_id' => $booking->id,
                    'error'      => $e->getMessage(),
                    'trace'      => $e->getTraceAsString(),
                ]);

                continue;
            }
        }

        Log::info('Abandoned Booking Notification Job Finished');

        return 0;
    }
}
