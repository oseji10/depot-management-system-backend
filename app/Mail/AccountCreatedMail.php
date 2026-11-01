<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** Tells a new staff member their account exists. It never contains a password. */
class AccountCreatedMail extends Mailable
{
    public function __construct(public string $name, public string $storeName, public string $email, public string $role, public string $loginUrl, public string $forgotUrl)
    {
    }

    public function build(): static
    {
        return $this->subject('Your '.config('depot.app_name').' account for '.$this->storeName)->view('emails.account-created');
    }
}
