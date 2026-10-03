<?php

namespace App\Support;

use App\Models\Bracket;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Builds brackets, records results and works out who's winning. */
class TournamentBracket
{
    public const WIN_POINTS = 3;

    public const DRAW_POINTS = 1;

    /**
     * Create every match. Round robin shuffles the seeds; elimination fills each of the organizer's brackets (see
     * `placements`) and byes advance straight away. After an elimination stage only the entries moving on play in
     * the brackets, and the elimination-stage matches are kept.
     *
     * @throws RuntimeException when the brackets are too small, or would leave a first-round match empty
     */
    public static function generate(Tournament $tournament): void
    {
        $entries = $tournament->inGroupStage()
            ? $tournament->entries()->where('advanced', true)->get()
            : $tournament->entries()->get();
        $placements = $tournament->isRoundRobin() ? [] : self::placements($tournament, $entries);

        DB::transaction(function () use ($tournament, $entries, $placements) {
            $tournament->matches()->where('side', '!=', TournamentMatch::GROUP)->delete();

            if ($tournament->isRoundRobin()) {
                $entries = $entries->shuffle()->values();
                $entries->each(fn (TournamentEntry $entry, int $i) => $entry->update(['seed' => $i + 1]));
                self::createRoundRobin($tournament, $entries);
            } else {
                $tournament->entries()->update(['seed' => null]);
                foreach ($placements as [$bracket, $slots]) {
                    if (! $bracket->exists) {
                        $tournament->brackets()->save($bracket);
                    }
                    $bracket->update(['winner_entry_id' => null, 'completed_at' => null]);
                    // Seeds follow the draw top to bottom, and the draw is kept so a reset brings it back.
                    $seed = 0;
                    foreach ($slots as $slot => $entryId) {
                        if ($entryId !== null) {
                            TournamentEntry::query()->whereKey($entryId)
                                ->update(['bracket_id' => $bracket->id, 'slot' => $slot, 'seed' => ++$seed]);
                        }
                    }
                    $positions = self::positions($slots, self::openingFor($bracket));
                    self::createElimination($bracket, $positions);
                    if ($tournament->isDoubleElimination()) {
                        DoubleElimination::create($bracket, count($positions));
                        DoubleElimination::sync($bracket);
                    }
                }
                $tournament->syncCapacity();
            }

            $tournament->update([
                'status' => Tournament::IN_PROGRESS,
                'stage' => $tournament->hasGroupStage() ? Tournament::STAGE_KNOCKOUT : null,
                'started_at' => $tournament->started_at ?? now(),
                'completed_at' => null,
                'winner_entry_id' => null,
            ]);

            self::refreshOutcome($tournament);
        });
    }

    /**
     * Slots 1…`count` split into `groups` groups in order (Group A gets the first slots); the first groups get one
     * more when it doesn't divide evenly.
     *
     * @return array<int, array<int, int>> group number => its slot numbers
     */
    public static function slotGroups(int $count, int $groups): array
    {
        $groups = max(1, $groups);
        $result = [];
        $slot = 1;
        for ($group = 1; $group <= $groups; $group++) {
            $size = intdiv($count, $groups) + ($group <= $count % $groups ? 1 : 0);
            $result[$group] = $size > 0 ? range($slot, $slot + $size - 1) : [];
            $slot += $size;
        }

        return $result;
    }

    /**
     * The elimination stage's match map by slot, before anyone is drawn: each group's slots and its matches
     * (round 1 is slot 1 vs slot 2, slot 3 vs slot 4 …). Start deals the entries into slots and plays exactly this
     * map. Empty when the slots can't make 2 a group, or a group would pass the round-robin limit.
     *
     * @return array<int, array{number: int, name: string, slots: array<int, int>, rounds: int, matches: array<int, array{round: int, slot1: int, slot2: int}>}>
     */
    public static function slotMap(int $slots, int $groups, int $legs): array
    {
        $groups = max(1, $groups);
        if ($slots < $groups * 2 || (int) ceil($slots / $groups) > Tournament::MAX_ROUND_ROBIN_ENTRIES) {
            return [];
        }

        $map = [];
        foreach (self::slotGroups($slots, $groups) as $number => $members) {
            $pairs = self::roundRobinPairs($members, $legs);
            $map[] = [
                'number' => $number,
                'name' => 'Group '.chr(64 + $number),
                'slots' => $members,
                'rounds' => (int) (collect($pairs)->max(0) ?? 0),
                'matches' => array_map(fn ($p) => ['round' => $p[0], 'slot1' => $p[1], 'slot2' => $p[2]], $pairs),
            ];
        }

        return $map;
    }

