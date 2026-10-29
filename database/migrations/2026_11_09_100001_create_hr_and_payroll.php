<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();   // their login, if they have one
            $t->string('staff_no', 30);
            $t->string('name');
            $t->string('phone', 30)->nullable();
            $t->string('job_title')->nullable();
            $t->string('department')->nullable();
            $t->date('hired_on')->nullable();
            $t->date('left_on')->nullable();
            $t->string('status', 12)->default('active');      // active | left
            $t->unsignedBigInteger('basic_salary')->default(0); // kobo per month
            $t->string('bank_name')->nullable();
            $t->string('account_number', 30)->nullable();
            $t->string('account_name')->nullable();
            $t->string('notes')->nullable();
            $t->timestamps();
            $t->unique(['store_id', 'staff_no']);
            $t->index(['store_id', 'status']);
        });

        // Allowances and deductions are defined by the business, never hardcoded.
        Schema::create('pay_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('kind', 12);                            // earning | deduction
            $t->string('calc', 20)->default('fixed');          // fixed | percent_of_basic
            $t->unsignedBigInteger('value')->default(0);       // kobo when fixed, basis points (1000 = 10%) when percent
            $t->boolean('apply_to_all')->default(false);       // given to every active employee automatically
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['store_id', 'name']);
        });

        Schema::create('employee_pay_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('pay_item_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('value')->nullable();       // overrides the item's value for this person
            $t->timestamps();
            $t->unique(['employee_id', 'pay_item_id']);
        });

        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();   // null = every branch
            $t->string('number', 30);
            $t->string('period', 7);                           // YYYY-MM
            $t->string('status', 12)->default('draft');        // draft | approved | paid | void
            $t->unsignedBigInteger('total_gross')->default(0);
            $t->unsignedBigInteger('total_deductions')->default(0);
            $t->unsignedBigInteger('total_net')->default(0);
            $t->string('note')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->string('pay_method', 10)->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('void_reason')->nullable();
            $t->timestamps();
            $t->unique(['store_id', 'number']);
            $t->index(['store_id', 'period']);
        });

        Schema::create('payslips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('basic')->default(0);
            $t->unsignedBigInteger('gross')->default(0);
            $t->unsignedBigInteger('charges_total')->default(0);   // recovered from staff charges
            $t->unsignedBigInteger('other_deductions')->default(0);
            $t->unsignedBigInteger('net')->default(0);
            $t->timestamps();
            $t->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('payslip_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 12);                            // earning | deduction
            $t->string('source', 16);                          // basic | pay_item | staff_charge | manual
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('label');
            $t->unsignedBigInteger('amount');
            $t->timestamps();
        });

        // Money an employee owes the business, recovered through payroll.
        Schema::create('staff_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->string('number', 30);
            $t->string('reason', 16)->default('damage');       // damage | shortage | loss | other
            $t->string('description');
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedInteger('quantity')->nullable();
            $t->unsignedBigInteger('unit_amount')->nullable(); // kobo
            $t->unsignedBigInteger('amount');                  // kobo, total owed
            $t->unsignedBigInteger('recovered')->default(0);
            $t->unsignedSmallInteger('installments')->default(1);   // months to spread it over
            $t->string('status', 12)->default('pending');      // pending | approved | cleared | rejected | waived
            $t->date('incident_on')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note')->nullable();
            $t->timestamps();
            $t->unique(['store_id', 'number']);
            $t->index(['employee_id', 'status']);
        });

        foreach (DB::table('stores')->pluck('id') as $storeId) {
            foreach ([
                ['1420', 'Staff Receivables', 'asset', 'staff_receivable'],
                ['2400', 'Salaries Payable', 'liability', 'payroll_payable'],
                ['2410', 'Payroll Deductions Payable', 'liability', 'payroll_deductions'],
            ] as [$code, $name, $type, $role]) {
                DB::table('accounts')->insertOrIgnore([
                    'store_id' => $storeId, 'code' => $code, 'name' => $name, 'type' => $type, 'role' => $role,
                    'is_system' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('accounts')->where('store_id', $storeId)->where('code', '6000')->whereNull('role')->update(['role' => 'salaries_expense', 'is_system' => true]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_charges');
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employee_pay_items');
        Schema::dropIfExists('pay_items');
        Schema::dropIfExists('employees');
    }
};
