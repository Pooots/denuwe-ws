<?php

namespace App\Models;

use App\Support\UserPresenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A message in a 1-to-1 conversation. `is_read` / `read_at` are set when the recipient opens the conversation. */
class Message extends Model
{
    public const TEXT = 'text';

    public const MAX_LENGTH = 2000;

    protected $fillable = ['conversation_id', 'sender_id', 'message', 'message_type', 'is_read', 'read_at'];

    protected $attributes = [
        'message_type' => self::TEXT,
        'is_read' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** The same shape for API responses and the MessageSent broadcast, so the client can merge either. */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => (int) $this->conversation_id,
            'sender_id' => (int) $this->sender_id,
            'sender' => $this->sender ? UserPresenter::author($this->sender) : null,
            'message' => $this->message,
            'message_type' => $this->message_type,
            'is_read' => (bool) $this->is_read,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
