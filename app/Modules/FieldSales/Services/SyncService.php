<?php

namespace App\Modules\FieldSales\Services;

use App\Models\User;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\FieldSales\Models\SyncIssue;
use App\Modules\FieldSales\Models\Trip;
use App\Modules\Organization\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SalePayment;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Takes what a salesperson's phone queued while it had no signal and records it, oldest first.
 *
 * Every item carries a client_ref chosen on the phone, so sending the same thing twice (a dropped connection, a
 * retry) never records it twice. Each item succeeds or fails on its own:
 *   created    recorded now
 *   duplicate  already recorded earlier; nothing changed
 *   rejected   the rules say no (not enough stock, credit limit, trip closed...). Kept as a sync issue for a manager.
 *   retry      something broke on the server; the phone should send it again later
 */
class SyncService
{
    public function __construct(private SaleService $sales, private ExpenseService $expenses)
    {
    }

    /**
     * @param  array<int, array{type:string, client_ref:string, data:array}>  $items
     * @return array<int, array{type:string, client_ref:string, status:string, id?:int, number?:string, error?:string}>
     */
    public function process(array $items, User $rep, ?string $snapshotAt): array
    {
        $results = [];
        $customers = [];   // client_ref of a customer made on the phone => real id

        foreach ($items as $item) {
            $type = $item['type'];
            $ref = $item['client_ref'];

            try {
                $results[] = ['type' => $type, 'client_ref' => $ref, ...$this->one($type, $ref, $item['data'], $rep, $snapshotAt, $customers)];
            } catch (Throwable $e) {
                if ($this->isRule($e)) {
                    $message = $this->messageOf($e);
                    $this->keep($rep, $type, $ref, [...$item['data'], '_snapshot_at' => $snapshotAt], $message);
                    $results[] = ['type' => $type, 'client_ref' => $ref, 'status' => 'rejected', 'error' => $message];
                } else {
                    report($e);
                    $results[] = ['type' => $type, 'client_ref' => $ref, 'status' => 'retry', 'error' => 'The server could not record this yet.'];
                }
            }
        }

        return $results;
    }

    /** Runs a rejected item again as the salesperson who made it. Used by managers. */
    public function retry(SyncIssue $issue, bool $reprice = false): array
    {
        $rep = User::findOrFail($issue->user_id);
        $payload = $issue->payload;

        try {
            $result = $this->one($issue->kind, $issue->client_ref, $payload, $rep, $payload['_snapshot_at'] ?? null, [], $reprice);
        } catch (Throwable $e) {
            // A real fault (not a business rule) is logged, and its message is shown to the manager instead of a bare 500.
            if (! $this->isRule($e)) {
                report($e);
                $issue->update(['error' => 'Server error: '.class_basename($e).': '.mb_substr($e->getMessage(), 0, 400), 'attempts' => $issue->attempts + 1]);

                return ['status' => 'rejected', 'error' => $issue->error];
            }

            $issue->update(['error' => $this->messageOf($e), 'attempts' => $issue->attempts + 1]);

            return ['status' => 'rejected', 'error' => $issue->error];
        }

        return $result;
    }

    private function one(string $type, string $ref, array $data, User $rep, ?string $snapshotAt, array &$customers, bool $reprice = false): array
    {
        return match ($type) {
            'customer' => $this->customer($ref, $data, $rep, $customers),
            'sale' => $this->sale($ref, $data, $rep, $snapshotAt, $customers, $reprice),
            'expense' => $this->expense($ref, $data, $rep),
            default => throw ValidationException::withMessages(['type' => "Unknown item type {$type}."]),
        };
    }

