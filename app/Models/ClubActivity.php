<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ClubActivity extends Model
{
    public const RESPONSES = ['going', 'not_going'];

    protected $fillable = [
        'club_id',
        'user_id',
        'title',
        'description',
        'location',
        'starts_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Members who answered, with pivot status "going" or "not_going". */
    public function responders(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'club_activity_responses', 'activity_id')
            ->withPivot('status')
            ->withTimestamps();
    }

    /** Everything ClubPresenter::activity() needs, for with() or load(). */
    public static function details(): array
    {
        return [
            'club',
            'user',
            'responders' => fn ($q) => $q->orderBy('club_activity_responses.updated_at'),
        ];
    }

    public function scopeWithDetails(Builder $query): Builder
    {
        return $query->with(self::details());
    }

    public function hasStarted(): bool
    {
        return $this->starts_at->isPast();
    }
}
