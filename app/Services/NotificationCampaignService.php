<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Location;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignQueue;
use App\Models\Users;
use App\Traits\PushNotificationTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Models\NotificationCategory;
use Illuminate\Support\Facades\DB;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Factory;
use App\Models\NotificationLogs;

class NotificationCampaignService
{
    use PushNotificationTrait;

    public function scheduleBookingConfirmationNotification($booking)
    {
        if (!$booking || !isset($booking->users) || !$booking->users) {
            return false;
        }

        $campaign = $this->getBookingConfirmationCampaign($booking);
        if (!$campaign) {
            Log::info('No active booking confirmation campaign found for booking', ['booking_id' => $booking->id]);
            return false;
        }

        $user = $booking->users;
        $scheduledTime = $this->resolveScheduledTime($campaign);

        $message = $this->replacePlaceholders($campaign->message, $booking);
        $title = $this->replacePlaceholders($campaign->title, $booking);

        return NotificationCampaignQueue::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'mobile' => $user->phone,
            'fcm_token' => $user->fcm_id,
            'status' => 'PENDING',
            'retry_count' => 0,
            'scheduled_time' => $scheduledTime,
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    protected function getBookingConfirmationCampaign($booking)
    {
        $user = $booking->users;

        $query = NotificationCampaign::active();

        $query->where(function ($builder) use ($user) {
            $builder->where('target_type', 'ALL');

            if (isset($user->is_verified) && $user->is_verified) {
                $builder->orWhere('target_type', 'VERIFIED');
            }
        });

        return $query->where(function ($builder) {
            $builder->where('campaign_name', 'CONFIRM_BOOKING')
                ->orWhere('type', 'TRANSACTIONAL');
        })->orderBy('id', 'desc')->first();
    }

    protected function resolveScheduledTime(NotificationCampaign $campaign)
    {
        if ($campaign->schedule_type === 'SCHEDULED' && $campaign->schedule_at) {
            return $campaign->schedule_at;
        }

        if ($campaign->schedule_type === 'BEFORE_EVENT' || $campaign->schedule_type === 'AFTER_EVENT') {
            return $campaign->schedule_at ?: Carbon::now();
        }

        if ($campaign->schedule_type === 'IMMEDIATE') {
            return Carbon::now()->addMinutes(2);
        }

        if ($campaign->schedule_minutes && $campaign->schedule_minutes > 0) {
            return Carbon::now()->addMinutes($campaign->schedule_minutes);
        }

        return Carbon::now();
    }

    protected function replacePlaceholders($text, $booking)
    {
        /*
        * ---------------------------------------------------------
        * 1. GET SEAT NUMBERS
        *
        * booking.id
        *     -> booking_detail.booking_id
        *     -> booking_detail.bus_seats_id
        *     -> bus_seats.id
        *     -> bus_seats.seats_id
        *     -> seats.id
        *     -> seats.seatText
        * ---------------------------------------------------------
        */

        $seatNos = '';

        $bookingDetails = DB::table('booking_detail')
            ->where('booking_id', $booking->id)
            ->get();

        $seatTexts = [];

        foreach ($bookingDetails as $detail) {

            if (!empty($detail->bus_seats_id)) {

                $busSeat = DB::table('bus_seats')
                    ->where('id', $detail->bus_seats_id)
                    ->first();

                if ($busSeat && !empty($busSeat->seats_id)) {

                    $seat = DB::table('seats')
                        ->where('id', $busSeat->seats_id)
                        ->first();

                    if ($seat && !empty($seat->seatText)) {
                        $seatTexts[] = $seat->seatText;
                    }
                }
            }
        }

        $seatNos = implode(',', $seatTexts);


        /*
            * ---------------------------------------------------------
            * 2. GET SOURCE
            * ---------------------------------------------------------
            */

        $sourceName = '';

        if (!empty($booking->source_id)) {

            $source = Location::find($booking->source_id);

            if ($source) {
                $sourceName = $source->name;
            }
        }


        /*
            * ---------------------------------------------------------
            * 3. GET DESTINATION
            * ---------------------------------------------------------
            */

        $destinationName = '';

        if (!empty($booking->destination_id)) {

            $destination = Location::find($booking->destination_id);

            if ($destination) {
                $destinationName = $destination->name;
            }
        }


        /*
            * ---------------------------------------------------------
            * 4. GET BUS NAME + BUS NUMBER
            *
            * booking.bus_id
            *      -> bus.id
            *      -> bus_name
            *      -> bus_number
            * ---------------------------------------------------------
            */

        $busName = '';
        $busNumber = '';

        if (!empty($booking->bus_id)) {

            $bus = DB::table('bus')
                ->where('id', $booking->bus_id)
                ->first();

            if ($bus) {
                $busName = $bus->name ?? '';
                $busNumber = $bus->bus_number ?? '';
            }
        }

        $busNameNo = trim(
            $busName .
                (!empty($busNumber) ? ' (' . $busNumber . ')' : '')
        );


        /*
        * ---------------------------------------------------------
        * 5. JOURNEY DATE
        * ---------------------------------------------------------
        */

        $journeyDate = '';

        if (!empty($booking->journey_dt)) {

            $journeyDate = Carbon::parse(
                $booking->journey_dt
            )->format('d M Y');
        }


        $departureTime = '';

        if (!empty($booking->departure_time)) {

            $departureTime = Carbon::parse(
                $booking->departure_time
            )->format('h:i A');
        } elseif (!empty($booking->boarding_time)) {

            $departureTime = Carbon::parse(
                $booking->boarding_time
            )->format('h:i A');
        }


        $userName = optional($booking->users)->name ?? '';

        $placeholders = [

            '{{pnr}}' => $booking->pnr ?? '',
            '{{name}}' => $userName,
            '{{journeydate}}' => $journeyDate,
            '{{source}}' => $sourceName,
            '{{destination}}' => $destinationName,
            '{{Name}}' => $userName,
            '{{SeatsNo}}' => $seatNos,
            '{{BusNameNo}}' => $busNameNo,
            '{{JourneyDate}}' => $journeyDate,
            '{{Source}}' => $sourceName,
            '{{Destination}}' => $destinationName,
            '{{DepartureTime}}' => $departureTime,
        ];


        /*
        * DEBUG: Check all values before replacing placeholders
        */
        Log::info('Booking Notification Placeholder Values', [
            'booking_id'      => $booking->id,
            'pnr'             => $booking->pnr,
            'user_name'       => $userName,
            'source_id'       => $booking->source_id,
            'source_name'     => $sourceName,
            'destination_id'  => $booking->destination_id,
            'destination_name' => $destinationName,
            'bus_id'          => $booking->bus_id,
            'bus_name'        => $busName,
            'bus_number'      => $busNumber,
            'bus_name_no'     => $busNameNo,
            'seat_nos'        => $seatNos,
            'journey_dt'      => $booking->journey_dt,
            'journey_date'    => $journeyDate,
            'boarding_time'   => $booking->boarding_time ?? null,
            'departure_time'  => $departureTime,
            'original_text'   => $text,
        ]);


        $finalText = str_replace(
            array_keys($placeholders),
            array_values($placeholders),
            $text
        );


        /*
        * DEBUG: Check final notification message
        */
        Log::info('Booking Notification Final Message', [
            'booking_id' => $booking->id,
            'message'    => $finalText,
        ]);


        return $finalText;
    }
    public function scheduleBookingAbandonedNotification($booking)
    {
        if (!$booking) {
            return false;
        }

        /*
        * Booking must still be abandoned.
        *
        * 0 = Not booked / abandoned
        * 1 = Successfully booked
        * 2 = Cancelled
        * 4 = Seat/payment hold
        */
        if ((int) $booking->status !== 0) {
            return false;
        }

        /*
        * Get user.
        *
        * Your booking table contains users_id.
        */
        $user = null;

        if (!empty($booking->users_id)) {
            $user = Users::find($booking->users_id);
        }

        /*
        * Fallback to relationship if available.
        */
        if (!$user && isset($booking->users)) {
            $user = $booking->users;
        }

        if (!$user) {

            Log::warning('User not found for abandoned booking', [
                'booking_id' => $booking->id,
                'users_id'   => $booking->users_id,
            ]);

            return false;
        }

        /*
        * User must have FCM token.
        */
        if (empty($user->fcm_id)) {

            Log::warning('FCM token missing for abandoned booking', [
                'booking_id' => $booking->id,
                'user_id'    => $user->id,
            ]);

            return false;
        }

        /*
        * Get active abandoned booking campaigns.
        *
        * notification_category_id = 2
        */
        $campaigns = NotificationCampaign::active()
            ->where('notification_category_id', 2)
            ->where('type', 'PROMOTIONAL')
            ->where('target_type', 'ACTIVE')
            ->where('schedule_type', 'AFTER_EVENT')
            ->where('active_status', 1)
            ->orderBy('id')
            ->get();

        if ($campaigns->isEmpty()) {

            Log::warning('No abandoned booking campaigns found', [
                'booking_id' => $booking->id,
            ]);

            return false;
        }

        $createdCount = 0;

        foreach ($campaigns as $campaign) {

            /*
         * Prevent duplicate notification queue records.
         *
         * If campaign 6 has already been prepared for this booking,
         * don't create it again when this command runs next minute.
         */
            $alreadyQueued = NotificationCampaignQueue::where(
                'campaign_id',
                $campaign->id
            )
                ->where('booking_id', $booking->id)
                ->exists();

            if ($alreadyQueued) {

                Log::info('Abandoned booking notification already queued', [
                    'booking_id'  => $booking->id,
                    'campaign_id' => $campaign->id,
                ]);

                continue;
            }

            /*
         * Replace campaign placeholders.
         */
            $title = $this->replacePlaceholders(
                $campaign->title,
                $booking
            );

            $message = $this->replacePlaceholders(
                $campaign->message,
                $booking
            );

            /*
         * Image:
         *
         * If campaign has an image use it.
         * Otherwise use ODBUS logo.
         */
            $imageUrl = !empty($campaign->image)
                ? $campaign->image
                : 'https://www.odbus.in/assets/odbus-logo.png';

            /*
         * Campaign schedule_minutes:
         *
         * Campaign 6 = 360 minutes = 6 hours
         * Campaign 7 = 720 minutes = 12 hours
         * Campaign 8 = 1440 minutes = 24 hours
         */
            $scheduledTime = Carbon::now()->addMinutes(
                (int) $campaign->schedule_minutes
            );

            /*
         * Create notification queue.
         */
            $queue = NotificationCampaignQueue::create([
                'campaign_id'    => $campaign->id,
                'user_id'        => $booking->users->id,
                'booking_id'     => $booking->id,
                'email'          => $booking->users->email,
                'mobile'         => $booking->users->phone,
                'fcm_token'      => $booking->users->fcm_id,
                'status'         => 'PENDING',
                'title'          => $title,
                'message'        => $message,
                'image_url'      => $campaign->image,
                'booking_status' => $booking->status,
                'retry_count'    => 0,
                'scheduled_time' => $scheduledTime,
                'processed_at'   => null,
                'error_code'     => null,
                'error_message'  => null,
            ]);

            Log::info('Confirmation Notification Queued', [
                'queue_id'       => $queue->id,
                'booking_id'     => $booking->id,
                'campaign_id'    => $campaign->id,
                'title'          => $queue->title,
                'message'        => $queue->message,
                'fcm_token'      => !empty($queue->fcm_token),
                'image_url'      => $queue->image_url,
                'status'         => $queue->status,
                'scheduled_time' => $queue->scheduled_time,
            ]);

            $createdCount++;

            Log::info('Abandoned booking notification queued', [
                'booking_id'    => $booking->id,
                'campaign_id'   => $campaign->id,
                'user_id'       => $user->id,
                'scheduled_time' => $scheduledTime->toDateTimeString(),
            ]);
        }

        return $createdCount > 0;
    }
     
    public function queueBookingNotifications(int $bookingId): bool
    {
        DB::beginTransaction();
        try {

            /**
             * Fetch booking with relations
             */
            $booking = Booking::with([
                'users',
                'bookingDetail',
                'bus',
            ])->find($bookingId);

            if (!$booking) {
                DB::rollBack();
                Log::warning('Booking not found for confirmation notification', ['booking_id' => $bookingId]);
                return false;
            }

            /**
             * IMPORTANT:
             *
             * Only status = 1 should receive
             * successful booking confirmation notifications.
             *
             * 0 = Abandoned
             * 1 = Successfully Booked
             * 2 = Cancelled
             * 4 = Hold
             */
            if ((int) $booking->status !== 1) {

                Log::info('Skipping confirmation notification - booking is not active', [
                    'booking_id' => $booking->id,
                    'pnr'        => $booking->pnr,
                    'status'     => $booking->status,
                    'app_type'   => $booking->app_type,
                ]);
                DB::commit();
                return true;
            }

            /**
             * User must exist
             */
            if (!$booking->users) {

                Log::warning('Skipping confirmation notification - user not found', [
                    'booking_id' => $booking->id,
                    'users_id'   => $booking->users_id,
                ]);
                DB::commit();
                return true;
            }

            /**
             * Fetch Notification Category
             *
             * CONFIRM_BOOKING = Successful Booking Notification
             */
            $category = NotificationCategory::where(
                'category_code',
                'CONFIRM_BOOKING'
            )
                ->where('status', 1)
                ->first();

            if (!$category) {

                Log::warning('CONFIRM_BOOKING notification category not found', ['booking_id' => $bookingId]);
                DB::commit();
                return true;
            }

            /**
             * Fetch all active campaigns
             * belonging to CONFIRM_BOOKING category
             */
            $campaigns = NotificationCampaign::where(
                'notification_category_id',
                $category->id
            )
                ->where('active_status', 1)
                ->orderBy('id')
                ->get();

            if ($campaigns->isEmpty()) {

                Log::info('No active CONFIRM_BOOKING campaigns found', [
                    'booking_id' => $bookingId,
                    'category_id' => $category->id
                ]);
                DB::commit();
                return true;
            }

            /**
             * Journey Departure Date Time
             */
            $departure = Carbon::parse(
                $booking->journey_dt . ' ' . $booking->boarding_time
            );

            foreach ($campaigns as $campaign) {

                /**
                 * Prevent duplicate confirmation notifications
                 *
                 * If the PaymentSuccessful event is fired twice,
                 * don't create the same campaign again.
                 */
                $alreadyQueued = NotificationCampaignQueue::where(
                    'campaign_id',
                    $campaign->id
                )
                    ->where('booking_id', $booking->id)
                    ->whereIn('status', [
                        'PENDING',
                        'PROCESSING',
                        'SUCCESS',
                    ])
                    ->exists();

                if ($alreadyQueued) {

                    Log::info('Confirmation notification already queued', [
                        'booking_id' => $booking->id,
                        'campaign_id' => $campaign->id,
                    ]);

                    continue;
                }

                $message = $this->replacePlaceholders(
                    $campaign->message,
                    $booking
                );

                $title = $this->replacePlaceholders(
                    $campaign->title,
                    $booking
                );

                Log::info('Confirmation Notification Prepared', [
                    'booking_id'      => $booking->id,
                    'campaign_id'     => $campaign->id,
                    'title'           => $title,
                    'message'         => $message,
                    'fcm_token'       => $booking->users->fcm_id ?? null,
                    'schedule_type'   => $campaign->schedule_type,
                    'schedule_minutes' => $campaign->schedule_minutes,
                ]);

                /**
                 * Calculate Schedule Time
                 */
                switch ($campaign->schedule_type) {

                    case 'IMMEDIATE':

                        // Give command enough time to pick it up
                        $scheduledTime = now()->addMinutes(2);

                        break;

                    case 'BEFORE_EVENT':

                        $scheduledTime = $departure
                            ->copy()
                            ->subMinutes(
                                abs((int) $campaign->schedule_minutes)
                            );

                        break;

                    case 'AFTER_EVENT':

                        $scheduledTime = $departure
                            ->copy()
                            ->addMinutes(
                                (int) $campaign->schedule_minutes
                            );

                        break;

                    default:

                        $scheduledTime = now()->addMinutes(2);

                        break;
                }

                /**
                 * Insert Notification Queue
                 */
                NotificationCampaignQueue::create([

                    'campaign_id'    => $campaign->id,
                    'user_id'        => $booking->users->id,
                    'booking_id'     => $booking->id,
                    'email'          => $booking->users->email,
                    'mobile'         => $booking->users->phone,
                    'fcm_token'      => $booking->users->fcm_id,
                    'status'         => 'PENDING',
                    'title'          => $title,
                    'message'        => $message,

                    // Get image from notification_campaigns.image
                    'image_url'      => $campaign->image,

                    'booking_status' => $booking->status,
                    'retry_count'    => 0,
                    'scheduled_time' => $scheduledTime,
                    'processed_at'   => null,
                    'error_code'     => null,
                    'error_message'  => null,
                ]);
            }

            DB::commit();

            return true;
        } catch (\Exception $e) {

            DB::rollBack();

            Log::error('Failed to queue booking notifications', [
                'booking_id' => $bookingId,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    public function processNotificationQueue()
    {
        try {

            $notifications = NotificationCampaignQueue::where('status', 'PENDING')
                ->where('scheduled_time', '<=', now())
                ->orderBy('scheduled_time')
                ->get();

            Log::info('Notification Queue Processing Started', [
                'count' => $notifications->count(),
                'time' => now()->toDateTimeString()
            ]);

            /*
         * Store notification logs here.
         * We will insert all logs into notification_logs
         * together after processing the queue.
         */
            $notificationLogs = [];

            foreach ($notifications as $notification) {

                // Reset status for every queue item
                $notificationStatus = 'FAILED';

                // Get notification type from campaign
                $notificationType = DB::table('notification_campaigns')
                    ->where('id', $notification->campaign_id)
                    ->value('type');

                $mobileNo = DB::table('users')
                    ->where('id', $notification->user_id)
                    ->value('phone');

                $notification->notification_type = $notificationType;
                $startTime = microtime(true);

                Log::info('Processing notification queue item', [
                    'queue_id'       => $notification->id,
                    'campaign_id'    => $notification->campaign_id,
                    'user_id'        => $notification->user_id,
                    'booking_id'     => $notification->booking_id,
                    'scheduled_time' => $notification->scheduled_time,
                    'status'         => $notification->status,
                ]);

                /*
             * FCM TOKEN MISSING
             */
                if (empty($notification->fcm_token)) {

                    Log::warning('Notification failed - FCM token missing', [
                        'queue_id'    => $notification->id,
                        'campaign_id' => $notification->campaign_id,
                        'user_id'     => $notification->user_id,
                    ]);

                    $notification->update([
                        'status' => 'FAILED',
                        'error_code' => 'FCM_TOKEN_MISSING',
                        'error_message' => 'FCM Token Missing',
                        'processed_at' => now()
                    ]);

                    $notificationLogs[] = [
                        'campaign_id'       => $notification->campaign_id,
                        'notification_type' => $notification->notification_type,
                        'queue_id'          => $notification->id,
                        'user_id'           => $notification->user_id,
                        'mobile_no'         => $mobileNo,
                        'fcm_token'         => $notification->fcm_token,
                        'fcm_message_id'    => null,
                        'status'            => 'FAILED',
                        'error_code'        => 'FCM_TOKEN_MISSING',
                        'error_message'     => 'FCM Token Missing',
                        'firebase_response' => null,
                        'sent_at'           => null,
                        'response_time_ms'  => round(
                            (microtime(true) - $startTime) * 1000,
                            2
                        ),
                        'created_at'        => now(),
                    ];

                    continue;
                }

                try {

                    /*
                 * SEND FCM
                 */
                    Log::info('Sending notification through FCM', [
                        'queue_id' => $notification->id,
                        'campaign_id' => $notification->campaign_id,
                        'user_id' => $notification->user_id,
                        'title' => $notification->title,
                    ]);


                    $firebaseResponse = $this->sendPushNotification(
                        $notification->fcm_token,
                        $notification->title,
                        $notification->message
                    );

                    Log::info('Fire Base Response', $firebaseResponse);

                    /*
                    |--------------------------------------------------------------------------
                    | Determine FCM response status
                    |--------------------------------------------------------------------------
                    */
                    $notificationStatus = 'SUCCESS';

                    if ($this->isInvalidTokenResponse($firebaseResponse)) {
                        $notificationStatus = 'INVALID_TOKEN';

                        Log::warning('Invalid FCM token detected', [
                            'queue_id'       => $notification->id,
                            'campaign_id'    => $notification->campaign_id,
                            'user_id'        => $notification->user_id,
                            'fcm_token'      => substr($notification->fcm_token, 0, 25) . '...',
                            'firebase_response' => $firebaseResponse,
                        ]);
                    }
                    $responseTime = round(
                        (microtime(true) - $startTime) * 1000,
                        2
                    );

                    Log::info('FCM SEND COMPLETED', [
                        'queue_id' => $notification->id,
                        'firebase_response' => $firebaseResponse,
                        'response_time_ms' => $responseTime,
                    ]);

                    /*
                 * Extract Firebase message ID
                 */
                    $firebaseMessageId = null;

                    if (is_array($firebaseResponse)) {

                        $firebaseMessageId =
                            data_get($firebaseResponse, 'response.name')
                            ?? data_get($firebaseResponse, 'name');
                    }

                    /*
                 * Prepare log for batch insert
                 */
                    $notificationLogs[] = [
                        'campaign_id'       => $notification->campaign_id,
                        'notification_type' => $notification->notification_type,
                        'queue_id'          => $notification->id,
                        'user_id'           => $notification->user_id,
                        'mobile_no'         => $mobileNo,
                        'fcm_token'         => $notification->fcm_token,
                        'fcm_message_id'    => $firebaseMessageId,
                        'status'            => $notificationStatus,
                        'error_code'        => null,
                        'error_message'     => null,
                        'firebase_response' => is_array($firebaseResponse)
                            ? json_encode($firebaseResponse)
                            : $firebaseResponse,
                        'sent_at'           => now(),
                        'response_time_ms'  => $responseTime,
                        'created_at'        => now(),
                    ];

                    Log::info('Notification log prepared for batch insert', [
                        'queue_id' => $notification->id,
                        'campaign_id' => $notification->campaign_id,
                        'firebase_message_id' => $firebaseMessageId,
                    ]);

                    /*
                 * UPDATE QUEUE
                 */
                    $notification->update([
                        'status' => $notificationStatus,
                        'processed_at' => now(),
                        'retry_count' => $notification->retry_count + 1,
                        'error_message' => $notificationStatus === 'INVALID_TOKEN' ? 'Invalid or unregistered FCM token' : null,
                        'error_code' => $notificationStatus === 'INVALID_TOKEN' ? 'INVALID_TOKEN' : null
                    ]);

                    Log::info('Notification queue updated successfully', [
                        'queue_id' => $notification->id,
                        'status'   => $notificationStatus
                    ]);
                } catch (\Throwable $e) {

                    $responseTime = round(
                        (microtime(true) - $startTime) * 1000,
                        2
                    );

                    Log::error('Notification sending failed', [
                        'queue_id' => $notification->id,
                        'campaign_id' => $notification->campaign_id,
                        'user_id' => $notification->user_id,
                        'error_code' => (string) $e->getCode(),
                        'error_message' => $e->getMessage(),
                        'response_time_ms' => $responseTime,
                    ]);

                    /*
                 * Add FAILED notification to batch
                 */
                    $notificationLogs[] = [
                        'campaign_id'       => $notification->campaign_id,
                        'notification_type' => $notification->notification_type,
                        'queue_id'          => $notification->id,
                        'user_id'           => $notification->user_id,
                        'mobile_no'         => $mobileNo,
                        'fcm_token'         => $notification->fcm_token,
                        'fcm_message_id'    => null,
                        'status'            => 'FAILED',
                        'error_code'        => (string) $e->getCode(),
                        'error_message'     => $e->getMessage(),
                        'firebase_response' => null,
                        'sent_at'           => null,
                        'response_time_ms'  => $responseTime,
                        'created_at'        => now(),
                    ];

                    /*
                 * UPDATE QUEUE
                 */
                    $notification->update([
                        'status' => 'FAILED',
                        'retry_count' => $notification->retry_count + 1,
                        'processed_at' => now(),
                        'error_code' => (string) $e->getCode(),
                        'error_message' => $e->getMessage()
                    ]);
                }
            }


            Log::info('Notification log batch preparation completed', [
                'log_count' => count($notificationLogs),
            ]);

            if (!empty($notificationLogs)) {

                Log::info('Notification log batch insert START', [
                    'count' => count($notificationLogs),
                ]);

                try {

                    NotificationLogs::insert($notificationLogs);

                    Log::info('Notification log batch insert SUCCESS', [
                        'inserted_count' => count($notificationLogs),
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | INVALID TOKEN → Mark User Logged Out
                    |--------------------------------------------------------------------------
                    |
                    | Only change login_status after the INVALID_TOKEN log
                    | has been successfully inserted.
                    |
                    */

                    foreach ($notificationLogs as $log) {

                        if (($log['status'] ?? null) !== 'INVALID_TOKEN') {
                            continue;
                        }

                        $updated = DB::table('users')
                            ->where('id', $log['user_id'])
                            ->where('login_status', 1)
                            ->update([
                                'login_status' => 2,
                                'fcm_id'       => null,
                                'updated_at'   => now(),
                            ]);

                        Log::warning('User login_status changed due to INVALID FCM token', [
                            'user_id'      => $log['user_id'],
                            'queue_id'     => $log['queue_id'],
                            'login_status' => '1 → 2',
                            'rows_updated' => $updated,
                        ]);
                    }
                } catch (\Throwable $e) {

                    Log::error('Notification log batch insert FAILED', [
                        'count'         => count($notificationLogs),
                        'error_code'    => (string) $e->getCode(),
                        'error_message' => $e->getMessage(),
                        'file'          => $e->getFile(),
                        'line'          => $e->getLine(),
                        'trace'         => $e->getTraceAsString(),
                    ]);
                }
            } else {

                Log::info('Notification log insert SKIPPED - no logs prepared');
            }

            Log::info('Notification Queue Processing Finished');
        } catch (\Throwable $e) {

            Log::error('Notification queue processing crashed', [
                'error_code' => (string) $e->getCode(),
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    protected function isInvalidTokenResponse($response): bool
    {
        if (!is_array($response)) {
            return false;
        }

        $responseText = json_encode($response);

        return str_contains($responseText, 'UNREGISTERED')
            || str_contains($responseText, 'INVALID_ARGUMENT')
            || str_contains($responseText, 'Invalid registration token')
            || str_contains($responseText, 'NOT_FOUND')
            || str_contains($responseText, 'NotRegistered');
    }
}
