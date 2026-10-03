<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubActivity;
use App\Models\ClubPosition;
use App\Models\Tournament;
use App\Models\User;
use App\Support\ClubPresenter;
use App\Support\TournamentPresenter;
use App\Support\UserPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ClubController extends Controller
{
    /** Discover clubs, most popular first. */
    public function index(Request $request): JsonResponse
    {
        $viewerId = $this->viewerId();
        $search = trim((string) $request->query('search', ''));

        $clubs = $this->clubQuery($viewerId)
            ->listedFor($viewerId)
            ->when($search !== '', fn (Builder $q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderByDesc('members_count')
            ->orderByDesc('id')
            ->limit(60)
            ->get();

        return response()->json([
            'data' => $clubs->map(fn (Club $club) => ClubPresenter::club($club, $viewerId))->values(),
        ]);
    }

    /** Clubs the signed-in user belongs to. */
    public function mine(): JsonResponse
    {
        $viewerId = $this->viewerId();

        $clubs = $this->clubQuery($viewerId)
            ->whereHas('members', fn (Builder $q) => $q->where('users.id', $viewerId))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $clubs->map(fn (Club $club) => ClubPresenter::club($club, $viewerId))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $viewerId = $this->viewerId();

        $data = $this->validateDetails($request);

        $club = DB::transaction(function () use ($data, $viewerId) {
            $club = Club::query()->create(['owner_id' => $viewerId] + $this->detailAttributes($data));
            $club->members()->attach($viewerId, ['role' => 'owner']);

            return $club;
        });

        return response()->json([
            'message' => ucfirst($club->type).' created.',
            'club' => ClubPresenter::club($this->clubQuery($viewerId)->findOrFail($club->id), $viewerId),
        ], 201);
    }

    /** `$club` is the slug, or the id for older links. */
    public function show(string $club): JsonResponse
    {
        $viewerId = $this->viewerId();
        $model = $this->clubQuery($viewerId)->identifiedBy($club)->firstOrFail();

        // Someone with the link only learns enough to ask to join.
        if ($model->isPrivate() && ! $model->is_member) {
            return response()->json([
                'club' => [
                    'description' => null,
                    'banner_url' => null,
                    'members_count' => 0,
                    'upcoming_count' => 0,
                ] + ClubPresenter::club($model, $viewerId),
                'can_view' => false,
                'members' => [],
                'positions' => [],
                'activities' => [],
                'tournaments' => [],
                'join_requests' => [],
                'can_organize' => false,
            ]);
        }

        $positions = $model->positions()->get()->keyBy('id');

        // Officers first in the owner's position order, then the owner, then members by join date.
        $members = $model->members()
            ->orderByRaw("CASE WHEN club_members.role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('club_members.created_at')
            ->limit(200)
            ->get()
            ->sortBy(fn (User $user) => $positions->get($user->pivot->position_id)?->sort_order ?? PHP_INT_MAX)
            ->values();
        $holders = $members->countBy(fn (User $user) => $user->pivot->position_id);

        $activities = $model->activities()
            ->withDetails()
            ->where('starts_at', '>=', now()->startOfDay())
            ->orderBy('starts_at')
            ->limit(30)
            ->get();

        $activeTournaments = $model->tournaments()->withSummary()
            ->where('status', '!=', Tournament::COMPLETED)
            ->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 ELSE 1 END")
            ->orderBy('starts_at')
            ->limit(30)
            ->get();
        $finishedTournaments = $model->tournaments()->withSummary()
            ->where('status', Tournament::COMPLETED)
            ->orderByDesc('completed_at')
            ->limit(10)
            ->get();

        $isOwner = (int) $model->owner_id === $viewerId;
        $requests = $isOwner
            ? $model->joinRequests()->orderBy('club_join_requests.created_at')->limit(100)->get()
            : collect();

        return response()->json([
            'club' => ClubPresenter::club($model, $viewerId),
            'can_view' => true,
            'join_requests' => $requests->map(fn (User $user) => UserPresenter::author($user) + [
                'requested_at' => $user->pivot->created_at?->toIso8601String(),
            ])->values(),
            'members' => $members->map(function (User $user) use ($isOwner, $viewerId, $positions) {
                $position = $positions->get($user->pivot->position_id);

                return UserPresenter::author($user) + [
                    'role' => $user->pivot->role,
                    'position' => $position ? ['id' => $position->id, 'name' => $position->name] : null,
                    'fee_status' => $isOwner || $user->id === $viewerId ? $user->pivot->fee_status : null,
                ];
            })->values(),
            'positions' => $positions->values()->map(fn (ClubPosition $p) => $p->present($holders->get($p->id, 0))),
            'activities' => $activities->map(fn (ClubActivity $a) => ClubPresenter::activity($a, $viewerId))->values(),
            'tournaments' => TournamentPresenter::list($activeTournaments->concat($finishedTournaments), $viewerId),
            'can_organize' => $model->canOrganize($viewerId),
        ]);
    }

    /** Owner edits name, type, description, colour and membership fee. */
    public function update(Request $request, Club $club): JsonResponse
    {
        if ($denied = $this->denyUnlessOwner($club, 'edit')) {
            return $denied;
        }

        $data = $this->validateDetails($request);
        $wasPaid = $club->isPaid();
        $wasPrivate = $club->isPrivate();

        DB::transaction(function () use ($club, $data, $wasPaid, $wasPrivate) {
            $club->update($this->detailAttributes($data));

            if ($wasPaid !== $club->isPaid()) {
                // Switching to paid makes existing members pending; switching to free clears fee tracking.
                DB::table('club_members')
                    ->where('club_id', $club->id)
                    ->where('role', '!=', 'owner')
                    ->update(['fee_status' => $club->isPaid() ? 'unpaid' : null]);
            }

            // Anyone can join a public club, so people already waiting are let in.
            if ($wasPrivate && ! $club->isPrivate()) {
                foreach ($club->joinRequests()->pluck('users.id') as $userId) {
                    $club->admit((int) $userId);
                }
            }
        });

        return $this->clubResponse($club, ucfirst($club->type).' updated.');
    }

    public function uploadAvatar(Request $request, Club $club): JsonResponse
    {
        return $this->storeMedia($request, $club, 'avatar');
    }

    public function removeAvatar(Club $club): JsonResponse
    {
        return $this->removeMedia($club, 'avatar');
    }

    public function uploadBanner(Request $request, Club $club): JsonResponse
    {
        return $this->storeMedia($request, $club, 'banner');
    }

    public function removeBanner(Club $club): JsonResponse
    {
        return $this->removeMedia($club, 'banner');
    }

    public function destroy(Club $club): JsonResponse
    {
        if ((int) $club->owner_id !== $this->viewerId()) {
            return response()->json(['message' => 'Only the club owner can delete this club.'], 403);
        }

        $postImages = $club->posts()->whereNotNull('image_path')->pluck('image_path')->all();
        $tournamentImages = $club->tournaments()->get()->flatMap(fn (Tournament $t) => $t->mediaPaths())->all();
        Storage::disk('media')->delete(array_filter([$club->avatar_path, $club->banner_path, ...$postImages, ...$tournamentImages]));
        $club->delete();

        return response()->json(['message' => 'Club deleted.']);
    }

    /** Public clubs: join right away. Private clubs: ask the owner to let you in. */
    public function join(Club $club): JsonResponse
    {
        $viewerId = $this->viewerId();

        if ($club->isPrivate() && ! $club->hasMember($viewerId)) {
            $club->joinRequests()->syncWithoutDetaching([$viewerId]);

            return $this->clubResponse($club, 'Request sent. You’ll join '.$club->name.' once the owner approves.');
        }

        $club->admit($viewerId);

        return $this->clubResponse($club, $club->isPaid()
            ? 'You joined '.$club->name.'. Your membership fee is pending.'
            : 'You joined '.$club->name.'.');
    }

    /** Owner lets someone who asked into their private club. */
    public function approveRequest(Club $club, User $user): JsonResponse
    {
        if ($denied = $this->denyUnlessOwner($club, 'approve requests for')) {
            return $denied;
        }
        if (! $club->joinRequests()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => $user->name.' has no pending request.'], 422);
        }

        $club->admit($user->id);

        return response()->json(['message' => $user->name.' is now a member.', 'user_id' => $user->id]);
    }

    public function declineRequest(Club $club, User $user): JsonResponse
    {
        if ($denied = $this->denyUnlessOwner($club, 'decline requests for')) {
            return $denied;
        }

        $club->joinRequests()->detach($user->id);

        return response()->json(['message' => 'Request from '.$user->name.' declined.', 'user_id' => $user->id]);
    }

    /** Owner marks a member's membership fee as paid or unpaid. */
    public function updateMemberFee(Request $request, Club $club, User $user): JsonResponse
    {
        if ((int) $club->owner_id !== $this->viewerId()) {
            return response()->json(['message' => 'Only the owner can update membership fees.'], 403);
        }

        if (! $club->isPaid()) {
            return response()->json(['message' => 'This '.$club->type.' is free to join.'], 422);
        }

        $data = $request->validate([
            'fee_status' => ['required', Rule::in(['paid', 'unpaid'])],
        ]);

        $membership = $club->members()->where('users.id', $user->id)->first();
        if (! $membership || $membership->pivot->role === 'owner') {
            return response()->json(['message' => 'That person isn’t a paying member.'], 422);
        }

        $club->members()->updateExistingPivot($user->id, ['fee_status' => $data['fee_status']]);

        return response()->json([
            'message' => $data['fee_status'] === 'paid'
                ? $user->name.' is marked as paid.'
                : $user->name.' is marked as unpaid.',
            'member_id' => $user->id,
            'fee_status' => $data['fee_status'],
        ]);
    }

    public function leave(Club $club): JsonResponse
    {
        $viewerId = $this->viewerId();

        if ((int) $club->owner_id === $viewerId) {
            return response()->json(['message' => 'Owners can’t leave their own club. Delete it instead.'], 422);
        }

        if (! $club->hasMember($viewerId)) {
            $club->joinRequests()->detach($viewerId);

            return $this->clubResponse($club, 'Your request to join '.$club->name.' was cancelled.');
        }

        $club->members()->detach($viewerId);
        DB::table('club_activity_responses')
            ->where('user_id', $viewerId)
            ->whereIn('activity_id', ClubActivity::query()
                ->where('club_id', $club->id)
                ->where('starts_at', '>', now())
                ->select('id'))
            ->delete();

        return response()->json([
            'message' => 'You left '.$club->name.'.',
            'club' => ClubPresenter::club($this->clubQuery($viewerId)->findOrFail($club->id), $viewerId),
        ]);
    }

    private const MEDIA = [
        'avatar' => ['column' => 'avatar_path', 'folder' => 'club-avatars', 'max_kb' => 5120, 'label' => 'Profile pictures', 'noun' => 'Profile picture'],
        'banner' => ['column' => 'banner_path', 'folder' => 'club-banners', 'max_kb' => 8192, 'label' => 'Banners', 'noun' => 'Banner'],
    ];

    /** @return array<string, mixed> */
    private function validateDetails(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(Club::TYPES)],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['required', Rule::in(Club::COLORS)],
            'visibility' => ['sometimes', Rule::in(Club::VISIBILITIES)],
            'membership' => ['required', Rule::in(['free', 'paid'])],
            'fee_amount' => ['exclude_unless:membership,paid', 'required', 'numeric', 'min:1', 'max:1000000'],
            'fee_currency' => ['exclude_unless:membership,paid', 'required', Rule::in(Club::CURRENCIES)],
            'fee_period' => ['exclude_unless:membership,paid', 'required', Rule::in(Club::FEE_PERIODS)],
        ], [
            'name.required' => 'Give it a name.',
            'fee_amount.required' => 'Enter the membership fee.',
            'fee_amount.min' => 'The membership fee must be at least 1.',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function detailAttributes(array $data): array
    {
        $paid = $data['membership'] === 'paid';

        return [
            'type' => $data['type'],
            'name' => trim($data['name']),
            'description' => isset($data['description']) ? (trim($data['description']) ?: null) : null,
            'color' => $data['color'],
            'fee_amount' => $paid ? round((float) $data['fee_amount'], 2) : null,
            'fee_currency' => $paid ? $data['fee_currency'] : 'PHP',
            'fee_period' => $paid ? $data['fee_period'] : null,
        ] + (isset($data['visibility']) ? ['visibility' => $data['visibility']] : []);
    }

    private function storeMedia(Request $request, Club $club, string $type): JsonResponse
    {
        if ($denied = $this->denyUnlessOwner($club, 'edit')) {
            return $denied;
        }

        $media = self::MEDIA[$type];

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:'.$media['max_kb']],
        ], [
            'image.required' => 'Choose a photo to upload.',
            'image.max' => $media['label'].' can be up to '.($media['max_kb'] / 1024).' MB.',
            'image.mimes' => 'Use a JPG, PNG, GIF or WEBP photo.',
        ]);

        $previous = $club->{$media['column']};
        $club->{$media['column']} = $request->file('image')->store($media['folder'], 'media');
        $club->save();

        if ($previous) {
            Storage::disk('media')->delete($previous);
        }

        return $this->clubResponse($club, $media['noun'].' updated.');
    }

    private function removeMedia(Club $club, string $type): JsonResponse
    {
        if ($denied = $this->denyUnlessOwner($club, 'edit')) {
            return $denied;
        }

        $media = self::MEDIA[$type];

        if ($club->{$media['column']}) {
            Storage::disk('media')->delete($club->{$media['column']});
            $club->{$media['column']} = null;
            $club->save();
        }

        return $this->clubResponse($club, $media['noun'].' removed.');
    }

    private function denyUnlessOwner(Club $club, string $verb): ?JsonResponse
    {
        if ((int) $club->owner_id === $this->viewerId()) {
            return null;
        }

        return response()->json(['message' => 'Only the owner can '.$verb.' this '.$club->type.'.'], 403);
    }

    private function clubResponse(Club $club, string $message): JsonResponse
    {
        $viewerId = $this->viewerId();

        return response()->json([
            'message' => $message,
            'club' => ClubPresenter::club($this->clubQuery($viewerId)->findOrFail($club->id), $viewerId),
        ]);
    }

    private function clubQuery(int $viewerId): Builder
    {
        return Club::query()
            ->select('clubs.*')
            ->addSelect([
                'my_fee_status' => DB::table('club_members')
                    ->select('fee_status')
                    ->whereColumn('club_members.club_id', 'clubs.id')
                    ->where('club_members.user_id', $viewerId)
                    ->limit(1),
                'my_position' => DB::table('club_members')
                    ->join('club_positions', 'club_positions.id', '=', 'club_members.position_id')
                    ->select('club_positions.name')
                    ->whereColumn('club_members.club_id', 'clubs.id')
                    ->where('club_members.user_id', $viewerId)
                    ->limit(1),
            ])
            ->withCount([
                'members',
                'activities as upcoming_count' => fn (Builder $q) => $q->where('starts_at', '>=', now()->startOfDay()),
                'joinRequests as requests_count',
            ])
            ->withExists([
                'members as is_member' => fn (Builder $q) => $q->where('users.id', $viewerId),
                'joinRequests as has_requested' => fn (Builder $q) => $q->where('users.id', $viewerId),
            ]);
    }

    private function viewerId(): int
    {
        return (int) auth('api')->id();
    }
}
