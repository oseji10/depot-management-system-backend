<?php

namespace App\Modules\Accounting\Models;

use App\Support\Tenancy\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class BankStatementMatch extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $guarded = [];
}
