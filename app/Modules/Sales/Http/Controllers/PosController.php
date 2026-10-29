<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Services\PosCatalogue;
use App\Modules\Organization\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosController extends Controller
{
    public function __construct(private PosCatalogue $catalogue)
    {
    }

    /**
     * Everything the till needs in one call: sellable products with today's prices (kobo) in each unit, and how many
     * base units are on the shelf in this warehouse. Products with no price are left out; they cannot be sold.
     */
    public function catalogue(Request $request): JsonResponse
    {
        $warehouse = Warehouse::where('is_active', true)->findOrFail((int) $request->query('warehouse_id'));

        // A van is only for its salesperson, and a salesperson only sells from their van.
        $own = Warehouse::vanOf($request->user()->id);
        abort_if($warehouse->isVan() && $warehouse->assigned_user_id !== $request->user()->id, 403, 'That van belongs to another salesperson.');
        abort_if($own && $own->id !== $warehouse->id, 403, 'You sell from your van.');

        return response()->json($this->catalogue->build($warehouse));
    }
}
