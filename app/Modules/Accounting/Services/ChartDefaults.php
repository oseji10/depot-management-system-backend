<?php

namespace App\Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;

/** The starting chart of accounts for a depot. Accounts with a role are used by automatic postings. */
class ChartDefaults
{
    /** code, name, type, role */
    public const ACCOUNTS = [
        ['1000', 'Cash on Hand', 'asset', 'cash'],
        ['1010', 'Bank', 'asset', 'bank'],
        ['1100', 'Trade Receivables', 'asset', 'receivables'],
        ['1200', 'Inventory', 'asset', 'inventory'],
        ['1300', 'Rebate Receivable', 'asset', 'rebate_receivable'],
        ['1400', 'Driver Imprest', 'asset', 'imprest'],
        ['1410', 'Fuel Prepayments', 'asset', 'fuel_prepaid'],
        ['1420', 'Staff Receivables', 'asset', 'staff_receivable'],
        ['2000', 'Trade Payables', 'liability', 'payables'],
        ['2100', 'Goods Received Not Invoiced', 'liability', 'grni'],
        ['2200', 'VAT Payable', 'liability', 'vat_payable'],
        ['2300', 'Customer Container Deposits', 'liability', 'container_deposits'],
        ['2400', 'Salaries Payable', 'liability', 'payroll_payable'],
        ['2410', 'Payroll Deductions Payable', 'liability', 'payroll_deductions'],
        ['3000', "Owner's Equity", 'equity', 'equity'],
        ['3100', 'Opening Balance Equity', 'equity', 'opening_balance_equity'],
        ['4000', 'Sales', 'revenue', 'sales'],
        ['4100', 'Rebate Income', 'other_income', 'rebate_income'],
        ['4900', 'Other Income', 'other_income', 'other_income'],
        ['5000', 'Cost of Goods Sold', 'cogs', 'cogs'],
        ['6000', 'Salaries and Wages', 'expense', 'salaries_expense'],
        ['6100', 'Rent', 'expense', null],
        ['6200', 'Fuel and Vehicle Running', 'expense', 'fuel_expense'],
        ['6300', 'Utilities', 'expense', null],
        ['6400', 'Inventory Adjustments', 'expense', 'inventory_adjustment'],
        ['6900', 'General Expenses', 'expense', null],
    ];

    public function seed(int $storeId): void
    {
        foreach (self::ACCOUNTS as [$code, $name, $type, $role]) {
            DB::table('accounts')->insertOrIgnore([
                'store_id' => $storeId, 'code' => $code, 'name' => $name, 'type' => $type, 'role' => $role,
                'is_system' => $role !== null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
