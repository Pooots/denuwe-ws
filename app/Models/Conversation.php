<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** A 1-to-1 conversation between two users. */
class Conversation extends Model
{
    protected $fillable = ['direct_key'];

    /** The users in the conversation. */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')->withTimestamps();
    }

    public function participantRecords(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /** Conversations the user takes part in. */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->whereHas('participantRecords', fn (Builder $q) => $q->where('user_id', $userId));
    }

    public function hasParticipant(int $userId): bool
    {
        if ($this->relationLoaded('participants')) {
            return $this->participants->contains('id', $userId);
        }

        return $this->participantRecords()->where('user_id', $userId)->exists();
    }

    /** The other person in a 1-to-1 conversation. */
    public function otherParticipant(int $viewerId): ?User
    {
        return $this->participants->first(fn (User $user) => (int) $user->id !== $viewerId);
    }

    /** @return array<int, int> */
    public function participantIds(): array
    {
        return $this->participantRecords()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    /** The conversation between two users, created on first use. Safe when both open it at the same moment. */
    public static function between(User $a, User $b): self
    {
        $key = self::directKey($a->id, $b->id);

        $existing = self::query()->where('direct_key', $key)->first();
        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($key, $a, $b) {
                $conversation = self::query()->create(['direct_key' => $key]);
                $conversation->participants()->attach([$a->id, $b->id]);

                return $conversation;
            });
        } catch (UniqueConstraintViolationException) {
            return self::query()->where('direct_key', $key)->firstOrFail();
        }
    }
}
