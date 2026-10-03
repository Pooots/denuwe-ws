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

/**
 * Real-time 1-to-1 messaging. Everything is scoped to the signed-in (JWT) user: you only see, read and send
 * in conversations you take part in, and the sender is always you. New messages and read receipts are
 * pushed over Laravel Reverb (see App\Events and routes/channels.php).
 */
class ConversationController extends Controller
{
    private const PAGE_SIZE = 50;

    /** Your conversations that have messages, latest activity first, with the unread count for each. */
    public function index(): JsonResponse
    {
        $viewerId = $this->viewerId();

        $conversations = Conversation::query()
            ->forUser($viewerId)
            ->whereHas('messages')
            ->with(['participants', 'latestMessage.sender'])
            ->get()
            ->sortByDesc(fn (Conversation $c) => $c->latestMessage?->id ?? 0)
            ->values();

        $unread = Messenger::unreadCounts($conversations->modelKeys(), $viewerId);

        return response()->json([
            'data' => $conversations->map(fn (Conversation $c) => $this->present($c, $viewerId, $unread[$c->id] ?? 0)),
            'unread_count' => array_sum($unread),
        ]);
    }

    /** Open (or start) the conversation with a friend. */
    public function store(Request $request): JsonResponse
    {
        $viewer = $this->viewer();
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
        ]);

        $other = User::query()->whereKey($data['user_id'])->where('status', 'active')->first();
        if (! $other) {
            return response()->json(['message' => 'That person is no longer on denuwe.'], 404);
        }
        if ($other->id === $viewer->id) {
            return response()->json(['message' => 'You can’t message yourself.'], 422);
        }
        if (! Friendship::areFriends($viewer->id, $other->id)) {
            return response()->json(['message' => 'You can only message people in your society.'], 403);
        }

        $conversation = Conversation::between($viewer, $other);
        $conversation->load(['participants', 'latestMessage.sender']);
        $unread = Messenger::unreadCounts([$conversation->id], $viewer->id);

        return response()->json(
            ['data' => $this->present($conversation, $viewer->id, $unread[$conversation->id] ?? 0)],
            $conversation->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function show(Conversation $conversation): JsonResponse
    {
        $viewerId = $this->viewerId();
        if ($denied = $this->denyUnlessParticipant($conversation, $viewerId)) {
            return $denied;
        }

        $conversation->load(['participants', 'latestMessage.sender']);
        $unread = Messenger::unreadCounts([$conversation->id], $viewerId);

        return response()->json(['data' => $this->present($conversation, $viewerId, $unread[$conversation->id] ?? 0)]);
    }

    /**
     * One page of messages, oldest to newest. The first page is the latest 50; pass `before=<oldest id you have>`
     * for older ones. `has_more` says whether there is anything before this page.
     */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $viewerId = $this->viewerId();
        if ($denied = $this->denyUnlessParticipant($conversation, $viewerId)) {
            return $denied;
        }

        $data = $request->validate([
            'before' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $limit = (int) ($data['limit'] ?? self::PAGE_SIZE);

        $rows = $conversation->messages()
            ->with('sender')
            ->when(isset($data['before']), fn (Builder $q) => $q->where('id', '<', (int) $data['before']))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->reverse()->values();

        return response()->json([
            'data' => $page->map(fn (Message $m) => $m->present()),
            'has_more' => $hasMore,
            'next_before' => $hasMore ? $page->first()?->id : null,
        ]);
    }

    /** Send a message as yourself. `client_id` (optional) is echoed back so the sender can match its optimistic copy. */
    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $viewer = $this->viewer();
        if ($denied = $this->denyUnlessParticipant($conversation, $viewer->id)) {
            return $denied;
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.Message::MAX_LENGTH],
            'client_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ], [
            'message.required' => 'Write a message first.',
            'message.max' => 'Messages can be up to '.Message::MAX_LENGTH.' characters.',
        ]);

        $text = trim($data['message']);
        if ($text === '') {
            return response()->json(['message' => 'Write a message first.', 'errors' => ['message' => ['Write a message first.']]], 422);
        }

        $conversation->load('participants');
        $other = $conversation->otherParticipant($viewer->id);
        if (! $other || ! Friendship::areFriends($viewer->id, $other->id)) {
            return response()->json(['message' => 'You can only message people in your society.'], 403);
        }

        $clientId = $data['client_id'] ?? null;
        ['message' => $message, 'live' => $live] = Messenger::send($conversation, $viewer, $text, $clientId);

        return response()->json([
            'data' => $message->present() + ['client_id' => $clientId],
            'live' => $live,
        ], 201);
    }

    /** Mark the messages sent to you in this conversation as read. The sender is told over the socket. */
    public function read(Conversation $conversation): JsonResponse
    {
        $viewerId = $this->viewerId();
        if ($denied = $this->denyUnlessParticipant($conversation, $viewerId)) {
            return $denied;
        }

        $result = Messenger::markRead($conversation, $viewerId);

        return response()->json(['conversation_id' => $conversation->id, 'unread_count' => 0] + $result);
    }

    private function present(Conversation $conversation, int $viewerId, int $unreadCount): array
    {
        $other = $conversation->otherParticipant($viewerId);
        $latest = $conversation->latestMessage;

        return [
            'id' => $conversation->id,
            'other_participant' => $other ? UserPresenter::person($other) : null,
            'latest_message' => $latest?->present(),
            'latest_message_at' => $latest?->created_at?->toIso8601String(),
            'unread_count' => $unreadCount,
            'created_at' => $conversation->created_at?->toIso8601String(),
        ];
    }

    private function denyUnlessParticipant(Conversation $conversation, int $viewerId): ?JsonResponse
    {
        return $conversation->hasParticipant($viewerId)
            ? null
            : response()->json(['message' => 'Conversation not found.'], 404);
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
