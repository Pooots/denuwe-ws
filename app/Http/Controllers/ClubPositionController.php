<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubPosition;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Owner-defined positions (President, VP, Director, …) and who holds them. */
class ClubPositionController extends Controller
{
    public function store(Request $request, Club $club): JsonResponse
    {
        if ($denied = $this->denyUnlessOwner($club)) {
            return $denied;
        }

        if ($club->positions()->count() >= ClubPosition::MAX_PER_CLUB) {
            return response()->json(['message' => 'You can have up to '.ClubPosition::MAX_PER_CLUB.' positions.'], 422);
        }

        $data = $this->validateName($request, $club);
        $canOrganize = $request->validate(['can_organize' => ['sometimes', 'boolean']])['can_organize']
            ?? in_array(mb_strtolower($data['name']), ClubPosition::ORGANIZER_DEFAULTS, true);

        $club->positions()->create([
            'name' => $data['name'],
            'sort_order' => (int) $club->positions()->max('sort_order') + 1,
            'can_organize' => $canOrganize,
        ]);

        return $this->positionsResponse($club, $data['name'].' added.', 201);
    }

    /** Rename a position and/or change whether its holders can create activities and tournaments. */
    public function update(Request $request, Club $club, ClubPosition $position): JsonResponse
    {
        if ($denied = $this->denyUnlessOwner($club) ?? $this->denyForeign($club, $position)) {
            return $denied;
        }

        $changes = [];
        if ($request->has('name')) {
            $changes['name'] = $this->validateName($request, $club, $position)['name'];
        }
        if ($request->has('can_organize')) {
            $changes['can_organize'] = $request->validate(['can_organize' => ['required', 'boolean']])['can_organize'];
            if (! $changes['can_organize'] && $position->isPresident() && ! isset($changes['name'])) {
                return response()->json(['message' => 'The President can always create activities and tournaments.'], 422);
            }
        }
        if ($changes === []) {
            return response()->json(['message' => 'Nothing to update.'], 422);
        }

        $position->update($changes);

        $message = isset($changes['name']) ? 'Position renamed.' : ($position->can_organize
            ? $position->name.' can now create activities and tournaments.'
            : $position->name.' can no longer create activities and tournaments.');

        return $this->positionsResponse($club, $message);
    }

    public function destroy(Club $club, ClubPosition $position): JsonResponse
    {
        if ($denied = $this->denyUnlessOwner($club) ?? $this->denyForeign($club, $position)) {
            return $denied;
        }

        $position->delete();

        return $this->positionsResponse($club, $position->name.' removed.');
    }

    /** Save a new order: `ids` lists every position id, top first. */
    public function reorder(Request $request, Club $club): JsonResponse
    {
        if ($denied = $this->denyUnlessOwner($club)) {
            return $denied;
        }

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', Rule::exists('club_positions', 'id')->where('club_id', $club->id)],
        ]);

        DB::transaction(function () use ($data, $club) {
            foreach (array_values(array_unique($data['ids'])) as $index => $id) {
                ClubPosition::query()->where('club_id', $club->id)->whereKey($id)->update(['sort_order' => $index + 1]);
            }
        });

        return $this->positionsResponse($club, 'Order saved.');
    }

    /** Give a member a position, or clear it with `position_id: null`. */
    public function assign(Request $request, Club $club, User $user): JsonResponse
    {
        if ($denied = $this->denyUnlessOwner($club)) {
            return $denied;
        }

        $data = $request->validate([
            'position_id' => ['present', 'nullable', 'integer', Rule::exists('club_positions', 'id')->where('club_id', $club->id)],
        ], [
            'position_id.exists' => 'That position no longer exists.',
        ]);

        if (! $club->hasMember($user->id)) {
            return response()->json(['message' => $user->name.' isn’t a member.'], 422);
        }

        $club->members()->updateExistingPivot($user->id, ['position_id' => $data['position_id']]);
        $position = $data['position_id'] ? ClubPosition::query()->find($data['position_id']) : null;

        return response()->json([
            'message' => $position ? $user->name.' is now '.$position->name.'.' : $user->name.'’s position was cleared.',
            'member_id' => $user->id,
            'position' => $position ? ['id' => $position->id, 'name' => $position->name] : null,
        ]);
    }

    /** @return array{name: string} */
    private function validateName(Request $request, Club $club, ?ClubPosition $ignore = null): array
    {
        $request->merge(['name' => trim((string) $request->input('name', ''))]);

        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:60',
                Rule::unique('club_positions', 'name')->where('club_id', $club->id)->ignore($ignore?->id),
            ],
        ], [
            'name.required' => 'Give the position a name.',
            'name.unique' => 'That position already exists.',
        ]);
    }

    private function positionsResponse(Club $club, string $message, int $status = 200): JsonResponse
    {
        $holders = DB::table('club_members')
            ->where('club_id', $club->id)
            ->whereNotNull('position_id')
            ->selectRaw('position_id, COUNT(*) as total')
            ->groupBy('position_id')
            ->pluck('total', 'position_id');

        return response()->json([
            'message' => $message,
            'positions' => $club->positions()->get()->map(fn (ClubPosition $p) => $p->present((int) ($holders[$p->id] ?? 0)))->values(),
        ], $status);
    }

    private function denyUnlessOwner(Club $club): ?JsonResponse
    {
        if ((int) $club->owner_id === (int) auth('api')->id()) {
            return null;
        }

        return response()->json(['message' => 'Only the owner can manage positions.'], 403);
    }

    private function denyForeign(Club $club, ClubPosition $position): ?JsonResponse
    {
        return (int) $position->club_id === $club->id ? null : response()->json(['message' => 'Not found.'], 404);
    }
}
