<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Hosted by anyone, optionally for a club. Entries are single players or teams; once the organizer starts it,
 * the bracket is generated and registration closes.
 */
class Tournament extends Model
{
    use HasUuids;

    public const FORMATS = ['individual', 'team'];

    public const BRACKETS = ['single_elimination', 'double_elimination', 'round_robin'];

    /** Public tournaments are listed for everyone and anyone can enter; private ones need an invite (or the club). */
    public const PUBLIC = 'public';

    public const PRIVATE = 'private';

    public const VISIBILITIES = [self::PUBLIC, self::PRIVATE];

    public const REGISTRATION = 'registration';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    /** Elimination stage before the bracket: everyone in a group plays the others once, or twice (home and away). */
    public const GROUP_STAGES = ['single', 'double'];

    public const MAX_GROUPS = 8;

    /** `stage` while the tournament is live: the elimination stage, then the bracket. */
    public const STAGE_GROUPS = 'groups';

    public const STAGE_KNOCKOUT = 'knockout';

    public const MAX_ENTRIES = 128;

    /** Every entry plays every other one, so the match count grows fast. */
    public const MAX_ROUND_ROBIN_ENTRIES = 32;

    public const MAX_TEAM_SIZE = 20;

    protected $fillable = [
        'club_id',
        'visibility',
        'user_id',
        'name',
        'game',
        'format',
        'team_size',
        'bracket',
        'group_stage',
        'group_count',
        'schedule',
        'stage',
        'starts_at',
        'location',
        'prize',
        'max_entries',
        'description',
        'avatar_path',
        'banner_path',
        'status',
        'winner_entry_id',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'team_size' => 'integer',
            'max_entries' => 'integer',
            'group_count' => 'integer',
            'schedule' => 'array',
        ];
    }

    /** Every tournament gets a UUID when it's created; the numeric id stays the primary key. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function booted(): void
    {
        static::saving(function (Tournament $tournament) {
            if ($tournament->slug === null || $tournament->isDirty('name')) {
                $tournament->slug = static::uniqueSlug($tournament->name, $tournament->id);
            }
        });
    }

    /** URL name from the tournament name, e.g. "Pickle Ball" → "pickle-ball", "-2" etc. when taken. Never all digits or UUID-shaped, so it can't be mistaken for an id or uuid. */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = trim(Str::limit(Str::slug($name), 90, ''), '-') ?: 'tournament';
        if (ctype_digit($base) || Str::isUuid($base)) {
            $base = 'tournament-'.$base;
        }

        $slug = $base;
        for ($n = 2; static::query()->where('slug', $slug)->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    /** Find by slug, uuid, or numeric id for older links. */
    public function scopeIdentifiedBy(Builder $query, string $key): Builder
    {
        return match (true) {
            ctype_digit($key) => $query->whereKey((int) $key),
            Str::isUuid($key) => $query->where('uuid', strtolower($key)),
            default => $query->where('slug', $key),
        };
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $field === null
            ? static::query()->identifiedBy((string) $value)->first()
            : parent::resolveRouteBinding($value, $field);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TournamentEntry::class)->orderByRaw('seed IS NULL')->orderBy('seed')->orderBy('id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TournamentEntryMember::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(TournamentInvite::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class)->orderBy('round')->orderBy('position');
    }

    /** Elimination tournaments only, in the organizer's order. */
    public function brackets(): HasMany
    {
        return $this->hasMany(Bracket::class)->orderBy('position')->orderBy('id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'winner_entry_id');
    }

    /**
     * With brackets, Max players is every bracket's slots added up. Not with an elimination stage: more can enter
     * than move on to the bracket.
     */
    public function syncCapacity(): void
    {
        if ($this->hasGroupStage()) {
            return;
        }
        $total = (int) $this->brackets()->sum('size');
        if ($total > 0 && $total !== $this->max_entries) {
            $this->update(['max_entries' => $total]);
        }
    }

    /** Host club, organizer, champions and counts used on every card. */
    public function scopeWithSummary(Builder $query): Builder
    {
        return $query
            ->with(['club', 'user', 'winner.members', 'brackets.winner.members'])
            ->withCount(['entries', 'memberships']);
    }

    /**
     * Tournaments the user organizes, plays in, was invited to, or that belong to a club they're in.
     */
    public function scopeRelevantTo(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('tournaments.user_id', $userId)
            ->orWhereHas('memberships', fn (Builder $m) => $m->where('user_id', $userId))
            ->orWhereHas('invites', fn (Builder $i) => $i->where('user_id', $userId))
            ->orWhereHas('club.members', fn (Builder $m) => $m->where('users.id', $userId)));
    }

    public function scopePublicOnes(Builder $query): Builder
    {
        return $query->where('tournaments.visibility', self::PUBLIC);
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('media')->url($this->avatar_path) : null;
    }

    public function bannerUrl(): ?string
    {
        return $this->banner_path ? Storage::disk('media')->url($this->banner_path) : null;
    }

    /** @return array<int, string> */
    public function mediaPaths(): array
    {
        return array_values(array_filter([$this->avatar_path, $this->banner_path]));
    }

    public function isTeam(): bool
    {
        return $this->format === 'team';
    }

    public function isRoundRobin(): bool
    {
        return $this->bracket === 'round_robin';
    }

    /** Lose twice and you're out: a winners bracket, a losers bracket and a grand final. */
    public function isDoubleElimination(): bool
    {
        return $this->bracket === 'double_elimination';
    }

    public function isRegistering(): bool
    {
        return $this->status === self::REGISTRATION;
    }

    /** Knockout tournaments can start with an elimination stage (`group_stage`: single or double round robin). */
    public function hasGroupStage(): bool
    {
        return $this->group_stage !== null && ! $this->isRoundRobin();
    }

    /** The elimination stage is being played; the bracket comes next. */
    public function inGroupStage(): bool
    {
        return $this->status === self::IN_PROGRESS && $this->stage === self::STAGE_GROUPS;
    }

    /** Brackets can be created and filled until the knockout starts. */
    public function isDrawing(): bool
    {
        return $this->isRegistering() || $this->inGroupStage();
    }

    /** The organizer, and the club owner for club tournaments, run the event. */
    public function canManage(int $userId): bool
    {
        return (int) $this->user_id === $userId || ($this->club && (int) $this->club->owner_id === $userId);
    }

    public function hasPlayer(int $userId): bool
    {
        return $this->memberships()->where('user_id', $userId)->exists();
    }

    public function isInvited(int $userId): bool
    {
        return $this->invites()->where('user_id', $userId)->exists();
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::PUBLIC;
    }

    /** Anyone can enter a public tournament; club members can enter club tournaments; anyone else needs an invite. */
    public function canEnter(int $userId): bool
    {
        return $this->isPublic()
            || $this->canManage($userId)
            || $this->isInvited($userId)
            || ($this->club !== null && $this->club->hasMember($userId));
    }

    public function canView(int $userId): bool
    {
        return $this->canEnter($userId)
            || $this->hasPlayer($userId)
            || ($this->club !== null && $this->club->isVisibleTo($userId));
    }

    public function isFull(): bool
    {
        return $this->max_entries !== null && $this->entries()->count() >= $this->max_entries;
    }
}
