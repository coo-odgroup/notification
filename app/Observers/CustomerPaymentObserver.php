<?php

namespace App\Observers;

use App\Models\CustomerPayment;
use App\Events\PaymentSuccessful;
use App\Models\Booking;
use Illuminate\Support\Facades\Log;



class CustomerPaymentObserver
{
    /**
     * Handle the CustomerPayment "created" event.
     *
     * @param  \App\Models\CustomerPayment  $customerPayment
     * @return void
     */
    public function created(CustomerPayment $customerPayment)
    {
        //
    }

    /**
     * Handle the CustomerPayment "updated" event.
     *
     * @param  \App\Models\CustomerPayment  $customerPayment
     * @return void
     */

    public function updated(CustomerPayment $payment)
    {
        Log::info('CustomerPayment Observer fired', [
            'payment_id' => $payment->id,
            'payment_done' => $payment->payment_done,
        ]);
    }


    /**
     * Handle the CustomerPayment "deleted" event.
     *
     * @param  \App\Models\CustomerPayment  $customerPayment
     * @return void
     */
    public function deleted(CustomerPayment $customerPayment)
    {
        //
    }

    /**
     * Handle the CustomerPayment "restored" event.
     *
     * @param  \App\Models\CustomerPayment  $customerPayment
     * @return void
     */
    public function restored(CustomerPayment $customerPayment)
    {
        //
    }

    /**
     * Handle the CustomerPayment "force deleted" event.
     *
     * @param  \App\Models\CustomerPayment  $customerPayment
     * @return void
     */
    public function forceDeleted(CustomerPayment $customerPayment)
    {
        //
    }
}
