<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingCancelled
{
    use Dispatchable, SerializesModels;

    public $bookingId;

    /**
     * Create a new event instance.
     */
    public function __construct($bookingId)
    {
        $this->bookingId = $bookingId;
    }
}