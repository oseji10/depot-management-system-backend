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
    protected $signature = 'reports:daily-sales {--store= : Only this business id} {--date= : Report this day (Y-m-d)} {--force : Send now even if not due or already sent} {--dry-run : Build the report and show who would get it, but send nothing}';

    protected $description = 'Email the daily sales report to each business that is due';

    public function handle(DailyReportSender $sender): int
    {
        $stores = DB::table('stores')->where('is_active', true)->when($this->option('store'), fn ($q, $id) => $q->where('id', $id))->pluck('name', 'id');

        if ($this->option('dry-run')) {
            foreach ($stores as $id => $name) {
                $this->dryRun($sender, (int) $id, $name);
            }

            return self::SUCCESS;
        }

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

    /** Nothing is emailed: prints the plan and saves the PDF under storage/app/reports so it can be opened. */
    private function dryRun(DailyReportSender $sender, int $id, string $name): void
    {
        try {
            $d = $sender->dryRun($id, $this->option('date') ?: null);
        } catch (\Throwable $e) {
            $this->error("{$name}: could not build the report: {$e->getMessage()}");

            return;
        }

        $this->newLine();
        $this->info("{$name} (id {$id}) · report for {$d['report_date']} · DRY RUN, nothing sent");
        $this->line("  Schedule: {$d['reason']}");
        $this->line('  Would go to: '.($d['recipients'] ? implode(', ', $d['recipients']) : 'nobody (add recipients in Settings → Daily sales report)'));
        $this->table(['', ''], collect($d['summary'])->map(fn ($v, $k) => [$k, $v])->values()->all());

        if ($d['pdf_data'] !== null) {
            $path = "reports/dry-run-{$id}-{$d['report_date']}.pdf";
            \Illuminate\Support\Facades\Storage::disk('local')->put($path, $d['pdf_data']);
            $this->line('  PDF saved: '.\Illuminate\Support\Facades\Storage::disk('local')->path($path)." ({$d['pdf']['bytes']} bytes, built in {$d['pdf']['seconds']}s)");
        } else {
            $this->warn('  No PDF: dompdf is not installed (composer require dompdf/dompdf). The email would carry the summary only.');
        }
    }
}
