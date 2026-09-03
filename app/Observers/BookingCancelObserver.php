<?php

namespace App\Observers;

use App\Models\Booking;
use App\Events\BookingCancelled;
use Illuminate\Support\Facades\Log;

class BookingCancelObserver
{
    public function updated(Booking $booking)
    {
        // Only Android bookings
        if (strtoupper((string) $booking->app_type) !== 'ANDROID') {
            return;
        }

        // Only status change 1 -> 2
        if (
            $booking->wasChanged('status') &&
            (int) $booking->getOriginal('status') === 1 &&
            (int) $booking->status === 2
        ) {
            Log::info('Booking Cancel Observer Triggered', [
                'booking_id' => $booking->id,
                'pnr' => $booking->pnr,
                'users_id' => $booking->users_id,
                'app_type' => $booking->app_type,
                'old_status' => $booking->getOriginal('status'),
                'new_status' => $booking->status,
            ]);

            event(new BookingCancelled($booking->id));
        }
    }
}