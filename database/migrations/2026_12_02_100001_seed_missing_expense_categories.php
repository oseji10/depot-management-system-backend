<?php

use App\Modules\Expenses\Services\ExpenseDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Businesses that ended up with no expense categories (their chart was not ready when categories were first added) get the starting set. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('stores')->pluck('id') as $storeId) {
            if (! DB::table('expense_categories')->where('store_id', $storeId)->exists()) {
                app(ExpenseDefaults::class)->seed($storeId);
            }
        }
    }

    public function down(): void
    {
    }
};
