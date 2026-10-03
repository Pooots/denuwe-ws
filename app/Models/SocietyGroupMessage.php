<?php

namespace App\Models;

use App\Support\UserPresenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocietyGroupMessage extends Model
{
    public const TEXT = 'text';

    public const SYSTEM = 'system';

    protected $fillable = ['society_group_id', 'user_id', 'kind', 'body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** System messages read as a sentence: "You added Ana Cruz", "Ben Reyes left the group". */
    public function present(int $viewerId): array
    {
        $mine = $this->user_id !== null && (int) $this->user_id === $viewerId;
        $system = $this->kind === self::SYSTEM;

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'body' => $system ? ($mine ? 'You' : ($this->user?->name ?? 'Someone')).' '.$this->body : $this->body,
            'author' => $this->user ? UserPresenter::author($this->user) : null,
            'mine' => $mine,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
