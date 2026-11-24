<?php

use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customers get first name, last name and business name. Existing names are split: a name that looks like a business
 * ("Mama Nkechi Ventures", "Grace Stores Ltd") becomes the business name; anything else becomes first name + the rest
 * as last name. `name` stays as the display name, so receipts and reports keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            if (! Schema::hasColumn('customers', 'first_name')) {
                $t->string('first_name', 80)->nullable()->after('name');
            }
            if (! Schema::hasColumn('customers', 'last_name')) {
                $t->string('last_name', 80)->nullable()->after('first_name');
            }
            if (! Schema::hasColumn('customers', 'business_name')) {
                $t->string('business_name', 150)->nullable()->after('last_name');
            }
        });

        DB::table('customers')->whereNull('first_name')->whereNull('last_name')->whereNull('business_name')
            ->whereNotNull('name')->orderBy('id')->select('id', 'name')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    if (trim((string) $row->name) === '') {
                        continue;
                    }
                    DB::table('customers')->where('id', $row->id)->update(Customer::splitName($row->name));
                }
            });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn(['first_name', 'last_name', 'business_name']);
        });
    }
};
