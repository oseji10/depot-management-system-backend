<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\FiscalPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Months are created on demand. A closed month accepts no new postings. */
class FiscalPeriods
{
    public function ensure(int $storeId, string $date): object
    {
        $start = CarbonImmutable::parse($date)->startOfMonth();

        DB::table('fiscal_periods')->insertOrIgnore([
            'store_id' => $storeId, 'starts_on' => $start->toDateString(), 'ends_on' => $start->endOfMonth()->toDateString(),
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('fiscal_periods')->where('store_id', $storeId)->where('starts_on', $start->toDateString())->first();
    }

    public function assertOpen(int $storeId, string $date): void
    {
        $period = $this->ensure($storeId, $date);

        abort_if($period->status === 'closed', 422, sprintf(
            'The period %s is closed. Reopen it or post into an open period.',
            CarbonImmutable::parse($period->starts_on)->format('F Y'),
        ));
    }

    public function close(int $id, int $userId): FiscalPeriod
    {
        $period = FiscalPeriod::findOrFail($id);

        abort_unless($period->status === 'open', 422, 'This period is already closed.');

        $pendingDrafts = DB::table('journal_entries')->where('store_id', $period->store_id)->where('status', 'draft')
            ->whereBetween('entry_date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()])->count();

        abort_if($pendingDrafts > 0, 422, "{$pendingDrafts} draft journal entr".($pendingDrafts === 1 ? 'y is' : 'ies are').' still in this period. Post or delete them first.');

        $period->update(['status' => 'closed', 'closed_by' => $userId, 'closed_at' => now()]);

        return $period;
    }

    public function reopen(int $id): FiscalPeriod
    {
        $period = FiscalPeriod::findOrFail($id);

        abort_unless($period->status === 'closed', 422, 'This period is already open.');

        $period->update(['status' => 'open', 'closed_by' => null, 'closed_at' => null]);

        return $period;
    }
}
