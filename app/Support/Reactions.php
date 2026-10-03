<?php

namespace App\Support;

use App\Models\PostLike;
use Illuminate\Support\Collection;

class Reactions
{
    /**
     * `{reaction, total}` rows as `[{type, count}]`, most used first.
     *
     * @return array<int, array{type: string, count: int}>
     */
    public static function sorted(Collection $rows): array
    {
        $order = array_flip(PostLike::REACTIONS);

        return $rows
            ->sort(fn ($a, $b) => [$b->total, $order[$a->reaction] ?? 99] <=> [$a->total, $order[$b->reaction] ?? 99])
            ->map(fn ($row) => ['type' => $row->reaction, 'count' => (int) $row->total])
            ->values()
            ->all();
    }
}
