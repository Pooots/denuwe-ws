<?php

namespace App\Support;

use App\Models\Bracket;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentEntryMember;
use App\Models\TournamentInvite;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TournamentPresenter
{
    /**
     * What the viewer has to do with each tournament, loaded once for a whole list.
     *
     * @param  array<int, int>  $tournamentIds
     * @return array{viewer: int, entries: array<int, int>, invites: array<int, array{status: string, invited_by: int}>, clubs: array<int, true>}
     */
    public static function context(int $viewerId, array $tournamentIds): array
    {
        return [
            'viewer' => $viewerId,
            'entries' => TournamentEntryMember::query()
                ->where('user_id', $viewerId)
                ->whereIn('tournament_id', $tournamentIds)
                ->pluck('entry_id', 'tournament_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
            'invites' => TournamentInvite::query()
                ->where('user_id', $viewerId)
                ->whereIn('tournament_id', $tournamentIds)
                ->get()
                ->mapWithKeys(fn (TournamentInvite $i) => [$i->tournament_id => ['status' => $i->status, 'invited_by' => (int) $i->invited_by]])
                ->all(),
            'clubs' => DB::table('club_members')
                ->where('user_id', $viewerId)
                ->pluck('club_id')
                ->mapWithKeys(fn ($id) => [(int) $id => true])
                ->all(),
        ];
    }

    /** Card payload. Expects `Tournament::withSummary()`. */
    public static function summary(Tournament $tournament, array $context): array
    {
        $viewerId = $context['viewer'];
        $myEntry = $context['entries'][$tournament->id] ?? null;
        $invite = $context['invites'][$tournament->id] ?? null;
        $canManage = $tournament->canManage($viewerId);
        $inClub = $tournament->club_id !== null && isset($context['clubs'][(int) $tournament->club_id]);
        $entries = (int) ($tournament->entries_count ?? 0);
        $isFull = $tournament->max_entries !== null && $entries >= $tournament->max_entries;

        return [
            'id' => $tournament->id,
            'uuid' => $tournament->uuid,
            'slug' => $tournament->slug,
            'name' => $tournament->name,
            'game' => $tournament->game,
            'format' => $tournament->format,
            'team_size' => $tournament->team_size,
            // `public`: listed for everyone, anyone can enter; `private`: invites (and club members) only.
            'visibility' => $tournament->visibility,
            'bracket' => $tournament->bracket,
            // Elimination stage before the bracket: null (none), `single` or `double` round robin in groups.
            'group_stage' => $tournament->hasGroupStage() ? $tournament->group_stage : null,
            'group_count' => $tournament->group_count,
            'status' => $tournament->status,
            // While live: `groups` (elimination stage) or `knockout` (the bracket, after an elimination stage).
            'stage' => $tournament->stage,
            'starts_at' => $tournament->starts_at->toIso8601String(),
            'location' => $tournament->location,
            'prize' => $tournament->prize,
            'description' => $tournament->description,
            'avatar_url' => $tournament->avatarUrl(),
            'banner_url' => $tournament->bannerUrl(),
            'max_entries' => $tournament->max_entries,
            'entries_count' => $entries,
            'players_count' => (int) ($tournament->memberships_count ?? 0),
            'is_full' => $isFull,
            'club' => $tournament->club ? ClubPresenter::summary($tournament->club) : null,
            'created_by' => UserPresenter::author($tournament->user),
            'winner' => $tournament->winner ? self::entry($tournament->winner) : null,
            // Each bracket's champion, in bracket order (one tournament can crown several).
            'champions' => $tournament->brackets
                ->filter(fn (Bracket $b) => $b->winner !== null)
                ->map(fn (Bracket $b) => ['bracket' => $b->name, 'entry' => self::entry($b->winner)])
                ->values(),
            'started_at' => $tournament->started_at?->toIso8601String(),
            'completed_at' => $tournament->completed_at?->toIso8601String(),
            'my_entry_id' => $myEntry,
            'invite_pending' => $invite !== null && $invite['status'] === TournamentInvite::PENDING && $myEntry === null,
            'can_manage' => $canManage,
            'can_enter' => $tournament->isRegistering() && $myEntry === null && ($tournament->isPublic() || $canManage || $invite !== null || $inClub),
            // Where the viewer can share it as a post: `feed` (public), `club` (private, on its club's wall) or null.
            'share_to' => $tournament->isPublic() ? 'feed' : ($inClub ? 'club' : null),
        ];
    }

    /** Everything on the tournament page. */
    public static function detail(Tournament $tournament, int $viewerId): array
    {
        $tournament->loadMissing('club', 'user', 'winner.members', 'brackets.winner.members')->loadCount(['entries', 'memberships']);
        $entries = $tournament->entries()->with('members')->get();
        $matches = $tournament->matches()->get();
        $context = self::context($viewerId, [$tournament->id]);
        $summary = self::summary($tournament, $context);

        $invites = $tournament->invites()->with(['user', 'inviter'])->latest('id')->get();
        $playerIds = $entries->flatMap(fn (TournamentEntry $e) => $e->members->pluck('id'))->all();
        $inviter = isset($context['invites'][$tournament->id])
            ? User::query()->find($context['invites'][$tournament->id]['invited_by'])
            : null;
        $drawing = $tournament->isDrawing() && ! $tournament->isRoundRobin();

        return $summary + [
            'entries' => $entries->map(fn (TournamentEntry $e) => self::entry($e))->values(),
            'matches' => $matches->map(fn (TournamentMatch $m) => self::match($m))->values(),
            // Round robin: how many rounds. Elimination tournaments have it per bracket.
            'rounds' => (int) ($matches->where('side', TournamentMatch::WINNERS)->max('round') ?? 0),
            'brackets' => $tournament->brackets
                ->map(fn (Bracket $b) => self::bracket($b, $entries, $matches, $drawing))
                ->values(),
            'standings' => $tournament->isRoundRobin() && $matches->isNotEmpty()
                ? TournamentBracket::standings($entries, $matches)
                : [],
            // Every result so far, elimination stage and brackets together (byes don't count).
            'overall_standings' => $matches->contains(fn (TournamentMatch $m) => $m->isCompleted())
                ? TournamentBracket::standings($entries, $matches)
                : [],
            'groups' => self::groups($entries, $matches),
            'group_map' => self::groupMap($tournament, $entries->count()),
            'schedule' => TournamentSchedule::present($tournament, $entries, $matches),
            // Organizers see who hasn't answered yet; everyone sees how many were invited.
            'invites' => $summary['can_manage']
                ? $invites
                    ->reject(fn (TournamentInvite $i) => in_array($i->user_id, $playerIds, true))
                    ->map(fn (TournamentInvite $i) => [
                        'user' => UserPresenter::author($i->user),
                        'invited_by' => UserPresenter::author($i->inviter),
                        'created_at' => $i->created_at?->toIso8601String(),
                    ])->values()
                : [],
            'invites_count' => $invites->count(),
            'invited_by' => $summary['invite_pending'] && $inviter ? UserPresenter::author($inviter) : null,
            'max_team_size' => Tournament::MAX_TEAM_SIZE,
        ];
    }

    /** @param  Collection<int, Tournament>  $tournaments */
    public static function list(Collection $tournaments, int $viewerId): Collection
    {
        $context = self::context($viewerId, $tournaments->pluck('id')->all());

        return $tournaments->map(fn (Tournament $t) => self::summary($t, $context))->values();
    }

    public static function entry(TournamentEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'name' => $entry->displayName(),
            'team_name' => $entry->name,
            'seed' => $entry->seed,
            'group_number' => $entry->group_number,
            'group_slot' => $entry->group_slot,
            'advanced' => (bool) $entry->advanced,
            'captain_id' => $entry->captain_id,
            'members' => $entry->members->map(fn (User $user) => UserPresenter::author($user))->values(),
        ];
    }

    /**
     * @param  Collection<int, TournamentEntry>  $entries
     * @param  Collection<int, TournamentMatch>  $matches
     */
    public static function bracket(Bracket $bracket, Collection $entries, Collection $matches, bool $drawing): array
    {
        return [
            'id' => $bracket->id,
            'name' => $bracket->name,
            'size' => $bracket->size,
            // Rounds in its winners bracket once it's started (the only one unless it's double elimination).
            'rounds' => (int) ($matches->where('bracket_id', $bracket->id)->where('side', TournamentMatch::WINNERS)->max('round') ?? 0),
            // Before the start: who the organizer put in each slot, top to bottom (null = open).
            'draw' => $drawing ? TournamentBracket::draw($bracket, $entries) : null,
            // With the draw: which first-round pairs play an opening match (the rest are byes).
            'opening' => $drawing ? TournamentBracket::openingFor($bracket) : null,
            // Custom round titles counted back from the final; null entries use the usual name.
            'round_names' => $bracket->round_names ?? [],
            'winner_id' => $bracket->winner_entry_id,
        ];
    }

    /**
     * Elimination-stage groups, once it has started: who's in each and their table (see `TournamentBracket::standings`).
     *
     * @param  Collection<int, TournamentEntry>  $entries
     * @param  Collection<int, TournamentMatch>  $matches
     */
    public static function groups(Collection $entries, Collection $matches): array
    {
        $groupMatches = $matches->filter(fn (TournamentMatch $m) => $m->isGroup());
        if ($groupMatches->isEmpty()) {
            return [];
        }

        return $entries->whereNotNull('group_number')->groupBy('group_number')->sortKeys()
            ->map(function (Collection $members, int $number) use ($groupMatches) {
                $own = $groupMatches->where('group_number', $number);

                return [
                    'number' => $number,
                    'name' => 'Group '.chr(64 + $number),
                    'entry_ids' => $members->pluck('id')->values(),
                    'rounds' => (int) ($own->max('round') ?? 0),
                    'standings' => TournamentBracket::standings($members, $own),
                ];
            })->values()->all();
    }

    /**
     * Before the start, with an elimination stage: its match map by slot (see `TournamentBracket::slotMap`) for
     * Max players' slots, or the entries so far when there's no limit.
     */
    public static function groupMap(Tournament $tournament, int $entryCount): ?array
    {
        if (! $tournament->isRegistering() || ! $tournament->hasGroupStage()) {
            return null;
        }
        $slots = TournamentBracket::mapSlots($tournament, $entryCount);

        return [
            'slots' => $slots,
            'from_max_entries' => $tournament->max_entries !== null,
            'groups' => TournamentBracket::slotMap($slots, $tournament->group_count, $tournament->group_stage === 'double' ? 2 : 1),
        ];
    }

    public static function match(TournamentMatch $match): array
    {
        return [
            'id' => $match->id,
            'bracket_id' => $match->bracket_id,
            'group_number' => $match->group_number,
            'side' => $match->side,
            'round' => $match->round,
            'position' => $match->position,
            'entry1_id' => $match->entry1_id,
            'entry2_id' => $match->entry2_id,
            'score1' => $match->score1,
            'score2' => $match->score2,
            'winner_id' => $match->winner_entry_id,
            'completed' => $match->isCompleted(),
            'is_bye' => $match->isBye(),
        ];
    }
}
