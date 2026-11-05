<?php

namespace App\Modules\Catalogue\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use App\Support\Concerns\SoftDeletesFreeingKeys;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use Auditable, BelongsToStore, SoftDeletesFreeingKeys;

    /** Unique values released when the record is deleted, with their column lengths. */
    protected array $freedOnDelete = ['abbreviation' => 10];

    protected $fillable = ['store_id', 'name', 'abbreviation'];
}
