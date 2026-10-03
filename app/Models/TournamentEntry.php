<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A player (individual format) or a team in a tournament. */
class TournamentEntry extends Model
{
    /**
     * `bracket_id` and `slot`: where the organizer placed them (or where the draw put them at the start).
     * `group_number` and `advanced`: their elimination-stage group, and whether the organizer moved them on to the
     * bracket. `group_slot`: the match-map slot the organizer put them in before the elimination stage starts.
     */
    protected $fillable = ['tournament_id', 'bracket_id', 'group_number', 'group_slot', 'advanced', 'name', 'captain_id', 'seed', 'slot'];

    protected function casts(): array
    {
        return [
            'seed' => 'integer',
            'slot' => 'integer',
            'group_number' => 'integer',
            'group_slot' => 'integer',
            'advanced' => 'boolean',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tournament_entry_members', 'entry_id')
            ->withPivot('tournament_id')
            ->withTimestamps()
            ->orderBy('tournament_entry_members.id');
    }

    /** Team name, or the player's name for individual entries. */
    public function displayName(): string
    {
        return $this->name ?? $this->members->first()?->name ?? 'Removed player';
    }
}
