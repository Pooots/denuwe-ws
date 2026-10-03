<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Someone opened a conversation and read the messages sent to them; the sender sees "Seen" right away. */
class MessagesRead implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    /**
     * @param  array<int, int>  $messageIds
     * @param  array<int, int>  $participantIds
     */
    public function __construct(
        public int $conversationId,
        public int $readerId,
        public array $messageIds,
        public string $readAt,
        public array $participantIds,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->conversationId),
            ...array_map(fn (int $id) => new PrivateChannel('user.'.$id), $this->participantIds),
        ];
    }

    public function broadcastAs(): string
    {
        return 'MessagesRead';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'reader_id' => $this->readerId,
            'message_ids' => $this->messageIds,
            'read_at' => $this->readAt,
        ];
    }
}
