<?php

namespace App\Support\Auditing;

use App\Models\User;
use App\Modules\Organization\Models\Store;
use App\Support\Tenancy\AccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public static function record(
        string $event,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?User $actor = null,
        ?int $storeId = null,
    ): void {
        $actor ??= Auth::user();

        $storeId ??= match (true) {
            $subject instanceof Store => $subject->id,
            $subject !== null && isset($subject->store_id) => $subject->store_id,
            default => $actor?->store_id ?? app(AccessScope::class)->storeId(),
        };

        AuditLog::create([
            'store_id' => $storeId,
            'branch_id' => $subject?->branch_id ?? $actor?->branch_id,
            'user_id' => $actor?->id,
            'event' => $event,
            'auditable_type' => $subject ? $subject->getMorphClass() : null,
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
        ]);
    }
}
