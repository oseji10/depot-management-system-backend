<?php

namespace App\Modules\Organization\Services;

use App\Mail\AccountCreatedMail;
use App\Mail\NotificationDigestMail;
use App\Mail\SubscriptionPaymentMail;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Modules\Organization\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;

/**
 * Turns notifications and a few account events into emails. Email never breaks the request that triggered it:
 * every send is wrapped, and a failure is only reported to the log.
 */
class NotificationEmailer
{
    /** What a person can switch on or off. */
    public const KINDS = [
        'milestone' => 'Sales milestones',
        'stock' => 'Low stock',
        'expiry' => 'Stock that is expiring',
        'fleet' => 'Vehicle papers',
        'sync' => 'Offline sales that need attention',
        'approval' => 'Staff charges awaiting approval',
        'payroll' => 'Payroll awaiting approval',
        'billing' => 'Subscription and billing',
    ];

    /** @param array<int, array<string, mixed>> $fresh notifications that were just created for this store */
    public function digest(int $storeId, array $fresh): void
    {
        if ($fresh === []) {
            return;
        }

        $storeName = (string) DB::table('stores')->where('id', $storeId)->value('name');
        $base = config('depot.frontend_url');

        foreach ($this->users($storeId) as $user) {
            $items = collect($fresh)
                ->filter(fn ($n) => ($n['permission'] ?? null) === null || $user->can($n['permission']))
                ->filter(fn ($n) => $user->email_kinds === null || in_array($n['kind'], (array) $user->email_kinds, true))
                ->map(fn ($n) => ['title' => $n['title'], 'body' => $n['body'] ?? null, 'severity' => $n['severity'] ?? 'info', 'url' => ! empty($n['url']) ? $base.$n['url'] : null])
                ->values()->all();

            if ($items !== []) {
                $this->deliver($user->email, new NotificationDigestMail($user->name, $storeName, $items, $base.'/'));
            }
        }
    }

    public function paymentReceived(int $storeId, int $paymentId): void
    {
        $p = DB::table('subscription_payments')->where('id', $paymentId)->first();

        if (! $p) {
            return;
        }

        $storeName = (string) DB::table('stores')->where('id', $storeId)->value('name');
        $plan = $p->plan_id ? (string) DB::table('plans')->where('id', $p->plan_id)->value('name') : 'Subscription';
        $amount = '₦'.number_format(((int) $p->amount) / 100, 2);
        $until = $p->period_to ? \Illuminate\Support\Carbon::parse($p->period_to)->format('j M Y') : '-';
        $reference = (string) ($p->reference ?: ('PAY-'.$p->id));

        // Billing receipts go to whoever manages the business, (the master email switch still applies).
        foreach ($this->users($storeId, 'business.manage') as $user) {
            $this->deliver($user->email, new SubscriptionPaymentMail($user->name, $storeName, $plan ?: 'Subscription', $amount, $until, $reference, config('depot.frontend_url').'/subscription/'));
        }
    }

    public function welcome(Store $store, User $owner): void
    {
        if (! $owner->email) {
            return;
        }

        $days = DB::table('subscriptions')->where('store_id', $store->id)->where('kind', 'trial')->value('ends_at');
        $trialDays = $days ? max(0, (int) ceil(now()->floatDiffInDays($days, false))) : null;

        $this->deliver($owner->email, new WelcomeMail($owner->name, $store->name, $owner->email, config('depot.frontend_url').'/login/', $trialDays));
    }

    public function accountCreated(User $user, int $storeId, string $role): void
    {
        if (! $user->email) {
            return;
        }

        $storeName = (string) DB::table('stores')->where('id', $storeId)->value('name');
        $base = config('depot.frontend_url');

        $this->deliver($user->email, new AccountCreatedMail($user->name, $storeName, $user->email, $role, $base.'/login/', $base.'/forgot-password/'));
    }

    /**
     * Head-office people of the business (not branch-restricted) who have email switched on and, when a permission is given, hold it.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function users(int $storeId, ?string $permission = null)
    {
        $ids = DB::table('user_stores')->where('store_id', $storeId)->whereNull('branch_id')->pluck('user_id');

        $users = User::whereIn('id', $ids)->where('is_active', true)->where('email_notifications', true)->whereNotNull('email')->get();

        app(PermissionRegistrar::class)->setPermissionsTeamId($storeId);

        return $users->each(fn (User $u) => $u->unsetRelation('roles')->unsetRelation('permissions'))
            ->filter(fn (User $u) => $permission === null || $u->can($permission))
            ->values();
    }

    private function deliver(string $email, $mailable): void
    {
        try {
            Mail::to($email)->queue($mailable);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
