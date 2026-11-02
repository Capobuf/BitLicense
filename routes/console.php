<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

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
