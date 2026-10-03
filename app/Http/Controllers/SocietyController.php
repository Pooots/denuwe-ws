<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\User;
use App\Support\Messenger;
use App\Support\UserPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "My Society": friend requests between denuwe accounts. */
class SocietyController extends Controller
{
    /** Friends, invitations received and requests sent. */
    public function index(): JsonResponse
    {
        $viewerId = $this->viewerId();

        $friendships = Friendship::query()
            ->involving($viewerId)
            ->with(['requester', 'addressee'])
            ->latest('updated_at')
            ->get();

        $friendIds = $this->friendIds($viewerId);
        $people = $friendships->map(function (Friendship $f) use ($viewerId) {
            $other = (int) $f->requester_id === $viewerId ? $f->addressee : $f->requester;

            return ['friendship' => $f, 'user' => $other];
        });
        $mutuals = $this->mutualCounts($people->pluck('user.id')->all(), $friendIds);

        $chats = $this->chatSummaries($viewerId);

        $present = fn (string $relationship) => $people
            ->filter(fn (array $p) => $p['friendship']->relationshipFor($viewerId) === $relationship)
            ->map(fn (array $p) => $this->present($p['user'], $p['friendship'], $viewerId, $mutuals, $chats))
            ->values();

        // Most recent conversation first, then friends you haven't messaged yet by name.
        $friends = $present('friends')
            ->sortBy([
                fn (array $a, array $b) => ($b['last_message']['id'] ?? 0) <=> ($a['last_message']['id'] ?? 0),
                fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']),
            ])
            ->values();

        return response()->json([
            'friends' => $friends,
            'incoming' => $present('incoming'),
            'outgoing' => $present('outgoing'),
            'unread_count' => $friends->sum('unread_count'),
        ]);
    }

    /**
     * Conversation with a friend, oldest first. Pass `after` to fetch only newer messages.
     * Kept for older clients; the app now uses /conversations, which this reads from too.
     */
    public function messages(Request $request, User $user): JsonResponse
    {
        $viewerId = $this->viewerId();

        if (! $this->areFriends($viewerId, $user->id)) {
            return response()->json(['message' => 'You can only message people in your society.'], 403);
        }

        $conversation = Conversation::query()->where('direct_key', Conversation::directKey($viewerId, $user->id))->first();
        if (! $conversation) {
            return response()->json(['data' => [], 'last_read_id' => null]);
        }

        $after = (int) $request->query('after', 0);

        $messages = $conversation->messages()
            ->when($after > 0, fn (Builder $q) => $q->where('id', '>', $after))
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->reverse();

        Messenger::markRead($conversation, $viewerId);

        // Read receipts on your own messages can change without new messages arriving.
        $lastMine = $conversation->messages()->where('sender_id', $viewerId)->latest('id')->first();

        return response()->json([
            'data' => $messages->map(fn (Message $m) => $this->presentMessage($m, $viewerId))->values(),
            'last_read_id' => $lastMine?->is_read ? $lastMine->id : null,
        ]);
    }

