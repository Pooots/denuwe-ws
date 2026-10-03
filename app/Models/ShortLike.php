<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reaction per person per short; `reaction` is one of PostLike::REACTIONS. */
class ShortLike extends Model
{
    protected $fillable = [
        'short_id',
        'user_id',
        'reaction',
    ];

    public function short(): BelongsTo
    {
        return $this->belongsTo(Short::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
