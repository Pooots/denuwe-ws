<?php

namespace App\Support;

use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Saving, reading and broadcasting 1-to-1 messages; shared by the conversations API and the older My Society chat routes. */
class Messenger
{
    /**
     * Save a message, then broadcast it. A broadcast failure (Reverb down) is logged, not thrown:
     * the message is already saved and shows up when the conversation is next loaded.
     *
     * @return array{message: Message, live: bool}
     */
    public static function send(Conversation $conversation, User $sender, string $text, ?string $clientId = null): array
    {
        $message = DB::transaction(function () use ($conversation, $sender, $text) {
            $message = $conversation->messages()->create([
                'sender_id' => $sender->id,
                'message' => $text,
                'message_type' => Message::TEXT,
            ]);
            $conversation->touch();

            return $message;
        });
        $message->setRelation('sender', $sender);

        $live = self::broadcast(new MessageSent($message, $conversation->participantIds(), $clientId));

        return ['message' => $message, 'live' => $live];
    }

    /**
     * Mark everything the other person sent as read, and tell them.
     *
     * @return array{message_ids: array<int, int>, read_at: string|null}
     */
    public static function markRead(Conversation $conversation, int $readerId): array
    {
        $ids = $conversation->messages()
            ->where('sender_id', '!=', $readerId)
            ->where('is_read', false)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            return ['message_ids' => [], 'read_at' => null];
        }

        $readAt = now();
        Message::query()->whereKey($ids)->update(['is_read' => true, 'read_at' => $readAt]);

        self::broadcast(new MessagesRead(
            $conversation->id,
            $readerId,
            $ids,
            $readAt->toIso8601String(),
            $conversation->participantIds(),
        ));

        return ['message_ids' => $ids, 'read_at' => $readAt->toIso8601String()];
    }

    /**
     * Unread messages per conversation for the viewer.
     *
     * @param  array<int, int>  $conversationIds
     * @return array<int, int>
     */
    public static function unreadCounts(array $conversationIds, int $viewerId): array
    {
        if ($conversationIds === []) {
            return [];
        }

        return Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('sender_id', '!=', $viewerId)
            ->where('is_read', false)
            ->selectRaw('conversation_id, COUNT(*) as unread')
            ->groupBy('conversation_id')
            ->pluck('unread', 'conversation_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** Broadcast (synchronously: the events are ShouldBroadcastNow) without echoing back to the sender's own socket (X-Socket-ID). */
    private static function broadcast(MessageSent|MessagesRead $event): bool
    {
        try {
            event($event->dontBroadcastToCurrentUser());

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
