<?php

namespace App\Console\Commands;

use App\Mail\WelcomeMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** php artisan mail:test you@example.com  — sends one email now and prints the real error if it fails. */
class MailTest extends Command
{
    protected $signature = 'mail:test {email}';
    protected $description = 'Send a test email immediately and show any error';

    public function handle(): int
    {
        $this->info('Mailer: '.config('mail.default').' | host: '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port').' | from: '.config('mail.from.address').' | queue: '.config('queue.default'));

        try {
            Mail::to($this->argument('email'))->send(new WelcomeMail('Test', 'Test business', $this->argument('email'), config('depot.frontend_url').'/login/', null));
        } catch (\Throwable $e) {
            $this->error('FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(config('mail.default') === 'log' ? 'Written to storage/logs/laravel.log (MAIL_MAILER=log sends nothing).' : 'Sent. Check the inbox and spam folder.');

        return self::SUCCESS;
    }
}
