<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\BoardingDroping;
use App\Models\BusLocationSequence;


class Location extends Model
{
    use HasFactory;
    protected $table = 'location';
    protected $fillable = ['name','synonym','created_by','status'];
}
