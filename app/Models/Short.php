<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A short vertical video, or a single photo (`kind`). `audience` decides who sees it: everyone, the author's society (accepted friends),
 * or the members of one club or community (`club_id`).
 */
class Short extends Model
{
    public const EVERYONE = 'everyone';

    public const SOCIETY = 'society';

    public const CLUB = 'club';

    public const AUDIENCES = [self::EVERYONE, self::SOCIETY, self::CLUB];

    public const VIDEO = 'video';

    public const IMAGE = 'image';

    public const KINDS = [self::VIDEO, self::IMAGE];

    public const MAX_SECONDS = 60;

    /** Kilobytes, as Laravel's `max` rule counts file sizes. */
    public const MAX_KB = 40 * 1024;

    public const MAX_IMAGE_KB = 10 * 1024;

    protected $fillable = [
        'user_id',
        'club_id',
        'audience',
        'kind',
        'caption',
        'video_path',
        'poster_path',
        'image_path',
        'duration_ms',
        'width',
        'height',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(ShortLike::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ShortComment::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(ShortView::class);
    }

    /** Your own shorts, public ones, your friends' society shorts and shorts in clubs you belong to. */
    public function scopeVisibleTo(Builder $query, int $viewerId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('shorts.user_id', $viewerId)
            ->orWhere('shorts.audience', self::EVERYONE)
            ->orWhere(fn (Builder $q) => $q
                ->where('shorts.audience', self::SOCIETY)
                ->where(fn (Builder $q) => $q
                    ->whereIn('shorts.user_id', fn ($f) => $f
                        ->select('addressee_id')
                        ->from('friendships')
                        ->where('requester_id', $viewerId)
                        ->where('status', Friendship::ACCEPTED))
                    ->orWhereIn('shorts.user_id', fn ($f) => $f
                        ->select('requester_id')
                        ->from('friendships')
                        ->where('addressee_id', $viewerId)
                        ->where('status', Friendship::ACCEPTED))))
            ->orWhere(fn (Builder $q) => $q
                ->where('shorts.audience', self::CLUB)
                ->whereIn('shorts.club_id', fn ($members) => $members
                    ->select('club_id')
                    ->from('club_members')
                    ->where('user_id', $viewerId))));
    }

    public function isVisibleTo(int $viewerId): bool
    {
        return static::query()->whereKey($this->id)->visibleTo($viewerId)->exists();
    }

    /** Author, club, counters and the viewer's reaction needed to render a short. */
    public function scopeForViewer(Builder $query, int $viewerId): Builder
    {
        return $query
            ->visibleTo($viewerId)
            ->with(['user', 'club'])
            ->withCount(['likes', 'comments', 'views'])
            ->addSelect(['my_reaction' => ShortLike::query()
                ->select('reaction')
                ->whereColumn('short_likes.short_id', 'shorts.id')
                ->where('short_likes.user_id', $viewerId)
                ->limit(1)]);
    }
}
