<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Friendship extends Model
{
    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    protected $fillable = ['requester_id', 'addressee_id', 'status', 'accepted_at'];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function addressee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addressee_id');
    }

    /** Friendships the user is part of, in either direction. */
    public function scopeInvolving(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('requester_id', $userId)->orWhere('addressee_id', $userId));
    }

    public function scopeBetween(Builder $query, int $a, int $b): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('requester_id', $a)->where('addressee_id', $b))
            ->orWhere(fn (Builder $q) => $q->where('requester_id', $b)->where('addressee_id', $a)));
    }

    public static function areFriends(int $a, int $b): bool
    {
        return self::query()->between($a, $b)->where('status', self::ACCEPTED)->exists();
    }

    /** @return array<int, int> */
    public static function friendIdsOf(int $userId): array
    {
        return self::query()
            ->involving($userId)
            ->where('status', self::ACCEPTED)
            ->get()
            ->map(fn (self $f) => $f->otherId($userId))
            ->all();
    }

    public function otherId(int $userId): int
    {
        return (int) ($this->requester_id === $userId ? $this->addressee_id : $this->requester_id);
    }

    /** `friends`, `outgoing` (viewer sent it) or `incoming` (viewer received it). */
    public function relationshipFor(int $viewerId): string
    {
        if ($this->status === self::ACCEPTED) {
            return 'friends';
        }

        return (int) $this->requester_id === $viewerId ? 'outgoing' : 'incoming';
    }
}
