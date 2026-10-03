<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Club extends Model
{
    use HasUuids;

    public const COLORS = ['emerald', 'blue', 'pink', 'ink', 'amber', 'purple'];

    public const TYPES = ['club', 'community'];

    public const CURRENCIES = ['PHP', 'USD'];

    public const FEE_PERIODS = ['one_time', 'monthly', 'yearly'];

    /** Public: anyone sees everything and joins directly. Private: members only; others request to join. */
    public const VISIBILITIES = ['public', 'private'];

    protected $fillable = [
        'owner_id',
        'type',
        'name',
        'description',
        'color',
        'visibility',
        'avatar_path',
        'banner_path',
        'fee_amount',
        'fee_currency',
        'fee_period',
    ];

    /** Slugs that would clash with other routes under /clubs. */
    private const RESERVED_SLUGS = ['mine'];

    protected function casts(): array
    {
        return [
            'fee_amount' => 'decimal:2',
        ];
    }

    /** Every club gets a UUID when it's created; the numeric id stays the primary key. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function booted(): void
    {
        static::saving(function (Club $club) {
            if ($club->slug === null || $club->isDirty('name')) {
                $club->slug = static::uniqueSlug($club->name, $club->id);
            }
        });
    }

    /** URL name from the club name, e.g. "JCI Makati" → "jci-makati", "-2" etc. when taken. Never all digits or UUID-shaped, so it can't be mistaken for an id or uuid. */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = trim(Str::limit(Str::slug($name), 90, ''), '-') ?: 'club';
        if (ctype_digit($base) || Str::isUuid($base) || in_array($base, self::RESERVED_SLUGS, true)) {
            $base = 'club-'.$base;
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

    public function isPaid(): bool
    {
        return $this->fee_amount !== null && (float) $this->fee_amount > 0;
    }

    public function isPrivate(): bool
    {
        return $this->visibility === 'private';
    }

    /** Members see everything; a private club is closed to everyone else. */
    public function isVisibleTo(int $userId): bool
    {
        return ! $this->isPrivate() || $this->hasMember($userId);
    }

    /**
     * Clubs that show up in lists for this user: public ones, private ones they belong to, and private ones they
     * asked to join (so they can follow or cancel the request).
     */
    public function scopeListedFor(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('clubs.visibility', 'public')
            ->orWhereHas('members', fn (Builder $m) => $m->where('users.id', $userId))
            ->orWhereHas('joinRequests', fn (Builder $r) => $r->where('users.id', $userId)));
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('media')->url($this->avatar_path) : null;
    }

    public function bannerUrl(): ?string
    {
        return $this->banner_path ? Storage::disk('media')->url($this->banner_path) : null;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'club_members')
            ->withPivot('role', 'fee_status', 'position_id')
            ->withTimestamps();
    }

    /** People waiting for the owner to let them into a private club. */
    public function joinRequests(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'club_join_requests')->withTimestamps();
    }

    /** Add someone as a member; paid clubs start them as fee pending. */
    public function admit(int $userId): void
    {
        if (! $this->hasMember($userId)) {
            $this->members()->attach($userId, [
                'role' => 'member',
                'fee_status' => $this->isPaid() ? 'unpaid' : null,
            ]);
        }
        $this->joinRequests()->detach($userId);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(ClubPosition::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ClubActivity::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class);
    }

    public function hasMember(int $userId): bool
    {
        return $this->members()->where('users.id', $userId)->exists();
    }

    /** The owner, the President, or a member whose position is allowed to create activities and tournaments. */
    public function canOrganize(int $userId): bool
    {
        if ((int) $this->owner_id === $userId) {
            return true;
        }

        return ClubPosition::whereOrganizes(DB::table('club_members')
            ->join('club_positions', 'club_positions.id', '=', 'club_members.position_id')
            ->where('club_members.club_id', $this->id)
            ->where('club_members.user_id', $userId))
            ->exists();
    }

    /** Clubs where the user can create activities and tournaments. */
    public function scopeOrganizableBy(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('clubs.owner_id', $userId)
            ->orWhereExists(fn ($sub) => ClubPosition::whereOrganizes($sub
                ->from('club_members')
                ->join('club_positions', 'club_positions.id', '=', 'club_members.position_id')
                ->whereColumn('club_members.club_id', 'clubs.id')
                ->where('club_members.user_id', $userId))));
    }
}
