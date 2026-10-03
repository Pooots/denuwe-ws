<?php

namespace App\Support;

use App\Models\Bracket;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentMatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Game setup: the organizer picks the playing days (date, first game's time and how many games) and how long a
 * game takes. Every game, from the elimination stage through the bracket, fills the days in playing order, and the
 * organizer can move a game to a set day. Before the start the games come from the slot maps ("Slot 1 vs Slot 2"),
 * keyed the same way as the matches the start creates, so a moved game stays on its day.
 */
final class TournamentSchedule
{
    public const DEFAULT_MINUTES = 30;

    public const MAX_DAYS = 60;

    public const MAX_GAMES_A_DAY = 200;

    private const SIDES = [TournamentMatch::GROUP => 0, TournamentMatch::WINNERS => 1, TournamentMatch::LOSERS => 2, TournamentMatch::FINAL => 3];

    /**
     * The saved setup, days in date order.
     *
     * @return array{minutes: int, days: array<int, array{date: string, start: string, games: int}>, moves: array<string, string>}
     */
    public static function settings(Tournament $tournament): array
    {
        $saved = $tournament->schedule ?? [];

        return [
            'minutes' => (int) ($saved['minutes'] ?? self::DEFAULT_MINUTES),
            'days' => collect($saved['days'] ?? [])
                ->map(fn (array $day) => ['date' => $day['date'], 'start' => $day['start'], 'games' => (int) $day['games']])
                ->sortBy('date')
                ->values()
                ->all(),
            'moves' => $saved['moves'] ?? [],
        ];
    }

    /**
     * @param  Collection<int, TournamentEntry>  $entries
     * @param  Collection<int, TournamentMatch>  $matches
     */
    public static function present(Tournament $tournament, Collection $entries, Collection $matches): array
    {
        $settings = self::settings($tournament);
        [$games, $bracketPending] = self::games($tournament, $entries, $matches);

        return [
            'minutes' => $settings['minutes'],
            'days' => $settings['days'],
            'games' => self::place($games, $settings),
            // An elimination stage without a bracket yet: its games show once the organizer creates one.
            'bracket_pending' => $bracketPending,
        ];
    }

    /** @return array<int, string> every game's key, in playing order */
    public static function keys(Tournament $tournament): array
    {
        [$games] = self::games($tournament, $tournament->entries()->get(), $tournament->matches()->get());

        return array_column($games, 'key');
    }

    /**
     * Every game in playing order: elimination-stage rounds, then the bracket round by round (upper and lower
     * rounds as soon as the players can be there), several brackets side by side. Byes aren't games.
     *
     * @param  Collection<int, TournamentEntry>  $entries
     * @param  Collection<int, TournamentMatch>  $matches
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    private static function games(Tournament $tournament, Collection $entries, Collection $matches): array
    {
        $games = [];
        $offset = 0;
        $bracketPending = false;
        $groupMatches = $matches->filter(fn (TournamentMatch $m) => $m->isGroup());

        if ($groupMatches->isNotEmpty()) {
            foreach ($groupMatches as $match) {
                $games[] = self::fromMatch($match, $match->round, null, 0);
            }
            $offset = (int) $groupMatches->max('round');
        } elseif ($tournament->isRegistering() && $tournament->hasGroupStage()) {
            $placed = $entries->whereNotNull('group_slot')->pluck('id', 'group_slot')->map(fn ($id) => (int) $id);
            $map = TournamentBracket::slotMap(
                TournamentBracket::mapSlots($tournament, $entries->count()),
                $tournament->group_count,
                $tournament->group_stage === 'double' ? 2 : 1,
            );
            // Numbered like `TournamentBracket::startGroups`: positions count up per round across the groups.
            $positions = [];
            foreach ($map as $group) {
                foreach ($group['matches'] as $pair) {
                    $round = $pair['round'];
                    $positions[$round] = ($positions[$round] ?? -1) + 1;
                    $games[] = self::game(TournamentMatch::GROUP, null, null, $group['number'], $round, $positions[$round], 0, $round)
                        + self::slots($pair['slot1'], $pair['slot2'], $placed->get($pair['slot1']), $placed->get($pair['slot2']));
                    $offset = max($offset, $round);
                }
            }
        }

        if ($tournament->isRoundRobin()) {
            if ($matches->isNotEmpty()) {
                foreach ($matches as $match) {
                    $games[] = self::fromMatch($match, $match->round, null, 0);
                }
            } elseif ($tournament->isRegistering()) {
                $count = TournamentBracket::mapSlots($tournament, $entries->count());
                if ($count >= 2 && $count <= Tournament::MAX_ROUND_ROBIN_ENTRIES) {
                    $positions = [];
                    foreach (TournamentBracket::roundRobinPairs(range(1, $count), 1) as [$round, $slot1, $slot2]) {
                        $positions[$round] = ($positions[$round] ?? -1) + 1;
                        $games[] = self::game(TournamentMatch::WINNERS, null, null, null, $round, $positions[$round], 0, $round)
                            + self::slots($slot1, $slot2, null, null);
                    }
                }
            }
        } else {
            $brackets = $tournament->brackets->values();
            $index = $brackets->pluck('id')->flip();
            $knockout = $matches->reject(fn (TournamentMatch $m) => $m->isGroup());

            if ($knockout->isNotEmpty()) {
                foreach ($knockout->groupBy('bracket_id') as $bracketId => $own) {
                    $size = 2 ** (int) $own->where('side', TournamentMatch::WINNERS)->max('round');
                    $waves = self::waves($tournament->isDoubleElimination(), $size);
                    foreach ($own as $match) {
                        if ($match->isCompleted() && ($match->entry1_id === null || $match->entry2_id === null)) {
                            continue;
                        }
                        $wave = $waves["{$match->side}-{$match->round}"] ?? max($waves) + 1;
                        $games[] = self::fromMatch($match, $offset + $wave, (int) ($index[$bracketId] ?? 0), (int) log($size, 2));
                    }
                }
            } elseif ($brackets->isEmpty() && $tournament->hasGroupStage()) {
                $bracketPending = true;
            } elseif ($brackets->isEmpty()) {
                // Without brackets the start makes one "Main bracket" with a slot for everyone.
                $size = TournamentBracket::mapSlots($tournament, $entries->count());
                if ($size >= 2) {
                    array_push($games, ...self::previewBracket($tournament, null, $size, [], 0, $offset));
                }
            } else {
                foreach ($brackets as $i => $bracket) {
                    $draw = TournamentBracket::draw($bracket, $entries);
                    array_push($games, ...self::previewBracket($tournament, $bracket, $bracket->size, $draw, $i, $offset));
                }
            }
        }

        usort($games, fn (array $a, array $b) => self::sortKey($a) <=> self::sortKey($b));

        return [$games, $bracketPending];
    }

    /**
     * A bracket's games before it's played, from its slots: round 1 is the first-round pairs that aren't byes,
     * then every later match both of whose sides can be filled.
     *
     * @param  array<int, int|null>  $draw  entry id (or null) per slot
     * @return array<int, array<string, mixed>>
     */
    private static function previewBracket(Tournament $tournament, ?Bracket $bracket, int $size, array $draw, int $index, int $offset): array
    {
        $layout = TournamentBracket::layout($size, $bracket ? TournamentBracket::openingFor($bracket) : null);
        $positions = count($layout);
        $rounds = (int) log($positions, 2);
        $double = $tournament->isDoubleElimination();
        $waves = self::waves($double, $positions);
        $id = $bracket?->id;
        $games = [];
        /** @var array<string, int> $sides how many sides of each match get a player: 2 is a game, 1 a bye */
        $sides = [];

        for ($position = 0; $position < $positions / 2; $position++) {
            [$a, $b] = [$layout[$position * 2], $layout[$position * 2 + 1]];
            $sides["winners-1-{$position}"] = ($a !== null ? 1 : 0) + ($b !== null ? 1 : 0);
            if ($a !== null && $b !== null) {
                $games[] = self::game(TournamentMatch::WINNERS, $id, $index, null, 1, $position, $rounds, $offset + $waves['winners-1'])
                    + self::slots($a + 1, $b + 1, $draw[$a] ?? null, $draw[$b] ?? null);
            }
        }

        $later = [];
        for ($round = 2; $round <= $rounds; $round++) {
            for ($position = 0; $position < $positions / 2 ** $round; $position++) {
                $later[] = [TournamentMatch::WINNERS, $round, $position];
            }
        }
        if ($double) {
            for ($round = 1; $round <= DoubleElimination::loserRounds($positions); $round++) {
                for ($position = 0; $position < DoubleElimination::loserMatches($positions, $round); $position++) {
                    $later[] = [TournamentMatch::LOSERS, $round, $position];
                }
            }
            $later[] = [TournamentMatch::FINAL, 1, 0];
        }

        foreach ($later as [$side, $round, $position]) {
            $filled = 0;
            foreach (DoubleElimination::sources($side, $round, $position, $positions) as $source) {
                $from = $source ? ($sides["{$source[1]}-{$source[2]}-{$source[3]}"] ?? 0) : 0;
                // A match's winner comes out of a game or a bye; only a game has a loser.
                $filled += ($source !== null && ($source[0] === 'winner' ? $from > 0 : $from === 2)) ? 1 : 0;
            }
            $sides["{$side}-{$round}-{$position}"] = $filled;
            if ($filled === 2) {
                $games[] = self::game($side, $id, $index, null, $round, $position, $rounds, $offset + $waves["{$side}-{$round}"])
                    + self::slots(null, null, null, null);
            }
        }

        return $games;
    }

    /**
     * When each round of a bracket can be played, counting from 1: the upper bracket a round at a time, each lower
     * round right after the rounds feeding it, and the grand final (and its reset) last.
     *
     * @return array<string, int> "side-round" => wave
     */
    private static function waves(bool $double, int $size): array
    {
        $rounds = (int) log($size, 2);
        $waves = [];
        for ($round = 1; $round <= $rounds; $round++) {
            $waves[TournamentMatch::WINNERS."-{$round}"] = $round;
        }
        if (! $double) {
            return $waves;
        }

        $after = function (string $side, int $round) use (&$waves, $size): int {
            return 1 + max(array_map(
                fn (?array $source) => $source ? $waves["{$source[1]}-{$source[2]}"] : 0,
                DoubleElimination::sources($side, $round, 0, $size),
            ));
        };
        for ($round = 1; $round <= DoubleElimination::loserRounds($size); $round++) {
            $waves[TournamentMatch::LOSERS."-{$round}"] = $after(TournamentMatch::LOSERS, $round);
        }
        $waves[TournamentMatch::FINAL.'-1'] = $after(TournamentMatch::FINAL, 1);
        $waves[TournamentMatch::FINAL.'-2'] = $waves[TournamentMatch::FINAL.'-1'] + 1;

        return $waves;
    }

    /**
     * Days fill in order: games moved to a day go there, the rest take the next free spot. Each day's games run
     * back to back from its start time, in playing order.
     *
     * @param  array<int, array<string, mixed>>  $games
     * @param  array{minutes: int, days: array<int, array{date: string, start: string, games: int}>, moves: array<string, string>}  $settings
     * @return array<int, array<string, mixed>>
     */
    private static function place(array $games, array $settings): array
    {
        $days = $settings['days'];
        $dayOf = array_flip(array_column($days, 'date'));
        $moves = array_filter($settings['moves'], fn ($date) => isset($dayOf[$date]));
        $byDay = array_fill(0, count($days), []);

        foreach ($games as $i => $game) {
            if (isset($moves[$game['key']])) {
                $byDay[$dayOf[$moves[$game['key']]]][] = $i;
            }
        }
        $day = 0;
        foreach ($games as $i => $game) {
            if (isset($moves[$game['key']])) {
                continue;
            }
            while ($day < count($days) && count($byDay[$day]) >= $days[$day]['games']) {
                $day++;
            }
            if ($day === count($days)) {
                break;
            }
            $byDay[$day][] = $i;
        }

        $when = [];
        foreach ($byDay as $d => $list) {
            sort($list);
            $start = CarbonImmutable::createFromFormat('Y-m-d H:i', $days[$d]['date'].' '.$days[$d]['start']);
            foreach ($list as $n => $i) {
                $when[$i] = [$days[$d]['date'], $start->addMinutes($n * $settings['minutes'])->format('H:i')];
            }
        }

        return array_map(function (int $i) use ($games, $when, $moves) {
            $game = $games[$i];
            unset($game['wave'], $game['bracket_index']);

            return $game + [
                'date' => $when[$i][0] ?? null,
                'time' => $when[$i][1] ?? null,
                'moved' => isset($moves[$game['key']]),
            ];
        }, array_keys($games));
    }

    private static function fromMatch(TournamentMatch $match, int $wave, ?int $bracketIndex, int $rounds): array
    {
        return self::game($match->side, $match->bracket_id, $bracketIndex, $match->group_number, $match->round, $match->position, $rounds, $wave) + [
            'match_id' => $match->id,
            'slot1' => null,
            'slot2' => null,
            'entry1_id' => $match->entry1_id,
            'entry2_id' => $match->entry2_id,
            'score1' => $match->score1,
            'score2' => $match->score2,
            'winner_id' => $match->winner_entry_id,
            'completed' => $match->isCompleted(),
        ];
    }

    /** Before the start: the two slots, and whoever the organizer put there. */
    private static function slots(?int $slot1, ?int $slot2, ?int $entry1, ?int $entry2): array
    {
        return [
            'match_id' => null,
            'slot1' => $slot1,
            'slot2' => $slot2,
            'entry1_id' => $entry1,
            'entry2_id' => $entry2,
            'score1' => null,
            'score2' => null,
            'winner_id' => null,
            'completed' => false,
        ];
    }

    /**
     * `key` names the game the same before and after the start: the group, or the bracket's place in the list,
     * plus side, round and position.
     */
    private static function game(string $side, ?int $bracketId, ?int $bracketIndex, ?int $group, int $round, int $position, int $rounds, int $wave): array
    {
        return [
            'key' => match (true) {
                $side === TournamentMatch::GROUP => "group-g{$group}-r{$round}-{$position}",
                $bracketIndex === null => "rr-r{$round}-{$position}",
                default => "{$side}-b{$bracketIndex}-r{$round}-{$position}",
            },
            'side' => $side,
            'bracket_id' => $bracketId,
            'group_number' => $group,
            'round' => $round,
            'position' => $position,
            // The bracket's upper rounds, for round titles (Final, Semifinals …).
            'rounds' => $rounds,
            'wave' => $wave,
            'bracket_index' => $bracketIndex,
        ];
    }

    private static function sortKey(array $game): array
    {
        return [$game['wave'], $game['bracket_index'] ?? -1, self::SIDES[$game['side']] ?? 9, $game['group_number'] ?? 0, $game['round'], $game['position']];
    }
}
