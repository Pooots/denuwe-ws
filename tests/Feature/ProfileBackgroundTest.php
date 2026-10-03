<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class ProfileBackgroundTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        $this->user = User::factory()->create();
    }

    private function as(User $user, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['tymon.jwt']->unsetToken();
        JWTAuth::unsetToken();
        $this->app['auth']->forgetGuards();

        return $this->json($method, $uri, $data, [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.JWTAuth::fromUser($user),
        ]);
    }

    public function test_defaults_to_no_background(): void
    {
        $this->as($this->user, 'GET', '/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.background', null)
            ->assertJsonPath('user.background_url', null)
            ->assertJsonPath('user.background_effect', null);
    }

    public function test_choose_a_template_and_back_to_none(): void
    {
        $this->as($this->user, 'PATCH', '/api/v1/profile/background', ['background' => 'aurora'])
            ->assertOk()
            ->assertJsonPath('user.background', 'aurora');

        $this->as($this->user, 'PATCH', '/api/v1/profile/background', ['background' => 'none'])
            ->assertOk()
            ->assertJsonPath('user.background', null);
    }

    public function test_rejects_unknown_designs_and_photo_without_upload(): void
    {
        $this->as($this->user, 'PATCH', '/api/v1/profile/background', ['background' => 'rainbow'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('background');

        $this->as($this->user, 'PATCH', '/api/v1/profile/background', ['background' => 'photo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('background');

        $this->as($this->user, 'PATCH', '/api/v1/profile/background', ['background' => 'sky', 'effect' => 'neon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effect');
    }

    public function test_upload_photo_switch_look_switch_away_and_back_then_remove(): void
    {
        $upload = $this->as($this->user, 'POST', '/api/v1/profile/background', [
            'image' => UploadedFile::fake()->image('portrait.jpg', 600, 1200),
            'effect' => 'frosted',
        ])->assertOk()
            ->assertJsonPath('user.background', 'photo')
            ->assertJsonPath('user.background_effect', 'frosted');
        $path = $this->user->fresh()->profile_background_path;
        Storage::disk('media')->assertExists($path);
        $this->assertNotNull($upload->json('user.background_url'));

        $this->as($this->user, 'PATCH', '/api/v1/profile/background', ['background' => 'photo', 'effect' => 'dark'])
            ->assertOk()
            ->assertJsonPath('user.background_effect', 'dark');

        // A template keeps the uploaded photo, so it can be picked again later.
        $this->as($this->user, 'PATCH', '/api/v1/profile/background', ['background' => 'midnight'])
            ->assertOk()
            ->assertJsonPath('user.background', 'midnight');
        Storage::disk('media')->assertExists($path);
        $this->as($this->user, 'PATCH', '/api/v1/profile/background', ['background' => 'photo'])
            ->assertOk()
            ->assertJsonPath('user.background', 'photo')
            ->assertJsonPath('user.background_effect', 'dark');

        $this->as($this->user, 'DELETE', '/api/v1/profile/background')
            ->assertOk()
            ->assertJsonPath('user.background', null)
            ->assertJsonPath('user.background_url', null);
        Storage::disk('media')->assertMissing($path);
    }

    public function test_new_upload_replaces_the_old_file(): void
    {
        $this->as($this->user, 'POST', '/api/v1/profile/background', ['image' => UploadedFile::fake()->image('a.jpg', 1600, 900)])
            ->assertOk()
            ->assertJsonPath('user.background_effect', 'soft');
        $first = $this->user->fresh()->profile_background_path;

        $this->as($this->user, 'POST', '/api/v1/profile/background', ['image' => UploadedFile::fake()->image('b.png', 900, 1600)])
            ->assertOk();
        Storage::disk('media')->assertMissing($first);
        Storage::disk('media')->assertExists($this->user->fresh()->profile_background_path);
    }

    public function test_rejects_non_images(): void
    {
        $this->as($this->user, 'POST', '/api/v1/profile/background', [
            'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_visitors_see_the_background(): void
    {
        $this->user->update(['profile_background' => 'court']);
        $visitor = User::factory()->create();

        $this->as($visitor, 'GET', '/api/v1/users/'.$this->user->id)
            ->assertOk()
            ->assertJsonPath('user.background', 'court')
            ->assertJsonPath('user.background_url', null);
    }
}
