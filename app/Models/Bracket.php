<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One named bracket in an elimination tournament (e.g. "Men's division"). It has `size` first-round slots the
 * organizer fills with entries, its own opening matches and round names, and its own champion.
 */
class Bracket extends Model
{
    protected $table = 'tournament_brackets';

    public const DEFAULT_NAME = 'Main bracket';

    public const MAX_PER_TOURNAMENT = 8;

    protected $fillable = [
        'tournament_id',
        'name',
        'size',
        'position',
        'opening_matches',
        'round_names',
        'winner_entry_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'position' => 'integer',
            'opening_matches' => 'array',
            'round_names' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TournamentEntry::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class)->orderBy('round')->orderBy('position');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'winner_entry_id');
    }
}
