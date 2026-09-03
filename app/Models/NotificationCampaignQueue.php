<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationCampaignQueue extends Model
{
    use HasFactory;

    protected $table = 'notification_campaign_queue';

    protected $fillable = [
        'campaign_id',
        'user_id',
        'email',
        'mobile',
        'fcm_token',
        'status',
        'title',
        'message',
        'booking_status',
        'booking_id',
        'image_url',
        'retry_count',
        'scheduled_time',
        'processed_at',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'scheduled_time' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(NotificationCampaign::class, 'campaign_id');
    }

    public function user()
    {
        return $this->belongsTo(Users::class, 'user_id');
    }
}
