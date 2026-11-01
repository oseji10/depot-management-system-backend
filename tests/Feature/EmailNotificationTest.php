<?php

namespace Tests\Feature;

use App\Mail\NotificationDigestMail;
use App\Mail\SubscriptionPaymentMail;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Services\NotificationService;
use App\Modules\Organization\Services\StoreProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Mail::fake();
        [$this->store, $this->owner] = app(StoreProvisioner::class)->provision([
            'name' => 'Alpha Depot', 'owner_name' => 'Ada', 'owner_email' => 'ada@a.test', 'owner_password' => 'password123',
        ]);
        Sanctum::actingAs($this->owner);
    }

    private function nearTrialEnd(int $days): void
    {
        DB::table('subscriptions')->where('store_id', $this->store->id)->update(['ends_at' => now()->addDays($days)->addHour()]);
        app(\App\Modules\Organization\Services\SubscriptionService::class)->forget($this->store->id);
        Cache::forget("notifications-refresh:{$this->store->id}");
    }

    public function test_a_new_business_owner_gets_a_welcome_email(): void
    {
        Mail::assertQueued(WelcomeMail::class, fn ($m) => $m->hasTo('ada@a.test'));
    }

    public function test_an_ending_trial_creates_one_notification_and_one_email(): void
    {
        $this->nearTrialEnd(2);
        app(NotificationService::class)->refresh($this->store->id);

        $this->assertSame(1, DB::table('app_notifications')->where('store_id', $this->store->id)->where('kind', 'billing')->count());
        Mail::assertQueued(NotificationDigestMail::class, 1);
        Mail::assertQueued(NotificationDigestMail::class, fn ($m) => $m->hasTo('ada@a.test') && str_contains($m->items[0]['title'], 'ends in'));

        // The same event is never announced twice.
        Cache::forget("notifications-refresh:{$this->store->id}");
        app(NotificationService::class)->refresh($this->store->id);
        Mail::assertQueued(NotificationDigestMail::class, 1);
    }

    public function test_switching_email_off_or_filtering_kinds_stops_the_email(): void
    {
        $this->putJson('/api/v1/notifications/email', ['enabled' => false])->assertOk()->assertJsonPath('enabled', false);
        $this->nearTrialEnd(2);
        app(NotificationService::class)->refresh($this->store->id);
        Mail::assertNotQueued(NotificationDigestMail::class);

        $this->putJson('/api/v1/notifications/email', ['enabled' => true, 'kinds' => ['stock']])->assertOk();
        DB::table('app_notifications')->delete();
        $this->nearTrialEnd(1);
        app(NotificationService::class)->refresh($this->store->id);
        Mail::assertNotQueued(NotificationDigestMail::class); // billing is not among the chosen kinds

        $this->getJson('/api/v1/notifications/email')->assertOk()->assertJsonPath('kinds', ['stock'])->assertJsonPath('email', 'ada@a.test');
        $this->putJson('/api/v1/notifications/email', ['enabled' => true, 'kinds' => ['nonsense']])->assertStatus(422);
    }

    public function test_recording_a_payment_emails_a_receipt_once(): void
    {
        $svc = app(\App\Modules\Organization\Services\SubscriptionService::class);
        $svc->recordPayment($this->store->id, null, 500_000, 1, 'manual', 'REF-1', $this->owner->id);
        $svc->recordPayment($this->store->id, null, 500_000, 1, 'manual', 'REF-1', $this->owner->id); // repeat of the same reference

        Mail::assertQueued(SubscriptionPaymentMail::class, 1);
        Mail::assertQueued(SubscriptionPaymentMail::class, fn ($m) => $m->hasTo('ada@a.test') && $m->reference === 'REF-1');
    }

    public function test_the_refresh_command_runs_for_every_business(): void
    {
        $this->nearTrialEnd(3);
        $this->artisan('notifications:refresh')->assertSuccessful();
        Mail::assertQueued(NotificationDigestMail::class);
    }

    public function test_forgot_password_emails_a_link_and_reset_changes_the_password(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@a.test'])->assertOk(); // same answer, no leak
        Mail::assertNotQueued(\App\Mail\PasswordResetMail::class);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@a.test'])->assertOk();
        $url = null;
        Mail::assertQueued(\App\Mail\PasswordResetMail::class, function ($m) use (&$url) {
            $url = $m->url;

            return $m->hasTo('ada@a.test');
        });

        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->postJson('/api/v1/auth/reset-password', ['token' => 'wrong', 'email' => 'ada@a.test', 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1'])->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', ['token' => $q['token'], 'email' => $q['email'], 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'ada@a.test', 'password' => 'newpassword1', 'device_name' => 't'])->assertOk();
    }
}
