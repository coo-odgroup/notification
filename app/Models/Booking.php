<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Users;
use App\Models\Bus;
use App\Models\BookingSequence;
use App\Models\ClientWallet;
use App\Models\CustomerPayment;

class Booking extends Model
{
      use HasFactory;
      protected $table = 'booking';
      protected $fillable = [
            'transaction_id',
            'pnr',
            'users_id',
            'bus_id',
            'source_id',
            'destination_id',
            'j_day',
            'journey_dt',
            'boarding_point',
            'dropping_point',
            'boarding_time',
            'dropping_time',
            'origin',
            'app_type',
            'typ_id',
            'total_fare',
            'owner_fare',
            'odbus_Charges',
            'odbus_gst_charges',
            'odbus_gst_amount',
            'owner_gst_charges',
            'owner_gst_amount',
            'created_by',
            'status'
      ];

      public function users()
      {
            return $this->belongsTo(Users::class);
      }
}
