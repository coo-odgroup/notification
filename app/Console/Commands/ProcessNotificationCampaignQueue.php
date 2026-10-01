<?php

namespace App\Console\Commands;

use App\Models\NotificationCampaignQueue;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Jobs\SendNotificationJob;
use Throwable;

class ProcessNotificationCampaignQueue extends Command
{
    protected $signature = 'notification:process-queue';

    protected $description = 'Dispatch due notification campaign queue records to RabbitMQ';

    public function handle()
    {
        $now = Carbon::now();

        Log::info('Notification Queue Dispatcher Started');
        Log::info('Current Time: ' . $now->toDateTimeString());
        Log::info('==============================================');

        /*
        |--------------------------------------------------------------------------
        | Get notifications which are due
        |--------------------------------------------------------------------------
        */

        $notifications = NotificationCampaignQueue::where('status', 'PENDING')
            ->where('scheduled_time', '<=', $now)
            ->orderBy('id', 'asc')
            ->limit(500)
            ->get();

        Log::info('Due notifications found: ' . $notifications->count());

        if ($notifications->isEmpty()) {
            Log::info('No due notifications found.');
            return Command::SUCCESS;
        }

        foreach ($notifications as $notification) {

            try {

                /*
                |--------------------------------------------------------------------------
                | Atomic PENDING -> QUEUED
                |--------------------------------------------------------------------------
                */

                $claimed = NotificationCampaignQueue::where('id', $notification->id)
                    ->where('status', 'PENDING')
                    ->update([
                        'status' => 'QUEUED',
                        'queued_at' => $now,
                        'updated_at' => $now,
                    ]);

                if ($claimed !== 1) {

                    Log::warning(
                        'Notification already claimed. Skipping.',
                        [
                            'queue_id' => $notification->id,
                        ]
                    );

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Dispatch Job to RabbitMQ
                |--------------------------------------------------------------------------
                */

                /*
                    |--------------------------------------------------------------------------
                    | RabbitMQ Configuration Debug
                    |--------------------------------------------------------------------------
                    */

                Log::info('RabbitMQ Dispatch Configuration', [
                    'queue_id' => $notification->id,
                    'connection' => config('queue.default'),
                    'rabbitmq_queue' => config('queue.connections.rabbitmq.queue'),
                    'rabbitmq_host' => config('queue.connections.rabbitmq.hosts.0.host'),
                    'rabbitmq_port' => config('queue.connections.rabbitmq.hosts.0.port'),
                    'rabbitmq_vhost' => config('queue.connections.rabbitmq.hosts.0.vhost'),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Test RabbitMQ Connection Before Dispatch
                |--------------------------------------------------------------------------
                */

                try {

                    $rabbitConnection = app('queue')
                        ->connection('rabbitmq')
                        ->getConnection();

                    Log::info('RabbitMQ Connection Object Created', [
                        'class' => get_class($rabbitConnection),
                        'connected' => method_exists($rabbitConnection, 'isConnected')
                            ? $rabbitConnection->isConnected()
                            : 'method_not_available',
                    ]);
                } catch (Throwable $rabbitException) {

                    Log::error('RabbitMQ Connection Test FAILED', [
                        'queue_id' => $notification->id,
                        'error' => $rabbitException->getMessage(),
                        'file' => $rabbitException->getFile(),
                        'line' => $rabbitException->getLine(),
                    ]);

                    throw $rabbitException;
                }

                /*
                |--------------------------------------------------------------------------
                | Dispatch Job
                |--------------------------------------------------------------------------
                */

                Log::info('RabbitMQ Dispatch STARTING', [
                    'queue_id' => $notification->id,
                    'queue' => config('queue.connections.rabbitmq.queue'),
                ]);

                SendNotificationJob::dispatch($notification->id)
                    ->onConnection('rabbitmq')
                    ->onQueue(config('queue.connections.rabbitmq.queue'))
                    ->afterCommit();

                Log::info('RabbitMQ Dispatch SUCCESS', [
                    'queue_id' => $notification->id,
                    'queue' => config('queue.connections.rabbitmq.queue'),
                ]);
            } catch (Throwable $e) {

                /*
                |--------------------------------------------------------------------------
                | If RabbitMQ dispatch fails
                |--------------------------------------------------------------------------
                */

                NotificationCampaignQueue::where('id', $notification->id)
                    ->where('status', 'QUEUED')
                    ->update([
                        'status' => 'PENDING',
                        'queued_at' => null,
                        'updated_at' => Carbon::now(),
                        'error_message' => $e->getMessage(),
                    ]);

                Log::error(
                    'Failed to dispatch notification to RabbitMQ.',
                    [
                        'queue_id' => $notification->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                );
            }
        }

        Log::info('==============================================');
        Log::info('Notification Queue Dispatcher Finished');
        Log::info('==============================================');

        return Command::SUCCESS;
    }
}
