<?php

namespace App\Modules\Fleet\Services;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
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
 * Money prepaid to a fuel station is an asset (Dr Fuel Prepayments / Cr Cash or Bank), bought as a number of litres.
 * A voucher is for a number of litres at the station's price. Once it is issued (and printed) the fuel counts as used:
 * the litres and the money come off the station's balance and the cost is booked as fuel expense. A driver who brings a
 * voucher back unused has it voided with a reason, which puts the litres and the money back. Nothing is ever deleted.
 */
class FuelService
{
    public function __construct(private Ledger $ledger, private DocumentNumber $numbers)
    {
    }

    public function topUp(FuelStation $station, int $amount, float $litres, string $method, ?string $reference, ?string $date, User $actor): FuelStationEntry
    {
        return DB::transaction(function () use ($station, $amount, $litres, $method, $reference, $date, $actor) {
            $date ??= now()->toDateString();

            $entry = FuelStationEntry::create([
                'store_id' => $station->store_id, 'fuel_station_id' => $station->id, 'type' => 'top_up', 'amount' => $amount, 'litres' => $litres,
                'entry_date' => $date, 'method' => $method, 'reference' => $reference, 'created_by' => $actor->id,
            ]);

            $this->ledger->post($station->store_id, [
                'date' => $date, 'memo' => sprintf('Fuel prepayment to %s (%s L)', $station->name, rtrim(rtrim(number_format($litres, 2, '.', ''), '0'), '.')),
                'branch_id' => $actor->branch_id, 'source' => $entry, 'posting_key' => "fuel-topup:{$entry->id}", 'user_id' => $actor->id,
                'lines' => [['account' => 'fuel_prepaid', 'debit' => $amount], ['account' => $method === 'cash' ? 'cash' : 'bank', 'credit' => $amount]],
            ]);

            // The latest rate paid is the station's usual price, offered when the next voucher is written.
            $station->update(['price_per_litre' => (int) round($amount / $litres)]);

            return $entry;
        });
    }

    public function issue(FuelStation $station, User $driver, float $litres, ?int $pricePerLitre, ?string $vehicle, ?string $note, User $actor, ?int $vehicleId = null): FuelVoucher
    {
        return DB::transaction(function () use ($station, $driver, $litres, $pricePerLitre, $vehicle, $note, $actor, $vehicleId) {
            $station = FuelStation::lockForUpdate()->findOrFail($station->id);

            abort_unless($station->is_active, 422, 'This fuel station is switched off.');

            $price = $pricePerLitre ?: $station->price_per_litre;
            if (! $price) {
                throw ValidationException::withMessages(['price_per_litre' => 'Enter the price per litre. This station has no usual price yet.']);
            }

            $amount = (int) round($litres * $price);

            if ($station->tracksLitres() && $litres > $station->litresBalance() + 0.0001) {
                throw ValidationException::withMessages(['litres' => sprintf('Only %s litres are left with %s. Top the station up first.', rtrim(rtrim(number_format(max(0, $station->litresBalance()), 2, '.', ''), '0'), '.'), $station->name)]);
            }

            if ($amount > $station->balance()) {
                throw ValidationException::withMessages(['litres' => sprintf('Only ₦%s is left with %s, which is less than this voucher costs. Top the station up first.', number_format(max(0, $station->balance()) / 100), $station->name)]);
            }

            $van = Warehouse::vanOf($driver->id);
            $trip = $van ? Trip::withoutGlobalScopes()->where('van_warehouse_id', $van->id)->where('status', 'open')->first() : null;
            $branchId = $van?->branch_id ?? $driver->branch_id ?? $actor->branch_id
                ?? Warehouse::withoutGlobalScopes()->where('store_id', $station->store_id)->value('branch_id');

            // A registered vehicle wins over free text, and the voucher follows the trip's vehicle when none is named.
            $registered = $vehicleId ? \App\Modules\Fleet\Models\Vehicle::find($vehicleId) : ($trip?->vehicle_id ? \App\Modules\Fleet\Models\Vehicle::find($trip->vehicle_id) : null);

            $voucher = FuelVoucher::create([
                'store_id' => $station->store_id, 'branch_id' => $branchId, 'fuel_station_id' => $station->id, 'driver_id' => $driver->id,
                'trip_id' => $trip?->id, 'number' => $this->numbers->next($station->store_id, 'FV'), 'code' => $this->code($station->store_id),
                'litres' => $litres, 'price_per_litre' => $price, 'amount' => $amount, 'amount_used' => $amount,
                'vehicle_id' => $registered?->id, 'vehicle' => $registered?->reg_no ?? $vehicle ?? $van?->name, 'status' => 'issued', 'note' => $note, 'issued_by' => $actor->id,
            ]);

            $entry = FuelStationEntry::create([
                'store_id' => $station->store_id, 'fuel_station_id' => $station->id, 'type' => 'redeemed', 'amount' => -$amount, 'litres' => -$litres,
                'entry_date' => now()->toDateString(), 'reference' => $voucher->number, 'fuel_voucher_id' => $voucher->id, 'created_by' => $actor->id,
            ]);

            $this->ledger->post($station->store_id, [
                'date' => now()->toDateString(), 'memo' => "Fuel voucher {$voucher->number} at {$station->name}", 'branch_id' => $branchId,
                'source' => $entry, 'posting_key' => "fuel-redeem:{$voucher->id}", 'user_id' => $actor->id,
                'lines' => [['account' => 'fuel_expense', 'debit' => $amount], ['account' => 'fuel_prepaid', 'credit' => $amount]],
            ]);

            return $voucher;
        });
    }

    /** A driver handed a voucher back unused: the litres and the money go back to the station's balance, and the reason is kept. */
    public function void(int $id, string $reason, User $actor): FuelVoucher
    {
        return DB::transaction(function () use ($id, $reason, $actor) {
            $voucher = FuelVoucher::lockForUpdate()->findOrFail($id);

            abort_unless($voucher->status === 'issued', 422, 'This voucher has already been voided.');

            $amount = (int) ($voucher->amount_used ?? $voucher->amount);

            FuelStationEntry::create([
                'store_id' => $voucher->store_id, 'fuel_station_id' => $voucher->fuel_station_id, 'type' => 'reversal', 'amount' => $amount,
                'litres' => $voucher->litres, 'entry_date' => now()->toDateString(), 'reference' => $voucher->number,
                'fuel_voucher_id' => $voucher->id, 'created_by' => $actor->id,
            ]);

            $posted = JournalEntry::withoutGlobalScopes()->where('store_id', $voucher->store_id)->where('posting_key', "fuel-redeem:{$voucher->id}")->first();
            if ($posted && $posted->reversed_at === null) {
                $this->ledger->reverse($posted->id, $actor, "Fuel voucher {$voucher->number} voided: {$reason}");
            }

            $voucher->update(['status' => 'cancelled', 'cancelled_at' => now(), 'void_reason' => $reason, 'voided_by' => $actor->id]);

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
