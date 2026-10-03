<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    protected $fillable = [
        'user_id',
        'club_id',
        'tournament_id',
        'body',
        'image_path',
        'repost_of_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    /** The tournament this post shares, shown as a card under the text. */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function repostOf(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'repost_of_id');
    }

    public function reposts(): HasMany
    {
        return $this->hasMany(Post::class, 'repost_of_id');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    /** A repost with no text or image of its own. */
    public function isPlainRepost(): bool
    {
        return $this->repost_of_id !== null && blank($this->body) && blank($this->image_path);
    }

    /** Public posts, plus club posts when the viewer is a member of that club or wrote the post. */
    public function scopeVisibleTo(Builder $query, int $viewerId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('posts.club_id')
            ->orWhere('posts.user_id', $viewerId)
            ->orWhereIn('posts.club_id', fn ($members) => $members
                ->select('club_id')
                ->from('club_members')
                ->where('user_id', $viewerId)));
    }

    public function isVisibleTo(int $viewerId): bool
    {
        return static::query()->whereKey($this->id)->visibleTo($viewerId)->exists();
    }

    /** Author, counters and the viewer's reaction/repost flags needed to render a post card. */
    public function scopeForViewer(Builder $query, int $viewerId): Builder
    {
        return $query
            ->visibleTo($viewerId)
            ->with(['user', 'club', 'tournament' => fn ($q) => $q->withSummary()])
            ->withCount(['likes', 'comments', 'reposts'])
            ->addSelect(['my_reaction' => PostLike::query()
                ->select('reaction')
                ->whereColumn('post_likes.post_id', 'posts.id')
                ->where('post_likes.user_id', $viewerId)
                ->limit(1)])
            ->withExists([
                'likes as liked' => fn (Builder $q) => $q->where('user_id', $viewerId),
                'reposts as reposted' => fn (Builder $q) => $q
                    ->where('user_id', $viewerId)
                    ->whereNull('body')
                    ->whereNull('image_path'),
            ]);
    }
}
