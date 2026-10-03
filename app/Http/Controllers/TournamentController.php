<?php

namespace App\Http\Controllers;

use App\Models\Bracket;
use App\Models\Club;
use App\Models\Friendship;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentEntryMember;
use App\Models\TournamentInvite;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\ClubPresenter;
use App\Support\TournamentBracket;
use App\Support\TournamentPresenter;
use App\Support\TournamentSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Tournaments hosted by anyone (personal, invite-only) or by a club's owner, President or organizing officers
 * (open to members). Players or teams register, the organizer starts it to generate the bracket and records
 * results until a champion is crowned.
 */
class TournamentController extends Controller
{
    private const MEDIA = [
        'avatar' => ['column' => 'avatar_path', 'folder' => 'tournament-avatars', 'max_kb' => 5120, 'label' => 'Profile pictures', 'noun' => 'Profile picture'],
        'banner' => ['column' => 'banner_path', 'folder' => 'tournament-banners', 'max_kb' => 8192, 'label' => 'Banners', 'noun' => 'Banner'],
    ];

    /**
     * Tournaments you organize, play in, were invited to or that your clubs host; every public tournament
     * (`public`); and where you can host.
     */
    public function index(): JsonResponse
    {
        $viewerId = $this->viewerId();
        $ordered = fn ($query) => $query
            ->withSummary()
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'registration' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE WHEN status = 'completed' THEN NULL ELSE starts_at END")
            ->orderByDesc('completed_at')
            ->limit(100)
            ->get();

        $tournaments = $ordered(Tournament::query()->relevantTo($viewerId));
        $public = $ordered(Tournament::query()->publicOnes());

        return response()->json([
            'data' => TournamentPresenter::list($tournaments, $viewerId),
            'public' => TournamentPresenter::list($public, $viewerId),
            'organize_clubs' => Club::query()->organizableBy($viewerId)->orderBy('name')->get()
                ->map(fn (Club $club) => ClubPresenter::summary($club))->values(),
        ]);
    }

    public function show(Tournament $tournament): JsonResponse
    {
        $viewerId = $this->viewerId();

        if (! $tournament->canView($viewerId)) {
            return response()->json(['message' => 'This tournament is private.'], 403);
        }

        return response()->json(['tournament' => TournamentPresenter::detail($tournament, $viewerId)]);
    }

    public function store(Request $request): JsonResponse
    {
        $viewerId = $this->viewerId();
        $data = $this->validateDetails($request, null);

        $club = null;
        if (! empty($data['club_id'])) {
            $club = Club::query()->findOrFail($data['club_id']);
            if (! $club->canOrganize($viewerId)) {
                return response()->json(['message' => 'Only the owner, the President and organizing officers can host tournaments for '.$club->name.'.'], 403);
            }
        }

        $tournament = DB::transaction(function () use ($data, $club, $viewerId, $request) {
            $tournament = Tournament::query()->create([
                'club_id' => $club?->id,
                'user_id' => $viewerId,
                ...$this->attributes($data),
            ]);

            $this->createInvites($tournament, (array) $request->input('invite_ids', []), $viewerId);

            return $tournament;
        });

        return $this->respond($tournament, $tournament->name.' is open for registration.', 201);
    }

