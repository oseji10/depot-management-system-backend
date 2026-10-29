<?php

namespace App\Modules\Procurement\Support;

use App\Modules\Catalogue\Models\ProductUnit;
use Illuminate\Validation\ValidationException;

class UnitResolver
{
    /** Base units per unit for this product, or a validation error on the given lines.N.unit_id field. */
    public static function factor(int $productId, int $unitId, int $lineIndex): int
    {
        $factor = ProductUnit::where('product_id', $productId)->where('unit_id', $unitId)->where('is_active', true)->value('factor');

        if ($factor === null) {
            throw ValidationException::withMessages(["lines.{$lineIndex}.unit_id" => 'This product is not sold in that unit.']);
        }

        return (int) $factor;
    }
}
