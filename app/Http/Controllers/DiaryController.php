<?php

namespace App\Http\Controllers;

use App\Models\DiaryEntry;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Your diary. Only you can write to it; your friends (your society) can read it, nobody else can.
 */
class DiaryController extends Controller
{
    private const DATES_FOR_STREAK = 400;

    public function index(Request $request): JsonResponse
    {
        return $this->listing($this->viewerId(), $request);
    }

    /** Read a friend's diary. */
    public function forUser(Request $request, User $user): JsonResponse
    {
        $viewerId = $this->viewerId();

        if ($user->id !== $viewerId && ! Friendship::areFriends($viewerId, $user->id)) {
            return response()->json(['message' => 'Only people in '.$user->first_name.'’s society can read their diary.'], 403);
        }

        return $this->listing($user->id, $request);
    }

    private function listing(int $userId, Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $entries = fn () => DiaryEntry::query()->where('user_id', $userId);

        $page = $entries()
            ->when($search !== '', function (Builder $q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('title', 'like', $like)->orWhere('body', 'like', $like));
            })
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->cursorPaginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (DiaryEntry $e) => $e->present())->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'total' => $entries()->count(),
            // Distinct days with an entry, newest first, so the client can work out streaks in the user's timezone.
            'dates' => $entries()
                ->select('entry_date')
                ->distinct()
                ->orderByDesc('entry_date')
                ->limit(self::DATES_FOR_STREAK)
                ->toBase()
                ->pluck('entry_date')
                ->map(fn ($date) => substr((string) $date, 0, 10))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $entry = DiaryEntry::query()->create(['user_id' => $this->viewerId()] + $this->validated($request));

        return response()->json(['message' => 'Diary entry saved.', 'entry' => $entry->present()], 201);
    }

    public function update(Request $request, int $entry): JsonResponse
    {
        $model = $this->entries()->findOrFail($entry);
        $model->update($this->validated($request));

        return response()->json(['message' => 'Diary entry updated.', 'entry' => $model->present()]);
    }

    public function destroy(int $entry): JsonResponse
    {
        $this->entries()->findOrFail($entry)->delete();

        return response()->json(['message' => 'Diary entry deleted.']);
    }

    /** @return array{entry_date: string, mood: ?string, title: ?string, body: string} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            // The client sends its local date, which can be a day ahead of the server's.
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->addDay()->toDateString()],
            'mood' => ['nullable', Rule::in(DiaryEntry::MOODS)],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:10000'],
        ], [
            'entry_date.required' => 'Pick a date for this entry.',
            'entry_date.before_or_equal' => 'You can’t write an entry for a future date.',
            'body.required' => 'Write something before saving.',
        ]);

        return [
            'entry_date' => $data['entry_date'],
            'mood' => $data['mood'] ?? null,
            'title' => isset($data['title']) ? (trim($data['title']) ?: null) : null,
            'body' => trim($data['body']),
        ];
    }

    private function entries(): Builder
    {
        return DiaryEntry::query()->where('user_id', $this->viewerId());
    }

    private function viewerId(): int
    {
        return (int) auth('api')->id();
    }
}
