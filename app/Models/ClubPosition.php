<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ClubPosition extends Model
{
    public const MAX_PER_CLUB = 30;

    /** Positions that can create activities and tournaments unless the owner says otherwise. */
    public const ORGANIZER_DEFAULTS = ['president', 'vice president'];

    /** Always organizes, whatever the flag says. */
    public const PRESIDENT = 'president';

    protected $fillable = ['club_id', 'name', 'sort_order', 'can_organize'];

    protected function casts(): array
    {
        return [
            'can_organize' => 'boolean',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function isPresident(): bool
    {
        return mb_strtolower(trim($this->name)) === self::PRESIDENT;
    }

    public function organizes(): bool
    {
        return $this->can_organize || $this->isPresident();
    }

    /** Limits a query joined with `club_positions` to positions whose holders organize. */
    public static function whereOrganizes(QueryBuilder $query): QueryBuilder
    {
        return $query->where(fn (QueryBuilder $q) => $q
            ->where('club_positions.can_organize', true)
            ->orWhereRaw('LOWER(TRIM(club_positions.name)) = ?', [self::PRESIDENT]));
    }

    public function present(int $holders): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'can_organize' => $this->organizes(),
            'organize_locked' => $this->isPresident(),
            'holders_count' => $holders,
        ];
    }
}
