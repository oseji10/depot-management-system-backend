<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('email_notifications')->default(true)->after('is_active'); // master switch
            $t->json('email_kinds')->nullable();                                   // null = every kind; otherwise only the listed ones
        });

        Schema::table('app_notifications', function (Blueprint $t) {
            $t->timestamp('emailed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', fn (Blueprint $t) => $t->dropColumn('emailed_at'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['email_notifications', 'email_kinds']));
    }
};
