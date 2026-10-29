<?php

namespace App\Modules\Rebates\Services;

use App\Modules\Catalogue\Models\RebateCategory;
use App\Modules\Catalogue\Services\CatalogueDefaults;
use App\Modules\Rebates\Models\RebateAgreement;
use Illuminate\Support\Facades\DB;

/**
 * Starting points that save typing. A template is only data copied into the store's own agreement, which is then edited
 * like any other. Nothing here is read when rebates are calculated.
 */
class RebateTemplates
{
    public const WHOLESALER_OCT_2026 = 'coca_cola_wholesaler_oct_2026';

    /** cluster => [volume_above, [category code => [[from_cases, rate %], ...]]]. Transcribed from the wholesaler chart. */
    private const WHOLESALER = [
        'Standard' => [null, [
            'sparkling' => [[0, 6.0], [1000, 6.5], [2001, 7.0]],
            'sparkling_premium' => [[150, 7.0], [201, 8.0], [501, 9.0]],
            'water' => [[0, 8.0], [400, 8.5], [1001, 9.0]],
            'juice' => [[0, 4.5], [70, 5.0], [201, 5.5]],
            'energy_premium' => [[0, 3.0], [8, 3.5], [16, 4.0]],
            'energy_affordable' => [[0, 4.0], [500, 4.5], [1001, 5.0]],
        ]],
        'Maxi' => [12000, [
            'sparkling' => [[0, 6.5], [2001, 7.0], [17001, 7.5]],
            'sparkling_premium' => [[201, 8.0], [501, 9.0], [5101, 9.5]],
            'water' => [[0, 8.5], [1001, 9.0], [3501, 9.5]],
            'juice' => [[0, 5.0], [201, 5.5], [1001, 6.0]],
            'energy_premium' => [[0, 3.5], [16, 4.0], [71, 4.5]],
            'energy_affordable' => [[0, 4.5], [1001, 5.0], [8501, 5.5]],
        ]],
        'Mega' => [40000, [
            'sparkling' => [[0, 7.0], [17001, 7.5], [45001, 8.0], [62001, 8.5]],
            'sparkling_premium' => [[501, 9.0], [5101, 9.5], [15801, 10.0], [25001, 10.5]],
            'water' => [[0, 9.0], [5501, 9.5], [7501, 10.0]],
            'juice' => [[0, 5.5], [1001, 6.0], [3001, 6.5]],
            'energy_premium' => [[0, 4.0], [71, 4.5], [201, 5.0]],
            'energy_affordable' => [[0, 5.0], [8501, 5.5], [22501, 6.0]],
        ]],
    ];

    public static function available(): array
    {
        return [self::WHOLESALER_OCT_2026 => 'Coca-Cola wholesaler rebate chart (October 2026)'];
    }

    public function create(int $storeId, string $template, string $effectiveFrom): RebateAgreement
    {
        abort_unless($template === self::WHOLESALER_OCT_2026, 422, 'Unknown template.');

        return DB::transaction(function () use ($storeId, $effectiveFrom) {
            $ids = [];

            foreach (CatalogueDefaults::REBATE_CATEGORIES as $code => $name) {
                $ids[$code] = RebateCategory::withoutGlobalScopes()->firstOrCreate(
                    ['store_id' => $storeId, 'code' => $code], ['name' => $name, 'sort_order' => count($ids) + 1],
                )->id;
            }

            // Standard rates and thresholds are a starting point; the depot confirms them against its own agreement.
            $agreement = RebateAgreement::withoutGlobalScopes()->create([
                'store_id' => $storeId, 'name' => self::available()[self::WHOLESALER_OCT_2026], 'effective_from' => $effectiveFrom,
                'method' => 'all_units', 'accounting_treatment' => 'income', 'is_active' => true,
                'note' => 'Loaded from the wholesaler chart. Check every rate and the tier method against your signed agreement.',
            ]);

            $order = 0;

            foreach (self::WHOLESALER as $name => [$above, $categories]) {
                $cluster = $agreement->clusters()->create(['store_id' => $storeId, 'name' => $name, 'volume_above' => $above, 'sort_order' => ++$order]);

                foreach ($categories as $code => $tiers) {
                    foreach ($tiers as [$from, $rate]) {
                        $cluster->tiers()->create(['store_id' => $storeId, 'rebate_category_id' => $ids[$code], 'from_cases' => $from, 'rate' => $rate]);
                    }
                }
            }

            return $agreement->load('clusters.tiers');
        });
    }
}
