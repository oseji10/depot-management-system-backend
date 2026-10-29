<?php

namespace App\Support\Tenancy;

use App\Modules\Organization\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Add to every model that carries a store_id. Reads are scoped; writes are stamped and verified. */
trait BelongsToStore
{
    public static function bootBelongsToStore(): void
    {
        static::addGlobalScope(new StoreScope());

        static::creating(function (Model $model) {
            $access = app(AccessScope::class);

            if ($model->store_id === null) {
                $storeId = $access->storeId();

                if ($storeId === null) {
                    throw new LogicException(class_basename($model).': store_id is required but no store context exists.');
                }

                $model->store_id = $storeId;
            }

            // A store or branch level user can never write into another store.
            if ($access->check() && ! $access->isSuperAdmin() && (int) $model->store_id !== $access->storeId()) {
                throw new LogicException('Cross-store write blocked.');
            }
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
