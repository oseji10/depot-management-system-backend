<?php

namespace App\Modules\Organization\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Where a business stands: full access, read-only (can look and print but not change anything), or blocked.
 * Dates decide it, unless the platform admin has put an override on the business.
 */
class SubscriptionService
{
    private array $cache = [];

    public function setting(string $key, int $default): int
    {
        $v = DB::table('platform_settings')->where('key', $key)->value('value');

        return is_numeric($v) ? (int) $v : $default;
    }

    public function setSetting(string $key, int $value): void
    {
        DB::table('platform_settings')->updateOrInsert(['key' => $key], ['value' => (string) $value]);
    }

    public function state(int $storeId): array
    {
        return $this->cache[$storeId] ??= $this->compute($storeId);
    }

    public function forget(int $storeId): void
    {
        unset($this->cache[$storeId]);
    }

    private function compute(int $storeId): array
    {
        $sub = DB::table('subscriptions')->where('store_id', $storeId)->first();

        // No record at all: nobody has set this business up for billing, so do not lock it.
        if (! $sub) {
            return ['mode' => 'full', 'kind' => 'none', 'ends_at' => null, 'days_left' => null, 'readonly_until' => null, 'overridden' => false, 'override_note' => null, 'plan_id' => null, 'readonly_days' => null];
        }

        $now = now();
        $ends = $sub->ends_at ? Carbon::parse($sub->ends_at) : null;
        $roDays = $sub->readonly_days ?? $this->setting('readonly_days', 14);
        $roUntil = $ends?->copy()->addDays($roDays);

        $mode = 'full';

        if ($ends && $now->gt($ends)) {
            $mode = $now->lte($roUntil) ? 'readonly' : 'blocked';
        }

        $overridden = false;

        if ($sub->override_mode && (! $sub->override_until || $now->lte(Carbon::parse($sub->override_until)))) {
            $mode = $sub->override_mode;
            $overridden = true;
        }

        return [
            'mode' => $mode, 'kind' => $sub->kind, 'plan_id' => $sub->plan_id, 'ends_at' => $ends?->toIso8601String(), 'readonly_until' => $roUntil?->toIso8601String(),
            'days_left' => $ends ? (int) ceil($now->floatDiffInDays($ends, false)) : null, 'overridden' => $overridden, 'override_note' => $overridden ? $sub->override_note : null,
            'override_until' => $overridden && $sub->override_until ? Carbon::parse($sub->override_until)->toIso8601String() : null, 'readonly_days' => $roDays,
        ];
    }

    /** A free trial from now. Replaces whatever period the business had. */
    public function startTrial(int $storeId, ?int $days = null): void
    {
        $days ??= $this->setting('trial_days', 14);
        $this->put($storeId, ['kind' => 'trial', 'plan_id' => null, 'starts_at' => now(), 'ends_at' => now()->addDays($days)]);
    }

    /** Free extra days on top of the current end date (or from today when it has already run out). */
    public function extend(int $storeId, int $days): void
    {
        $sub = DB::table('subscriptions')->where('store_id', $storeId)->first();
        $base = $sub?->ends_at && Carbon::parse($sub->ends_at)->isFuture() ? Carbon::parse($sub->ends_at) : now();
        $this->put($storeId, ['starts_at' => $sub?->starts_at ?? now(), 'ends_at' => $base->copy()->addDays($days)]);
    }

    /** Records a payment that has been received and moves the end date forward. Safe to call twice for the same reference. */
    public function recordPayment(int $storeId, ?int $planId, int $amount, int $months, string $method, ?string $reference, ?int $userId, ?string $note = null): int
    {
        $already = $reference && DB::table('subscription_payments')->where('reference', $reference)->where('status', 'success')->exists();

        $id = DB::transaction(function () use ($storeId, $planId, $amount, $months, $method, $reference, $userId, $note) {
            $pending = $reference ? DB::table('subscription_payments')->where('reference', $reference)->lockForUpdate()->first() : null;

            if ($pending && $pending->status === 'success') {
                return (int) $pending->id;
            }

            $sub = DB::table('subscriptions')->where('store_id', $storeId)->lockForUpdate()->first();
            $from = $sub?->ends_at && Carbon::parse($sub->ends_at)->isFuture() ? Carbon::parse($sub->ends_at) : now();
            $to = $from->copy()->addMonthsNoOverflow($months);
            $fields = ['status' => 'success', 'paid_at' => now(), 'period_from' => $from, 'period_to' => $to, 'updated_at' => now()];

            if ($pending) {
                DB::table('subscription_payments')->where('id', $pending->id)->update($fields);
                $id = (int) $pending->id;
            } else {
                $id = DB::table('subscription_payments')->insertGetId([
                    'store_id' => $storeId, 'plan_id' => $planId, 'amount' => $amount, 'months' => $months, 'method' => $method, 'reference' => $reference,
                    'recorded_by' => $userId, 'note' => $note, 'created_at' => now(), ...$fields,
                ]);
            }

            $this->put($storeId, ['kind' => 'paid', 'plan_id' => $planId, 'starts_at' => $sub?->starts_at ?? now(), 'ends_at' => $to]);

            return $id;
        });

        if (! $already) {
            app(NotificationEmailer::class)->paymentReceived($storeId, $id);
        }

        return $id;
    }

    /** mode: full | readonly | blocked | null (follow the dates). */
    public function override(int $storeId, ?string $mode, ?string $until, ?string $note, int $userId, ?int $readonlyDays = null, bool $setReadonlyDays = false): void
    {
        $fields = ['override_mode' => $mode, 'override_until' => $mode ? $until : null, 'override_note' => $mode ? $note : null, 'override_by' => $mode ? $userId : null];

        if ($setReadonlyDays) {
            $fields['readonly_days'] = $readonlyDays;
        }

        $this->put($storeId, $fields);
    }

    private function put(int $storeId, array $fields): void
    {
        DB::table('subscriptions')->updateOrInsert(['store_id' => $storeId], [...$fields, 'updated_at' => now()]);
        DB::table('subscriptions')->where('store_id', $storeId)->whereNull('created_at')->update(['created_at' => now()]);
        $this->forget($storeId);
    }
}
