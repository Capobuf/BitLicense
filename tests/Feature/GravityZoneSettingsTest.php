<?php

namespace Tests\Feature;

use App\Filament\Pages\GravityZoneSettings;
use App\Models\GravityZoneSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class GravityZoneSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_KEY = 'fixture-key-not-a-real-credential';

    private const API_URL = 'https://cloud.gravityzone.bitdefender.com/api';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->administrator()->create());
    }

    public function test_saving_encrypts_the_key_without_contacting_gravityzone_or_returning_the_key(): void
    {
        $page = $this->submit(self::API_URL.'/', self::TEST_KEY)->assertHasNoFormErrors();

        $settings = GravityZoneSetting::findOrFail(1);
        $this->assertSame(self::API_URL, $settings->api_url);
        $this->assertSame(self::TEST_KEY, $settings->api_key);
        $this->assertNotSame(self::TEST_KEY, DB::table('gravity_zone_settings')->value('api_key'));
        $this->assertArrayNotHasKey('api_key', $settings->toArray());
        $this->assertSecretAbsent($page);
        $this->assertSecretAbsent(Livewire::test(GravityZoneSettings::class));
        Http::assertNothingSent();
    }

    public function test_blank_key_preserves_it_and_a_replacement_updates_the_single_configuration(): void
    {
        $this->submit(self::API_URL, self::TEST_KEY)->assertHasNoFormErrors();
        $this->submit(self::API_URL, '')->assertHasNoFormErrors();
        $this->assertSame(self::TEST_KEY, GravityZoneSetting::findOrFail(1)->api_key);

        $this->submit(self::API_URL, 'replacement-fixture-key')->assertHasNoFormErrors();
        $this->assertSame('replacement-fixture-key', GravityZoneSetting::findOrFail(1)->api_key);
        $this->assertDatabaseCount('gravity_zone_settings', 1);
        Http::assertNothingSent();
    }

    public function test_validation_and_other_responses_also_clear_the_transient_key(): void
    {
        foreach (['http://example.test/api', 'https://example.test/api?key=bad', 'https://example.test/network'] as $url) {
            $page = $this->submit($url, self::TEST_KEY)->assertHasFormErrors(['api_url']);
            $this->assertSecretAbsent($page);
        }

        $page = Livewire::test(GravityZoneSettings::class)->set('data.api_key', self::TEST_KEY);
        $this->assertSecretAbsent($page);
        $this->assertDatabaseCount('gravity_zone_settings', 0);
        Http::assertNothingSent();
    }

    public function test_first_configuration_requires_a_key(): void
    {
        $this->submit(self::API_URL, '')->assertHasFormErrors(['api_key']);
        $this->assertDatabaseCount('gravity_zone_settings', 0);
    }

    public function test_operators_and_disabled_users_cannot_access_the_page(): void
    {
        foreach ([['role' => 'operator'], ['role' => 'admin', 'is_active' => false]] as $state) {
            $this->actingAs(User::factory()->create($state));
            $this->get('/admin/impostazioni/gravityzone')->assertForbidden();
            Livewire::test(GravityZoneSettings::class)->assertForbidden();
        }

        Http::assertNothingSent();
    }

    public function test_actions_recheck_authorization_after_an_administrator_loses_permissions(): void
    {
        foreach (['save', 'testConnection'] as $action) {
            $admin = User::factory()->administrator()->create();
            $this->actingAs($admin);
            $page = Livewire::test(GravityZoneSettings::class);
            $admin->update(['role' => 'operator']);
            $this->actingAs($admin->fresh());
            $page->call($action)->assertForbidden();
        }
        $this->assertDatabaseCount('gravity_zone_settings', 0);
        Http::assertNothingSent();
    }

    public function test_connecting_requires_saved_configuration_and_is_an_explicit_read_only_action(): void
    {
        Livewire::test(GravityZoneSettings::class)->call('testConnection');
        Http::assertNothingSent();
        $this->submit(self::API_URL, self::TEST_KEY)->assertHasNoFormErrors();
        Http::assertNothingSent();

        Http::fake(['*' => Http::response(json_decode(file_get_contents(
            base_path('tests/Fixtures/GravityZone/companies-list.json')
        ), true))]);

        $page = Livewire::test(GravityZoneSettings::class)->call('testConnection');
        $this->assertSecretAbsent($page);
        $page->assertNotified('Connessione verificata');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['method'] === 'getCompaniesList');
    }

    private function submit(string $url, string $key): Testable
    {
        // Match a browser submit: deferred fields and the action share one request.
        return Livewire::test(GravityZoneSettings::class)->update(
            calls: [['method' => 'save', 'params' => [], 'path' => '']],
            updates: ['data.api_url' => $url, 'data.api_key' => $key],
        );
    }

    private function assertSecretAbsent(Testable $page): void
    {
        $page->assertSet('data.api_key', '')->assertDontSee(self::TEST_KEY);
        $this->assertStringNotContainsString(self::TEST_KEY, json_encode($page->snapshot));
        $this->assertStringNotContainsString(self::TEST_KEY, json_encode($page->effects));
    }
}
