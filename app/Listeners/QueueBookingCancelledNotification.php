<?php

namespace App\Listeners;

use App\Events\BookingCancelled;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class QueueBookingCancelledNotification
{
    public function handle(BookingCancelled $event)
    {
        $bookingId = $event->bookingId;

        Log::info('Booking Cancelled Notification Listener Started', [
            'booking_id' => $bookingId,
        ]);

        $booking = Booking::find($bookingId);

        if (!$booking) {
            Log::warning('Cancelled booking not found', [
                'booking_id' => $bookingId,
            ]);

            return;
        }

        // Only Android bookings
        if (strtoupper((string) $booking->app_type) !== 'ANDROID') {
            return;
        }

        // Must be cancelled
        if ((int) $booking->status !== 2) {
            return;
        }

        /*
         * Get BOOKING_CANCELLED category
         */
        $category = DB::table('notification_category')
            ->where('category_code', 'BOOKING_CANCELLED')
            ->where('status', 1)
            ->first();

        if (!$category) {
            Log::warning('BOOKING_CANCELLED category not found', [
                'booking_id' => $bookingId,
            ]);

            return;
        }

        /*
         * Get active campaign
         */
        $campaign = DB::table('notification_campaigns')
            ->where('notification_category_id', $category->id)
            ->where('active_status', 1)
            ->first();

        if (!$campaign) {
            Log::warning('BOOKING_CANCELLED campaign not found', [
                'booking_id' => $bookingId,
                'category_id' => $category->id,
            ]);

            return;
        }

        /*
         * Get user using booking.users_id
         */
        $user = DB::table('users')
            ->where('id', $booking->users_id)
            ->first([
                'id',
                'email',
                'phone',
                'fcm_id'
            ]);

        if (!$user) {
            Log::warning('User not found for cancelled booking', [
                'booking_id' => $bookingId,
                'users_id' => $booking->users_id,
            ]);

            return;
        }

        /*
         * Replace placeholders
         */
        $title = str_replace(
            '{PNR}',
            $booking->pnr,
            $campaign->title
        );

        $message = str_replace(
            ['{PNR}', '{RefundAmount}'],
            [
                $booking->pnr,
                $booking->refund_amount ?? 0
            ],
            $campaign->message
        );

        /*
         * Schedule after 2 minutes
         */
        $scheduledTime = Carbon::now()->addMinutes(2);

        /*
         * Insert queue
         */
        $queueId = DB::table('notification_campaign_queue')
            ->insertGetId([
                'campaign_id'    => $campaign->id,
                'user_id'        => $user->id,
                'email'          => $user->email,
                'mobile'         => $user->phone,
                'fcm_token'      => $user->fcm_id,
                'status'         => 'PENDING',
                'title'          => $title,
                'message'        => $message,
                'image_url'      => $campaign->image,
                'booking_status' => 2,
                'booking_id'     => $bookingId,
                'retry_count'    => 0,
                'scheduled_time' => $scheduledTime,
                'created_at'     => Carbon::now(),
                'updated_at'     => Carbon::now(),
            ]);

        Log::info('Booking Cancelled Notification Queued', [
            'queue_id' => $queueId,
            'booking_id' => $bookingId,
            'pnr' => $booking->pnr,
        ]);
    }
}
