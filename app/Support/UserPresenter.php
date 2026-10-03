<?php

namespace App\Support;

use App\Models\User;

class UserPresenter
{
    /** Full account payload for the signed-in user. */
    public static function account(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'birthday' => $user->birthday?->toDateString(),
            'gender' => $user->gender,
            'avatar_url' => $user->avatarUrl(),
            'banner_url' => $user->bannerUrl(),
            ...$user->profileBackground(),
            'headline' => $user->headline,
            'pronouns' => $user->pronouns,
            'location' => $user->location,
            'bio' => $user->bio,
            'website' => $user->website,
            'contact_email' => $user->contact_email,
            'contact_phone' => $user->contact_phone,
            'role' => $user->role,
            'status' => $user->status,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /** Minimal public payload used for post and comment authors. */
    public static function author(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar_url' => $user->avatarUrl(),
        ];
    }

    /** Public card payload used in My Society. */
    public static function person(User $user): array
    {
        return self::author($user) + [
            'banner_url' => $user->bannerUrl(),
            'headline' => $user->headline,
            'location' => $user->location,
        ];
    }
}
