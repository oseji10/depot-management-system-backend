<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** One email per person per refresh, listing everything new that they are allowed to see. */
class NotificationDigestMail extends Mailable
{
    /** @param array<int, array{title:string, body:?string, url:?string, severity:string}> $items */
    public function __construct(public string $name, public string $storeName, public array $items, public string $settingsUrl)
    {
    }

    public function build(): static
    {
        $subject = count($this->items) === 1
            ? $this->items[0]['title']
            : count($this->items).' updates from '.$this->storeName;

        return $this->subject($subject.' · '.config('depot.app_name'))->view('emails.digest');
    }
}
