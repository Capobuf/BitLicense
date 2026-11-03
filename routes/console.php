<?php

use App\Integrations\GravityZone\CompanySynchronizer;
use App\Integrations\GravityZone\EndpointSynchronizer;
use App\Integrations\GravityZone\GravityZoneException;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
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
    try {
        $count = app(CompanySynchronizer::class)->sync();
        $this->info("Sincronizzazione completata. Aziende presenti: {$count}. Nessuna modifica a GravityZone.");

        return 0;
    } catch (GravityZoneException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }
})->purpose('Legge aziende e licenze tecniche GravityZone preservando contatti e note locali');

Schedule::command('bitlicense:sync-companies')->dailyAt('03:00')->timezone('Europe/Rome');

Artisan::command('bitlicense:sync-endpoints', function () {
    try {
        $count = app(EndpointSynchronizer::class)->sync();
        $this->info("Import completato. Endpoint rilevati: {$count}. Date commerciali locali conservate.");

        return 0;
    } catch (GravityZoneException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }
})->purpose('Importa i computer GravityZone preservando date e dati commerciali locali');

Schedule::command('bitlicense:sync-endpoints')->dailyAt('04:00')->timezone('Europe/Rome');
