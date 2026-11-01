<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class SubscriptionPaymentMail extends Mailable
{
    public function __construct(public string $name, public string $storeName, public string $plan, public string $amount, public string $validUntil, public string $reference, public string $billingUrl)
    {
    }

    public function build(): static
    {
        return $this->subject('Payment received · '.config('depot.app_name'))->view('emails.payment');
    }
}
