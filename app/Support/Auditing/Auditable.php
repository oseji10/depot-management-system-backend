<?php

namespace App\Support\Auditing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/** Records created / updated / deleted events with before and after values. */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $m) {
            AuditLogger::record('created', $m, null, Arr::except($m->getAttributes(), $m->auditExcluded()));
        });

        static::updated(function (Model $m) {
            $changes = Arr::except($m->getChanges(), $m->auditExcluded());

            if ($changes === []) {
                return;
            }

            AuditLogger::record('updated', $m, Arr::only($m->getOriginal(), array_keys($changes)), $changes);
        });

        static::deleted(function (Model $m) {
            AuditLogger::record('deleted', $m, Arr::except($m->getOriginal(), $m->auditExcluded()));
        });
    }

    public function auditExcluded(): array
    {
        return ['password', 'remember_token', 'created_at', 'updated_at', 'last_login_at'];
    }
}
