<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends Laravel mail through Brevo's HTTP API (POST /v3/smtp/email) instead of SMTP. A short timeout means a Brevo problem
 * fails fast with a clear message instead of hanging the request until the browser gives up.
 */
class BrevoTransport extends AbstractTransport
{
    public function __construct(private string $apiKey, private int $timeout = 10)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $addr = fn (Address $a) => array_filter(['email' => $a->getAddress(), 'name' => $a->getName() ?: null]);

        $from = $email->getFrom()[0] ?? new Address((string) config('mail.from.address'), (string) config('mail.from.name'));

        $payload = [
            'sender' => $addr($from),
            'to' => array_map($addr, $email->getTo()),
            'subject' => (string) $email->getSubject(),
        ];
        if ($email->getCc()) { $payload['cc'] = array_map($addr, $email->getCc()); }
        if ($email->getBcc()) { $payload['bcc'] = array_map($addr, $email->getBcc()); }
        if ($email->getReplyTo()) { $payload['replyTo'] = $addr($email->getReplyTo()[0]); }

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        if ($html) { $payload['htmlContent'] = (string) $html; }
        if ($text) { $payload['textContent'] = (string) $text; }
        if (! $html && ! $text) { $payload['textContent'] = ' '; }

        $files = [];
        foreach ($email->getAttachments() as $part) {
            $files[] = ['name' => $part->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename') ?: 'attachment', 'content' => base64_encode($part->getBody())];
        }
        if ($files) { $payload['attachment'] = $files; }

        try {
            $res = Http::withHeaders(['api-key' => $this->apiKey, 'accept' => 'application/json'])->timeout($this->timeout)->connectTimeout(5)->asJson()->post('https://api.brevo.com/v3/smtp/email', $payload);
        } catch (\Throwable $e) {
            throw new TransportException('Brevo could not be reached: '.$e->getMessage(), 0, $e);
        }

        if (! $res->successful()) {
            throw new TransportException('Brevo refused the email ('.$res->status().'): '.($res->json('message') ?: $res->body()));
        }
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
