<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reaction per person per post; `reaction` is one of REACTIONS (a plain like is `like`). */
class PostLike extends Model
{
    public const REACTIONS = ['like', 'love', 'care', 'haha', 'wow', 'sad', 'angry'];

    protected $fillable = [
        'post_id',
        'user_id',
        'reaction',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
