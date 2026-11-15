<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Settings;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Workspace::create(['name' => 'Personal', 'type' => 'personal']);
        $this->user = User::factory()->create(['email' => 'me@example.com', 'password' => Hash::make('correct-horse')]);
    }

    /** @return array<string, array{string}> */
    public static function protectedPages(): array
    {
        return ['dashboard' => ['/'], 'transactions' => ['/transactions'], 'budgets' => ['/budgets'], 'debts' => ['/debts'], 'settings' => ['/settings']];
    }

    #[DataProvider('protectedPages')]
    public function test_guests_are_redirected_to_login(string $url): void
    {
        $this->get($url)->assertRedirect('/login');
    }

    public function test_login_page_renders_for_guests_only(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
        $this->actingAs($this->user)->get('/login')->assertRedirect('/');
    }

    public function test_valid_credentials_sign_in(): void
    {
        Livewire::test(Login::class)
            ->set('email', 'me@example.com')->set('password', 'correct-horse')
            ->call('login')->assertHasNoErrors()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->user);
        $this->get('/')->assertOk()->assertSee('me@example.com');
    }

    public function test_wrong_password_is_rejected_and_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)
                ->set('email', 'me@example.com')->set('password', 'nope')
                ->call('login')->assertHasErrors('email')->assertSet('password', '');
        }
        $this->assertGuest();

        // Sixth try is locked out even with the right password.
        Livewire::test(Login::class)
            ->set('email', 'me@example.com')->set('password', 'correct-horse')
            ->call('login')->assertSee('Too many attempts');
        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs($this->user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_password_can_be_changed_from_settings(): void
    {
        $this->actingAs($this->user);

        Livewire::test(Settings::class)
            ->set('currentPassword', 'wrong')->set('newPassword', 'new-secret-123')->set('newPasswordConfirmation', 'new-secret-123')
            ->call('updatePassword')->assertHasErrors('currentPassword')
            ->set('currentPassword', 'correct-horse')->set('newPasswordConfirmation', 'different')
            ->call('updatePassword')->assertHasErrors('newPassword')
            ->set('newPasswordConfirmation', 'new-secret-123')
            ->call('updatePassword')->assertHasNoErrors();

        $this->assertTrue(Hash::check('new-secret-123', $this->user->fresh()->password));
    }

    public function test_user_create_command_creates_and_resets_logins(): void
    {
        $this->artisan('user:create', ['email' => 'new@example.com', '--password' => 'long-enough-pw'])->assertSuccessful();
        $this->assertTrue(Hash::check('long-enough-pw', User::where('email', 'new@example.com')->firstOrFail()->password));

        $this->artisan('user:create', ['email' => 'new@example.com', '--password' => 'short'])->assertFailed();
    }
}
