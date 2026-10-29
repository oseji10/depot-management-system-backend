<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Services\StockReconciler;
use Illuminate\Console\Command;

class ReconcileStock extends Command
{
    protected $signature = 'stock:reconcile {--store= : Only this store id} {--fix : Rebuild drifting balances from the ledger}';

    protected $description = 'Check that stock balances equal the sum of the stock ledger';

    public function handle(StockReconciler $reconciler): int
    {
        $rows = $reconciler->discrepancies($this->option('store') ? (int) $this->option('store') : null);

        if ($rows->isEmpty()) {
            $this->info('Stock balances match the ledger.');

            return self::SUCCESS;
        }

        $this->table(['warehouse', 'product', 'status', 'ledger', 'balance'], $rows->map(fn ($r) => array_values($r))->all());

        if ($this->option('fix')) {
            $this->warn($reconciler->repair($rows).' balance(s) rebuilt from the ledger.');

            return self::SUCCESS;
        }

        $this->error($rows->count().' discrepancy(ies). Investigate, then re-run with --fix.');

        return self::FAILURE;
    }
}