    /** Kept for older clients; saves to the conversation and broadcasts like POST /conversations/{id}/messages. */
    public function sendMessage(Request $request, User $user): JsonResponse
    {
        $viewer = $this->viewer();

        if (! $this->areFriends($viewer->id, $user->id)) {
            return response()->json(['message' => 'You can only message people in your society.'], 403);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.Message::MAX_LENGTH],
        ], [
            'body.required' => 'Write a message first.',
        ]);

        $body = trim($data['body']);
        if ($body === '') {
            return response()->json(['message' => 'Write a message first.', 'errors' => ['body' => ['Write a message first.']]], 422);
        }

        ['message' => $message] = Messenger::send(Conversation::between($viewer, $user), $viewer, $body);

        return response()->json(['message' => $this->presentMessage($message, $viewer->id)], 201);
    }

    /**
     * Search accounts by name, exact email or exact mobile number.
     * Without a search, suggests people you aren't connected to yet (most mutual friends first).
     */
    public function people(Request $request): JsonResponse
    {
        $viewerId = $this->viewerId();
        $search = trim((string) $request->query('search', ''));

        $query = User::query()
            ->whereKeyNot($viewerId)
            ->where('status', 'active');

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $users = $query
                ->where(fn (Builder $q) => $q
                    ->where('name', 'like', $like)
                    ->orWhere('email', $search)
                    ->orWhere('phone', $search))
                ->orderBy('name')
                ->limit(30)
                ->get();
        } else {
            $connected = Friendship::query()->involving($viewerId)->get()
                ->map(fn (Friendship $f) => $f->otherId($viewerId));
            $users = $query->whereKeyNot($connected->all())->latest('id')->limit(200)->get();
        }

        $ids = $users->pluck('id')->all();
        $friendships = Friendship::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('requester_id', $viewerId)->whereIn('addressee_id', $ids))
                ->orWhere(fn (Builder $q) => $q->where('addressee_id', $viewerId)->whereIn('requester_id', $ids)))
            ->get()
            ->keyBy(fn (Friendship $f) => $f->otherId($viewerId));

        $mutuals = $this->mutualCounts($ids, $this->friendIds($viewerId));

        $data = $users->map(fn (User $u) => $this->present($u, $friendships->get($u->id), $viewerId, $mutuals));

        if ($search === '') {
            $data = $data->sortByDesc('mutual_count')->take(24);
        }

        return response()->json(['data' => $data->values()]);
    }

    /** Send a friend request, or accept theirs if they already asked you. */
    public function store(User $user): JsonResponse
    {
        $viewerId = $this->viewerId();

        if ($user->id === $viewerId) {
            return response()->json(['message' => 'You can’t add yourself.'], 422);
        }

        $existing = Friendship::query()->between($viewerId, $user->id)->first();

        if ($existing && $existing->relationshipFor($viewerId) === 'incoming') {
            return $this->acceptFriendship($existing, $user);
        }

        if (! $existing) {
            $existing = Friendship::query()->create([
                'requester_id' => $viewerId,
                'addressee_id' => $user->id,
                'status' => Friendship::PENDING,
            ]);
        }

        $relationship = $existing->relationshipFor($viewerId);

        return response()->json([
            'message' => $relationship === 'friends'
                ? 'You and '.$user->name.' are already friends.'
                : 'Friend request sent to '.$user->name.'.',
            'user_id' => $user->id,
            'relationship' => $relationship,
        ], $existing->wasRecentlyCreated ? 201 : 200);
    }

    public function accept(User $user): JsonResponse
    {
        $viewerId = $this->viewerId();

        $friendship = Friendship::query()
            ->where('requester_id', $user->id)
            ->where('addressee_id', $viewerId)
            ->where('status', Friendship::PENDING)
            ->first();

        if (! $friendship) {
            return response()->json(['message' => 'That friend request no longer exists.'], 404);
        }

        return $this->acceptFriendship($friendship, $user);
    }

    /** Cancel a sent request, ignore a received one, or remove a friend. */
    public function destroy(User $user): JsonResponse
    {
        $viewerId = $this->viewerId();
        $friendship = Friendship::query()->between($viewerId, $user->id)->first();

        if (! $friendship) {
            return response()->json(['message' => 'You aren’t connected.', 'user_id' => $user->id, 'relationship' => 'none']);
        }

        $message = match ($friendship->relationshipFor($viewerId)) {
            'friends' => $user->name.' was removed from your society.',
            'outgoing' => 'Friend request withdrawn.',
            default => 'Friend request ignored.',
        };

        $friendship->delete();

        return response()->json(['message' => $message, 'user_id' => $user->id, 'relationship' => 'none']);
    }

    private function acceptFriendship(Friendship $friendship, User $user): JsonResponse
    {
        $friendship->update(['status' => Friendship::ACCEPTED, 'accepted_at' => now()]);

        return response()->json([
            'message' => 'You and '.$user->name.' are now friends.',
            'user_id' => $user->id,
            'relationship' => 'friends',
        ]);
    }

    /** @param  array<int, array{conversation_id: int, last_message: array|null, unread_count: int}>  $chats */
    private function present(User $user, ?Friendship $friendship, int $viewerId, array $mutuals, array $chats = []): array
    {
        return UserPresenter::person($user) + [
            'relationship' => $friendship?->relationshipFor($viewerId) ?? 'none',
            'mutual_count' => $mutuals[$user->id] ?? 0,
            'since' => ($friendship?->accepted_at ?? $friendship?->created_at)?->toIso8601String(),
            'conversation_id' => $chats[$user->id]['conversation_id'] ?? null,
            'last_message' => $chats[$user->id]['last_message'] ?? null,
            'unread_count' => $chats[$user->id]['unread_count'] ?? 0,
        ];
    }

    /**
     * Conversation, latest message and unread count per conversation partner.
     *
     * @return array<int, array{conversation_id: int, last_message: array|null, unread_count: int}>
     */
    private function chatSummaries(int $viewerId): array
    {
        $conversations = Conversation::query()
            ->forUser($viewerId)
            ->whereHas('messages')
            ->with(['participantRecords', 'latestMessage'])
            ->get();
        $unread = Messenger::unreadCounts($conversations->modelKeys(), $viewerId);

        $summaries = [];
        foreach ($conversations as $conversation) {
            $other = $conversation->participantRecords->first(fn ($p) => (int) $p->user_id !== $viewerId);
            if (! $other) {
                continue;
            }
            $summaries[(int) $other->user_id] = [
                'conversation_id' => $conversation->id,
                'last_message' => $conversation->latestMessage ? $this->presentMessage($conversation->latestMessage, $viewerId) : null,
                'unread_count' => $unread[$conversation->id] ?? 0,
            ];
        }

        return $summaries;
    }

    /** The older My Society message shape. */
    private function presentMessage(Message $message, int $viewerId): array
    {
        return [
            'id' => $message->id,
            'body' => $message->message,
            'mine' => (int) $message->sender_id === $viewerId,
            'created_at' => $message->created_at?->toIso8601String(),
            'read_at' => $message->read_at?->toIso8601String(),
        ];
    }

    private function areFriends(int $a, int $b): bool
    {
        return Friendship::query()->between($a, $b)->where('status', Friendship::ACCEPTED)->exists();
    }

    /** @return array<int, int> */
    private function friendIds(int $userId): array
    {
        return Friendship::query()
            ->involving($userId)
            ->where('status', Friendship::ACCEPTED)
            ->get()
            ->map(fn (Friendship $f) => $f->otherId($userId))
            ->all();
    }

    /**
     * Number of the viewer's friends each user is also friends with.
     *
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $viewerFriendIds
     * @return array<int, int>
     */
    private function mutualCounts(array $userIds, array $viewerFriendIds): array
    {
        if ($userIds === [] || $viewerFriendIds === []) {
            return [];
        }

        $counts = [];
        Friendship::query()
            ->where('status', Friendship::ACCEPTED)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->whereIn('requester_id', $userIds)->whereIn('addressee_id', $viewerFriendIds))
                ->orWhere(fn (Builder $q) => $q->whereIn('addressee_id', $userIds)->whereIn('requester_id', $viewerFriendIds)))
            ->get(['requester_id', 'addressee_id'])
            ->each(function (Friendship $f) use (&$counts, $userIds, $viewerFriendIds) {
                $pairs = [
                    [(int) $f->requester_id, (int) $f->addressee_id],
                    [(int) $f->addressee_id, (int) $f->requester_id],
                ];
                foreach ($pairs as [$id, $other]) {
                    if (in_array($id, $userIds, true) && in_array($other, $viewerFriendIds, true)) {
                        $counts[$id] = ($counts[$id] ?? 0) + 1;
                    }
                }
            });

        return $counts;
    }

    private function viewer(): User
    {
        /** @var User */
        return auth('api')->user();
    }

    private function viewerId(): int
    {
        return (int) auth('api')->id();
    }
}
