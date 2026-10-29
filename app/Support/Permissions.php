<?php

namespace App\Support;

/**
 * Every permission the platform knows about. Permissions are global; roles are per store.
 * Later modules add their permissions here.
 */
class Permissions
{
    public const CATALOGUE = [
        'Organization' => [
            'branches.view', 'branches.create', 'branches.update', 'branches.delete',
            'warehouses.view', 'warehouses.create', 'warehouses.update', 'warehouses.delete',
            'users.view', 'users.create', 'users.update',
            'roles.view', 'roles.manage',
            'audit.view', 'business.manage',
        ],
        'Catalogue' => [
            'products.view', 'products.manage',
            'pricing.view', 'pricing.manage',
        ],
        'Inventory' => [
            'inventory.view', 'inventory.adjust', 'inventory.approve', 'inventory.transfer', 'inventory.count',
            'goods-receipts.view', 'goods-receipts.record', 'goods-receipts.post', 'goods-receipts.reverse',
            'containers.view', 'containers.manage',
        ],
        'Purchasing' => [
            'purchasing.view', 'purchasing.manage',
        ],
        'Sales' => [
            'sales.view', 'sales.create', 'sales.discount', 'sales.refund',
            'terminals.view', 'terminals.manage',
            'customers.view', 'customers.manage',
        ],
        'Field sales' => [
            'trips.view', 'trips.manage',
        ],
        'Rebates' => [
            'rebates.view', 'rebates.configure', 'rebates.calculate', 'rebates.approve', 'rebates.settle',
        ],
        'Accounting' => [
            'accounts.view', 'accounts.manage', 'journals.create', 'journals.post', 'journals.reverse', 'periods.close',
            'expenses.view', 'expenses.create', 'expenses.manage', 'expenses.approve', 'reports.view',
        ],
        'HR' => [
            'employees.view', 'employees.manage', 'payroll.view', 'payroll.manage', 'payroll.approve', 'payroll.pay',
            'staff-charges.view', 'staff-charges.manage', 'staff-charges.approve',
        ],
        'Fleet' => [
            'deliveries.view', 'deliveries.manage', 'vehicles.view', 'vehicles.manage',
            'imprest.view', 'imprest.manage', 'fuel.view', 'fuel.manage',
        ],
    ];

    public static function all(): array
    {
        return array_merge(...array_values(self::CATALOGUE));
    }

    /** Starting-point roles copied into every new store. Stores can edit them or add their own. */
    public static function roleTemplates(): array
    {
        $all = self::all();
        $views = array_values(array_filter($all, fn ($p) => str_ends_with($p, '.view')));

        return [
            'Owner' => $all,
            'Administrator' => $all,
            'Depot Manager' => array_values(array_diff($all, ['roles.manage', 'business.manage'])),
            'Accountant' => [
                'branches.view', 'rebates.calculate', 'rebates.approve', 'rebates.settle', 'accounts.view', 'accounts.manage', 'journals.create', 'journals.post', 'journals.reverse', 'periods.close', 'expenses.view', 'expenses.create', 'expenses.manage',
                'expenses.approve', 'reports.view', 'rebates.view', 'customers.view', 'customers.manage', 'sales.view', 'sales.create', 'sales.refund', 'employees.view', 'payroll.view', 'payroll.manage', 'payroll.approve', 'payroll.pay', 'staff-charges.view', 'staff-charges.manage', 'staff-charges.approve', 'terminals.view', 'terminals.manage',
                'products.view', 'pricing.view', 'inventory.view', 'purchasing.view', 'goods-receipts.view', 'trips.view', 'containers.view',
                'imprest.view', 'imprest.manage', 'fuel.view', 'fuel.manage', 'vehicles.view',
            ],
            'Inventory Officer' => [
                'branches.view', 'warehouses.view', 'products.view', 'products.manage', 'pricing.view',
                'inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.count', 'containers.view', 'containers.manage',
                'purchasing.view', 'purchasing.manage', 'goods-receipts.view', 'goods-receipts.record', 'goods-receipts.post',
                'trips.view', 'trips.manage',
            ],
            'Sales Cashier' => [
                'branches.view', 'warehouses.view', 'products.view', 'inventory.view', 'customers.view', 'customers.manage', 'sales.view', 'sales.create', 'containers.view', 'containers.manage',
            ],
            // Salespersons and drivers. They can only sell from their own van, and only see their own sales.
            'Field Sales' => ['products.view', 'customers.view', 'customers.manage', 'sales.view', 'sales.create', 'expenses.view', 'expenses.create'],
            'Warehouse Officer' => [
                'warehouses.view', 'products.view', 'inventory.view', 'inventory.transfer', 'inventory.count', 'containers.view', 'containers.manage',
                'purchasing.view', 'goods-receipts.view', 'goods-receipts.record', 'trips.view', 'trips.manage',
            ],
            'Delivery Coordinator' => ['sales.view', 'deliveries.view', 'deliveries.manage', 'vehicles.view', 'vehicles.manage'],
            'Driver' => ['deliveries.view'],
            'HR Officer' => ['users.view', 'employees.view', 'employees.manage', 'payroll.view', 'payroll.manage', 'staff-charges.view', 'staff-charges.manage'],
            'Auditor' => array_values(array_unique(array_merge($views, ['audit.view', 'reports.view']))),
        ];
    }
}
