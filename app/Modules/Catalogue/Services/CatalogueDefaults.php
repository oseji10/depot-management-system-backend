<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Models\PriceList;
use App\Modules\Catalogue\Models\ProductCategory;
use App\Modules\Catalogue\Models\RebateCategory;
use App\Modules\Catalogue\Models\Unit;
use App\Modules\Organization\Models\Store;

/** Starting data for every store. All of it is editable afterwards. Safe to run more than once. */
class CatalogueDefaults
{
    public const CATEGORIES = [
        'RGB (Crates)', 'PET Drinks (Plastic)', 'Can Drinks', 'Juices', 'Energy Drinks', 'Water',
    ];

    // Names follow Coca-Cola's wholesaler rebate chart. The tiers themselves are configured in the rebate phase.
    public const REBATE_CATEGORIES = [
        'sparkling' => 'Sparkling (SSD)',
        'sparkling_premium' => 'Sparkling Premium (SSD Premium)',
        'water' => 'Water',
        'juice' => 'Juice',
        'energy_premium' => 'Energy Premium',
        'energy_affordable' => 'Energy Affordable',
    ];

    public const UNITS = [
        'BTL' => 'Bottle', 'CAN' => 'Can', 'CRT' => 'Crate', 'CSE' => 'Case', 'PK' => 'Pack',
    ];

    public function seed(Store $store): void
    {
        foreach (self::CATEGORIES as $i => $name) {
            ProductCategory::withoutGlobalScopes()->firstOrCreate(
                ['store_id' => $store->id, 'name' => $name],
                ['sort_order' => $i + 1],
            );
        }

        $i = 0;
        foreach (self::REBATE_CATEGORIES as $code => $name) {
            RebateCategory::withoutGlobalScopes()->firstOrCreate(
                ['store_id' => $store->id, 'code' => $code],
                ['name' => $name, 'sort_order' => ++$i],
            );
        }

        foreach (self::UNITS as $abbr => $name) {
            Unit::withoutGlobalScopes()->firstOrCreate(
                ['store_id' => $store->id, 'abbreviation' => $abbr],
                ['name' => $name],
            );
        }

        PriceList::withoutGlobalScopes()->firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Standard prices'],
            ['type' => 'standard', 'is_default' => true],
        );
    }
}
