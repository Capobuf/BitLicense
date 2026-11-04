<?php

use App\Integrations\GravityZone\CompanySynchronizer;
use App\Integrations\GravityZone\EndpointSynchronizer;
use App\Integrations\GravityZone\GravityZoneException;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('bitlicense:bootstrap-admin {email}', function () {
    if (User::query()->where('role', 'admin')->where('is_active', true)->exists()) {
        $this->error('Esiste già un amministratore attivo. Usa la gestione utenti del pannello.');

        return 1;
    }

    $user = User::query()->where('email', $this->argument('email'))->where('is_active', true)->first();

    if (! $user) {
        $this->error('Utente attivo non trovato. Crealo prima con make:filament-user.');

        return 1;
    }

    $user->update(['role' => 'admin']);
    $this->info('Primo amministratore abilitato.');

    return 0;
})->purpose('Abilita il primo amministratore a partire da un utente già creato');

Artisan::command('bitlicense:sync-companies', function () {
    $lock = Cache::lock('gravityzone-refresh', 3600);
    if (! $lock->get()) {
        $this->error('Un aggiornamento GravityZone è già in corso.');

        return 1;
    }

    try {
        $count = app(CompanySynchronizer::class)->sync();
        $this->info("Sincronizzazione completata. Aziende presenti: {$count}. Nessuna modifica a GravityZone.");

        return 0;
    } catch (GravityZoneException $exception) {
        $this->error($exception->getMessage());

        return 1;
    } finally {
        $lock->release();
    }
})->purpose('Legge aziende e licenze tecniche GravityZone preservando contatti e note locali');

Artisan::command('bitlicense:sync-endpoints', function () {
    $lock = Cache::lock('gravityzone-refresh', 3600);
    if (! $lock->get()) {
        $this->error('Un aggiornamento GravityZone è già in corso.');

        return 1;
    }

    try {
        $count = app(EndpointSynchronizer::class)->sync();
        $this->info("Import completato. Endpoint rilevati: {$count}. Date commerciali locali conservate.");

        return 0;
    } catch (GravityZoneException $exception) {
        $this->error($exception->getMessage());

        return 1;
    } finally {
        $lock->release();
    }
})->purpose('Importa i computer GravityZone preservando date e dati commerciali locali');

Artisan::command('bitlicense:sync', function () {
    $lock = Cache::lock('gravityzone-refresh', 3600);
    if (! $lock->get()) {
        $this->info('Un aggiornamento GravityZone è già in corso.');

        return 0;
    }

    try {
        Cache::forget('gravityzone-sync-requested');
        app(CompanySynchronizer::class)->sync();
        app(EndpointSynchronizer::class)->sync();
        $this->info('Aggiornamento GravityZone completato.');

        return 0;
    } catch (GravityZoneException $exception) {
        $this->error($exception->getMessage());

        return 1;
    } finally {
        $lock->release();
    }
})->purpose('Aggiorna aziende e computer GravityZone in sequenza');

Schedule::command('bitlicense:sync')->everyMinute()->timezone('Europe/Rome')
    ->when(fn (): bool => now('Europe/Rome')->format('H:i') === '03:00' || Cache::has('gravityzone-sync-requested'))
    ->withoutOverlapping(60);

Artisan::command('bitlicense:detect-sales', function () {
    $count = 0;
    foreach (Company::orderBy('id')->lazyById() as $company) {
        $count += $company->initializeDetectedSales();
    }
    $this->info("Prime vendite collegate a {$count} computer usando i primi rilevamenti già salvati. Nessuna chiamata GravityZone.");
})->purpose('Raggruppa le prime vendite per azienda e mese dai dati locali GravityZone');
