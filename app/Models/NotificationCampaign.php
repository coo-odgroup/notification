<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationCampaign extends Model
{
    use HasFactory;

    protected $table = 'notification_campaigns';

    protected $fillable = [
        'campaign_name',
        'title',
        'message',
        'image',
        'type',
        'active_status',
        'total_users',
        'processed_users',
        'success_users',
        'failed_users',
        'target_type',
        'schedule_type',
        'schedule_minutes',
        'schedule_at',
        'is_completed',
        'started_at',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'active_status' => 'boolean',
        'is_completed' => 'boolean',
        'schedule_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function queue()
    {
        return $this->hasMany(NotificationCampaignQueue::class, 'campaign_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active_status', 1);
    }
}
