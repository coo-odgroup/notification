<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class NotificationCategory extends Model
{
    use HasFactory;
    protected $table = 'notification_category';
    protected $fillable = ['category_code','category_name','status'];
                     
}
