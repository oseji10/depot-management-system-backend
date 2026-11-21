<?php

namespace App\Console\Commands;

use App\Modules\Sales\Reports\DailyReportSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Emails each business its daily sales report once its chosen time has passed (in the business's own time zone).
 * Run it every 15 minutes from the scheduler; it only sends once per day per business.
 *   php artisan reports:daily-sales                 send whatever is due now
 *   php artisan reports:daily-sales --store=3 --force --date=2026-11-20   send one now, for a given day
 */
class SendDailySalesReports extends Command
{
    protected $signature = 'reports:daily-sales {--store= : Only this business id} {--date= : Report this day (Y-m-d)} {--force : Send now even if not due or already sent}';

    protected $description = 'Email the daily sales report to each business that is due';

    public function handle(DailyReportSender $sender): int
    {
        $stores = DB::table('stores')->where('is_active', true)->when($this->option('store'), fn ($q, $id) => $q->where('id', $id))->pluck('name', 'id');

        foreach ($stores as $id => $name) {
            if (! $this->option('force') && ! $sender->isDue($id)) {
                continue;
            }

            try {
                $sent = $sender->send($id, $this->option('date') ?: null);
                $this->line($sent ? "{$name}: sent to ".implode(', ', $sent) : "{$name}: nobody to send to");
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
