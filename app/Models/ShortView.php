<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Someone other than the author watched a short; each person counts once. */
class ShortView extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'short_id',
        'user_id',
    ];
}
