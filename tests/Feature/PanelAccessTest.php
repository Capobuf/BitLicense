<?php

namespace Tests\Feature;

use App\Filament\Clusters\Impostazioni;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_italian_login(): void
    {
        $this->get('/')->assertRedirect('/admin');
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('Accedi')->assertSee('BitLicense');
        $this->get('/admin/register')->assertNotFound();
    }

    public function test_an_internal_user_can_log_in_and_log_out(): void
    {
        $user = User::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
        $this->get('/admin')->assertOk()->assertSee('Licenze');
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_wrong_credentials_do_not_start_a_session(): void
    {
        $user = User::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'wrong-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_a_disabled_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_disabling_a_user_blocks_an_existing_session(): void
    {
        $user = User::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate');

        $this->get('/admin')->assertOk();
        User::query()->whereKey($user->id)->update(['is_active' => false]);
        $this->app['auth']->guard()->forgetUser();

        $this->get('/admin')->assertForbidden();
        $this->get('/admin/profile')->assertForbidden();
    }

    public function test_settings_has_one_navigation_item_and_two_native_tabs_for_administrators(): void
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->administrator()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get('/admin/impostazioni')->assertRedirect('/admin/impostazioni/gravityzone');
        foreach (['gravityzone', 'utenti'] as $tab) {
            $this->get('/admin/impostazioni/'.$tab)->assertOk()->assertSee('Impostazioni')
                ->assertSee('fi-page-sub-navigation-tabs')->assertSee('API')->assertSee('Utenti');
        }
        $labels = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())->values()->all();
        $this->assertSame(['Licenze', 'Impostazioni'], $labels);
        Http::assertNothingSent();
    }

    public function test_settings_entry_and_tabs_reject_operators_and_disabled_administrators(): void
    {
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach ([['role' => 'operator'], ['role' => 'admin', 'is_active' => false]] as $state) {
            $this->actingAs(User::factory()->create($state));
            foreach (['/admin/impostazioni', '/admin/impostazioni/gravityzone', '/admin/impostazioni/utenti'] as $path) {
                $this->get($path)->assertForbidden();
            }
            Livewire::test(Impostazioni::class)->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_both_roles_can_access_the_panel_and_native_profile(): void
    {
        foreach (array_keys(User::ROLES) as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));

            $this->get('/admin')->assertOk();
            $this->get('/admin/profile')->assertOk();
        }
    }
}