    /** The match map's slot count before the start: Max players, or the entries so far when there's no limit. */
    public static function mapSlots(Tournament $tournament, ?int $entryCount = null): int
    {
        return $tournament->max_entries ?? $entryCount ?? $tournament->entries()->count();
    }

    /**
     * The entries in slot order for the start: those the organizer placed (`group_slot`) keep their slot, the rest
     * go into random open slots, then any slots still empty are dropped so everyone after them moves up.
     *
     * @return Collection<int, TournamentEntry>
     */
    public static function slotOrder(Tournament $tournament): Collection
    {
        $entries = $tournament->entries()->get();
        $count = max(self::mapSlots($tournament, $entries->count()), $entries->count());
        $board = array_fill(1, $count, null);
        $unplaced = [];
        foreach ($entries->shuffle() as $entry) {
            $slot = $entry->group_slot;
            if ($slot !== null && $slot >= 1 && $slot <= $count && $board[$slot] === null) {
                $board[$slot] = $entry;
            } else {
                $unplaced[] = $entry;
            }
        }
        $open = collect(array_keys(array_filter($board, fn ($e) => $e === null)))->shuffle()->values();
        foreach ($unplaced as $i => $entry) {
            $board[$open[$i]] = $entry;
        }

        return collect(array_values(array_filter($board)));
    }

