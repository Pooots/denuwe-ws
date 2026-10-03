<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\User;
use App\Support\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    private const MEDIA = [
        'avatar' => ['column' => 'avatar_path', 'folder' => 'avatars', 'max_kb' => 5120, 'label' => 'Profile photos'],
        'banner' => ['column' => 'banner_path', 'folder' => 'banners', 'max_kb' => 8192, 'label' => 'Banners'],
    ];

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->user();
        $data = $request->validated();

        $user->fill($data);
        $user->name = trim($data['first_name'].' '.$data['last_name']);
        $user->save();

        return $this->userResponse($user, 'Profile updated.');
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        return $this->storeMedia($request, 'avatar', 'Profile photo updated.');
    }

    public function removeAvatar(): JsonResponse
    {
        return $this->removeMedia('avatar', 'Profile photo removed.');
    }

    public function uploadBanner(Request $request): JsonResponse
    {
        return $this->storeMedia($request, 'banner', 'Banner updated.');
    }

    public function removeBanner(): JsonResponse
    {
        return $this->removeMedia('banner', 'Banner removed.');
    }

    private function storeMedia(Request $request, string $type, string $message): JsonResponse
    {
        $media = self::MEDIA[$type];

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:'.$media['max_kb']],
        ], [
            'image.required' => 'Choose a photo to upload.',
            'image.max' => $media['label'].' can be up to '.($media['max_kb'] / 1024).' MB.',
            'image.mimes' => 'Use a JPG, PNG, GIF or WEBP photo.',
        ]);

        $user = $this->user();
        $previous = $user->{$media['column']};

        $user->{$media['column']} = $request->file('image')->store($media['folder'], 'media');
        $user->save();

        if ($previous) {
            Storage::disk('media')->delete($previous);
        }

        return $this->userResponse($user, $message);
    }

    private function removeMedia(string $type, string $message): JsonResponse
    {
        $column = self::MEDIA[$type]['column'];
        $user = $this->user();

        if ($user->{$column}) {
            Storage::disk('media')->delete($user->{$column});
            $user->{$column} = null;
            $user->save();
        }

        return $this->userResponse($user, $message);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth('api')->user();

        return $user;
    }

    private function userResponse(User $user, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'user' => UserPresenter::account($user),
        ]);
    }
}
