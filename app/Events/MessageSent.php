<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * A message was saved. Goes to the conversation channel (open chats) and to each participant's own
 * channel (conversation lists, unread badges, other tabs). Sent right away, so no queue worker is needed.
 */
class MessageSent implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    /** @param  array<int, int>  $participantIds */
    public function __construct(
        public Message $message,
        public array $participantIds,
        public ?string $clientId = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->message->conversation_id),
            ...array_map(fn (int $id) => new PrivateChannel('user.'.$id), $this->participantIds),
        ];
    }

    public function broadcastAs(): string
    {
        return 'MessageSent';
    }

    public function broadcastWith(): array
    {
        return $this->message->present() + ['client_id' => $this->clientId];
    }
}
