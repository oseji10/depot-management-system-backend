<?php

namespace App\Modules\Fleet\Services;

use App\Models\User;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\FieldSales\Models\Trip;
use App\Modules\Fleet\Models\FuelStation;
use App\Modules\Fleet\Models\FuelStationEntry;
use App\Modules\Fleet\Models\FuelVoucher;
use App\Modules\Inventory\Services\DocumentNumber;
use App\Modules\Organization\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Money prepaid to a fuel station is an asset (Dr Fuel Prepayments / Cr Cash or Bank). A voucher lets a driver draw fuel
 * up to a limit; when the station's slip comes back the real amount is booked (Dr Fuel expense / Cr Fuel Prepayments)
 * and comes off the station's balance. Vouchers already handed out are set aside so the balance is never over-promised.
 */
class FuelService
{
    public function __construct(private Ledger $ledger, private DocumentNumber $numbers)
    {
    }

    public function topUp(FuelStation $station, int $amount, string $method, ?string $reference, ?string $date, User $actor): FuelStationEntry
    {
        return DB::transaction(function () use ($station, $amount, $method, $reference, $date, $actor) {
            $date ??= now()->toDateString();

            $entry = FuelStationEntry::create([
                'store_id' => $station->store_id, 'fuel_station_id' => $station->id, 'type' => 'top_up', 'amount' => $amount,
                'entry_date' => $date, 'method' => $method, 'reference' => $reference, 'created_by' => $actor->id,
            ]);

            $this->ledger->post($station->store_id, [
                'date' => $date, 'memo' => "Fuel prepayment to {$station->name}", 'branch_id' => $actor->branch_id,
                'source' => $entry, 'posting_key' => "fuel-topup:{$entry->id}", 'user_id' => $actor->id,
                'lines' => [['account' => 'fuel_prepaid', 'debit' => $amount], ['account' => $method === 'cash' ? 'cash' : 'bank', 'credit' => $amount]],
            ]);

            return $entry;
        });
    }

    public function issue(FuelStation $station, User $driver, int $amount, ?string $vehicle, ?string $note, User $actor, ?int $vehicleId = null): FuelVoucher
    {
        return DB::transaction(function () use ($station, $driver, $amount, $vehicle, $note, $actor, $vehicleId) {
            $station = FuelStation::lockForUpdate()->findOrFail($station->id);

            abort_unless($station->is_active, 422, 'This fuel station is switched off.');

            $available = $station->balance() - $station->committed();

            if ($amount > $available) {
                throw ValidationException::withMessages(['amount' => sprintf('Only ₦%s is left with %s after vouchers already issued. Top the station up first.', number_format(max(0, $available) / 100), $station->name)]);
            }

            $van = Warehouse::vanOf($driver->id);
            $trip = $van ? Trip::withoutGlobalScopes()->where('van_warehouse_id', $van->id)->where('status', 'open')->first() : null;
            $branchId = $van?->branch_id ?? $driver->branch_id ?? $actor->branch_id
                ?? Warehouse::withoutGlobalScopes()->where('store_id', $station->store_id)->value('branch_id');

            // A registered vehicle wins over free text, and the voucher follows the trip's vehicle when none is named.
            $registered = $vehicleId ? \App\Modules\Fleet\Models\Vehicle::find($vehicleId) : ($trip?->vehicle_id ? \App\Modules\Fleet\Models\Vehicle::find($trip->vehicle_id) : null);

            return FuelVoucher::create([
                'store_id' => $station->store_id, 'branch_id' => $branchId, 'fuel_station_id' => $station->id, 'driver_id' => $driver->id,
                'trip_id' => $trip?->id, 'number' => $this->numbers->next($station->store_id, 'FV'), 'code' => $this->code($station->store_id),
                'amount' => $amount, 'vehicle_id' => $registered?->id, 'vehicle' => $registered?->reg_no ?? $vehicle ?? $van?->name, 'status' => 'issued', 'note' => $note, 'issued_by' => $actor->id,
            ]);
        });
    }

    /** The station's slip is in: book what was really drawn. Anything less than the limit is simply released. */
    public function redeem(int $id, int $used, ?float $litres, User $actor): FuelVoucher
    {
        return DB::transaction(function () use ($id, $used, $litres, $actor) {
            $voucher = FuelVoucher::lockForUpdate()->findOrFail($id);

            abort_unless($voucher->status === 'issued', 422, 'This voucher has already been settled or cancelled.');

            if ($used > $voucher->amount) {
                throw ValidationException::withMessages(['amount_used' => 'The amount drawn is more than the voucher limit.']);
            }

            $station = FuelStation::lockForUpdate()->findOrFail($voucher->fuel_station_id);

            $entry = FuelStationEntry::create([
                'store_id' => $voucher->store_id, 'fuel_station_id' => $station->id, 'type' => 'redeemed', 'amount' => -$used,
                'entry_date' => now()->toDateString(), 'reference' => $voucher->number, 'fuel_voucher_id' => $voucher->id, 'created_by' => $actor->id,
            ]);

            if ($used > 0) {
                $this->ledger->post($voucher->store_id, [
                    'date' => now()->toDateString(), 'memo' => "Fuel voucher {$voucher->number} at {$station->name}", 'branch_id' => $voucher->branch_id,
                    'source' => $entry, 'posting_key' => "fuel-redeem:{$voucher->id}", 'user_id' => $actor->id,
                    'lines' => [['account' => 'fuel_expense', 'debit' => $used], ['account' => 'fuel_prepaid', 'credit' => $used]],
                ]);
            }

            $voucher->update(['status' => 'redeemed', 'amount_used' => $used, 'litres' => $litres, 'redeemed_at' => now(), 'redeemed_by' => $actor->id]);

            return $voucher;
        });
    }

    public function cancel(int $id): FuelVoucher
    {
        return DB::transaction(function () use ($id) {
            $voucher = FuelVoucher::lockForUpdate()->findOrFail($id);
            abort_unless($voucher->status === 'issued', 422, 'Only an unused voucher can be cancelled.');
            $voucher->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return $voucher;
        });
    }

    private function code(int $storeId): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (FuelVoucher::withoutGlobalScopes()->where('store_id', $storeId)->where('code', $code)->exists());

        return $code;
    }
}
