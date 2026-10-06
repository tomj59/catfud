<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_a_token_that_works(): void
    {
        $res = $this->postJson('/api/v1/register', [
            'name' => 'Tom', 'email' => 'tom@example.test', 'password' => 'correct-horse-1',
        ])->assertCreated()->assertJsonStructure(['user' => ['id', 'email'], 'token']);

        $this->assertArrayNotHasKey('password', $res->json('user'));

        $this->withToken($res->json('token'))->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('user.email', 'tom@example.test');
    }

    public function test_register_validates_input(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->postJson('/api/v1/register', ['name' => '', 'email' => 'nope', 'password' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->postJson('/api/v1/register', ['name' => 'X', 'email' => 'taken@example.test', 'password' => 'long-enough-1'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_login_with_good_and_bad_credentials(): void
    {
        User::factory()->create(['email' => 'a@example.test', 'password' => 'right-password']);

        $this->postJson('/api/v1/login', ['email' => 'a@example.test', 'password' => 'right-password'])
            ->assertOk()->assertJsonStructure(['token']);

        $this->postJson('/api/v1/login', ['email' => 'a@example.test', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_protected_routes_need_a_token(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
        $this->getJson('/api/v1/inventory')->assertUnauthorized();
        $this->postJson('/api/v1/pets', ['name' => 'x'])->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = $this->postJson('/api/v1/register', [
            'name' => 'T', 'email' => 't@example.test', 'password' => 'long-enough-1',
        ])->json('token');

        $this->withToken($token)->postJson('/api/v1/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
