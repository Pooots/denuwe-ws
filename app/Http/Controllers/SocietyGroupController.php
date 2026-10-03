<?php

namespace App\Http\Controllers;

use App\Models\Friendship;
use App\Models\SocietyGroup;
use App\Models\SocietyGroupMember;
use App\Models\SocietyGroupMessage;
use App\Models\User;
use App\Support\UserPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Group Society: group chats between friends. Only members can see a group or its messages. */
class SocietyGroupController extends Controller
{
    /** Groups you created or were added to, most recent activity first. */
    public function index(): JsonResponse
    {
        $viewerId = $this->viewerId();
        $groups = SocietyGroup::query()->withMember($viewerId)->with('members.user')->get();
        $data = $this->presentMany($groups, $viewerId);

        return response()->json([
            'data' => $data,
            'unread_count' => collect($data)->sum('unread_count'),
        ]);
    }

    public function show(SocietyGroup $group): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($group)) {
            return $denied;
        }

        return response()->json(['group' => $this->presentOne($group)]);
    }

    public function store(Request $request): JsonResponse
    {
        $viewerId = $this->viewerId();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'member_ids' => ['required', 'array', 'min:1', 'max:'.(SocietyGroup::MAX_MEMBERS - 1)],
            'member_ids.*' => ['integer'],
        ], [
            'name.required' => 'Give your group a name.',
            'name.max' => 'Group names can be up to 80 characters.',
            'member_ids.required' => 'Add at least one friend.',
            'member_ids.min' => 'Add at least one friend.',
            'member_ids.max' => 'A group can have up to '.SocietyGroup::MAX_MEMBERS.' people.',
        ]);

        $name = trim($data['name']);
        if ($name === '') {
            return $this->invalid('name', 'Give your group a name.');
        }

        $ids = $this->cleanIds($data['member_ids'], $viewerId);
        if ($ids === []) {
            return $this->invalid('member_ids', 'Add at least one friend.');
        }
        if (array_diff($ids, Friendship::friendIdsOf($viewerId)) !== []) {
            return $this->invalid('member_ids', 'You can only add friends from your society.');
        }

        $group = DB::transaction(function () use ($name, $ids, $viewerId) {
            $group = SocietyGroup::query()->create(['name' => $name, 'owner_id' => $viewerId]);
            $group->members()->create(['user_id' => $viewerId, 'added_by' => $viewerId]);
            foreach ($ids as $id) {
                $group->members()->create(['user_id' => $id, 'added_by' => $viewerId]);
            }
            $message = $group->announce($viewerId, 'created the group');
            $group->members()->where('user_id', $viewerId)->update(['last_read_id' => $message->id]);

            return $group;
        });

        return response()->json(['message' => 'Group created.', 'group' => $this->presentOne($group)], 201);
    }

    /** Rename the group; any member can. */
    public function update(Request $request, SocietyGroup $group): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($group)) {
            return $denied;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ], [
            'name.required' => 'Give your group a name.',
            'name.max' => 'Group names can be up to 80 characters.',
        ]);
        $name = trim($data['name']);
        if ($name === '') {
            return $this->invalid('name', 'Give your group a name.');
        }

        if ($name !== $group->name) {
            $group->update(['name' => $name]);
            $group->announce($this->viewerId(), 'named the group “'.$name.'”');
        }

        return response()->json(['message' => 'Group renamed.', 'group' => $this->presentOne($group)]);
    }

    /** Delete the group and its messages; only its owner can. */
    public function destroy(SocietyGroup $group): JsonResponse
    {
        if ($denied = $this->denyUnlessMember($group)) {
            return $denied;
        }
        if ((int) $group->owner_id !== $this->viewerId()) {
            return response()->json(['message' => 'Only the group’s owner can delete it.'], 403);
        }

        $group->delete();

        return response()->json(['message' => 'Group deleted.', 'group_id' => $group->id]);
    }

    /** Any member can add their own friends; they join right away. */
    public function addMembers(Request $request, SocietyGroup $group): JsonResponse
    {
        $viewerId = $this->viewerId();
        if ($denied = $this->denyUnlessMember($group)) {
            return $denied;
        }

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
        ], [
            'user_ids.required' => 'Pick at least one friend.',
            'user_ids.min' => 'Pick at least one friend.',
        ]);

        $existing = $group->members()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_diff($this->cleanIds($data['user_ids'], $viewerId), $existing));
        if ($ids === []) {
            return $this->invalid('user_ids', 'They’re already in the group.');
        }
        if (array_diff($ids, Friendship::friendIdsOf($viewerId)) !== []) {
            return $this->invalid('user_ids', 'You can only add friends from your society.');
        }
        if (count($existing) + count($ids) > SocietyGroup::MAX_MEMBERS) {
            return $this->invalid('user_ids', 'A group can have up to '.SocietyGroup::MAX_MEMBERS.' people.');
        }

        DB::transaction(function () use ($group, $ids, $viewerId) {
            foreach ($ids as $id) {
                $group->members()->create(['user_id' => $id, 'added_by' => $viewerId]);
            }
            $names = User::query()->whereKey($ids)->orderBy('name')->pluck('name')->all();
            $group->announce($viewerId, 'added '.$this->nameList($names));
        });

        return response()->json([
            'message' => count($ids) === 1 ? 'Added to the group.' : count($ids).' people added to the group.',
            'group' => $this->presentOne($group),
        ]);
    }

    /** Leave the group yourself, or (as its owner) remove someone. */
    public function removeMember(SocietyGroup $group, User $user): JsonResponse
    {
        $viewerId = $this->viewerId();
        if ($denied = $this->denyUnlessMember($group)) {
            return $denied;
        }

        if ($user->id === $viewerId) {
            return $this->leave($group, $viewerId);
        }
        if ((int) $group->owner_id !== $viewerId) {
            return response()->json(['message' => 'Only the group’s owner can remove people.'], 403);
        }

        $membership = $group->membership($user->id);
        if (! $membership) {
            return response()->json(['message' => 'They’re not in this group.'], 404);
        }

        $membership->delete();
        $group->announce($viewerId, 'removed '.$user->name);

        return response()->json([
            'message' => $user->name.' was removed from the group.',
            'group' => $this->presentOne($group),
        ]);
    }

    /** Messages, oldest first (the latest 100). Pass `after` to fetch only newer ones. Opening the chat marks it read. */
    public function messages(Request $request, SocietyGroup $group): JsonResponse
    {
        $viewerId = $this->viewerId();
        $membership = $group->membership($viewerId);
        if (! $membership) {
            return $this->notFound();
        }

        $after = (int) $request->query('after', 0);
        $messages = $group->messages()
            ->with('user')
            ->when($after > 0, fn (Builder $q) => $q->where('id', '>', $after))
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->reverse();

        $latestId = (int) $group->messages()->max('id');
        if ($latestId > $membership->last_read_id) {
            $membership->update(['last_read_id' => $latestId]);
        }

        return response()->json([
            'data' => $messages->map(fn (SocietyGroupMessage $m) => $m->present($viewerId))->values(),
        ]);
    }

    public function sendMessage(Request $request, SocietyGroup $group): JsonResponse
    {
        $viewerId = $this->viewerId();
        $membership = $group->membership($viewerId);
        if (! $membership) {
            return $this->notFound();
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ], [
            'body.required' => 'Write a message first.',
        ]);
        $body = trim($data['body']);
        if ($body === '') {
            return $this->invalid('body', 'Write a message first.');
        }

        $message = $group->messages()->create([
            'user_id' => $viewerId,
            'kind' => SocietyGroupMessage::TEXT,
            'body' => $body,
        ]);
        $membership->update(['last_read_id' => $message->id]);
        $group->touch();
        $message->load('user');

        return response()->json(['message' => $message->present($viewerId)], 201);
    }

    private function leave(SocietyGroup $group, int $viewerId): JsonResponse
    {
        DB::transaction(function () use ($group, $viewerId) {
            $group->members()->where('user_id', $viewerId)->delete();
            $next = $group->members()->orderBy('id')->first();
            if (! $next) {
                $group->delete();

                return;
            }
            if ((int) $group->owner_id === $viewerId) {
                $group->update(['owner_id' => $next->user_id]);
            }
            $group->announce($viewerId, 'left the group');
        });

        return response()->json(['message' => 'You left “'.$group->name.'”.', 'group_id' => $group->id]);
    }

    private function presentOne(SocietyGroup $group): array
    {
        $group->load('members.user');

        return $this->presentMany(new Collection([$group]), $this->viewerId())[0];
    }

    /**
     * Latest message and unread count per group in two queries.
     *
     * @param  Collection<int, SocietyGroup>  $groups
     */
    private function presentMany(Collection $groups, int $viewerId): array
    {
        $ids = $groups->modelKeys();
        if ($ids === []) {
            return [];
        }

        $latestIds = SocietyGroupMessage::query()
            ->whereIn('society_group_id', $ids)
            ->selectRaw('MAX(id) as id')
            ->groupBy('society_group_id')
            ->pluck('id');
        $latest = SocietyGroupMessage::query()->with('user')->whereKey($latestIds)->get()->keyBy('society_group_id');

        $unread = SocietyGroupMessage::query()
            ->join('society_group_members as me', fn ($join) => $join
                ->on('me.society_group_id', '=', 'society_group_messages.society_group_id')
                ->where('me.user_id', $viewerId))
            ->whereIn('society_group_messages.society_group_id', $ids)
            ->whereColumn('society_group_messages.id', '>', 'me.last_read_id')
            ->where(fn (Builder $q) => $q
                ->whereNull('society_group_messages.user_id')
                ->orWhere('society_group_messages.user_id', '!=', $viewerId))
            ->selectRaw('society_group_messages.society_group_id as group_id, COUNT(*) as total')
            ->groupBy('society_group_messages.society_group_id')
            ->pluck('total', 'group_id');

        return $groups
            ->map(function (SocietyGroup $group) use ($viewerId, $latest, $unread) {
                $ownerId = (int) $group->owner_id;
                $members = $group->members
                    ->filter(fn (SocietyGroupMember $m) => $m->user !== null)
                    ->sortBy([
                        fn ($a, $b) => ((int) $b->user_id === $ownerId) <=> ((int) $a->user_id === $ownerId),
                        fn ($a, $b) => strnatcasecmp($a->user->name, $b->user->name),
                    ])
                    ->map(fn (SocietyGroupMember $m) => UserPresenter::author($m->user) + [
                        'is_owner' => (int) $m->user_id === $ownerId,
                    ])
                    ->values();
                $last = $latest->get($group->id);

                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'is_owner' => $ownerId === $viewerId,
                    'member_count' => $members->count(),
                    'members' => $members,
                    'last_message' => $last?->present($viewerId),
                    'unread_count' => (int) ($unread[$group->id] ?? 0),
                    'created_at' => $group->created_at?->toIso8601String(),
                ];
            })
            ->sortByDesc(fn (array $g) => $g['last_message']['id'] ?? 0)
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    private function cleanIds(array $ids, int $viewerId): array
    {
        return array_values(array_diff(array_unique(array_map('intval', $ids)), [$viewerId, 0]));
    }

    /** @param  array<int, string>  $names  "Ana", "Ana and Ben", "Ana, Ben and 2 others" */
    private function nameList(array $names): string
    {
        $count = count($names);

        return match (true) {
            $count === 1 => $names[0],
            $count === 2 => $names[0].' and '.$names[1],
            $count === 3 => $names[0].', '.$names[1].' and '.$names[2],
            default => $names[0].', '.$names[1].' and '.($count - 2).' others',
        };
    }

    private function denyUnlessMember(SocietyGroup $group): ?JsonResponse
    {
        return $group->membership($this->viewerId()) ? null : $this->notFound();
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Group not found.'], 404);
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }

    private function viewerId(): int
    {
        return (int) auth('api')->id();
    }
}
