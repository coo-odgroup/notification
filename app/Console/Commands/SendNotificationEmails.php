<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Msg91Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendNotificationEmails extends Command
{
    protected $msg91Service;

    public function __construct(Msg91Service $msg91Service)
    {
        parent::__construct();

        $this->msg91Service = $msg91Service;
    }
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-notification-emails {--agent_id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'SendNotificationEmails';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $agentId = $this->option('agent_id');

        $user = DB::table('user')
            ->join('agent_wallet', 'agent_wallet.user_id', '=', 'user.id')
            ->where('user.id', $agentId)
            ->orderByDesc('agent_wallet.id')
            ->select(
                'user.name',
                'user.email',
                'agent_wallet.amount as booking_amount',
                'agent_wallet.transaction_id',
                'agent_wallet.created_at',
                'agent_wallet.balance'
            )
            ->first();

        $msg91Arr = [
            'agent_name' => $user->name,
            'amount' => $user->booking_amount,
            'transaction_id' => $user->transaction_id,
            'transaction_datetime' => $user->created_at,
            'balance' => $user->balance,
            'login_url' => 'https://agent.odbus.in/login',
        ];

        Log::info($msg91Arr);

        $sentArr = [
            'template_id' => config('msg91.EMAIL_TEMPLATE_IDS.WALLET_RECHARGE_SUCCESS'),
            'name' => $user->name,
            'to' => $user->email,
        ];

        Log::info($sentArr);

        // $this->msg91Service->sendEmail($msg91Arr, $sentArr);

        $this->info('Scheduled notification sent successfully.');
        Log::info('Scheduled notification sent successfully.');

        return Command::SUCCESS;
    }
}
