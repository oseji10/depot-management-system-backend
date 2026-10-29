<?php

namespace App\Modules\Catalogue\Models;

use App\Support\Auditing\Auditable;
use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use Auditable, BelongsToStore;

    protected $fillable = ['store_id', 'name', 'abbreviation'];
}
