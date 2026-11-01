<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class WelcomeMail extends Mailable
{
    public function __construct(public string $name, public string $storeName, public string $email, public string $loginUrl, public ?int $trialDays)
    {
    }

    public function build(): static
    {
        return $this->subject('Welcome to '.config('depot.app_name'))->view('emails.welcome');
    }
}
