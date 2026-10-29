<?php

namespace App\Support\Imports;

use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerGroup;
use App\Modules\Sales\Support\NetworkProvider;
use Illuminate\Support\Facades\DB;

/**
 * Customers from an export (Bumpa-style columns). A customer already here, matched by the old system's id or by phone
 * number, is left as it is; its group is still applied. So the same file can be uploaded again safely.
 */
class CustomerImporter
{
    public const REF = 'bumpa:';

    private array $groups = [];

    public function __construct(private int $storeId)
    {
    }

    public static function isWalkIn(?string $name, ?string $phone): bool
    {
        return $name !== null && in_array(mb_strtolower($name), ['walk-in customer', 'walk in customer', 'walk-in', 'walkin'], true)
            || ($phone !== null && preg_match('/1234567890$/', $phone));
    }

    /** The phone as we store it (0803...), or null when it is blank or the placeholder some systems use. */
    public static function phone(?string $raw): ?string
    {
        $local = NetworkProvider::normalise($raw);

        return $local === null || preg_match('/1234567890$/', $local) || strlen($local) < 7 ? null : mb_substr($local, 0, 30);
    }

    /** @param  list<array>  $rows  each row carries '_row' (its line number in the file) */
    public function run(array $rows): ImportReport
    {
        $report = new ImportReport();

        foreach ($rows as $i => $row) {
            $line = (int) ($row['_row'] ?? $i + 2);

            try {
                DB::transaction(fn () => $this->one($row, $line, $report));
            } catch (\Throwable $e) {
                $report->problem($line, 'Could not import: '.$e->getMessage());
            }
        }

        return $report;
    }

    /** Find or create a customer; also used by the orders import. Returns the customer id. */
    public function findOrCreate(?string $externalId, ?string $name, ?string $rawPhone, array $extra = [], ?ImportReport $report = null): ?int
    {
        $phone = self::phone($rawPhone);
        $ref = $externalId ? self::REF.$externalId : null;

        $found = ($ref ? Customer::where('client_ref', $ref)->first() : null) ?? ($phone ? Customer::where('phone', $phone)->first() : null);

        if ($found) {
            if ($ref && ! $found->client_ref) {
                $found->update(['client_ref' => $ref]);
            }
            $report?->add('customers_existing');

            return $found->id;
        }

        $name ??= $phone ?? 'Customer '.$externalId;

        $customer = new Customer([
            'store_id' => $this->storeId, 'client_ref' => $ref, 'name' => mb_substr($name, 0, 150), 'type' => 'retail', 'phone' => $phone,
            'email' => $extra['email'] ?? null, 'address' => $extra['address'] ?? null, 'credit_limit' => 0, 'is_active' => true,
        ]);

        if (! empty($extra['created_at'])) {
            $customer->created_at = $extra['created_at'];
        }

        $customer->save();
        $report?->add('customers_created');

        return $customer->id;
    }

    private function one(array $row, int $line, ImportReport $report): void
    {
        $name = Cell::text($row, 'Name', 150) ?? trim((Cell::text($row, 'First Name') ?? '').' '.(Cell::text($row, 'Last Name') ?? '')) ?: null;
        $phone = Cell::text($row, 'Phone', 40);

        if ($name === null && $phone === null) {
            $report->problem($line, 'No name or phone number.');

            return;
        }

        if (self::isWalkIn($name, $phone)) {
            $report->add('skipped_walk_in');

            return;
        }

        $address = implode(', ', array_filter([Cell::text($row, 'Street'), Cell::text($row, 'City'), Cell::text($row, 'State')]));

        $id = $this->findOrCreate(Cell::text($row, 'Customer ID', 30), $name, $phone, [
            'email' => filter_var(Cell::text($row, 'Email', 150), FILTER_VALIDATE_EMAIL) ?: null,
            'address' => $address !== '' ? mb_substr($address, 0, 255) : null,
            'created_at' => Cell::dateTime($row, 'Created'),
        ], $report);

        if ($groupName = Cell::text($row, 'Group / Route', 100)) {
            $groupId = $this->groups[$groupName] ??= $this->group($groupName);

            if (DB::table('customer_group_members')->insertOrIgnore(['customer_group_id' => $groupId, 'customer_id' => $id, 'created_at' => now()])) {
                $report->add('grouped');
            }
        }
    }

    private function group(string $name): int
    {
        $existing = CustomerGroup::where('name', $name)->first();

        return ($existing ?? CustomerGroup::create([
            'store_id' => $this->storeId, 'name' => $name, 'kind' => str_contains(mb_strtolower($name), 'route') ? 'route' : 'location',
        ]))->id;
    }
}
