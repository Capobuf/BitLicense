# BitLicense

Applicazione per seguire scadenze e rinnovi Bitdefender. La scadenza tecnica
dell'azienda in GravityZone è distinta dalla copertura commerciale di ogni PC.

## Ambiente di sviluppo

Servono PHP 8.3+, Composer 2 e MySQL 8+ o MariaDB compatibile con Laravel 13.
Estensioni PHP: ctype, curl, dom, fileinfo, filter, hash, intl, mbstring,
openssl, pcre, PDO con pdo_mysql, session, tokenizer, xml e zip.
Per i test automatici è usato anche pdo_sqlite.
`composer check-platform-reqs` verifica i requisiti effettivi delle dipendenze.

Non servono Node.js, npm, Vite, Docker, Redis o worker. Gli asset già compilati
di Filament vengono pubblicati automaticamente dagli script Composer.

Preparare un database vuoto dedicato allo sviluppo e un utente che possa
eseguire le migrazioni su quel solo database, poi:

```sh
cp .env.example .env
composer install
```

Impostare in `.env` le credenziali `DB_*` e l'URL locale `APP_URL`.
Se MySQL usa un socket dedicato, aggiungere `DB_SOCKET=/percorso/mysql.sock`.
Non usare credenziali o database di produzione.

```sh
php artisan key:generate
php artisan migrate
php artisan make:filament-user
php artisan bitlicense:bootstrap-admin indirizzo-scelto@example.test
php artisan serve --host=127.0.0.1 --port=8000
```

Aprire http://127.0.0.1:8000/admin e accedere con l'utente appena creato.
Sostituire l'indirizzo di esempio con quello inserito durante `make:filament-user`;
inserire la password al prompt, senza passarla come argomento di shell.
Il comando di bootstrap promuove solo un account attivo esistente e funziona
finché non esiste un amministratore attivo. Gli account nascono come operatori.
L'amministratore crea, modifica e disabilita gli altri utenti dalla voce **Utenti**;
le password iniziali sono impostate manualmente, senza email d'invito.
Ogni utente può aggiornare i propri dati e password dal profilo personale.
Il proprio ruolo e stato non sono modificabili dal pannello, per evitare di
perdere accidentalmente l'ultimo accesso amministrativo. La disabilitazione
blocca anche le sessioni già aperte alla richiesta successiva.
Per un server di sviluppo remoto usare un inoltro SSH verso la porta locale.
`composer dev` avvia lo stesso server sulla porta predefinita, senza altri processi.

Il pannello è in italiano, parte in modalità scura e usa il fuso Europe/Rome.
Non vengono creati utenti dimostrativi con il seeding. Non è prevista
registrazione pubblica. Le email sono configurate sul trasporto `log` in sviluppo;
non viene contattato alcun server SMTP. Non configurare credenziali reali
GravityZone o SMTP prima che sia disponibile il relativo pannello protetto.

## Verifiche

```sh
composer validate --strict
composer check-platform-reqs
php artisan test
vendor/bin/pint --test
```

I test del pannello verificano autenticazione, rifiuto di credenziali errate,
logout, ruoli, gestione utenti e rifiuto degli accessi disabilitati.
Le migrazioni e la navigazione vanno verificate
anche sul database MySQL/MariaDB di sviluppo; SQLite non sostituisce il collaudo
del database destinato all'applicazione.

Lo sviluppo segue `AGENTS.md` e le issue GitHub. La distribuzione in produzione
è esclusa da questa fase.