    /** Details, format and bracket type can change until the organizer starts it. */
    public function update(Request $request, Tournament $tournament): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament) ?? $this->denyUnlessRegistering($tournament)) {
            return $denied;
        }

        $data = $this->validateDetails($request, $tournament);
        $entries = $tournament->entries()->withCount('members')->get();
        // Brackets set Max players (their slots added up) until the tournament switches to round robin, unless
        // there's an elimination stage (then more can enter than move on).
        $keepBrackets = $data['bracket'] !== 'round_robin' && ! $tournament->hasGroupStage() && $tournament->brackets()->exists();

        if ($entries->isNotEmpty() && $data['format'] !== $tournament->format) {
            return $this->fail('Remove every entry before switching between individual and team.', 'format');
        }
        if ($data['format'] === 'team' && $entries->max('members_count') > $data['team_size']) {
            return $this->fail('A team already has more players than that.', 'team_size');
        }
        if (! $keepBrackets && ($data['max_entries'] ?? null) !== null && $entries->count() > $data['max_entries']) {
            return $this->fail('More '.($data['format'] === 'team' ? 'teams' : 'players').' have already entered.', 'max_entries');
        }

        $attributes = $this->attributes($data);
        if ($keepBrackets) {
            unset($attributes['max_entries']);
        } elseif ($data['bracket'] === 'round_robin') {
            TournamentBracket::removeAll($tournament);
            $attributes['group_stage'] = null;
        }
        $tournament->update($attributes);
        $this->trimGroupSlots($tournament);

        return $this->respond($tournament, 'Tournament updated.');
    }

    public function destroy(Tournament $tournament): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament)) {
            return $denied;
        }

        $paths = $tournament->mediaPaths();
        $tournament->delete();
        Storage::disk('media')->delete($paths);

        return response()->json(['message' => 'Tournament deleted.']);
    }

    /** Organizer uploads the profile picture (`avatar`) or banner, field `image`. */
    public function uploadMedia(Request $request, Tournament $tournament, string $type): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament)) {
            return $denied;
        }

        $media = self::MEDIA[$type];
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:'.$media['max_kb']],
        ], [
            'image.required' => 'Choose a photo to upload.',
            'image.image' => 'Use a JPG, PNG, GIF or WEBP photo.',
            'image.mimes' => 'Use a JPG, PNG, GIF or WEBP photo.',
            'image.max' => $media['label'].' can be up to '.($media['max_kb'] / 1024).' MB.',
        ]);

        $previous = $tournament->{$media['column']};
        $tournament->update([$media['column'] => $request->file('image')->store($media['folder'], 'media')]);
        if ($previous) {
            Storage::disk('media')->delete($previous);
        }

        return $this->respond($tournament, $media['noun'].' updated.');
    }

    public function removeMedia(Tournament $tournament, string $type): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament)) {
            return $denied;
        }

        $media = self::MEDIA[$type];
        if ($path = $tournament->{$media['column']}) {
            $tournament->update([$media['column'] => null]);
            Storage::disk('media')->delete($path);
        }

        return $this->respond($tournament, $media['noun'].' removed.');
    }

    /** Organizers invite people from their society. */
    public function invite(Request $request, Tournament $tournament): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament)) {
            return $denied;
        }
        if ($tournament->status === Tournament::COMPLETED) {
            return response()->json(['message' => 'This tournament is over.'], 422);
        }

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['integer'],
        ], [
            'user_ids.required' => 'Pick at least one friend.',
        ]);

        $added = $this->createInvites($tournament, $data['user_ids'], $this->viewerId());
        if ($added === 0) {
            return response()->json(['message' => 'Everyone you picked is already invited or playing.'], 422);
        }

        return $this->respond($tournament, $added === 1 ? 'Invite sent.' : $added.' invites sent.');
    }

    /** The organizer withdraws an invite, or the invited person declines it. */
    public function uninvite(Tournament $tournament, User $user): JsonResponse
    {
        $viewerId = $this->viewerId();
        $declining = $user->id === $viewerId;

        if (! $declining && ($denied = $this->denyUnlessManager($tournament))) {
            return $denied;
        }

        $tournament->invites()->where('user_id', $user->id)->delete();

        if ($declining) {
            return response()->json(['message' => 'Invite declined.']);
        }

        // Without the invite they can't play anymore, unless they can enter some other way.
        if ($tournament->isRegistering() && ! $tournament->canEnter($user->id)) {
            $this->removePlayer($tournament, $user->id);
        }

        return $this->respond($tournament, 'Invite for '.$user->name.' withdrawn.');
    }

    /**
     * Enter the tournament. Individual: you become an entry. Team: pass `team_name` to start a team (you're its
     * captain) or `entry_id` to join a team that has room.
     */
    public function join(Request $request, Tournament $tournament): JsonResponse
    {
        $viewerId = $this->viewerId();

        if ($denied = $this->denyUnlessRegistering($tournament)) {
            return $denied;
        }
        if ($tournament->hasPlayer($viewerId)) {
            return response()->json(['message' => 'You’re already in.'], 422);
        }
        if (! $tournament->canEnter($viewerId)) {
            return response()->json([
                'message' => $tournament->club
                    ? 'Join '.$tournament->club->name.' to enter its tournaments.'
                    : 'You need an invite to enter this tournament.',
            ], 403);
        }

        $data = $request->validate([
            'team_name' => ['nullable', 'string', 'max:80'],
            'entry_id' => ['nullable', 'integer'],
        ], [
            'team_name.max' => 'Team names can be up to 80 characters.',
        ]);

        $result = DB::transaction(function () use ($tournament, $data, $viewerId) {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

            if ($locked->isTeam() && ! empty($data['entry_id'])) {
                $entry = $locked->entries()->withCount('members')->find($data['entry_id']);
                if (! $entry) {
                    return $this->fail('That team no longer exists.');
                }
                if ($entry->members_count >= $locked->team_size) {
                    return $this->fail($entry->name.' is full.');
                }
                $this->addMember($locked, $entry, $viewerId);

                return 'You joined '.$entry->name.'.';
            }

            if ($locked->isFull()) {
                return $this->fail('This tournament is full.');
            }

            $teamName = $locked->isTeam() ? trim((string) ($data['team_name'] ?? '')) : null;
            if ($locked->isTeam()) {
                if ($teamName === '') {
                    return $this->fail('Give your team a name.', 'team_name');
                }
                if ($locked->entries()->whereRaw('LOWER(name) = ?', [mb_strtolower($teamName)])->exists()) {
                    return $this->fail('That team name is taken.', 'team_name');
                }
            }

            $entry = $locked->entries()->create(['name' => $teamName, 'captain_id' => $viewerId]);
            $this->addMember($locked, $entry, $viewerId);

            return $locked->isTeam() ? $teamName.' is in! Friends can join your team now.' : 'You’re in! Good luck.';
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $tournament->invites()->where('user_id', $viewerId)->update(['status' => TournamentInvite::ACCEPTED]);

        return $this->respond($tournament, $result);
    }

    public function leave(Tournament $tournament): JsonResponse
    {
        if ($denied = $this->denyUnlessRegistering($tournament)) {
            return $denied;
        }

        $this->removePlayer($tournament, $this->viewerId());

        return $this->respond($tournament, 'You left '.$tournament->name.'.');
    }

    /** The organizer removes a player or team before the start. */
    public function removeEntry(Tournament $tournament, TournamentEntry $entry): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament) ?? $this->denyUnlessRegistering($tournament)) {
            return $denied;
        }
        if ((int) $entry->tournament_id !== $tournament->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $name = $entry->load('members')->displayName();
        $entry->delete();

        return $this->respond($tournament, $name.' removed.');
    }

    /**
     * The next stage. From registration: close it and start the elimination stage if there is one, otherwise build
     * the bracket. From the elimination stage: build the bracket with the entries moving on.
     */
    public function start(Tournament $tournament): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament)) {
            return $denied;
        }
        $what = $tournament->isTeam() ? 'teams' : 'players';

        if ($tournament->inGroupStage()) {
            if ($tournament->entries()->where('advanced', true)->count() < 2) {
                return $this->fail("Pick at least 2 {$what} to move on to the bracket first.");
            }

            try {
                TournamentBracket::generate($tournament);
            } catch (RuntimeException $e) {
                return $this->fail($e->getMessage());
            }

            return $this->respond($tournament, 'The bracket is set. Let the games begin!');
        }

        if ($denied = $this->denyUnlessRegistering($tournament)) {
            return $denied;
        }

        $count = $tournament->entries()->count();
        if ($count < 2) {
            return response()->json(['message' => 'You need at least 2 '.$what.' to start.'], 422);
        }
        if ($tournament->isRoundRobin() && $count > Tournament::MAX_ROUND_ROBIN_ENTRIES) {
            return response()->json(['message' => 'Round robins can have up to '.Tournament::MAX_ROUND_ROBIN_ENTRIES.' '.$what.'. Switch to single elimination or remove some.'], 422);
        }

        try {
            if ($tournament->hasGroupStage()) {
                TournamentBracket::startGroups($tournament);
            } else {
                TournamentBracket::generate($tournament);
            }
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->respond($tournament, $tournament->hasGroupStage()
            ? 'The elimination stage has started. Good luck, everyone!'
            : 'The bracket is set. Let the games begin!');
    }

    /**
     * Turn the elimination stage on or off before the start: `group_stage` (null = off, `single` or `double` round
     * robin) and `group_count` (1–8).
     */
    public function groupStage(Request $request, Tournament $tournament): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament) ?? $this->denyUnlessRegistering($tournament)) {
            return $denied;
        }
        if ($tournament->isRoundRobin()) {
            return $this->fail('A round robin is already everyone against everyone. Switch to single or double elimination first.');
        }

        $data = $request->validate([
            'group_stage' => ['present', 'nullable', Rule::in(Tournament::GROUP_STAGES)],
            'group_count' => ['required', 'integer', 'min:1', 'max:'.Tournament::MAX_GROUPS],
        ], [
            'group_stage.in' => 'Choose single or double round robin.',
            'group_count.min' => 'Make at least 1 group.',
            'group_count.max' => 'You can have up to '.Tournament::MAX_GROUPS.' groups.',
        ]);

        $wasOn = $tournament->hasGroupStage();
        $tournament->update(['group_stage' => $data['group_stage'], 'group_count' => (int) $data['group_count']]);
        $tournament->entries()->update(['bracket_id' => null, 'slot' => null]);
        $tournament->syncCapacity();
        $this->trimGroupSlots($tournament->fresh());

        return $this->respond($tournament, match (true) {
            $data['group_stage'] === null => $wasOn ? 'Elimination stage turned off.' : 'Saved.',
            $wasOn => 'Elimination stage saved.',
            default => 'Elimination stage turned on.',
        });
    }

    /**
     * Before the start, with an elimination stage: `slots`, an entry id or null for each match-map slot (exactly the
     * map's slot count). Anyone left out is drawn into an open slot at the start.
     */
    public function groupSlots(Request $request, Tournament $tournament): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament) ?? $this->denyUnlessRegistering($tournament)) {
            return $denied;
        }
        if (! $tournament->hasGroupStage()) {
            return $this->fail('Turn on the elimination stage first.');
        }

        $count = TournamentBracket::mapSlots($tournament);
        $data = $request->validate([
            'slots' => ['present', 'array', 'size:'.$count],
            'slots.*' => ['nullable', 'integer'],
        ], [
            'slots.size' => "The map has {$count} slots. Refresh and try again.",
        ]);
        $placed = array_filter($data['slots'], fn ($id) => $id !== null);
        $known = $tournament->entries()->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($placed) !== count(array_unique($placed))) {
            return $this->fail('Someone is in two slots.');
        }
        if (array_diff(array_map('intval', $placed), $known)) {
            return $this->fail('Someone you placed is no longer in the tournament. Refresh and try again.');
        }

        DB::transaction(function () use ($tournament, $placed) {
            $tournament->entries()->update(['group_slot' => null]);
            foreach ($placed as $index => $entryId) {
                $tournament->entries()->whereKey($entryId)->update(['group_slot' => $index + 1]);
            }
        });

        return $this->respond($tournament, 'Slots saved.');
    }

    /** Clear match-map slots that no longer exist (the stage was turned off, or Max players went down). */
    private function trimGroupSlots(Tournament $tournament): void
    {
        $entries = $tournament->entries();
        if (! $tournament->hasGroupStage()) {
            $entries->update(['group_slot' => null]);
        } elseif ($tournament->max_entries !== null) {
            $entries->where('group_slot', '>', $tournament->max_entries)->update(['group_slot' => null]);
        }
    }

    /**
     * Game setup: `minutes` a game and `days` (each a `date`, the first game's `start` time and how many `games`).
     * Games moved to a day that's gone go back to filling in order.
     */
    public function schedule(Request $request, Tournament $tournament): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament)) {
            return $denied;
        }

        $data = $request->validate([
            'minutes' => ['required', 'integer', 'min:5', 'max:600'],
            'days' => ['present', 'array', 'max:'.TournamentSchedule::MAX_DAYS],
            'days.*.date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'days.*.start' => ['required', 'date_format:H:i'],
            'days.*.games' => ['required', 'integer', 'min:1', 'max:'.TournamentSchedule::MAX_GAMES_A_DAY],
        ], [
            'minutes.min' => 'A game takes at least 5 minutes.',
            'days.max' => 'You can set up to '.TournamentSchedule::MAX_DAYS.' days.',
            'days.*.date.required' => 'Pick a date for every day.',
            'days.*.date.distinct' => 'Each day needs its own date.',
            'days.*.start.required' => 'Pick a start time for every day.',
            'days.*.games.min' => 'A day needs at least 1 game.',
            'days.*.games.max' => 'A day can have up to '.TournamentSchedule::MAX_GAMES_A_DAY.' games.',
        ]);

        $days = collect($data['days'])
            ->map(fn (array $day) => ['date' => $day['date'], 'start' => $day['start'], 'games' => (int) $day['games']])
            ->sortBy('date')
            ->values();
        $dates = $days->pluck('date')->flip();
        $moves = array_filter(TournamentSchedule::settings($tournament)['moves'], fn ($date) => isset($dates[$date]));

        $tournament->update(['schedule' => ['minutes' => (int) $data['minutes'], 'days' => $days->all(), 'moves' => $moves]]);

        return $this->respond($tournament, $days->isEmpty() ? 'Game days cleared.' : 'Game setup saved.');
    }

    /** Game setup: put the game `key` on the day `date`, or back in order with `date` null. */
    public function moveGame(Request $request, Tournament $tournament): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament)) {
            return $denied;
        }

        $data = $request->validate([
            'key' => ['required', 'string', 'max:40'],
            'date' => ['present', 'nullable', 'date_format:Y-m-d'],
        ]);
        $settings = TournamentSchedule::settings($tournament);
        if (! in_array($data['key'], TournamentSchedule::keys($tournament), true)) {
            return $this->fail('That game isn’t in the schedule anymore. Refresh and try again.');
        }
        $moves = $settings['moves'];
        if ($data['date'] !== null) {
            $index = array_search($data['date'], array_column($settings['days'], 'date'), true);
            if ($index === false) {
                return $this->fail('Pick one of the game days.');
            }
            $taken = count(array_filter($moves, fn ($date, $key) => $date === $data['date'] && $key !== $data['key'], ARRAY_FILTER_USE_BOTH));
            if ($taken >= $settings['days'][$index]['games']) {
                return $this->fail('Day '.($index + 1).' is full: '.$settings['days'][$index]['games'].' games are already moved there. Allow more games that day or move one off it first.');
            }
        }
        if ($data['date'] === null) {
            unset($moves[$data['key']]);
        } else {
            $moves[$data['key']] = $data['date'];
        }
        $tournament->update(['schedule' => ['minutes' => $settings['minutes'], 'days' => $settings['days'], 'moves' => $moves]]);

        return $this->respond($tournament, $data['date'] === null ? 'Game back in order.' : 'Game moved.');
    }

    /** During the elimination stage: `entry_ids`, everyone moving on to the bracket. */
    public function advancing(Request $request, Tournament $tournament): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament)) {
            return $denied;
        }
        if (! $tournament->inGroupStage()) {
            return $this->fail('Picking who moves on happens during the elimination stage.');
        }

        $data = $request->validate([
            'entry_ids' => ['present', 'array'],
            'entry_ids.*' => ['integer', 'distinct'],
        ]);
        $ids = array_map('intval', $data['entry_ids']);
        $known = $tournament->entries()->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (array_diff($ids, $known)) {
            return $this->fail('Someone you picked is no longer in the tournament. Refresh and try again.');
        }

        TournamentBracket::advance($tournament, $ids);
        $count = count($ids);
        $who = $tournament->isTeam() ? ($count === 1 ? 'team' : 'teams') : ($count === 1 ? 'player' : 'players');

        return $this->respond($tournament, $count === 0 ? 'Nobody is moving on yet.' : "{$count} {$who} moving on.");
    }

    /**
     * Create a bracket before the start: `name` and `size` (its first-round slots). Max players becomes every
     * bracket's slots added up.
     */
    public function storeBracket(Request $request, Tournament $tournament): JsonResponse
    {
        if ($denied = $this->denyForBrackets($tournament)) {
            return $denied;
        }
        $brackets = $tournament->brackets()->get();
        if ($brackets->count() >= Bracket::MAX_PER_TOURNAMENT) {
            return $this->fail('A tournament can have up to '.Bracket::MAX_PER_TOURNAMENT.' brackets.');
        }

        $data = $this->validateBracket($request, $tournament, null);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $tournament->brackets()->create([
            'name' => $data['name'],
            'size' => $data['size'],
            'position' => $brackets->isEmpty() ? 0 : (int) $brackets->max('position') + 1,
        ]);
        $tournament->syncCapacity();

        return $this->respond($tournament, $data['name'].' created.');
    }

    /** Rename a bracket (any time) or change its size (before the start; anyone in a slot that's gone is unplaced). */
    public function updateBracket(Request $request, Tournament $tournament, Bracket $bracket): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament) ?? $this->denyForeignBracket($tournament, $bracket)) {
            return $denied;
        }

        $data = $this->validateBracket($request, $tournament, $bracket);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        if ($data['size'] !== $bracket->size) {
            if (! $tournament->isDrawing()) {
                return $this->fail('The bracket has started, so the size can’t change. Reset the bracket first.', 'size');
            }
            TournamentBracket::resize($bracket, $data['size']);
        }
        $bracket->update(['name' => $data['name']]);
        $tournament->syncCapacity();

        return $this->respond($tournament, 'Bracket saved.');
    }

    /**
     * Any time; its players are unplaced. Once the bracket has started, it's reset first (every bracket result is
     * cleared, back to the elimination stage or registration).
     */
    public function destroyBracket(Tournament $tournament, Bracket $bracket): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament) ?? $this->denyForeignBracket($tournament, $bracket)) {
            return $denied;
        }

        $started = ! $tournament->isDrawing();
        $toGroups = $started && TournamentBracket::resetsToGroups($tournament);
        DB::transaction(function () use ($tournament, $bracket, $started) {
            if ($started) {
                TournamentBracket::reset($tournament);
            }
            TournamentBracket::remove($bracket);
        });

        return $this->respond($tournament, $bracket->name.' deleted.'.match (true) {
            $toGroups => ' The bracket results were cleared; you’re back in the elimination stage.',
            $started => ' The results were cleared and registration is open again.',
            default => '',
        });
    }

    /**
     * Place entries in a bracket before the start: `slots` has an entry id (or null) for each of its slots, top to
     * bottom (see `TournamentBracket::layout` for where the byes go). Entries placed here leave any other bracket.
     * Anyone left out of every bracket goes into a random open slot at the start.
     */
    public function bracketDraw(Request $request, Tournament $tournament, Bracket $bracket): JsonResponse
    {
        if ($denied = $this->denyForBrackets($tournament) ?? $this->denyForeignBracket($tournament, $bracket)) {
            return $denied;
        }

        $data = $request->validate([
            'slots' => ['present', 'array'],
            'slots.*' => ['nullable', 'integer'],
            'opening' => ['nullable', 'array'],
            'opening.*' => ['integer', 'distinct', 'min:0'],
        ]);

        $who = $tournament->isTeam() ? 'team' : 'player';
        $entryIds = $tournament->entries()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $slots = array_map(fn ($id) => $id === null ? null : (int) $id, array_values($data['slots']));
        $placed = array_values(array_filter($slots, fn ($id) => $id !== null));
        $size = $bracket->size;

        if (count($slots) !== $size) {
            return $this->fail("{$bracket->name} has {$size} slots. Refresh and try again.");
        }
        if (array_diff($placed, $entryIds)) {
            return $this->fail("A {$who} in the bracket is no longer in the tournament. Refresh and try again.");
        }
        if ($tournament->hasGroupStage()) {
            $movingOn = $tournament->entries()->where('advanced', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (array_diff($placed, $movingOn)) {
                return $this->fail("Only {$who}s moving on from the elimination stage can go in the bracket.");
            }
        }
        if (count($placed) !== count(array_unique($placed))) {
            return $this->fail("Each {$who} can only be in one slot.");
        }

        $opening = isset($data['opening']) ? array_map('intval', array_values($data['opening'])) : null;
        if ($opening !== null) {
            $needed = TournamentBracket::openingCount($size);
            $pairs = TournamentBracket::sizeFor($size) / 2;
            if (count($opening) !== $needed || max([0, ...$opening]) >= $pairs) {
                return $this->fail("A {$size}-slot bracket has {$needed} opening ".($needed === 1 ? 'match' : 'matches')." out of {$pairs} spots.");
            }
            sort($opening);
            if ($opening === TournamentBracket::defaultOpening($size)) {
                $opening = null;
            }
        }

        TournamentBracket::saveDraw($bracket, $slots, $opening);

        return $this->respond($tournament, $bracket->name.' saved.');
    }

    /**
     * Rename a bracket's rounds: `names` counted back from its final (0 = final, 1 = semifinals …; in double
     * elimination 0 = the upper bracket final); blank = usual name.
     */
    public function bracketRoundNames(Request $request, Tournament $tournament, Bracket $bracket): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament) ?? $this->denyForeignBracket($tournament, $bracket)) {
            return $denied;
        }

        $data = $request->validate([
            'names' => ['present', 'array', 'max:7'],
            'names.*' => ['nullable', 'string', 'max:40'],
        ], [
            'names.*.max' => 'Keep round names to 40 characters.',
        ]);

        $names = array_map(fn ($name) => $this->clean($name), array_values($data['names']));
        while ($names !== [] && end($names) === null) {
            array_pop($names);
        }
        $bracket->update(['round_names' => $names === [] ? null : $names]);

        return $this->respond($tournament, 'Round names saved.');
    }

    /**
     * A trimmed, unique name and a size that keeps every bracket together within the slot limit.
     *
     * @return array{name: string, size: int}|JsonResponse
     */
    private function validateBracket(Request $request, Tournament $tournament, ?Bracket $bracket): array|JsonResponse
    {
        $max = Tournament::MAX_ENTRIES;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'size' => ['required', 'integer', 'min:2', 'max:'.$max],
        ], [
            'name.required' => 'Give the bracket a name.',
            'name.max' => 'Bracket names can be up to 60 characters.',
            'size.required' => 'How many slots should the bracket have?',
            'size.integer' => 'The bracket size is a whole number of slots.',
            'size.min' => 'A bracket needs at least 2 slots.',
            'size.max' => "A bracket can have up to {$max} slots.",
        ]);

        $name = trim($data['name']);
        $size = (int) $data['size'];
        if ($name === '') {
            return $this->fail('Give the bracket a name.', 'name');
        }

        $others = $tournament->brackets()->when($bracket, fn ($q) => $q->whereKeyNot($bracket->id))->get();
        if ($others->contains(fn (Bracket $other) => mb_strtolower($other->name) === mb_strtolower($name))) {
            return $this->fail('Another bracket is already called that.', 'name');
        }
        $room = $max - (int) $others->sum('size');
        if ($size > $room) {
            return $this->fail("All the brackets together can have up to {$max} slots, so this one can have up to {$room}.", 'size');
        }

        return ['name' => $name, 'size' => $size];
    }

    private function denyForBrackets(Tournament $tournament): ?JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament)) {
            return $denied;
        }
        if (! $tournament->isDrawing()) {
            return $this->fail('The bracket has started. Reset it first.');
        }

        return $tournament->isRoundRobin()
            ? $this->fail('In a round robin everyone plays everyone, so there are no brackets.')
            : null;
    }

    private function denyForeignBracket(Tournament $tournament, Bracket $bracket): ?JsonResponse
    {
        return (int) $bracket->tournament_id === $tournament->id
            ? null
            : response()->json(['message' => 'Not found.'], 404);
    }

    /** One stage back: the bracket goes back to the elimination stage if there was one, otherwise to registration. */
    public function reset(Tournament $tournament): JsonResponse
    {
        if ($denied = $this->denyUnlessManager($tournament)) {
            return $denied;
        }
        if ($tournament->isRegistering()) {
            return response()->json(['message' => 'This tournament hasn’t started yet.'], 422);
        }

        $toGroups = TournamentBracket::resetsToGroups($tournament);
        $fromGroups = $tournament->inGroupStage();
        TournamentBracket::reset($tournament);

        return $this->respond($tournament, match (true) {
            $toGroups => 'Bracket cleared. You’re back in the elimination stage.',
            $fromGroups => 'Elimination stage cleared. Registration is open again.',
            default => 'Bracket cleared. Registration is open again.',
        });
    }

    /** `winner_id` (an entry in the match, or null for a round-robin draw) and optional `score1` / `score2`. */
    public function recordMatch(Request $request, Tournament $tournament, TournamentMatch $match): JsonResponse
    {
        if ($denied = $this->denyForMatch($tournament, $match)) {
            return $denied;
        }

        $data = $request->validate([
            'winner_id' => ['present', 'nullable', 'integer'],
            'score1' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'score2' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [
            'score1.max' => 'Scores go up to 9999.',
            'score2.max' => 'Scores go up to 9999.',
            'score1.min' => 'Scores can’t be negative.',
            'score2.min' => 'Scores can’t be negative.',
        ]);

        $score1 = $data['score1'] ?? null;
        $score2 = $data['score2'] ?? null;
        if (($score1 === null) !== ($score2 === null)) {
            return $this->fail('Enter both scores, or leave both empty.');
        }

        try {
            TournamentBracket::record($match, $data['winner_id'] !== null ? (int) $data['winner_id'] : null, $score1, $score2);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        $tournament->refresh();
        $bracket = $match->bracket_id ? Bracket::query()->with('winner.members')->find($match->bracket_id) : null;
        $message = match (true) {
            $tournament->status === Tournament::COMPLETED && $tournament->winner !== null => $tournament->winner->load('members')->displayName().' wins '.$tournament->name.'!',
            $bracket?->winner !== null => $bracket->winner->displayName().' wins '.$bracket->name.'!',
            default => 'Result saved.',
        };

        return $this->respond($tournament, $message);
    }

    public function clearMatch(Tournament $tournament, TournamentMatch $match): JsonResponse
    {
        if ($denied = $this->denyForMatch($tournament, $match)) {
            return $denied;
        }

        try {
            TournamentBracket::clear($match);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->respond($tournament, 'Result cleared.');
    }

    /** @return array<string, mixed> */
    private function validateDetails(Request $request, ?Tournament $tournament): array
    {
        $isRoundRobin = $request->input('bracket') === 'round_robin';
        $maxEntries = $isRoundRobin ? Tournament::MAX_ROUND_ROBIN_ENTRIES : Tournament::MAX_ENTRIES;

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'game' => ['nullable', 'string', 'max:80'],
            'format' => ['required', Rule::in(Tournament::FORMATS)],
            'team_size' => ['nullable', 'required_if:format,team', 'integer', 'min:2', 'max:'.Tournament::MAX_TEAM_SIZE],
            'bracket' => ['required', Rule::in(Tournament::BRACKETS)],
            'starts_at' => $tournament ? ['required', 'date'] : ['required', 'date', 'after:now'],
            'location' => ['nullable', 'string', 'max:120'],
            'prize' => ['nullable', 'string', 'max:120'],
            'max_entries' => ['nullable', 'integer', 'min:2', 'max:'.$maxEntries],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', Rule::in(Tournament::VISIBILITIES)],
            'club_id' => $tournament ? ['prohibited'] : ['nullable', 'integer'],
            'invite_ids' => $tournament ? ['prohibited'] : ['nullable', 'array', 'max:100'],
            'invite_ids.*' => ['integer'],
        ], [
            'name.required' => 'Give the tournament a name.',
            'format.required' => 'Choose individual or team.',
            'format.in' => 'Choose individual or team.',
            'team_size.required_if' => 'How many players per team?',
            'team_size.min' => 'Teams need at least 2 players.',
            'team_size.max' => 'Teams can have up to '.Tournament::MAX_TEAM_SIZE.' players.',
            'bracket.required' => 'Choose how the bracket works.',
            'bracket.in' => 'Choose how the bracket works.',
            'starts_at.required' => 'Pick a date and time.',
            'starts_at.after' => 'Pick a date and time in the future.',
            'max_entries.min' => 'Allow at least 2 entries.',
            'max_entries.max' => ($isRoundRobin ? 'Round robins' : 'Tournaments').' can have up to '.$maxEntries.' entries.',
            'visibility.in' => 'Choose public or private.',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        $isTeam = $data['format'] === 'team';

        return [
            'name' => trim($data['name']),
            'game' => $this->clean($data['game'] ?? null),
            'format' => $data['format'],
            'team_size' => $isTeam ? (int) $data['team_size'] : null,
            'bracket' => $data['bracket'],
            'starts_at' => Carbon::parse($data['starts_at'])->setTimezone(config('app.timezone')),
            'location' => $this->clean($data['location'] ?? null),
            'prize' => $this->clean($data['prize'] ?? null),
            'max_entries' => $data['max_entries'] ?? null,
            'description' => $this->clean($data['description'] ?? null),
            ...(isset($data['visibility']) ? ['visibility' => $data['visibility']] : []),
        ];
    }

    /**
     * Invite the inviter's friends who aren't invited or playing yet.
     *
     * @param  array<int, mixed>  $userIds
     */
    private function createInvites(Tournament $tournament, array $userIds, int $inviterId): int
    {
        $friends = Friendship::friendIdsOf($inviterId);
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->unique()
            ->filter(fn (int $id) => in_array($id, $friends, true))
            ->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        $taken = $tournament->invites()->whereIn('user_id', $ids)->pluck('user_id')
            ->concat($tournament->memberships()->whereIn('user_id', $ids)->pluck('user_id'))
            ->map(fn ($id) => (int) $id)
            ->all();
        $fresh = $ids->reject(fn (int $id) => in_array($id, $taken, true))->values();

        $now = now();
        TournamentInvite::query()->insert($fresh->map(fn (int $id) => [
            'tournament_id' => $tournament->id,
            'user_id' => $id,
            'invited_by' => $inviterId,
            'status' => TournamentInvite::PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        return $fresh->count();
    }

    private function addMember(Tournament $tournament, TournamentEntry $entry, int $userId): void
    {
        TournamentEntryMember::query()->create([
            'tournament_id' => $tournament->id,
            'entry_id' => $entry->id,
            'user_id' => $userId,
        ]);
    }

    /** Take someone off their entry; an empty team disappears and a departing captain hands over. */
    private function removePlayer(Tournament $tournament, int $userId): void
    {
        DB::transaction(function () use ($tournament, $userId) {
            $membership = $tournament->memberships()->where('user_id', $userId)->first();
            if (! $membership) {
                return;
            }
            $entry = $membership->entry;
            $membership->delete();

            $next = $entry->members()->first();
            if (! $next) {
                $entry->delete();
            } elseif ((int) $entry->captain_id === $userId) {
                $entry->update(['captain_id' => $next->id]);
            }
        });
    }

    private function respond(Tournament $tournament, string $message, int $status = 200): JsonResponse
    {
        $fresh = Tournament::query()->findOrFail($tournament->id);

        return response()->json([
            'message' => $message,
            'tournament' => TournamentPresenter::detail($fresh, $this->viewerId()),
        ], $status);
    }

    private function denyUnlessManager(Tournament $tournament): ?JsonResponse
    {
        return $tournament->canManage($this->viewerId())
            ? null
            : response()->json(['message' => 'Only the organizer can do that.'], 403);
    }

    private function denyUnlessRegistering(Tournament $tournament): ?JsonResponse
    {
        return $tournament->isRegistering()
            ? null
            : response()->json(['message' => 'Registration is closed: the tournament has started.'], 422);
    }

    private function denyForMatch(Tournament $tournament, TournamentMatch $match): ?JsonResponse
    {
        if ((int) $match->tournament_id !== $tournament->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($denied = $this->denyUnlessManager($tournament)) {
            return $denied;
        }

        return match (true) {
            $tournament->isRegistering() => response()->json(['message' => 'Start the tournament first.'], 422),
            $match->isGroup() && ! $tournament->inGroupStage() => $this->fail('The bracket has started, so elimination-stage results are final. Reset the bracket to change them.'),
            default => null,
        };
    }

    private function fail(string $message, ?string $field = null): JsonResponse
    {
        return response()->json(['message' => $message] + ($field ? ['errors' => [$field => [$message]]] : []), 422);
    }

    private function clean(?string $value): ?string
    {
        return $value === null ? null : (trim($value) ?: null);
    }

    private function viewerId(): int
    {
        return (int) auth('api')->id();
    }
}
