<?php

namespace App\Models;

use App\Support\ClubPresenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something on a user's own schedule; only they can see it. It can be for one of their clubs or communities
 * (`club_id`), or just personal. It's on their calendar as going without a reply.
 */
class PersonalActivity extends Model
{
    protected $fillable = [
        'user_id',
        'club_id',
        'title',
        'starts_at',
        'ends_at',
        'all_day',
        'location',
        'is_meeting',
        'meeting_url',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'is_meeting' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function present(): array
    {
        return [
            'kind' => 'personal',
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'is_meeting' => $this->is_meeting,
            'meeting_url' => $this->meeting_url,
            'club' => $this->club ? ClubPresenter::summary($this->club) : null,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'all_day' => $this->all_day,
            'has_started' => $this->starts_at->isPast(),
        ];
    }
}
