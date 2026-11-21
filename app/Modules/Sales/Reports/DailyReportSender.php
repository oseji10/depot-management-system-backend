<?php

namespace App\Modules\Sales\Reports;

use App\Mail\DailySalesReportMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Settings, recipients, PDF and sending for the daily sales report. Settings live in stores.settings['daily_report'].
 */
class DailyReportSender
{
    public const DEFAULTS = [
        'enabled' => false,
        'time' => '20:00',        // local time it goes out
        'day' => 'today',         // today = the day so far; yesterday = the full previous day (for a morning send)
        'owners' => true,         // always include the business owner(s)
        'user_ids' => [],
        'role_ids' => [],
        'emails' => [],
        'last_sent' => null,      // the report date last sent, so it never goes twice
    ];

    public function __construct(private DailySalesReport $report)
    {
    }

    public function settings(int $storeId): array
    {
        $all = json_decode((string) DB::table('stores')->where('id', $storeId)->value('settings'), true) ?: [];

        return [...self::DEFAULTS, ...($all['daily_report'] ?? [])];
    }

    public function save(int $storeId, array $values): array
    {
        $all = json_decode((string) DB::table('stores')->where('id', $storeId)->value('settings'), true) ?: [];
        $all['daily_report'] = [...self::DEFAULTS, ...($all['daily_report'] ?? []), ...$values];
        DB::table('stores')->where('id', $storeId)->update(['settings' => json_encode($all), 'updated_at' => now()]);

        return $all['daily_report'];
    }

    /** The day the report covers if it were sent now. */
    public function reportDate(int $storeId, ?string $date = null): string
    {
        if ($date) {
            return CarbonImmutable::parse($date)->toDateString();
        }

        $tz = DB::table('stores')->where('id', $storeId)->value('timezone') ?: 'Africa/Lagos';
        $now = CarbonImmutable::now($tz);

        return ($this->settings($storeId)['day'] === 'yesterday' ? $now->subDay() : $now)->toDateString();
    }

    /** True when it is time to send today's report and it has not gone yet. */
    public function isDue(int $storeId): bool
    {
        $s = $this->settings($storeId);
        if (! $s['enabled']) {
            return false;
        }

        $tz = DB::table('stores')->where('id', $storeId)->value('timezone') ?: 'Africa/Lagos';
        $now = CarbonImmutable::now($tz);

        return $now->format('H:i') >= $s['time'] && $s['last_sent'] !== $this->reportDate($storeId);
    }

    /** Why it would or would not go out right now: what a dry run reports. */
    public function status(int $storeId): array
    {
        $s = $this->settings($storeId);
        $tz = DB::table('stores')->where('id', $storeId)->value('timezone') ?: 'Africa/Lagos';
        $now = CarbonImmutable::now($tz);
        $date = $this->reportDate($storeId);

        $reason = match (true) {
            ! $s['enabled'] => 'Automatic sending is switched off.',
            $now->format('H:i') < $s['time'] => "Not yet: it goes out after {$s['time']} (it is {$now->format('H:i')} now, {$tz}).",
            $s['last_sent'] === $date => "Already sent for {$date}.",
            default => 'Due now: the next check (every 15 minutes) will send it.',
        };

        return ['due' => $this->isDue($storeId), 'reason' => $reason, 'now' => $now->format('Y-m-d H:i'), 'timezone' => $tz, 'report_date' => $date];
    }

    /**
     * Build everything as if sending, but send nothing: who would get it, the headline numbers, and the PDF.
     */
    public function dryRun(int $storeId, ?string $date = null): array
    {
        $date = $this->reportDate($storeId, $date);
        $r = $this->report->build($storeId, $date);
        $started = microtime(true);
        $pdf = $this->pdf($r);

        return [
            ...$this->status($storeId),
            'report_date' => $date,
            'recipients' => $this->recipients($storeId),
            'pdf' => $pdf !== null ? ['bytes' => strlen($pdf), 'seconds' => round(microtime(true) - $started, 2)] : null,
            'pdf_data' => $pdf,
            'summary' => [
                'Total sales' => ReportFormat::money($r['today']['sales']),
                'Orders' => (string) $r['today']['orders'],
                'Money received' => ReportFormat::money($r['today']['collected']),
                'Gross profit' => ReportFormat::money($r['today']['profit']),
                'Products sold' => (string) count($r['products']),
                'Customers who bought' => (string) $r['customers']['buyers'],
                'Low stock items' => (string) count($r['low_stock']),
            ],
        ];
    }

    /**
     * Everyone who gets it: owners, chosen people, everyone holding a chosen role, and extra addresses.
     *
     * @return list<string>
     */
    public function recipients(int $storeId): array
    {
        $s = $this->settings($storeId);
        $userIds = collect($s['user_ids'])->map(fn ($v) => (int) $v);

        $roleIds = collect($s['role_ids'])->map(fn ($v) => (int) $v);
        if ($s['owners']) {
            $roleIds = $roleIds->merge(DB::table('roles')->where('store_id', $storeId)->where(fn ($q) => \Illuminate\Support\Facades\Schema::hasColumn('roles', 'system_key') ? $q->where('system_key', 'owner')->orWhere('name', 'Owner') : $q->where('name', 'Owner'))->pluck('id'));
        }

        if ($roleIds->isNotEmpty()) {
            $userIds = $userIds->merge(DB::table('model_has_roles')->whereIn('role_id', $roleIds)->where('model_type', \App\Models\User::class)->pluck('model_id'));
        }

        // Only active people who belong to this business.
        $members = DB::table('user_stores')->where('store_id', $storeId)->pluck('user_id');
        $emails = DB::table('users')->whereIn('id', $userIds->unique())->whereIn('id', $members)->where('is_active', true)->whereNotNull('email')->pluck('email');

        return $emails->merge($s['emails'])->map(fn ($e) => mb_strtolower(trim((string) $e)))->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
    }

    /** The PDF, or null when the PDF library is not installed (the email then carries the summary only). */
    public function pdf(array $r): ?string
    {
        if (! class_exists(\Dompdf\Dompdf::class)) {
            return null;
        }

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml(view('reports.daily-sales', ['r' => $r])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Build and email the report. Returns who it went to. Marks the date as sent unless it is a test.
     *
     * @return list<string>
     */
    public function send(int $storeId, ?string $date = null, ?array $to = null, bool $mark = true): array
    {
        $date = $this->reportDate($storeId, $date);
        $to ??= $this->recipients($storeId);

        if ($to === []) {
            return [];
        }

        $r = $this->report->build($storeId, $date);
        $pdf = $this->pdf($r);
        $sent = [];

        foreach ($to as $email) {
            try {
                Mail::to($email)->send(new DailySalesReportMail($r, $pdf));
                $sent[] = $email;
            } catch (\Throwable $e) {
                report($e);
                \Log::error("Daily report to {$email} failed: ".$e->getMessage());
            }
        }

        if ($mark && $sent !== []) {
            $this->save($storeId, ['last_sent' => $date]);
        }

        return $sent;
    }
}
