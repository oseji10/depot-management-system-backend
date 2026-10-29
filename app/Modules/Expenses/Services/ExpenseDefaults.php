<?php

namespace App\Modules\Expenses\Services;

use Illuminate\Support\Facades\DB;

/** Starting expense categories, each tied to an account in the standard chart. Stores can add their own. */
class ExpenseDefaults
{
    /** name => account code */
    public const CATEGORIES = [
        'Fuel' => '6200',
        'Vehicle repairs' => '6200',
        'Salaries and wages' => '6000',
        'Rent' => '6100',
        'Electricity and utilities' => '6300',
        'Loading and offloading' => '6900',
        'Levies and park fees' => '6900',
        'Meals and allowances' => '6900',
        'Other' => '6900',
    ];

    public function seed(int $storeId): void
    {
        foreach (self::CATEGORIES as $name => $code) {
            $accountId = DB::table('accounts')->where('store_id', $storeId)->where('code', $code)->value('id');

            if (! $accountId) {
                continue;
            }

            DB::table('expense_categories')->insertOrIgnore([
                'store_id' => $storeId, 'name' => $name, 'account_id' => $accountId, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
