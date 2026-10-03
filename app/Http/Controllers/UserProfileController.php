<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\DiaryEntry;
use App\Models\Friendship;
use App\Models\User;
use App\Support\ClubPresenter;
use App\Support\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Someone else's profile. Anyone sees the basic card; the full profile and diary are for their society (friends).
 */
class UserProfileController extends Controller
{
    public function show(User $user): JsonResponse
    {
        $viewerId = (int) auth('api')->id();
        $isSelf = $user->id === $viewerId;
        $friendship = $isSelf ? null : Friendship::query()->between($viewerId, $user->id)->first();
        $relationship = $isSelf ? 'self' : ($friendship?->relationshipFor($viewerId) ?? 'none');
        $canView = $isSelf || $relationship === 'friends';

        $theirFriends = Friendship::friendIdsOf($user->id);
        $mutual = $isSelf ? 0 : count(array_intersect($theirFriends, Friendship::friendIdsOf($viewerId)));

        $profile = UserPresenter::person($user) + [
            'first_name' => $user->first_name,
            'relationship' => $relationship,
            'mutual_count' => $mutual,
            'since' => ($friendship?->accepted_at ?? $friendship?->created_at)?->toIso8601String(),
            'can_view' => $canView,
        ];

        if ($canView) {
            $profile += [
                'pronouns' => $user->pronouns,
                'bio' => $user->bio,
                'website' => $user->website,
                'contact_email' => $user->contact_email,
                'contact_phone' => $user->contact_phone,
                'created_at' => $user->created_at?->toIso8601String(),
                'friends_count' => count($theirFriends),
                'diary_count' => DiaryEntry::query()->where('user_id', $user->id)->count(),
                'positions' => $this->positions($user->id, $viewerId),
            ];
        }

        return response()->json(['user' => $profile]);
    }

    /**
     * Their officer positions, leaving out private clubs the viewer isn't in.
     *
     * @return array<int, array{club: array, position: string}>
     */
    private function positions(int $userId, int $viewerId): array
    {
        $rows = DB::table('club_members')
            ->join('club_positions', 'club_positions.id', '=', 'club_members.position_id')
            ->where('club_members.user_id', $userId)
            ->orderBy('club_positions.sort_order')
            ->get(['club_members.club_id', 'club_positions.name']);

        $clubs = Club::query()
            ->whereIn('id', $rows->pluck('club_id'))
            ->where(fn ($q) => $q
                ->where('visibility', 'public')
                ->orWhereHas('members', fn ($m) => $m->where('users.id', $viewerId)))
            ->get()
            ->keyBy('id');

        return $rows
            ->filter(fn ($row) => $clubs->has($row->club_id))
            ->map(fn ($row) => ['club' => ClubPresenter::summary($clubs->get($row->club_id)), 'position' => $row->name])
            ->values()
            ->all();
    }
}
