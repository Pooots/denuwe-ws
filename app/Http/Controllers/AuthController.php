<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Support\ContactIdentifier;
use App\Support\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::query()->create([
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'birthday' => $data['birthday'],
            'gender' => $data['gender'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'role' => 'user',
            'status' => 'active',
        ]);

        $token = auth('api')->login($user);

        return $this->tokenResponse($token, $user, 'Account created.', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $contact = ContactIdentifier::parse($credentials['identifier']);

        $user = User::query()
            ->where($contact['column'], $contact['value'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'The email/mobile number or password you entered is incorrect.',
            ], 401);
        }

        if ($user->status === 'suspended') {
            return response()->json([
                'message' => 'This account is suspended.',
            ], 403);
        }

        $token = auth('api')->login($user);

        return $this->tokenResponse($token, $user, 'Logged in.');
    }

    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        return response()->json([
            'user' => $this->transformUser($user),
        ]);
    }

    public function logout(): JsonResponse
    {
        auth('api')->logout();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    public function refresh(): JsonResponse
    {
        try {
            $token = auth('api')->refresh();
        } catch (JWTException $e) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'error' => 'Token cannot be refreshed',
            ], 401);
        }

        /** @var User $user */
        $user = auth('api')->setToken($token)->user();

        return $this->tokenResponse($token, $user, 'Token refreshed.');
    }

    private function tokenResponse(string $token, User $user, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $this->transformUser($user),
        ], $status);
    }

    private function transformUser(User $user): array
    {
        return UserPresenter::account($user);
    }
}
