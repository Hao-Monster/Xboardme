<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthSessionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_access_tokens_do_not_expire_for_normal_or_distributor_accounts(): void
    {
        foreach ([false, true] as $isDistributor) {
            $user = $this->makeUser(
                ($isDistributor ? 'distributor' : 'normal') . '@example.com',
                $isDistributor
            );

            $authData = (new AuthService($user))->generateAuthData();
            $token = PersonalAccessToken::findToken(substr($authData['auth_data'], 7));

            $this->assertNotNull($token);
            $this->assertNull($token->expires_at);
        }
    }

    public function test_logout_revokes_only_the_current_browser_token_for_distributors_too(): void
    {
        $user = $this->makeUser('logout@example.com', true);
        $current = (new AuthService($user))->generateAuthData();
        $other = (new AuthService($user))->generateAuthData();
        $currentTokenValue = substr($current['auth_data'], 7);
        $otherToken = PersonalAccessToken::findToken(substr($other['auth_data'], 7));

        $this->withHeader('Authorization', $current['auth_data'])
            ->postJson('/api/v1/user/logout')
            ->assertOk()
            ->assertJsonPath('data', true);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNull(PersonalAccessToken::findToken($currentTokenValue));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->id]);
    }

    public function test_logout_endpoint_cannot_revoke_tokens_without_authentication(): void
    {
        $user = $this->makeUser('protected@example.com');
        (new AuthService($user))->generateAuthData();

        $this->postJson('/api/v1/user/logout')->assertForbidden();

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_changing_email_revokes_every_browser_token(): void
    {
        $user = $this->makeUser('before@example.com');
        (new AuthService($user))->generateAuthData();
        (new AuthService($user))->generateAuthData();

        $user->email = 'after@example.com';
        $user->save();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_changing_password_through_the_api_revokes_every_browser_token(): void
    {
        $user = $this->makeUser('password@example.com');
        $current = (new AuthService($user))->generateAuthData();
        (new AuthService($user))->generateAuthData();

        $this->withHeader('Authorization', $current['auth_data'])
            ->postJson('/api/v1/user/changePassword', [
                'old_password' => 'password-123',
                'new_password' => 'new-password-456',
            ])
            ->assertOk()
            ->assertJsonPath('data', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_migration_converts_already_issued_tokens_to_permanent_tokens(): void
    {
        $user = $this->makeUser('existing@example.com');
        $user->createToken('expired', ['*'], now()->subDay());
        $user->createToken('future', ['*'], now()->addYear());

        $migration = require database_path('migrations/2026_08_16_000001_make_personal_access_tokens_permanent.php');
        $migration->up();

        $this->assertSame(0, PersonalAccessToken::query()->whereNotNull('expires_at')->count());
        $this->assertSame(2, PersonalAccessToken::query()->whereNull('expires_at')->count());
    }

    private function makeUser(string $email, bool $isDistributor = false): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => false,
            'is_staff' => false,
            'is_distributor' => $isDistributor,
            'banned' => false,
        ]);
    }
}
