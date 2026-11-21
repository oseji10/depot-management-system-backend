<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff grades (e.g. Senior, Junior) carry the overtime rate, and how Sunday work is paid (basic salary ÷ a divisor,
 * 30 by default). Nothing is hard-coded: each business edits its grades. Overtime entries now say what they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_grades')) {
            Schema::create('staff_grades', function (Blueprint $t) {
                $t->id();
                $t->foreignId('store_id')->constrained()->cascadeOnDelete();
                $t->string('name', 60);
                $t->unsignedBigInteger('overtime_rate')->default(0);    // kobo, per hour or per day (see basis)
                $t->string('overtime_basis', 5)->default('day');        // hour | day
                $t->unsignedSmallInteger('sunday_divisor')->default(30); // Sunday pay = basic salary ÷ this
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['store_id', 'name']);
            });

            // Starting grades from the client's rules; each business can change or add to them.
            foreach (DB::table('stores')->pluck('id') as $storeId) {
                DB::table('staff_grades')->insert([
                    ['store_id' => $storeId, 'name' => 'Senior staff', 'overtime_rate' => 200000, 'overtime_basis' => 'day', 'sunday_divisor' => 30, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                    ['store_id' => $storeId, 'name' => 'Junior staff', 'overtime_rate' => 100000, 'overtime_basis' => 'day', 'sunday_divisor' => 30, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                ]);
            }
        }

        if (! Schema::hasColumn('employees', 'grade_id')) {
            Schema::table('employees', function (Blueprint $t) {
                $t->foreignId('grade_id')->nullable()->after('job_title')->constrained('staff_grades')->nullOnDelete();
            });
        }

        if (Schema::hasTable('overtime_entries') && ! Schema::hasColumn('overtime_entries', 'kind')) {
            Schema::table('overtime_entries', function (Blueprint $t) {
                $t->string('kind', 10)->default('overtime')->after('employee_id'); // overtime | sunday
                $t->string('basis', 5)->default('hour')->after('hours');           // hour | day: what "hours" counts
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('overtime_entries', 'kind')) {
            Schema::table('overtime_entries', fn (Blueprint $t) => $t->dropColumn(['kind', 'basis']));
        }
        if (Schema::hasColumn('employees', 'grade_id')) {
            Schema::table('employees', function (Blueprint $t) {
                $t->dropConstrainedForeignId('grade_id');
            });
        }
        Schema::dropIfExists('staff_grades');
    }
};
