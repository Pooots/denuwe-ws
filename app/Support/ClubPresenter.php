<?php

namespace App\Support;

use App\Models\Club;
use App\Models\ClubActivity;
use App\Models\User;

class ClubPresenter
{
    public static function club(Club $club, int $viewerId): array
    {
        $isPaid = $club->isPaid();

        return [
            'id' => $club->id,
            'uuid' => $club->uuid,
            'type' => $club->type,
            'name' => $club->name,
            'slug' => $club->slug,
            'description' => $club->description,
            'color' => $club->color,
            'avatar_url' => $club->avatarUrl(),
            'banner_url' => $club->bannerUrl(),
            'is_free' => ! $isPaid,
            'fee_amount' => $isPaid ? (float) $club->fee_amount : null,
            'fee_currency' => $club->fee_currency,
            'fee_period' => $isPaid ? $club->fee_period : null,
            'members_count' => (int) ($club->members_count ?? 0),
            'upcoming_count' => (int) ($club->upcoming_count ?? 0),
            'visibility' => $club->visibility ?? 'public',
            'is_member' => (bool) ($club->is_member ?? false),
            'has_requested' => (bool) ($club->has_requested ?? false),
            'is_owner' => (int) $club->owner_id === $viewerId,
            'requests_count' => (int) $club->owner_id === $viewerId ? (int) ($club->requests_count ?? 0) : 0,
            'my_fee_status' => $club->my_fee_status ?? null,
            'my_position' => $club->my_position ?? null,
            'created_at' => $club->created_at?->toIso8601String(),
        ];
    }

    /** Expects an activity loaded with ClubActivity::details(). */
    public static function activity(ClubActivity $activity, int $viewerId): array
    {
        $responders = $activity->responders;
        $going = $responders->filter(fn (User $user) => $user->pivot->status === 'going');
        $notGoing = $responders->filter(fn (User $user) => $user->pivot->status === 'not_going');

        return [
            'kind' => 'club',
            'id' => $activity->id,
            'title' => $activity->title,
            'description' => $activity->description,
            'location' => $activity->location,
            'starts_at' => $activity->starts_at->toIso8601String(),
            'has_started' => $activity->hasStarted(),
            'going_count' => $going->count(),
            'not_going_count' => $notGoing->count(),
            'going' => $going->map(fn (User $user) => UserPresenter::author($user))->values(),
            'not_going' => $notGoing->map(fn (User $user) => UserPresenter::author($user))->values(),
            'my_response' => $responders->firstWhere('id', $viewerId)?->pivot->status,
            'club' => self::summary($activity->club),
            'created_by' => UserPresenter::author($activity->user),
            'can_delete' => (int) $activity->user_id === $viewerId || (int) $activity->club->owner_id === $viewerId,
        ];
    }

    /** Just enough to label something with the club it belongs to. */
    public static function summary(Club $club): array
    {
        return [
            'id' => $club->id,
            'uuid' => $club->uuid,
            'type' => $club->type,
            'name' => $club->name,
            'slug' => $club->slug,
            'color' => $club->color,
            'avatar_url' => $club->avatarUrl(),
        ];
    }
}
