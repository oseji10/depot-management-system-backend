<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** The daily sales report: a short summary in the email body, the full report attached as a PDF. */
class DailySalesReportMail extends Mailable
{
    public function __construct(public array $r, public ?string $pdf = null)
    {
    }

    public function build(): static
    {
        $mail = $this->subject('Daily sales report · '.$this->r['store']['name'].' · '.\Carbon\Carbon::parse($this->r['date'])->format('j M Y'))
            ->view('emails.daily-report', ['r' => $this->r, 'hasPdf' => $this->pdf !== null]);

        if ($this->pdf !== null) {
            $mail->attachData($this->pdf, 'daily-sales-report-'.$this->r['date'].'.pdf', ['mime' => 'application/pdf']);
        }

        return $mail;
    }
}
