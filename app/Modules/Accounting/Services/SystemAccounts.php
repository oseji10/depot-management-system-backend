<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;

/**
 * Makes sure a store has the system accounts the ledger posts to by role.
 * Safe to run any number of times: it only creates or re-activates what is missing.
 */
class SystemAccounts
{
    /** role => [preferred code, name, type] */
    public const REQUIRED = [
        'inventory' => ['1300', 'Inventory', 'asset'],
        'grni' => ['2150', 'Goods Received Not Invoiced', 'liability'],
        'inventory_adjustment' => ['5900', 'Inventory Adjustments', 'expense'],
        'opening_balance_equity' => ['3900', 'Opening Balance Equity', 'equity'],
    ];

    /** @return array<string, string> role => what was done (ok|activated|created) */
    public function ensure(int $storeId): array
    {
        $done = [];

        foreach (self::REQUIRED as $role => [$code, $name, $type]) {
            $base = Account::withoutGlobalScopes()->where('store_id', $storeId);

            if ((clone $base)->where('role', $role)->where('is_active', true)->exists()) {
                $done[$role] = 'ok';
                continue;
            }

            // An account already carries the role but is switched off: turn it back on.
            if ($inactive = (clone $base)->where('role', $role)->first()) {
                $inactive->forceFill(['is_active' => true, 'is_system' => true])->save();
                $done[$role] = 'activated';
                continue;
            }

            // The preferred code exists with no role: claim it instead of duplicating it.
            if ($existing = (clone $base)->where('code', $code)->whereNull('role')->where('type', $type)->first()) {
                $existing->forceFill(['role' => $role, 'is_active' => true, 'is_system' => true])->save();
                $done[$role] = 'activated';
                continue;
            }

            // Otherwise create it, stepping the code forward if another account already uses it.
            $free = (int) $code;
            while ((clone $base)->where('code', (string) $free)->exists()) {
                $free++;
            }

            Account::withoutGlobalScopes()->create([
                'store_id' => $storeId, 'code' => (string) $free, 'name' => $name, 'type' => $type,
                'role' => $role, 'is_system' => true, 'is_active' => true,
            ]);
            $done[$role] = 'created';
        }

        return $done;
    }
}