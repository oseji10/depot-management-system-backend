<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class PasswordResetMail extends Mailable
{
    public function __construct(public string $name, public string $url, public int $minutes)
    {
    }

    public function build(): static
    {
        return $this->subject('Reset your password · '.config('depot.app_name'))->view('emails.password-reset');
    }
}
