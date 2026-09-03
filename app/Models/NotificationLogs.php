<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationLogs extends Model
{
    use HasFactory;
    protected $table = 'notification_logs';
    public $timestamps = false;
    protected $fillable = [
        'campaign_id',
        'queue_id',
        'user_id',
        'fcm_token',
        'notification_type',
        'fcm_message_id',
        'status',
        'error_code',
        'error_message',
        'firebase_response',
        'sent_at',
        'response_time_ms',
        'created_at'
    ];
}