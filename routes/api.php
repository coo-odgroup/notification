<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/send-scheduled-notifications', function () {

    Artisan::call('app:send-scheduled-notifications');

    return response()->json([
        'status'  => 1,
        'message' => 'Scheduled notifications executed successfully',
        'output'  => Artisan::output()
    ]);
});

Route::post('/send-notifications', function (Request $request) {

    $request->validate([
        'agent_id' => 'required|integer',
    ]);

    $agentId = $request->input('agent_id');

    // Separate lock for each agent
    $cacheKey = 'send_notifications_agent_' . $agentId;

    if (!Cache::add($cacheKey, true, now()->addMinute())) {
        return response()->json([
            'status' => 0,
            'message' => 'This agent has already triggered the API. Please try again after 1 minute.',
            'agent_id' => $agentId,
        ], 429);
    }

    Artisan::call('app:send-notification-emails', [
        '--agent_id' => $agentId,
    ]);

    return response()->json([
        'status' => 1,
        'message' => 'Notification command executed successfully',
        'agent_id' => $agentId,
    ]);
});