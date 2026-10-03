<?php

namespace App\Http\Controllers;

use App\Models\PersonalActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** The signed-in user's own schedule. Past dates are allowed so people can log what they did. */
class PersonalActivityController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $activity = PersonalActivity::create([
            'user_id' => $this->viewerId(),
            ...$this->validated($request),
        ]);

        return response()->json([
            'message' => $activity->title.' added to your schedule.',
            'activity' => $activity->present(),
        ], 201);
    }

    public function update(Request $request, PersonalActivity $activity): JsonResponse
    {
        $this->ensureOwner($activity);
        $activity->update($this->validated($request));

        return response()->json([
            'message' => 'Activity updated.',
            'activity' => $activity->present(),
        ]);
    }

    public function destroy(PersonalActivity $activity): JsonResponse
    {
        $this->ensureOwner($activity);
        $activity->delete();

        return response()->json(['message' => 'Activity deleted.']);
    }

    private function validated(Request $request): array
    {
        $viewerId = $this->viewerId();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'club_id' => [
                'nullable',
                'integer',
                Rule::exists('club_members', 'club_id')->where('user_id', $viewerId),
            ],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'all_day' => ['sometimes', 'boolean'],
            'location' => ['nullable', 'string', 'max:120'],
            'is_meeting' => ['sometimes', 'boolean'],
            'meeting_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'title.required' => 'Give the activity a name.',
            'club_id.exists' => 'Pick one of your clubs or communities, or Personal.',
            'starts_at.required' => 'Pick a date.',
            'ends_at.after' => 'The end time must be after the start time.',
            'meeting_url.url' => 'The meeting link should start with https://',
            'meeting_url.max' => 'The meeting link can be up to 500 characters.',
        ]);

        $allDay = (bool) ($data['all_day'] ?? false);
        $isMeeting = (bool) ($data['is_meeting'] ?? false);
        $toAppTime = fn (string $value) => Carbon::parse($value)->setTimezone(config('app.timezone'));
        $clean = fn (?string $value) => $value === null ? null : (trim($value) ?: null);

        return [
            'title' => trim($data['title']),
            'club_id' => $data['club_id'] ?? null,
            'starts_at' => $toAppTime($data['starts_at']),
            'ends_at' => ! $allDay && isset($data['ends_at']) ? $toAppTime($data['ends_at']) : null,
            'all_day' => $allDay,
            'location' => $clean($data['location'] ?? null),
            'is_meeting' => $isMeeting,
            'meeting_url' => $isMeeting ? $clean($data['meeting_url'] ?? null) : null,
            'description' => $clean($data['description'] ?? null),
        ];
    }

    /** Someone else's activity answers as if it didn't exist. */
    private function ensureOwner(PersonalActivity $activity): void
    {
        abort_unless((int) $activity->user_id === $this->viewerId(), 404);
    }

    private function viewerId(): int
    {
        return (int) auth('api')->id();
    }
}
