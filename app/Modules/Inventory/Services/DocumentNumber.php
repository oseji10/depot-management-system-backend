<?php

namespace App\Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;

/** Gap-free per-store numbering (ADJ-000001). Call inside the transaction that creates the document. */
class DocumentNumber
{
    public function next(int $storeId, string $prefix): string
    {
        DB::table('document_sequences')->insertOrIgnore([
            'store_id' => $storeId, 'prefix' => $prefix, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = DB::table('document_sequences')->where('store_id', $storeId)->where('prefix', $prefix)->lockForUpdate()->first();
        $next = $row->last_number + 1;

        DB::table('document_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);

        return sprintf('%s-%06d', $prefix, $next);
    }
}
