<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const GENDERS = ['female', 'male', 'prefer_not_to_say'];

    /** Profile background designs; the frontend draws them (denuwe-core src/components/profile/backgrounds.ts). */
    public const BACKGROUND_TEMPLATES = [
        'sky', 'aurora', 'sunset', 'mint', 'lavender', 'midnight', 'dots', 'grid', 'waves', 'court',
    ];

    public const BACKGROUND_PHOTO = 'photo';

    /** How an uploaded background photo is shown. */
    public const BACKGROUND_EFFECTS = ['natural', 'soft', 'frosted', 'duotone', 'dark'];

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'birthday',
        'gender',
        'email',
        'password',
        'role',
        'status',
        'phone',
        'avatar_path',
        'banner_path',
        'profile_background',
        'profile_background_path',
        'profile_background_effect',
        'headline',
        'pronouns',
        'location',
        'bio',
        'website',
        'contact_email',
        'contact_phone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'birthday' => 'date',
            'password' => 'hashed',
            'notifications_seen_at' => 'datetime',
        ];
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('media')->url($this->avatar_path) : null;
    }

    public function bannerUrl(): ?string
    {
        return $this->banner_path ? Storage::disk('media')->url($this->banner_path) : null;
    }

    /** @return array{background: ?string, background_url: ?string, background_effect: ?string} */
    public function profileBackground(): array
    {
        return [
            'background' => $this->profile_background,
            'background_url' => $this->profile_background_path
                ? Storage::disk('media')->url($this->profile_background_path)
                : null,
            'background_effect' => $this->profile_background_effect,
        ];
    }

    /** 1-to-1 conversations this user takes part in. */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')->withTimestamps();
    }

    /** Messages this user sent. */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'role' => $this->role,
            'user_id' => $this->id,
        ];
    }
}
