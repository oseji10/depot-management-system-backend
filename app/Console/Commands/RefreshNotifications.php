<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Organization\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Notifications (and their emails) are normally worked out when someone has the app open. Run this on a schedule so alerts
 * still go out overnight or on a weekend: add  Schedule::command('notifications:refresh')->hourly();  to routes/console.php.
 */
class RefreshNotifications extends Command
{
    protected $signature = 'notifications:refresh {--store= : Only this store id}';

    protected $description = 'Work out new alerts for every active business and email them';

    public function handle(NotificationService $service): int
    {
        $stores = DB::table('stores')->where('is_active', true)->when($this->option('store'), fn ($q, $id) => $q->where('id', $id))->pluck('id');

        foreach ($stores as $storeId) {
            // The data queries are scoped to the signed-in user, so act as one of the business's own head-office users.
            $ownerId = DB::table('user_stores')->where('store_id', $storeId)->whereNull('branch_id')->orderByDesc('is_owner')->orderBy('user_id')->pluck('user_id')
                ->first(fn ($id) => User::where('id', $id)->where('is_active', true)->where('is_super_admin', false)->exists());

            if (! $ownerId) {
                continue;
            }

            $owner = User::find($ownerId);
            $owner->store_id = $storeId; // in memory only: this person may be working in another of their businesses right now
            $owner->branch_id = null;

            Auth::setUser($owner);
            app(PermissionRegistrar::class)->setPermissionsTeamId($storeId);
            $service->refresh($storeId);
        }

        Auth::forgetGuards();
        $this->info($stores->count().' business(es) checked.');

        return self::SUCCESS;
    }
}
