<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Services\SystemAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedSystemAccounts extends Command
{
    protected $signature = 'accounting:seed-system-accounts {--store= : Only this store id}';

    protected $description = 'Create or re-activate the system accounts (inventory, grni, ...) for every store';

    public function handle(SystemAccounts $accounts): int
    {
        $ids = DB::table('stores')->when($this->option('store'), fn ($q, $id) => $q->where('id', $id))->pluck('id');

        foreach ($ids as $id) {
            $result = $accounts->ensure((int) $id);
            $this->line("Store {$id}: ".collect($result)->map(fn ($v, $k) => "$k=$v")->implode(', '));
        }

        $this->info($ids->count().' store(s) checked.');

        return self::SUCCESS;
    }
}