    /**
     * Start the elimination stage: entries take their slots 1…n (their `seed`; see `slotOrder`), the slots split
     * into `group_count` groups in order (see `slotMap`), and everyone in a group plays everyone else once, or twice
     * with sides swapped.
     *
     * @throws RuntimeException when a group would have fewer than 2 entries or more than the round-robin limit
     */
    public static function startGroups(Tournament $tournament): void
    {
        $entries = self::slotOrder($tournament);
        $groups = max(1, (int) $tournament->group_count);
        $who = $tournament->isTeam() ? 'team' : 'player';
        $count = $entries->count();
        if ($count < $groups * 2) {
            throw new RuntimeException($groups === 1
                ? "You need at least 2 {$who}s to start."
                : "{$groups} groups need at least ".($groups * 2)." {$who}s (2 a group). With {$count}, make it ".max(1, intdiv($count, 2)).' '.(intdiv($count, 2) === 1 ? 'group' : 'groups').' or wait for more.');
        }
        if ((int) ceil($count / $groups) > Tournament::MAX_ROUND_ROBIN_ENTRIES) {
            throw new RuntimeException('A group can have up to '.Tournament::MAX_ROUND_ROBIN_ENTRIES." {$who}s. Make more groups.");
        }

        DB::transaction(function () use ($tournament, $entries, $groups) {
            $tournament->matches()->delete();
            $tournament->entries()->update(['bracket_id' => null, 'slot' => null, 'seed' => null, 'group_number' => null, 'advanced' => false]);
            $tournament->brackets()->update(['winner_entry_id' => null, 'completed_at' => null]);

            $members = [];
            foreach (self::slotGroups($entries->count(), $groups) as $group => $slots) {
                foreach ($slots as $slot) {
                    $entry = $entries[$slot - 1];
                    $entry->update(['seed' => $slot, 'group_number' => $group]);
                    $members[$group][] = $entry->id;
                }
            }

            $legs = $tournament->group_stage === 'double' ? 2 : 1;
            $now = now();
            $rows = [];
            $positions = [];
            foreach ($members as $group => $ids) {
                foreach (self::roundRobinPairs($ids, $legs) as [$round, $entry1, $entry2]) {
                    $positions[$round] = ($positions[$round] ?? -1) + 1;
                    $rows[] = [
                        'tournament_id' => $tournament->id,
                        'side' => TournamentMatch::GROUP,
                        'group_number' => $group,
                        'round' => $round,
                        'position' => $positions[$round],
                        'entry1_id' => $entry1,
                        'entry2_id' => $entry2,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            TournamentMatch::query()->insert($rows);

            $tournament->update([
                'status' => Tournament::IN_PROGRESS,
                'stage' => Tournament::STAGE_GROUPS,
                'started_at' => now(),
                'completed_at' => null,
                'winner_entry_id' => null,
            ]);
        });
    }

    /**
     * Who moves on from the elimination stage to the bracket. Anyone no longer moving on leaves the bracket slot
     * they were put in.
     *
     * @param  array<int, int>  $entryIds  validated: entries of this tournament
     */
    public static function advance(Tournament $tournament, array $entryIds): void
    {
        DB::transaction(function () use ($tournament, $entryIds) {
            $tournament->entries()->whereIn('id', $entryIds)->update(['advanced' => true]);
            $tournament->entries()->whereNotIn('id', $entryIds)->update(['advanced' => false, 'bracket_id' => null, 'slot' => null]);
        });
    }

    /** Whether `reset` goes back to the elimination stage (the bracket had started after one) rather than registration. */
    public static function resetsToGroups(Tournament $tournament): bool
    {
        return $tournament->hasGroupStage()
            && $tournament->stage === Tournament::STAGE_KNOCKOUT
            && $tournament->matches()->where('side', TournamentMatch::GROUP)->exists();
    }

    /** Positions in a full first round with room for this many: the next power of two, at least 2. */
    public static function sizeFor(int $count): int
    {
        return 2 ** (int) ceil(log(max($count, 2), 2));
    }

    /**
     * Where each of `$slots` bracket slots sits in the full first round. When `$slots` isn't a power of two, only
     * some first-round pairs play an opening match (`$opening`, by pair index); every other pair is one slot whose
     * player has a bye into the next round. Slots are numbered top to bottom.
     *
     * @param  array<int, int>|null  $opening  null for the spread-out default (see `defaultOpening`)
     * @return array<int, int|null> the slot index at each position, null for a bye
     */
    public static function layout(int $slots, ?array $opening = null): array
    {
        $opening = array_flip($opening ?? self::defaultOpening($slots));
        $layout = [];
        $slot = 0;
        for ($pair = 0; $pair < self::sizeFor($slots) / 2; $pair++) {
            $layout[] = $slot++;
            $layout[] = isset($opening[$pair]) ? $slot++ : null;
        }

        return $layout;
    }

    /**
     * Opening matches spread out like seeds past the field, so byes go to the top of each section, e.g. 10 slots →
     * pairs 1 and 5 play and six players go straight into the quarterfinals.
     *
     * @return array<int, int>
     */
    public static function defaultOpening(int $slots): array
    {
        $order = self::seedOrder(self::sizeFor($slots));
        $pairs = [];
        for ($pair = 0; $pair < count($order) / 2; $pair++) {
            if (max($order[$pair * 2], $order[$pair * 2 + 1]) <= $slots) {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /** How many first-round pairs play an opening match in a bracket of this many slots. */
    public static function openingCount(int $slots): int
    {
        return $slots - self::sizeFor($slots) / 2;
    }

    /**
     * The organizer's opening matches if they fit the bracket's size, otherwise the default.
     *
     * @return array<int, int>
     */
    public static function openingFor(Bracket $bracket): array
    {
        $opening = $bracket->opening_matches;
        $slots = $bracket->size;

        return is_array($opening) && count($opening) === self::openingCount($slots)
            && max([0, ...$opening]) < self::sizeFor($slots) / 2
            ? array_map('intval', $opening)
            : self::defaultOpening($slots);
    }

    /**
     * @param  array<int, int|null>  $slots  entry id (or null) per bracket slot
     * @param  array<int, int>  $opening
     * @return array<int, int|null> entry id per first-round position, two per match
     */
    private static function positions(array $slots, array $opening): array
    {
        return array_map(fn (?int $slot) => $slot === null ? null : $slots[$slot], self::layout(count($slots), $opening));
    }

    /** A new size: anyone in a slot that's gone is unplaced, and the opening matches go back to the default. */
    public static function resize(Bracket $bracket, int $size): void
    {
        DB::transaction(function () use ($bracket, $size) {
            $bracket->entries()->where('slot', '>=', $size)->update(['bracket_id' => null, 'slot' => null]);
            $bracket->update(['size' => $size, 'opening_matches' => null]);
        });
    }

    /**
     * Who the organizer put in each of the bracket's first-round slots, top to bottom (null = open).
     *
     * @param  Collection<int, TournamentEntry>  $entries
     * @return array<int, int|null>
     */
    public static function draw(Bracket $bracket, Collection $entries): array
    {
        $slots = array_fill(0, $bracket->size, null);
        foreach ($entries as $entry) {
            if ((int) $entry->bracket_id === $bracket->id && $entry->slot !== null && $entry->slot < $bracket->size && $slots[$entry->slot] === null) {
                $slots[$entry->slot] = $entry->id;
            }
        }

        return $slots;
    }

    /**
     * Entries placed here leave any other bracket they were in.
     *
     * @param  array<int, int|null>  $slots  validated: the bracket's size long, entries of this tournament, no repeats
     * @param  array<int, int>|null  $opening  validated against the size; null for the default
     */
    public static function saveDraw(Bracket $bracket, array $slots, ?array $opening = null): void
    {
        DB::transaction(function () use ($bracket, $slots, $opening) {
            $bracket->entries()->update(['bracket_id' => null, 'slot' => null]);
            foreach ($slots as $slot => $entryId) {
                if ($entryId !== null) {
                    TournamentEntry::query()->whereKey($entryId)->update(['bracket_id' => $bracket->id, 'slot' => $slot]);
                }
            }
            $bracket->update(['opening_matches' => $opening]);
        });
    }

    /** Delete a bracket before the start; its players are unplaced. */
    public static function remove(Bracket $bracket): void
    {
        DB::transaction(function () use ($bracket) {
            $tournament = $bracket->tournament;
            $bracket->entries()->update(['bracket_id' => null, 'slot' => null]);
            $bracket->delete();
            $tournament->syncCapacity();
        });
    }

    /** Every bracket goes, e.g. when the tournament switches to round robin. */
    public static function removeAll(Tournament $tournament): void
    {
        DB::transaction(function () use ($tournament) {
            $tournament->entries()->update(['bracket_id' => null, 'slot' => null]);
            $tournament->brackets()->delete();
        });
    }

    /**
     * Who starts where. Placed entries stay put; everyone else goes into a random open slot in any bracket, filling
     * empty first-round matches first so every match has someone. Without brackets there's one "Main bracket" with
     * a slot per entry in random order (saved when the tournament starts).
     *
     * @param  Collection<int, TournamentEntry>  $entries
     * @return array<int, array{0: Bracket, 1: array<int, int|null>}> each bracket with an entry id (or null) per slot
     *
     * @throws RuntimeException when the brackets are too small, or would leave a first-round match empty
     */
    public static function placements(Tournament $tournament, Collection $entries): array
    {
        $brackets = $tournament->brackets()->get()->values();
        if ($brackets->isEmpty()) {
            $bracket = new Bracket(['name' => Bracket::DEFAULT_NAME, 'size' => max(2, $entries->count()), 'position' => 0]);

            return [[$bracket, $entries->pluck('id')->shuffle()->values()->all()]];
        }

        $who = $tournament->isTeam() ? 'team' : 'player';
        $count = $entries->count();
        $total = (int) $brackets->sum('size');
        $several = $brackets->count() > 1;
        if ($count > $total) {
            throw new RuntimeException($several
                ? "There are {$count} {$who}s but the brackets have {$total} slots. Make one bigger or create another first."
                : "There are {$count} {$who}s but the bracket has {$total} slots. Make it bigger first.");
        }

        $draws = $brackets->map(fn (Bracket $bracket) => self::draw($bracket, $entries))->all();
        $placed = array_merge(...array_map(fn (array $slots) => array_values(array_filter($slots, fn ($id) => $id !== null)), $draws));
        $unplaced = $entries->pluck('id')->diff($placed)->shuffle()->values()->all();

        /** @var array<int, array{0: int, 1: array<int, int>}> $empty bracket index and the slots of each empty match */
        $empty = [];
        $needed = 0;
        foreach ($brackets as $i => $bracket) {
            foreach (array_chunk(self::layout($bracket->size, self::openingFor($bracket)), 2) as $pair) {
                $real = array_values(array_filter($pair, fn ($slot) => $slot !== null));
                $needed++;
                if (! array_filter($real, fn (int $slot) => $draws[$i][$slot] !== null)) {
                    $empty[] = [$i, $real];
                }
            }
        }
        if (count($empty) > count($unplaced)) {
            $size = $brackets->first()->size;
            throw new RuntimeException(match (true) {
                $count >= $needed => "Every first-round match needs at least one {$who}. Spread them out in the ".($several ? 'brackets' : 'bracket').' so no match is empty.',
                $several => "Your brackets need at least {$needed} {$who}s so every first-round match has someone. Wait for more to join, or make a bracket smaller.",
                default => "A {$size}-slot bracket needs at least {$needed} {$who}s so every first-round match has someone. Wait for more to join, or make it {$count} slots.",
            });
        }

        foreach ($empty as [$i, $real]) {
            $draws[$i][$real[array_rand($real)]] = array_shift($unplaced);
        }
        $open = [];
        foreach ($draws as $i => $slots) {
            foreach ($slots as $slot => $entryId) {
                if ($entryId === null) {
                    $open[] = [$i, $slot];
                }
            }
        }
        shuffle($open);
        foreach ($unplaced as $entryId) {
            [$i, $slot] = array_shift($open);
            $draws[$i][$slot] = $entryId;
        }

        return $brackets->map(fn (Bracket $bracket, int $i) => [$bracket, $draws[$i]])->all();
    }

    /**
     * One stage back. After an elimination stage, clearing the bracket goes back to the elimination stage (its
     * results and who moves on are kept). Otherwise back to registration: matches, seeds, groups and champions are
     * thrown away; the brackets and draws are kept (not after an elimination stage, since nobody's moving on yet).
     */
    public static function reset(Tournament $tournament): void
    {
        DB::transaction(function () use ($tournament) {
            $tournament->brackets()->update(['winner_entry_id' => null, 'completed_at' => null]);

            if (self::resetsToGroups($tournament)) {
                $tournament->matches()->where('side', '!=', TournamentMatch::GROUP)->delete();
                $tournament->update([
                    'status' => Tournament::IN_PROGRESS,
                    'stage' => Tournament::STAGE_GROUPS,
                    'completed_at' => null,
                    'winner_entry_id' => null,
                ]);

                return;
            }

            $tournament->matches()->delete();
            $tournament->entries()->update(['seed' => null, 'group_number' => null, 'advanced' => false]);
            if ($tournament->hasGroupStage()) {
                $tournament->entries()->update(['bracket_id' => null, 'slot' => null]);
            }
            $tournament->update([
                'status' => Tournament::REGISTRATION,
                'stage' => null,
                'started_at' => null,
                'completed_at' => null,
                'winner_entry_id' => null,
            ]);
        });
    }

    /**
     * Save a result. Elimination matches need a winner; round-robin matches can end in a draw (`$winnerId` null).
     *
     * @throws RuntimeException with a message for the organizer when the result can't be saved
     */
    public static function record(TournamentMatch $match, ?int $winnerId, ?int $score1, ?int $score2): void
    {
        $tournament = $match->tournament;

        if ($match->entry1_id === null || $match->entry2_id === null) {
            throw new RuntimeException('Both sides of this match need to be decided first.');
        }
        if ($winnerId !== null && ! $match->hasEntry($winnerId)) {
            throw new RuntimeException('Pick one of the two sides as the winner.');
        }
        if ($winnerId === null && ! $tournament->isRoundRobin() && ! $match->isGroup()) {
            throw new RuntimeException('Knockout matches need a winner.');
        }
        if ($score1 !== null && $score2 !== null && $winnerId !== null && $score1 !== $score2) {
            $higher = $score1 > $score2 ? (int) $match->entry1_id : (int) $match->entry2_id;
            if ($higher !== $winnerId) {
                throw new RuntimeException('The winner should have the higher score.');
            }
        }
        if ($winnerId === null && $score1 !== null && $score2 !== null && $score1 !== $score2) {
            throw new RuntimeException('A draw needs equal scores.');
        }

        if ($match->isGroup()) {
            $match->update([
                'winner_entry_id' => $winnerId,
                'score1' => $score1,
                'score2' => $score2,
                'completed_at' => now(),
            ]);

            return;
        }

        if ($tournament->isDoubleElimination()) {
            DB::transaction(function () use ($match, $winnerId, $score1, $score2, $tournament) {
                if ($match->isCompleted() && (int) $match->winner_entry_id !== $winnerId) {
                    DoubleElimination::guard($match);
                }
                $match->update([
                    'winner_entry_id' => $winnerId,
                    'score1' => $score1,
                    'score2' => $score2,
                    'completed_at' => now(),
                ]);
                DoubleElimination::sync($match->bracket);
                self::refreshOutcome($tournament);
            });

            return;
        }

        DB::transaction(function () use ($match, $winnerId, $score1, $score2, $tournament) {
            $next = self::nextMatch($match);
            if ($next && $next->isCompleted() && (int) $match->winner_entry_id !== $winnerId) {
                throw new RuntimeException('The next match already has a result. Clear it first.');
            }

            $match->update([
                'winner_entry_id' => $winnerId,
                'score1' => $score1,
                'score2' => $score2,
                'completed_at' => now(),
            ]);

            if ($next) {
                $next->update([self::slotFor($match) => $winnerId]);
            }

            self::refreshOutcome($tournament);
        });
    }

    /** @throws RuntimeException when a later match already depends on this one */
    public static function clear(TournamentMatch $match): void
    {
        if ($match->isBye()) {
            throw new RuntimeException('Byes can’t be cleared.');
        }

        if ($match->isGroup()) {
            $match->update(['winner_entry_id' => null, 'score1' => null, 'score2' => null, 'completed_at' => null]);

            return;
        }

        if ($match->tournament->isDoubleElimination()) {
            DB::transaction(function () use ($match) {
                DoubleElimination::guard($match);
                $match->update(['winner_entry_id' => null, 'score1' => null, 'score2' => null, 'completed_at' => null]);
                DoubleElimination::sync($match->bracket);
                self::refreshOutcome($match->tournament);
            });

            return;
        }

        DB::transaction(function () use ($match) {
            $next = self::nextMatch($match);
            if ($next && $next->isCompleted()) {
                throw new RuntimeException('The next match already has a result. Clear it first.');
            }

            $match->update(['winner_entry_id' => null, 'score1' => null, 'score2' => null, 'completed_at' => null]);
            $next?->update([self::slotFor($match) => null]);

            self::refreshOutcome($match->tournament);
        });
    }

    /**
     * Round-robin table: points (3 a win, 1 a draw), then score difference, then scores for, then seed.
     *
     * @param  Collection<int, TournamentEntry>  $entries
     * @param  Collection<int, TournamentMatch>  $matches
     * @return array<int, array{entry_id: int, played: int, won: int, drawn: int, lost: int, score_for: int, score_against: int, points: int}>
     */
    public static function standings(Collection $entries, Collection $matches): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            $rows[$entry->id] = [
                'entry_id' => $entry->id,
                'played' => 0,
                'won' => 0,
                'drawn' => 0,
                'lost' => 0,
                'score_for' => 0,
                'score_against' => 0,
                'points' => 0,
                'seed' => $entry->seed ?? PHP_INT_MAX,
            ];
        }

        foreach ($matches as $match) {
            if (! $match->isCompleted() || $match->entry1_id === null || $match->entry2_id === null) {
                continue;
            }
            foreach ([[(int) $match->entry1_id, $match->score1, $match->score2], [(int) $match->entry2_id, $match->score2, $match->score1]] as [$id, $for, $against]) {
                if (! isset($rows[$id])) {
                    continue;
                }
                $rows[$id]['played']++;
                $rows[$id]['score_for'] += (int) $for;
                $rows[$id]['score_against'] += (int) $against;
                if ($match->winner_entry_id === null) {
                    $rows[$id]['drawn']++;
                    $rows[$id]['points'] += self::DRAW_POINTS;
                } elseif ((int) $match->winner_entry_id === $id) {
                    $rows[$id]['won']++;
                    $rows[$id]['points'] += self::WIN_POINTS;
                } else {
                    $rows[$id]['lost']++;
                }
            }
        }

        usort($rows, fn (array $a, array $b) => [$b['points'], $b['score_for'] - $b['score_against'], $b['score_for'], $a['seed']]
            <=> [$a['points'], $a['score_for'] - $a['score_against'], $a['score_for'], $b['seed']]);

        return array_map(function (array $row) {
            unset($row['seed']);

            return $row;
        }, $rows);
    }

    /** Standard seeding order so the top seeds meet last and byes go to the highest seeds, e.g. 8 → 1,8,4,5,2,7,3,6. */
    public static function seedOrder(int $size): array
    {
        $order = [1];
        while (count($order) < $size) {
            $sum = count($order) * 2 + 1;
            $next = [];
            foreach ($order as $seed) {
                $next[] = $seed;
                $next[] = $sum - $seed;
            }
            $order = $next;
        }

        return $order;
    }

    /** @param  array<int, int|null>  $slots  first-round entry ids, two per match */
    private static function createElimination(Bracket $bracket, array $slots): void
    {
        $size = count($slots);
        $rounds = (int) log($size, 2);
        $now = now();

        $rows = [];
        for ($round = 1; $round <= $rounds; $round++) {
            $matchesInRound = intdiv($size, 2 ** $round);
            for ($position = 0; $position < $matchesInRound; $position++) {
                $row = [
                    'tournament_id' => $bracket->tournament_id,
                    'bracket_id' => $bracket->id,
                    'round' => $round,
                    'position' => $position,
                    'entry1_id' => null,
                    'entry2_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if ($round === 1) {
                    $row['entry1_id'] = $slots[$position * 2];
                    $row['entry2_id'] = $slots[$position * 2 + 1];
                }
                $rows[] = $row;
            }
        }
        TournamentMatch::query()->insert($rows);

        $bracket->matches()->where('side', TournamentMatch::WINNERS)->where('round', 1)->get()->each(function (TournamentMatch $match) {
            $only = $match->entry1_id ?? $match->entry2_id;
            if ($only !== null && ($match->entry1_id === null || $match->entry2_id === null)) {
                $match->update(['winner_entry_id' => $only, 'completed_at' => now()]);
                self::nextMatch($match)?->update([self::slotFor($match) => $only]);
            }
        });
    }

    private static function createRoundRobin(Tournament $tournament, Collection $entries): void
    {
        $now = now();
        $positions = [];
        $rows = [];
        foreach (self::roundRobinPairs($entries->pluck('id')->all(), 1) as [$round, $entry1, $entry2]) {
            $positions[$round] = ($positions[$round] ?? -1) + 1;
            $rows[] = [
                'tournament_id' => $tournament->id,
                'round' => $round,
                'position' => $positions[$round],
                'entry1_id' => $entry1,
                'entry2_id' => $entry2,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        TournamentMatch::query()->insert($rows);
    }

    /**
     * Circle method: one entry stays put while the others rotate; an odd count gives someone a rest each round.
     * They're seated so round 1 pairs neighbours in the list (1st vs 2nd, 3rd vs 4th …; the last one rests when
     * the count is odd). With two legs everyone meets twice, the second time with sides swapped, in the rounds
     * after the first leg.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{0: int, 1: int, 2: int}> round, entry1 id, entry2 id
     */
    public static function roundRobinPairs(array $ids, int $legs): array
    {
        $ids = array_values($ids);
        if (count($ids) % 2 === 1) {
            $ids[] = null;
        }
        $n = count($ids);
        // The circle pairs seat k with seat n-1-k, so seat the 2k-th and (2k+1)-th there.
        $seated = [];
        for ($k = 0; $k < $n / 2; $k++) {
            $seated[$k] = $ids[2 * $k];
            $seated[$n - 1 - $k] = $ids[2 * $k + 1];
        }
        ksort($seated);
        $ids = array_values($seated);

        $pairs = [];
        for ($round = 1; $round < $n; $round++) {
            for ($i = 0; $i < $n / 2; $i++) {
                [$a, $b] = [$ids[$i], $ids[$n - 1 - $i]];
                if ($a !== null && $b !== null) {
                    $pairs[] = $round % 2 === 0 ? [$round, $b, $a] : [$round, $a, $b];
                }
            }
            $ids = array_merge([$ids[0], $ids[$n - 1]], array_slice($ids, 1, $n - 2));
        }
        if ($legs === 2) {
            foreach ($pairs as [$round, $entry1, $entry2]) {
                $pairs[] = [$round + $n - 1, $entry2, $entry1];
            }
        }

        return $pairs;
    }

    private static function nextMatch(TournamentMatch $match): ?TournamentMatch
    {
        if ($match->bracket_id === null) {
            return null;
        }

        return TournamentMatch::query()
            ->where('bracket_id', $match->bracket_id)
            ->where('side', TournamentMatch::WINNERS)
            ->where('round', $match->round + 1)
            ->where('position', intdiv($match->position, 2))
            ->first();
    }

    private static function slotFor(TournamentMatch $match): string
    {
        return $match->position % 2 === 0 ? 'entry1_id' : 'entry2_id';
    }

    /**
     * Crown each bracket's champion once its final is played (or the round-robin winner once every match is); undo
     * it when a result is cleared. The tournament is over when every bracket has a champion.
     */
    private static function refreshOutcome(Tournament $tournament): void
    {
        if ($tournament->isRoundRobin()) {
            $matches = $tournament->matches()->get();
            $winnerId = $matches->isNotEmpty() && $matches->every(fn (TournamentMatch $m) => $m->isCompleted())
                ? (self::standings($tournament->entries()->get(), $matches)[0]['entry_id'] ?? null)
                : null;
            $done = $winnerId !== null;
        } else {
            $brackets = $tournament->brackets()->with('matches')->get();
            foreach ($brackets as $bracket) {
                $champion = self::champion($tournament, $bracket->matches);
                $bracket->update([
                    'winner_entry_id' => $champion,
                    'completed_at' => $champion !== null ? ($bracket->completed_at ?? now()) : null,
                ]);
            }
            $done = $brackets->isNotEmpty() && $brackets->every(fn (Bracket $b) => $b->winner_entry_id !== null);
            // With several brackets there's a champion per bracket rather than one for the tournament.
            $winnerId = $done && $brackets->count() === 1 ? $brackets->first()->winner_entry_id : null;
        }

        $tournament->update([
            'status' => $done ? Tournament::COMPLETED : Tournament::IN_PROGRESS,
            'winner_entry_id' => $winnerId,
            'completed_at' => $done ? ($tournament->completed_at ?? now()) : null,
        ]);
    }

    /** @param  Collection<int, TournamentMatch>  $matches  one bracket's */
    private static function champion(Tournament $tournament, Collection $matches): ?int
    {
        if ($tournament->isDoubleElimination()) {
            return DoubleElimination::champion($matches);
        }
        if ($matches->isEmpty() || ! $matches->every(fn (TournamentMatch $m) => $m->isCompleted())) {
            return null;
        }
        $final = $matches->sortByDesc('round')->first();

        return $final->winner_entry_id !== null ? (int) $final->winner_entry_id : null;
    }
}
