<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_an_administrator_creates_an_operator_without_sending_email(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $this->get('/admin/utenti')->assertOk();

        Livewire::test(ManageUsers::class)
            ->callAction('create', data: [
                'name' => 'Operatore di prova',
                'email' => 'operatore@example.test',
                'role' => 'operator',
                'is_active' => true,
                'password' => 'password-operatore',
                'password_confirmation' => 'password-operatore',
            ])
            ->assertHasNoFormErrors();

        $operator = User::query()->where('email', 'operatore@example.test')->sole();
        $this->assertSame('operator', $operator->role);
        $this->assertTrue($operator->is_active);
        $this->assertTrue(Hash::check('password-operatore', $operator->password));
        $this->assertArrayNotHasKey('password', $operator->toArray());
        Mail::assertNothingSent();
    }

    public function test_an_administrator_edits_and_disables_an_operator_preserving_a_blank_password(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $operator = User::factory()->create();
        $oldPassword = $operator->password;

        Livewire::test(ManageUsers::class)
            ->mountAction(TestAction::make('edit')->table($operator))
            ->assertActionDataSet(['password' => null])
            ->assertDontSee($oldPassword)
            ->fillForm(['name' => 'Nome aggiornato', 'is_active' => false, 'password' => ''])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $operator->refresh();
        $this->assertSame('Nome aggiornato', $operator->name);
        $this->assertFalse($operator->is_active);
        $this->assertSame($oldPassword, $operator->password);
        $this->assertFalse($operator->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_an_administrator_changes_another_users_role_and_password(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $operator = User::factory()->create();

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('edit')->table($operator), data: [
                'role' => 'admin',
                'password' => 'password-nuova-admin',
                'password_confirmation' => 'password-nuova-admin',
            ])
            ->assertHasNoFormErrors();

        $this->assertTrue($operator->refresh()->isAdministrator());
        $this->assertTrue(Hash::check('password-nuova-admin', $operator->password));
    }

    public function test_invalid_role_duplicate_email_and_short_password_are_rejected(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin);

        Livewire::test(ManageUsers::class)
            ->callAction('create', data: [
                'name' => 'Test',
                'email' => $admin->email,
                'role' => 'superadmin',
                'is_active' => true,
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertHasFormErrors(['email' => 'unique', 'role' => 'in', 'password']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_operators_cannot_open_user_management_or_mount_it_through_livewire(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk()->assertDontSee('href="http://localhost/admin/utenti"', false);
        $this->get('/admin/utenti')->assertForbidden();
        Livewire::test(ManageUsers::class)->assertForbidden();

        $this->assertFalse(Gate::allows('create', User::class));
        $this->assertFalse(Gate::allows('update', User::factory()->administrator()->create()));
    }

    public function test_a_mounted_create_action_rechecks_authorization_after_role_changes(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin);

        $page = Livewire::test(ManageUsers::class)
            ->mountAction('create')
            ->fillForm([
                'name' => 'Non consentito',
                'email' => 'non-consentito@example.test',
                'role' => 'admin',
                'is_active' => true,
                'password' => 'password-non-consentita',
                'password_confirmation' => 'password-non-consentita',
            ]);

        $admin->update(['role' => 'operator']);
        $this->actingAs($admin->fresh());

        $page->call('callMountedAction')->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'non-consentito@example.test']);
    }

    public function test_a_mounted_edit_action_rechecks_authorization_after_disable(): void
    {
        $admin = User::factory()->administrator()->create();
        $operator = User::factory()->create();
        $this->actingAs($admin);

        $page = Livewire::test(ManageUsers::class)
            ->mountAction(TestAction::make('edit')->table($operator))
            ->fillForm(['role' => 'admin']);

        $admin->update(['is_active' => false]);
        $this->actingAs($admin->fresh());

        $page->call('callMountedAction')->assertForbidden();
        $this->assertSame('operator', $operator->refresh()->role);
    }

    public function test_an_administrator_cannot_disable_or_demote_self_or_delete_users(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin);

        Livewire::test(ManageUsers::class)
            ->assertActionHidden(TestAction::make('edit')->table($admin))
            ->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => $admin->id])
            ->assertActionNotMounted();

        $this->assertFalse(Gate::allows('update', $admin));
        $this->assertFalse(Gate::allows('delete', $admin));
        $this->assertFalse(Gate::allows('deleteAny', User::class));
        $this->assertTrue($admin->refresh()->isAdministrator());
    }

    public function test_the_native_profile_changes_password_without_allowing_role_or_status_tampering(): void
    {
        $operator = User::factory()->create();
        $this->actingAs($operator);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'name' => 'Nome personale',
                'password' => 'password-personale-nuova',
                'passwordConfirmation' => 'password-personale-nuova',
                'currentPassword' => 'password',
            ])
            ->set('data.role', 'admin')
            ->set('data.is_active', false)
            ->call('save')
            ->assertHasNoFormErrors();

        $operator->refresh();
        $this->assertSame('Nome personale', $operator->name);
        $this->assertTrue(Hash::check('password-personale-nuova', $operator->password));
        $this->assertSame('operator', $operator->role);
        $this->assertTrue($operator->is_active);
    }

    public function test_the_native_profile_requires_the_current_password_before_changing_it(): void
    {
        $operator = User::factory()->create();
        $this->actingAs($operator);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'password-personale-nuova',
                'passwordConfirmation' => 'password-personale-nuova',
                'currentPassword' => 'errata',
            ])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->assertTrue(Hash::check('password', $operator->refresh()->password));
    }

    public function test_bootstrap_promotes_only_an_existing_active_user_and_only_without_an_admin(): void
    {
        $this->artisan('bitlicense:bootstrap-admin', ['email' => 'missing@example.test'])->assertFailed();
        $disabled = User::factory()->create(['is_active' => false]);
        $this->artisan('bitlicense:bootstrap-admin', ['email' => $disabled->email])->assertFailed();
        $this->assertSame('operator', $disabled->refresh()->role);
        $operator = User::factory()->create();
        $this->assertSame('operator', $operator->role);

        $this->artisan('bitlicense:bootstrap-admin', ['email' => $operator->email])->assertSuccessful();
        $this->assertTrue($operator->refresh()->isAdministrator());

        $another = User::factory()->create();
        $this->artisan('bitlicense:bootstrap-admin', ['email' => $another->email])->assertFailed();
        $this->assertSame('operator', $another->refresh()->role);
    }
}
