<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ScheduleCampaignNotifications extends Command
{
    protected $signature = 'notifications:schedule-campaigns';

    protected $description = 'Create notification queue entries for scheduled campaigns';

    public function handle()
    {
        $now = Carbon::now();

        Log::info('Scheduled Campaign Queue Command Started', [
            'time' => $now->toDateTimeString()
        ]);

        /*
        |--------------------------------------------------------------------------
        | Get scheduled campaigns
        |--------------------------------------------------------------------------
        |
        | We process:
        | 1. ACTIVE campaigns
        | 2. CUSTOM campaigns having active_user_duration
        |
        */

        $campaigns = DB::table('notification_campaigns')
            ->where('schedule_type', 'SCHEDULED')
            ->where('active_status', 1)
            ->whereNotNull('active_user_duration')
            ->whereIn('target_type', [
                'ACTIVE',
                'CUSTOM',
                'SELECTED'
            ])
            ->get();

        if ($campaigns->isEmpty()) {

            Log::info('No scheduled campaigns found');

            return Command::SUCCESS;
        }

        foreach ($campaigns as $campaign) {

            try {

                /*
                |--------------------------------------------------------------------------
                | Get schedule rows
                |--------------------------------------------------------------------------
                */

                $schedules = DB::table('notification_campaign_schedule')
                    ->where('notification_campaign_id', $campaign->id)
                    ->orderBy('schedule_date')
                    ->orderBy('start_time')
                    ->get();

                if ($schedules->isEmpty()) {

                    Log::warning('No schedule found for campaign', [
                        'campaign_id' => $campaign->id
                    ]);

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | ACTIVE TARGET
                |--------------------------------------------------------------------------
                */

                if ($campaign->target_type === 'ACTIVE') {

                    $users = $this->getActiveUsers($campaign);

                    if ($users->isEmpty()) {

                        Log::warning('No active users found', [
                            'campaign_id' => $campaign->id
                        ]);

                        continue;
                    }

                    Log::info('Active campaign users found', [
                        'campaign_id' => $campaign->id,
                        'users' => $users->count(),
                        'duration_days' => $campaign->active_user_duration
                    ]);

                    $this->createQueue(
                        $campaign,
                        $schedules,
                        $users
                    );

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | SELECTED TARGET
                |--------------------------------------------------------------------------
                |
                | Get only the users explicitly selected for this campaign.
                |
                | notification_campaign_selected_users
                |
                | campaign_id
                | selected_users
                | user_name
                | time_duration
                | mobile
                | email
                | fcm_id
                |
                */

                if ($campaign->target_type === 'SELECTED') {

                    $users = $this->getSelectedUsers($campaign);

                    if ($users->isEmpty()) {

                        Log::warning('No selected users found', [
                            'campaign_id' => $campaign->id
                        ]);

                        continue;
                    }

                    Log::info('Selected campaign users found', [
                        'campaign_id' => $campaign->id,
                        'users' => $users->count(),
                        'duration_days' => $campaign->active_user_duration
                    ]);

                    $this->createQueue(
                        $campaign,
                        $schedules,
                        $users
                    );

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | CUSTOM TARGET
                |--------------------------------------------------------------------------
                */

                if ($campaign->target_type === 'CUSTOM') {

                    $custom = DB::table('notification_campaign_custom')
                        ->where('campaign_id', $campaign->id)
                        ->first();

                    if (!$custom) {

                        Log::warning('No custom configuration found', [
                            'campaign_id' => $campaign->id
                        ]);

                        continue;
                    }

                    switch ((int) $custom->custom_type) {

                        case 1:

                            $this->scheduleRouteCampaign(
                                $campaign,
                                $custom,
                                $schedules
                            );

                            break;

                        case 2:

                            $this->scheduleNewUserCampaign(
                                $campaign,
                                $custom,
                                $schedules
                            );

                            break;

                        case 3:

                            $this->scheduleOperatorCampaign(
                                $campaign,
                                $custom,
                                $schedules
                            );

                            break;

                        case 4:

                            Log::info('Special offer custom campaign not implemented yet', [
                                'campaign_id' => $campaign->id
                            ]);

                            break;

                        default:

                            Log::warning('Invalid custom_type', [
                                'campaign_id' => $campaign->id,
                                'custom_type' => $custom->custom_type
                            ]);

                            break;
                    }
                }
            } catch (\Throwable $e) {

                Log::error('Campaign scheduling failed', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
        }

        Log::info('Scheduled Campaign Queue Command Finished');

        return Command::SUCCESS;
    }



    private function getActiveUsers($campaign)
    {
        $activeSince = Carbon::now()
            ->subDays((int) $campaign->active_user_duration);

        return DB::table('users')
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.phone',
                'users.fcm_id',
                'users.updated_at'
            ])
            ->whereNotNull('users.fcm_id')
            ->where('users.fcm_id', '!=', '')
            ->where('users.updated_at', '>=', $activeSince)
            ->orderBy('users.id')
            ->get();
    }


    /*
    |--------------------------------------------------------------------------
    | 1. ROUTE CAMPAIGN
    |--------------------------------------------------------------------------
    |
    | custom_type = 1
    |
    | source_id      -> booking.source_id
    | destination_id -> booking.destination_id
    |
    | Requirement:
    | source OR destination should match.
    |
    */

    private function scheduleRouteCampaign(
        $campaign,
        $custom,
        $schedules
    ) {

        if (
            empty($custom->source_id) &&
            empty($custom->destination_id)
        ) {

            Log::warning('Route campaign has no source/destination', [
                'campaign_id' => $campaign->id
            ]);

            return;
        }

        $activeSince = Carbon::now()
            ->subDays((int) $campaign->active_user_duration);

        $usersQuery = DB::table('booking')
            ->join(
                'users',
                'users.id',
                '=',
                'booking.users_id'
            )
            ->where('booking.app_type', 'ANDROID')
            ->where('booking.created_at', '>=', $activeSince)
            ->whereNotNull('booking.users_id')
            ->whereNotNull('users.fcm_id')
            ->where('users.fcm_id', '!=', '');

        /*
        |--------------------------------------------------------------------------
        | Route matching
        |--------------------------------------------------------------------------
        |
        | Source matches OR destination matches.
        |
        */

        $usersQuery->where(function ($query) use ($custom) {

            if (!empty($custom->source_id)) {

                $query->where(
                    'booking.source_id',
                    $custom->source_id
                );
            }

            if (!empty($custom->destination_id)) {

                $query->orWhere(
                    'booking.destination_id',
                    $custom->destination_id
                );
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Get unique users
        |--------------------------------------------------------------------------
        */

        $users = $usersQuery
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.phone',
                'users.fcm_id',
                'users.updated_at'
            ])
            ->distinct()
            ->orderBy('users.id')
            ->get();

        Log::info('Route campaign users found', [
            'campaign_id' => $campaign->id,
            'source_id' => $custom->source_id,
            'destination_id' => $custom->destination_id,
            'users' => $users->count(),
            'duration_days' => $campaign->active_user_duration
        ]);

        if ($users->isEmpty()) {

            Log::info('No users found for route campaign', [
                'campaign_id' => $campaign->id
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Reuse common queue creator
        |--------------------------------------------------------------------------
        */

        $this->createQueue(
            $campaign,
            $schedules,
            $users
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 2. NEW USER CAMPAIGN
    |--------------------------------------------------------------------------
    |
    | custom_type = 2
    |
    | A "new user" here means the user's first Android booking
    | falls inside active_user_duration.
    |
    */

    private function scheduleNewUserCampaign(
        $campaign,
        $custom,
        $schedules
    ) {

        $activeSince = Carbon::now()
            ->subDays((int) $campaign->active_user_duration);

        $users = DB::table('users')
            ->join(
                'booking',
                'booking.users_id',
                '=',
                'users.id'
            )
            ->where('booking.app_type', 'ANDROID')
            ->whereNotNull('booking.users_id')
            ->whereNotNull('users.fcm_id')
            ->where('users.fcm_id', '!=', '')
            ->where('booking.created_at', '>=', $activeSince)

            /*
            |--------------------------------------------------------------------------
            | Make sure there was no earlier booking
            |--------------------------------------------------------------------------
            */

            ->whereNotExists(function ($query) use ($activeSince) {

                $query->select(DB::raw(1))
                    ->from('booking as previous_booking')
                    ->whereColumn(
                        'previous_booking.users_id',
                        'booking.users_id'
                    )
                    ->where(
                        'previous_booking.created_at',
                        '<',
                        $activeSince
                    );
            })

            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.phone',
                'users.fcm_id',
                'users.updated_at'
            ])
            ->distinct()
            ->orderBy('users.id')
            ->get();

        Log::info('New user campaign users found', [
            'campaign_id' => $campaign->id,
            'users' => $users->count(),
            'duration_days' => $campaign->active_user_duration
        ]);

        if ($users->isEmpty()) {

            Log::info('No users found for new user campaign', [
                'campaign_id' => $campaign->id
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Reuse common queue creator
        |--------------------------------------------------------------------------
        */

        $this->createQueue(
            $campaign,
            $schedules,
            $users
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 3. OPERATOR CAMPAIGN
    |--------------------------------------------------------------------------
    |
    | custom_type = 3
    |
    | notification_campaign_custom.operator_id
    |        ↓
    | bus.bus_operator_id
    |        ↓
    | bus.id
    |        ↓
    | booking.bus_id
    |
    */

    private function scheduleOperatorCampaign(
        $campaign,
        $custom,
        $schedules
    ) {

        if (empty($custom->operator_id)) {

            Log::warning('Operator campaign has no operator_id', [
                'campaign_id' => $campaign->id
            ]);

            return;
        }

        $activeSince = Carbon::now()
            ->subDays((int) $campaign->active_user_duration);

        $users = DB::table('booking')
            ->join(
                'bus',
                'bus.id',
                '=',
                'booking.bus_id'
            )
            ->join(
                'users',
                'users.id',
                '=',
                'booking.users_id'
            )
            ->where(
                'bus.bus_operator_id',
                $custom->operator_id
            )
            ->where('booking.app_type', 'ANDROID')
            ->where('booking.created_at', '>=', $activeSince)
            ->whereNotNull('booking.users_id')
            ->whereNotNull('users.fcm_id')
            ->where('users.fcm_id', '!=', '')

            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.phone',
                'users.fcm_id',
                'users.updated_at'
            ])

            ->distinct()
            ->orderBy('users.id')
            ->get();

        Log::info('Operator campaign users found', [
            'campaign_id' => $campaign->id,
            'operator_id' => $custom->operator_id,
            'users' => $users->count(),
            'duration_days' => $campaign->active_user_duration
        ]);

        if ($users->isEmpty()) {

            Log::info('No users found for operator campaign', [
                'campaign_id' => $campaign->id,
                'operator_id' => $custom->operator_id
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Reuse common queue creator
        |--------------------------------------------------------------------------
        */

        $this->createQueue(
            $campaign,
            $schedules,
            $users
        );
    }


    /*
    |--------------------------------------------------------------------------
    | COMMON QUEUE CREATOR
    |--------------------------------------------------------------------------
    |
    | This method does not care where the users came from.
    |
    | Active users
    | Route users
    | New users
    | Operator users
    |
    | All are passed here.
    |
    */

    private function createQueue(
        $campaign,
        $schedules,
        $users
    ) {

        $totalUsers = $users->count();

        if ($totalUsers === 0) {

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Build every minute slot
        |--------------------------------------------------------------------------
        */

        $slots = [];

        foreach ($schedules as $schedule) {

            $start = Carbon::createFromFormat(
                'Y-m-d H:i:s',
                $schedule->schedule_date .
                    ' ' .
                    $schedule->start_time
            );

            $end = Carbon::createFromFormat(
                'Y-m-d H:i:s',
                $schedule->schedule_date .
                    ' ' .
                    $schedule->end_time
            );

            $minutes = $start->diffInMinutes($end);

            if ($minutes <= 0) {

                Log::warning('Invalid schedule time', [
                    'campaign_id' => $campaign->id,
                    'schedule_id' => $schedule->id,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time
                ]);

                continue;
            }

            for ($i = 0; $i < $minutes; $i++) {

                $slots[] = $start
                    ->copy()
                    ->addMinutes($i);
            }
        }

        $totalSlots = count($slots);

        if ($totalSlots === 0) {

            Log::warning('No valid schedule slots', [
                'campaign_id' => $campaign->id
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Don't queue same campaign twice
        |--------------------------------------------------------------------------
        */

        $alreadyQueued = DB::table(
            'notification_campaign_queue'
        )
            ->where(
                'campaign_id',
                $campaign->id
            )
            ->whereIn('status', [
                'PENDING',
                'PROCESSING',
                'SUCCESS'
            ])
            ->exists();

        if ($alreadyQueued) {

            Log::info('Campaign already queued. Skipping.', [
                'campaign_id' => $campaign->id
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Distribute users across all slots
        |--------------------------------------------------------------------------
        */

        $usersArray = $users
            ->values()
            ->all();

        $batchSize = 500;

        foreach (
            array_chunk(
                $usersArray,
                $batchSize
            ) as $batchIndex => $userBatch
        ) {

            $queueRows = [];

            foreach (
                $userBatch
                as $batchUserIndex => $user
            ) {

                $index =
                    ($batchIndex * $batchSize)
                    + $batchUserIndex;

                $slotIndex = (int) floor(
                    ($index * $totalSlots)
                        / $totalUsers
                );

                if (
                    $slotIndex >= $totalSlots
                ) {

                    $slotIndex =
                        $totalSlots - 1;
                }

                $scheduledTime =
                    $slots[$slotIndex];

                $queueRows[] = [
                    'campaign_id' => $campaign->id,
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'mobile' => $user->phone,
                    'fcm_token' => $user->fcm_id,
                    'status' => 'PENDING',
                    'title' => $campaign->title,
                    'message' => $campaign->message,
                    'image_url' => $campaign->image,
                    'booking_status' => null,
                    'booking_id' => null,
                    'retry_count' => 0,
                    'scheduled_time' => $scheduledTime,
                    'processed_at' => null,
                    'error_code' => null,
                    'error_message' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (!empty($queueRows)) {

                DB::table(
                    'notification_campaign_queue'
                )->insert($queueRows);
            }

            unset($queueRows);
            gc_collect_cycles();
        }

        /*
        |--------------------------------------------------------------------------
        | Update campaign counters
        |--------------------------------------------------------------------------
        */

        DB::table('notification_campaigns')
            ->where(
                'id',
                $campaign->id
            )
            ->update([
                'total_users' => $totalUsers,
                'started_at' => null,
                'is_completed' => 0,
                'updated_at' => now(),
            ]);

        Log::info(
            'Campaign notification queue created',
            [
                'campaign_id' => $campaign->id,
                'users' => $totalUsers,
                'slots' => $totalSlots,
                'first_schedule' => $slots[0]->toDateTimeString(),
                'last_schedule' => $slots[$totalSlots - 1]->toDateTimeString(),
            ]
        );
    }

    /*
        |--------------------------------------------------------------------------
        | SELECTED USERS
        |--------------------------------------------------------------------------
        */

    private function getSelectedUsers($campaign)
    {
        return DB::table('notification_campaign_selected_users')
            ->select([
                'selected_users as id',
                'user_name as name',
                'email',
                'mobile as phone',
                'fcm_id',
            ])
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('fcm_id')
            ->where('fcm_id', '!=', '')
            ->orderBy('id')
            ->get();
    }
}
