<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/** A variation (Crate, Half crate, Bottle…) can carry its usual size, used to prefill a product's "how many inside". */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('units', 'usual_size')) {
            Schema::table('units', function ($t) {
                $t->unsignedSmallInteger('usual_size')->nullable()->after('abbreviation');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('units', 'usual_size')) {
            Schema::table('units', fn ($t) => $t->dropColumn('usual_size'));
        }
    }
};
