<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One game between two entries. In a knockout bracket the winner of (round, position) moves into
 * (round + 1, position / 2); a match with only one entry is a bye. Double elimination adds the losers bracket and
 * the grand final (see `DoubleElimination`).
 */
class TournamentMatch extends Model
{
    public const WINNERS = 'winners';

    public const LOSERS = 'losers';

    public const FINAL = 'final';

    /** Elimination stage: a round-robin match inside one group, before the bracket. */
    public const GROUP = 'group';

    /** `bracket_id` is null for round-robin and elimination-stage matches; `group_number` is set for the latter. */
    protected $fillable = [
        'tournament_id',
        'bracket_id',
        'group_number',
        'side',
        'round',
        'position',
        'entry1_id',
        'entry2_id',
        'score1',
        'score2',
        'winner_entry_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'position' => 'integer',
            'group_number' => 'integer',
            'score1' => 'integer',
            'score2' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function bracket(): BelongsTo
    {
        return $this->belongsTo(Bracket::class);
    }

    public function isGroup(): bool
    {
        return $this->side === self::GROUP;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function isBye(): bool
    {
        return $this->isCompleted() && ($this->entry1_id === null || $this->entry2_id === null);
    }

    public function hasEntry(?int $entryId): bool
    {
        return $entryId !== null && in_array($entryId, [(int) $this->entry1_id, (int) $this->entry2_id], true);
    }
}
