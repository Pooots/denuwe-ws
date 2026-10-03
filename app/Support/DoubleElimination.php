<?php

namespace App\Support;

use App\Models\Bracket;
use App\Models\TournamentMatch;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Lose twice and you're out. The winners bracket is a normal knockout; whoever loses there drops into the losers
 * bracket, and whoever loses there is out. The two bracket winners meet in the grand final; if the one from the
 * losers bracket wins it, they've each lost once and a reset match decides it.
 *
 * Losers bracket rounds, for a winners bracket of `size` first-round positions (R rounds):
 *  - round 1: the winners-round-1 losers play each other;
 *  - even rounds 2m: last round's winners face the losers of winners round m + 1 (in reverse order, so people
 *    who just met don't meet again straight away);
 *  - odd rounds 2m + 1: last round's winners play each other.
 * That's 2(R − 1) rounds; the last one's winner goes to the grand final.
 */
final class DoubleElimination
{
    private const DEAD = 'dead';

    private const PENDING = 'pending';

    public static function loserRounds(int $size): int
    {
        return 2 * ((int) log($size, 2) - 1);
    }

    public static function loserMatches(int $size, int $round): int
    {
        return intdiv($size, 2 ** (intdiv($round + 1, 2) + 1));
    }

    /** Add the empty losers bracket and grand final once the winners bracket exists. */
    public static function create(Bracket $bracket, int $size): void
    {
        $now = now();
        $row = fn (string $side, int $round, int $position) => [
            'tournament_id' => $bracket->tournament_id,
            'bracket_id' => $bracket->id,
            'side' => $side,
            'round' => $round,
            'position' => $position,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $rows = [];
        for ($round = 1; $round <= self::loserRounds($size); $round++) {
            for ($position = 0; $position < self::loserMatches($size, $round); $position++) {
                $rows[] = $row(TournamentMatch::LOSERS, $round, $position);
            }
        }
        $rows[] = $row(TournamentMatch::FINAL, 1, 0);
        TournamentMatch::query()->insert($rows);
    }

    /**
     * Where a match's two sides come from: [winner|loser, side, round, position], or null for the draw.
     *
     * @return array{0: array{0: string, 1: string, 2: int, 3: int}|null, 1: array{0: string, 1: string, 2: int, 3: int}|null}
     */
    public static function sources(string $side, int $round, int $position, int $size): array
    {
        $winnerRounds = (int) log($size, 2);
        $loserRounds = self::loserRounds($size);

        if ($side === TournamentMatch::WINNERS) {
            return $round === 1 ? [null, null] : [
                ['winner', TournamentMatch::WINNERS, $round - 1, $position * 2],
                ['winner', TournamentMatch::WINNERS, $round - 1, $position * 2 + 1],
            ];
        }
        if ($side === TournamentMatch::FINAL) {
            return [
                ['winner', TournamentMatch::WINNERS, $winnerRounds, 0],
                $loserRounds > 0
                    ? ['winner', TournamentMatch::LOSERS, $loserRounds, 0]
                    : ['loser', TournamentMatch::WINNERS, $winnerRounds, 0],
            ];
        }
        if ($round === 1) {
            return [
                ['loser', TournamentMatch::WINNERS, 1, $position * 2],
                ['loser', TournamentMatch::WINNERS, 1, $position * 2 + 1],
            ];
        }
        if ($round % 2 === 0) {
            return [
                ['winner', TournamentMatch::LOSERS, $round - 1, $position],
                ['loser', TournamentMatch::WINNERS, intdiv($round, 2) + 1, self::loserMatches($size, $round) - 1 - $position],
            ];
        }

        return [
            ['winner', TournamentMatch::LOSERS, $round - 1, $position * 2],
            ['winner', TournamentMatch::LOSERS, $round - 1, $position * 2 + 1],
        ];
    }

    /**
     * Fill every match from the ones feeding it. Results the organizer entered stay; a side that can never be
     * filled (the loser of a bye) gives the other side a bye, and a match with nobody coming is passed over.
     */
    public static function sync(Bracket $bracket): void
    {
        $matches = self::keyed($bracket->matches()->get());
        $size = self::size($matches);

        $order = $matches->filter(fn (TournamentMatch $m) => $m->side !== TournamentMatch::WINNERS || $m->round > 1)
            ->reject(fn (TournamentMatch $m) => $m->side === TournamentMatch::FINAL && $m->round > 1)
            ->sortBy(fn (TournamentMatch $m) => [array_search($m->side, [TournamentMatch::WINNERS, TournamentMatch::LOSERS, TournamentMatch::FINAL], true), $m->round, $m->position]);

        foreach ($order as $match) {
            if (self::played($match)) {
                continue;
            }
            [$a, $b] = array_map(fn (?array $source) => self::outcome($matches, $source), self::sources($match->side, $match->round, $match->position, $size));
            $winner = match (true) {
                is_int($a) && $b === self::DEAD => $a,
                $a === self::DEAD && is_int($b) => $b,
                default => null,
            };
            $settled = $winner !== null || ($a === self::DEAD && $b === self::DEAD);
            $match->fill([
                'entry1_id' => is_int($a) ? $a : null,
                'entry2_id' => is_int($b) ? $b : null,
                'winner_entry_id' => $winner,
                'score1' => null,
                'score2' => null,
                'completed_at' => $settled ? ($match->completed_at ?? now()) : null,
            ]);
            if ($match->isDirty()) {
                $match->save();
            }
        }

        $first = $matches->get(self::key(TournamentMatch::FINAL, 1, 0));
        $reset = $matches->get(self::key(TournamentMatch::FINAL, 2, 0));
        $needsReset = $first !== null && self::played($first) && (int) $first->winner_entry_id === (int) $first->entry2_id;
        if ($needsReset && $reset === null) {
            TournamentMatch::query()->create([
                'tournament_id' => $bracket->tournament_id,
                'bracket_id' => $bracket->id,
                'side' => TournamentMatch::FINAL,
                'round' => 2,
                'position' => 0,
                'entry1_id' => $first->entry1_id,
                'entry2_id' => $first->entry2_id,
            ]);
        } elseif (! $needsReset && $reset !== null) {
            $reset->delete();
        }
    }

    /**
     * Stop a change that would pull the rug from under a match that's already been played.
     *
     * @throws RuntimeException
     */
    public static function guard(TournamentMatch $match): void
    {
        $matches = self::keyed($match->bracket->matches()->get());
        $size = self::size($matches);
        $key = self::key($match->side, $match->round, $match->position);

        foreach ($matches as $other) {
            $feeds = $match->side === TournamentMatch::FINAL && $match->round === 1
                ? $other->side === TournamentMatch::FINAL && $other->round === 2
                : collect(self::sources($other->side, $other->round, $other->position, $size))
                    ->filter()
                    ->contains(fn (array $source) => self::key($source[1], $source[2], $source[3]) === $key);
            if ($feeds && self::played($other)) {
                throw new RuntimeException('A later match already has a result. Clear it first.');
            }
        }
    }

    /** The champion: whoever wins the grand final, or its reset. */
    public static function champion(Collection $matches): ?int
    {
        $matches = self::keyed($matches);
        $reset = $matches->get(self::key(TournamentMatch::FINAL, 2, 0));
        if ($reset !== null) {
            return $reset->isCompleted() ? (int) $reset->winner_entry_id : null;
        }
        $first = $matches->get(self::key(TournamentMatch::FINAL, 1, 0));
        if ($first === null || ! $first->isCompleted() || $first->winner_entry_id === null) {
            return null;
        }

        return (int) $first->winner_entry_id === (int) $first->entry2_id && $first->entry1_id !== null
            ? null
            : (int) $first->winner_entry_id;
    }

    /** Played by both sides with a result (not a bye or a match nobody reached). */
    private static function played(TournamentMatch $match): bool
    {
        return $match->isCompleted() && $match->entry1_id !== null && $match->entry2_id !== null;
    }

    /** @param  array{0: string, 1: string, 2: int, 3: int}|null  $source */
    private static function outcome(Collection $matches, ?array $source): int|string
    {
        $match = $source ? $matches->get(self::key($source[1], $source[2], $source[3])) : null;
        if ($match === null) {
            return self::DEAD;
        }
        if (! $match->isCompleted()) {
            return self::PENDING;
        }
        if ($source[0] === 'winner') {
            return $match->winner_entry_id === null ? self::DEAD : (int) $match->winner_entry_id;
        }
        if (! self::played($match)) {
            return self::DEAD;
        }

        return (int) $match->winner_entry_id === (int) $match->entry1_id ? (int) $match->entry2_id : (int) $match->entry1_id;
    }

    private static function keyed(Collection $matches): Collection
    {
        return $matches->keyBy(fn (TournamentMatch $m) => self::key($m->side, $m->round, $m->position));
    }

    private static function key(string $side, int $round, int $position): string
    {
        return "{$side}-{$round}-{$position}";
    }

    private static function size(Collection $matches): int
    {
        return 2 ** (int) $matches->where('side', TournamentMatch::WINNERS)->max('round');
    }
}
