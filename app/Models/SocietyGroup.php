<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A group chat between friends ("Group Society"). Members add their own friends; anyone can leave. */
class SocietyGroup extends Model
{
    public const MAX_MEMBERS = 50;

    protected $fillable = ['name', 'owner_id'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(SocietyGroupMember::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SocietyGroupMessage::class);
    }

    public function scopeWithMember(Builder $query, int $userId): Builder
    {
        return $query->whereHas('members', fn (Builder $q) => $q->where('user_id', $userId));
    }

    public function membership(int $userId): ?SocietyGroupMember
    {
        return $this->members()->where('user_id', $userId)->first();
    }

    /** Record a join, leave or rename in the chat, e.g. "added Ana Cruz". */
    public function announce(int $actorId, string $action): SocietyGroupMessage
    {
        $this->touch();

        return $this->messages()->create([
            'user_id' => $actorId,
            'kind' => SocietyGroupMessage::SYSTEM,
            'body' => $action,
        ]);
    }
}
