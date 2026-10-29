<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Access model:
//   is_super_admin = true            -> platform level (all stores)
//   store_id set,  branch_id null    -> store level   (all branches of that store)
//   store_id set,  branch_id set     -> branch level  (that branch only)
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('store_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->nullable()->after('store_id')->constrained()->restrictOnDelete();
            $t->string('phone')->nullable()->after('email');
            $t->boolean('is_super_admin')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('branch_id');
            $t->dropConstrainedForeignId('store_id');
            $t->dropColumn(['phone', 'is_super_admin', 'is_active', 'last_login_at']);
        });
    }
};
