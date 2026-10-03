<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real-time 1-to-1 messaging: a conversation, its two participants and its messages.
 * Existing `direct_messages` rows are copied over (the old table is left in place, untouched).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            // "<lower user id>:<higher user id>" — one conversation per pair, even when both open it at once.
            $table->string('direct_key', 41)->nullable()->unique();
            $table->timestamps();

            $table->index('updated_at');
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'conversation_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->string('message_type', 20)->default('text');
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('is_read');
            $table->index(['conversation_id', 'id']);
            $table->index(['conversation_id', 'is_read', 'sender_id']);
        });

        $this->copyDirectMessages();
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }

    private function copyDirectMessages(): void
    {
        if (! Schema::hasTable('direct_messages') || DB::table('direct_messages')->doesntExist()) {
            return;
        }

        $pairs = DB::table('direct_messages')
            ->selectRaw('LEAST(sender_id, recipient_id) as a, GREATEST(sender_id, recipient_id) as b')
            ->selectRaw('MIN(created_at) as first_at, MAX(created_at) as last_at')
            ->groupByRaw('LEAST(sender_id, recipient_id), GREATEST(sender_id, recipient_id)')
            ->get();

        foreach ($pairs as $pair) {
            $conversationId = DB::table('conversations')->insertGetId([
                'direct_key' => $pair->a.':'.$pair->b,
                'created_at' => $pair->first_at,
                'updated_at' => $pair->last_at,
            ]);

            DB::table('conversation_participants')->insert([
                ['conversation_id' => $conversationId, 'user_id' => $pair->a, 'created_at' => $pair->first_at, 'updated_at' => $pair->first_at],
                ['conversation_id' => $conversationId, 'user_id' => $pair->b, 'created_at' => $pair->first_at, 'updated_at' => $pair->first_at],
            ]);

            DB::table('direct_messages')
                ->whereRaw('LEAST(sender_id, recipient_id) = ? AND GREATEST(sender_id, recipient_id) = ?', [$pair->a, $pair->b])
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($conversationId) {
                    DB::table('messages')->insert($rows->map(fn ($row) => [
                        'conversation_id' => $conversationId,
                        'sender_id' => $row->sender_id,
                        'message' => $row->body,
                        'message_type' => 'text',
                        'is_read' => $row->read_at !== null,
                        'read_at' => $row->read_at,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ])->all());
                });
        }
    }
};
