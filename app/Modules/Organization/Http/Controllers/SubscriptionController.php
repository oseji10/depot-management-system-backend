<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Services\SubscriptionService;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** A business looking at its own subscription and paying for it online. */
class SubscriptionController extends Controller
{
    public function __construct(private AccessScope $access, private SubscriptionService $subscriptions)
    {
    }

    public function show(): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        return response()->json([
            'subscription' => $this->subscriptions->state($storeId),
            'plans' => DB::table('plans')->where('is_active', true)->orderBy('sort')->orderBy('price')->get(),
            'payments' => DB::table('subscription_payments')->where('store_id', $storeId)->where('status', 'success')->orderByDesc('paid_at')->limit(50)->get(),
            'online' => (bool) config('paystack.secret'),
        ]);
    }

    /** Starts a Paystack payment and returns the page to send the customer to. */
    public function checkout(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate(['plan_id' => ['required', 'integer']]);
        $plan = DB::table('plans')->where('id', $data['plan_id'])->where('is_active', true)->first();
        abort_unless($plan, 422, 'That plan is not available.');
        abort_unless(config('paystack.secret'), 422, 'Online payment is not set up yet. Ask your provider to record your payment.');

        $reference = 'SUB-'.$storeId.'-'.strtoupper(Str::random(12));

        $res = Http::withToken(config('paystack.secret'))->post('https://api.paystack.co/transaction/initialize', array_filter([
            'email' => $request->user()->email, 'amount' => (int) $plan->price, 'reference' => $reference, 'callback_url' => config('paystack.callback_url'),
            'metadata' => ['store_id' => $storeId, 'plan_id' => $plan->id],
        ]));

        abort_unless($res->ok() && $res->json('status'), 422, 'Could not reach the payment provider. Try again.');

        DB::table('subscription_payments')->insert([
            'store_id' => $storeId, 'plan_id' => $plan->id, 'amount' => $plan->price, 'months' => $plan->months, 'method' => 'paystack', 'status' => 'pending',
            'reference' => $reference, 'recorded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['url' => $res->json('data.authorization_url'), 'reference' => $reference]);
    }

    /** The customer is back from Paystack: confirm with Paystack before extending anything. */
    public function verify(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate(['reference' => ['required', 'string', 'max:80']]);
        $payment = DB::table('subscription_payments')->where('store_id', $storeId)->where('reference', $data['reference'])->first();
        abort_unless($payment, 404, 'Payment not found.');

        $this->confirm($payment);

        return $this->show();
    }

    /** Paystack calls this itself, so a payment counts even when the customer closes the page. */
    public function webhook(Request $request): JsonResponse
    {
        $secret = (string) config('paystack.secret');
        $signature = (string) $request->header('x-paystack-signature');

        abort_unless($secret !== '' && hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature), 401, 'Bad signature.');

        if ($request->input('event') === 'charge.success' && ($ref = $request->input('data.reference'))) {
            $payment = DB::table('subscription_payments')->where('reference', $ref)->first();
            $payment && $this->confirm($payment);
        }

        return response()->json(['ok' => true]);
    }

    private function confirm(object $payment): void
    {
        if ($payment->status === 'success') {
            return;
        }

        $res = Http::withToken(config('paystack.secret'))->get('https://api.paystack.co/transaction/verify/'.urlencode($payment->reference));

        if ($res->ok() && $res->json('data.status') === 'success' && (int) $res->json('data.amount') >= (int) $payment->amount) {
            $this->subscriptions->recordPayment($payment->store_id, $payment->plan_id, (int) $payment->amount, (int) $payment->months, 'paystack', $payment->reference, null);
        } elseif ($res->ok() && in_array($res->json('data.status'), ['failed', 'abandoned'], true)) {
            DB::table('subscription_payments')->where('id', $payment->id)->update(['status' => 'failed', 'updated_at' => now()]);
        }
    }
}