    private function customer(string $ref, array $d, User $rep, array &$customers): array
    {
        $existing = Customer::withoutGlobalScopes()->where('store_id', $rep->store_id)->where('client_ref', $ref)->first();

        if ($existing) {
            $customers[$ref] = $existing->id;
            $this->resolve($rep, 'customer', $ref, "Recorded as customer {$existing->id}");

            return ['status' => 'duplicate', 'id' => $existing->id];
        }

        $d = Validator::make($d, [
            'name' => ['required', 'string', 'max:150'], 'type' => ['nullable', Rule::in(Customer::TYPES)],
            'phone' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $phone = ($d['phone'] ?? null) ?: null;

        // The same person may already be on file (someone added them at the depot meanwhile). Use that record.
        if ($phone && ($same = Customer::withoutGlobalScopes()->where('store_id', $rep->store_id)->where('phone', $phone)->first())) {
            $customers[$ref] = $same->id;

            return ['status' => 'duplicate', 'id' => $same->id];
        }

        $customer = Customer::create([
            'store_id' => $rep->store_id, 'client_ref' => $ref, 'name' => $d['name'], 'type' => $d['type'] ?? 'retail',
            'phone' => $phone, 'address' => $d['address'] ?? null, 'credit_limit' => 0,
        ]);

        $customers[$ref] = $customer->id;
        $this->resolve($rep, 'customer', $ref, "Recorded as customer {$customer->id}");

        return ['status' => 'created', 'id' => $customer->id];
    }

    private function sale(string $ref, array $d, User $rep, ?string $snapshotAt, array &$customers, bool $reprice): array
    {
        $existing = Sale::withoutGlobalScopes()->where('store_id', $rep->store_id)->where('client_ref', $ref)->first();

        if ($existing) {
            $this->resolve($rep, 'sale', $ref, "Recorded as {$existing->number}");

            return ['status' => 'duplicate', 'id' => $existing->id, 'number' => $existing->number];
        }

        // A sale to a customer made on the phone refers to them by the phone's reference.
        if (! empty($d['customer_ref'])) {
            $d['customer_id'] = $customers[$d['customer_ref']]
                ?? Customer::withoutGlobalScopes()->where('store_id', $rep->store_id)->where('client_ref', $d['customer_ref'])->value('id')
                ?? throw ValidationException::withMessages(['customer_id' => 'The customer for this sale could not be recorded.']);
        }

        $d = Validator::make($d, [
            'customer_id' => ['nullable', 'integer'], 'customer_ref' => ['nullable', 'string', 'max:64'],
            'channel' => ['nullable', Rule::in(Sale::CHANNELS)], 'note' => ['nullable', 'string', 'max:500'], 'sold_at' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer'], 'lines.*.unit_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'], 'lines.*.unit_price' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'lines.*.mix_layout_id' => ['nullable', 'integer', 'required_with:lines.*.mix_group'], 'lines.*.mix_group' => ['nullable', 'string', 'max:20'],
            'payments' => ['sometimes', 'array', 'max:5'],
            'empties_returned' => ['sometimes', 'array', 'max:20'],
            'empties_returned.*.container_type_id' => ['required', 'integer'],
            'empties_returned.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'payments.*.method' => ['required', Rule::in(SalePayment::METHODS)], 'payments.*.amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'payments.*.reference' => ['nullable', 'string', 'max:80'], 'payments.*.pos_terminal_id' => ['nullable', 'integer'],
        ])->validate();

        if (! empty($d['customer_id']) && ! Customer::withoutGlobalScopes()->where('store_id', $rep->store_id)->whereKey($d['customer_id'])->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'That customer does not exist.']);
        }

        $van = Warehouse::vanOf($rep->id) ?? throw ValidationException::withMessages(['warehouse_id' => 'This account has no van.']);
        $d['warehouse_id'] = $van->id;       // the phone cannot choose where stock comes from
        $d['client_ref'] = $ref;
        $d['channel'] = $d['channel'] ?? 'delivery';
        unset($d['customer_ref']);

        $this->actAs($rep);

        $sale = $this->sales->create($d, $rep, [
            'offline' => true, 'sold_at' => $d['sold_at'] ?? null, 'snapshot_at' => $snapshotAt, 'reprice' => $reprice,
        ]);

        $this->resolve($rep, 'sale', $ref, "Recorded as {$sale->number}");

        return ['status' => 'created', 'id' => $sale->id, 'number' => $sale->number];
    }

    private function expense(string $ref, array $d, User $rep): array
    {
        $existing = Expense::withoutGlobalScopes()->where('store_id', $rep->store_id)->where('client_ref', $ref)->first();

        if ($existing) {
            $this->resolve($rep, 'expense', $ref, "Recorded as {$existing->number}");

            return ['status' => 'duplicate', 'id' => $existing->id, 'number' => $existing->number];
        }

        $d = Validator::make($d, [
            'category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('store_id', $rep->store_id)],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'], 'method' => ['required', Rule::in(Expense::METHODS)],
            'expense_date' => ['nullable', 'date_format:Y-m-d'], 'paid_to' => ['nullable', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $this->actAs($rep);

        $expense = $this->expenses->create([...$d, 'client_ref' => $ref, 'offline' => true], $rep);
        $this->resolve($rep, 'expense', $ref, "Recorded as {$expense->number}");

        return ['status' => 'created', 'id' => $expense->id, 'number' => $expense->number];
    }

    /** Services run with the salesperson's permissions (discount rules, branch scope), even when a manager triggers a retry. */
    private function actAs(User $rep): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($rep->store_id);
    }

    private function keep(User $rep, string $kind, string $ref, array $data, string $error): void
    {
        $van = Warehouse::vanOf($rep->id);
        $trip = $van ? Trip::withoutGlobalScopes()->where('van_warehouse_id', $van->id)->where('status', 'open')->value('id') : null;

        $issue = SyncIssue::withoutGlobalScopes()->where('store_id', $rep->store_id)->where('kind', $kind)->where('client_ref', $ref)->first();

        if ($issue) {
            $issue->update(['error' => mb_substr($error, 0, 500), 'attempts' => $issue->attempts + 1, 'status' => 'open']);

            return;
        }

        SyncIssue::create([
            'store_id' => $rep->store_id, 'branch_id' => $van?->branch_id ?? $rep->branch_id, 'user_id' => $rep->id, 'trip_id' => $trip,
            'kind' => $kind, 'client_ref' => $ref, 'payload' => $data, 'error' => mb_substr($error, 0, 500),
        ]);
    }

    private function resolve(User $rep, string $kind, string $ref, string $how): void
    {
        SyncIssue::withoutGlobalScopes()->where('store_id', $rep->store_id)->where('kind', $kind)->where('client_ref', $ref)
            ->where('status', 'open')->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution' => $how]);
    }

    /** A rule said no (as opposed to the server breaking). */
    private function isRule(Throwable $e): bool
    {
        return $e instanceof ValidationException || ($e instanceof HttpException && $e->getStatusCode() >= 400 && $e->getStatusCode() < 500);
    }

    private function messageOf(Throwable $e): string
    {
        return $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : ($e->getMessage() ?: 'Not allowed.');
    }
}
