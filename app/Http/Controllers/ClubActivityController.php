<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubActivity;
use App\Models\PersonalActivity;
use App\Support\ClubPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ClubActivityController extends Controller
{
    /**
     * Everything for the activities page: club and personal activities from the last day onward (soonest
     * first), the most recent older ones, and the clubs where the signed-in user can plan new ones.
     */
    public function index(): JsonResponse
    {
        $viewerId = $this->viewerId();
        $cutoff = now()->subDay();
        $inMyClubs = fn () => ClubActivity::query()
            ->withDetails()
            ->whereHas('club.members', fn (Builder $q) => $q->where('users.id', $viewerId));
        $mine = fn () => PersonalActivity::query()->with('club')->where('user_id', $viewerId);

        $upcoming = $inMyClubs()->where('starts_at', '>=', $cutoff)->orderBy('starts_at')->limit(100)->get();
        $past = $inMyClubs()->where('starts_at', '<', $cutoff)->orderByDesc('starts_at')->limit(30)->get();
        $personalUpcoming = $mine()->where('starts_at', '>=', $cutoff)->orderBy('starts_at')->limit(100)->get();
        $personalPast = $mine()->where('starts_at', '<', $cutoff)->orderByDesc('starts_at')->limit(30)->get();

        $organizeClubs = Club::query()->organizableBy($viewerId)->orderBy('name')->get();
        $myClubs = Club::query()
            ->whereHas('members', fn (Builder $q) => $q->where('users.id', $viewerId))
            ->orderBy('name')
            ->get();

        $present = fn ($clubActivities, $personalActivities) => $clubActivities
            ->map(fn (ClubActivity $a) => ClubPresenter::activity($a, $viewerId))
            ->concat($personalActivities->map(fn (PersonalActivity $a) => $a->present()));

        return response()->json([
            'upcoming' => $present($upcoming, $personalUpcoming)->sortBy('starts_at')->values(),
            'past' => $present($past, $personalPast)->sortByDesc('starts_at')->values(),
            'organize_clubs' => $organizeClubs->map(fn (Club $club) => ClubPresenter::summary($club))->values(),
            'my_clubs' => $myClubs->map(fn (Club $club) => ClubPresenter::summary($club))->values(),
            'clubs_count' => $myClubs->count(),
        ]);
    }

    /** Next activities across the clubs the signed-in user belongs to. */
    public function upcoming(Request $request): JsonResponse
    {
        $viewerId = $this->viewerId();
        $limit = min(max((int) $request->query('limit', 5), 1), 50);

        $activities = ClubActivity::query()
            ->withDetails()
            ->whereHas('club.members', fn (Builder $q) => $q->where('users.id', $viewerId))
            ->where('starts_at', '>=', now()->startOfDay())
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $activities->map(fn (ClubActivity $a) => ClubPresenter::activity($a, $viewerId))->values(),
        ]);
    }

    public function store(Request $request, Club $club): JsonResponse
    {
        $viewerId = $this->viewerId();

        if (! $club->canOrganize($viewerId)) {
            return response()->json(['message' => 'Only the owner and officers who organize can add activities.'], 403);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'starts_at' => ['required', 'date', 'after:now'],
            'location' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'title.required' => 'Give the activity a name.',
            'starts_at.required' => 'Pick a date and time.',
            'starts_at.after' => 'Pick a date and time in the future.',
        ]);

        $activity = $club->activities()->create([
            'user_id' => $viewerId,
            'title' => trim($data['title']),
            'starts_at' => Carbon::parse($data['starts_at'])->setTimezone(config('app.timezone')),
            'location' => isset($data['location']) ? (trim($data['location']) ?: null) : null,
            'description' => isset($data['description']) ? (trim($data['description']) ?: null) : null,
        ]);
        $activity->load(ClubActivity::details());

        return response()->json([
            'message' => 'Activity added.',
            'activity' => ClubPresenter::activity($activity, $viewerId),
        ], 201);
    }

    /** A member says whether they'll attend; answering again changes their answer. */
    public function respond(Request $request, ClubActivity $activity): JsonResponse
    {
        $viewerId = $this->viewerId();
        $club = $activity->club;

        if (! $club->hasMember($viewerId)) {
            return response()->json(['message' => 'Join '.$club->name.' to respond to its activities.'], 403);
        }
        if ($activity->hasStarted()) {
            return response()->json(['message' => 'This activity has already started.'], 422);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(ClubActivity::RESPONSES)],
        ]);

        $activity->responders()->syncWithoutDetaching([$viewerId => ['status' => $data['status']]]);
        $activity->load(ClubActivity::details());

        return response()->json([
            'message' => $data['status'] === 'going'
                ? 'You’re going to '.$activity->title.'.'
                : 'Got it. You can’t make it to '.$activity->title.'.',
            'activity' => ClubPresenter::activity($activity, $viewerId),
        ]);
    }

    public function destroy(ClubActivity $activity): JsonResponse
    {
        $viewerId = $this->viewerId();

        if ((int) $activity->user_id !== $viewerId && (int) $activity->club->owner_id !== $viewerId) {
            return response()->json(['message' => 'You can’t delete this activity.'], 403);
        }

        $activity->delete();

        return response()->json(['message' => 'Activity deleted.']);
    }

    private function viewerId(): int
    {
        return (int) auth('api')->id();
    }
}
