<?php

use App\Modules\Accounting\Services\ChartDefaults;
use App\Modules\Organization\Models\Store;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/** Puts back any system account (receivables, sales, cash...) that a store is missing or has switched off. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('accounts')) {
            return;
        }

        foreach (Store::withoutGlobalScopes()->pluck('id') as $storeId) {
            app(ChartDefaults::class)->repair($storeId);
        }
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
