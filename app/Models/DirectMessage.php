<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Legacy: the pre-Reverb friend chat. Its rows were copied into conversations/messages by the
 * 2026_10_03_000040 migration and nothing writes here any more; the table is kept as a backup.
 */
class DirectMessage extends Model
{
    protected $fillable = ['sender_id', 'recipient_id', 'body', 'read_at'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function scopeBetween(Builder $query, int $a, int $b): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('sender_id', $a)->where('recipient_id', $b))
            ->orWhere(fn (Builder $q) => $q->where('sender_id', $b)->where('recipient_id', $a)));
    }

    public function present(int $viewerId): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'mine' => (int) $this->sender_id === $viewerId,
            'created_at' => $this->created_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
        ];
    }
}
