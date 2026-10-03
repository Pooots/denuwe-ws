<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reaction per person per short comment; `reaction` is one of PostLike::REACTIONS. */
class ShortCommentLike extends Model
{
    protected $fillable = [
        'short_comment_id',
        'user_id',
        'reaction',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
