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

### Configurazione GravityZone

Accedere come amministratore e aprire **Integrazione GravityZone**
(`/admin/gravityzone`). Inserire l'URL base API della propria console,
fino a `/api`, e la chiave nel campo protetto; premere **Salva configurazione**.
Il salvataggio non effettua richieste API. La chiave è cifrata a riposo,
non viene ricaricata nel browser e il campo viene svuotato dopo ogni risposta.
Lasciarlo vuoto in un salvataggio successivo conserva la chiave esistente.

**Verifica connessione** richiede una conferma ed esegue esclusivamente
la lettura `getCompaniesList` con la configurazione già salvata. Non importa
dati e non modifica GravityZone. Gli operatori non accedono a queste impostazioni.
Le credenziali non vanno comunicate in chat o inserite nei file del progetto.
Il collaudo reale richiede l'inserimento della chiave da parte del committente
e il suo comando esplicito di ripresa. Fino ad allora sono usate soltanto fixture
documentali, con i limiti descritti in [docs/gravityzone-api.md](docs/gravityzone-api.md).

### Aziende e sincronizzazione

La voce **Aziende** (`/admin/aziende`) mostra aziende, scadenza tecnica della
licenza principale e stato della lettura. Amministratori e operatori possono
cercare le aziende e modificare contatti e note locali. Solo gli amministratori
avviano **Sincronizza aziende**, con conferma esplicita. Il caricamento della
pagina non esegue chiamate API. Le scadenze mancanti sono indicate come non
disponibili; i dati precedenti restano visibili con un avviso se la lettura fallisce.
Le aziende assenti dal nuovo elenco vengono conservate con il relativo stato.
Nessuna sincronizzazione crea coperture commerciali o invia email.

Lo stesso processo è disponibile da CLI:

```sh
php artisan bitlicense:sync-companies
php artisan schedule:list
```

Il comando restituisce un codice di errore anche se il solo elenco è stato
aggiornato e alcune licenze non sono state lette. Il riepilogo del pannello e
il dettaglio azienda indicano il risultato. La chiave richiede i permessi di
lettura Network e Licensing. Le esecuzioni contemporanee vengono rifiutate;
un’interruzione anomala può lasciare il lock occupato per un massimo di un’ora.

La pianificazione nativa è giornaliera alle 03:00 Europe/Rome. In sviluppo
si può usare `php artisan schedule:work`; per un ambiente di sviluppo con Cron
la voce standard esegue ogni minuto `php /percorso/BitLicense/artisan schedule:run`,
come utente dell’applicazione. Nessun Cron è stato installato automaticamente.
Le chiamate sono sincrone: per elenchi molto più grandi delle 16 aziende collaudate,
preferire il comando CLI e verificare i limiti di durata PHP del proprio ambiente.
Non sono necessari queue worker, servizi permanenti o dipendenze aggiuntive.

### Endpoint e postazioni locali

**Endpoint GravityZone** (`/admin/endpoint`) è l’elenco tecnico importato.
**Sincronizza endpoint**, riservato agli amministratori, legge i computer,
le macchine virtuali e le istanze EC2 delle aziende già importate, compresi
i gruppi Active Directory. I nuovi endpoint restano **Da verificare**;
non vengono create postazioni locali o coperture commerciali automaticamente.

Da **Postazioni** (`/admin/postazioni`) entrambi i ruoli possono creare una
postazione manuale indicando azienda, nome locale e note. Dall’endpoint,
**Associa postazione** richiede la scelta esplicita di una postazione libera
della stessa azienda: gli ID locali distinguono anche i nomi uguali.
**Crea postazione** consente invece una creazione esplicita già collegata.
I nomi tecnici possono cambiare durante il sync; quelli locali restano invariati.
Un nuovo ID dopo reinstallazione resta un endpoint distinto da verificare.

Endpoint non più rilevati, postazioni e associazioni vengono conservati.
Se GravityZone sposta un endpoint a un’altra azienda, la postazione mantiene
l’azienda locale e viene segnalata l’associazione da verificare. Non sono
previsti merge incerti, eliminazioni o riassegnazioni automatiche.
Gli errori sono visibili globalmente e per azienda, anche senza endpoint.

```sh
php artisan bitlicense:sync-endpoints
```

Scheduler pianifica gli endpoint alle 04:00 Europe/Rome. Un lock condiviso
impedisce sovrapposizioni con l’import aziende. Cron non è stato attivato.
La paginazione e Active Directory sono descritti nel contratto API;
la continuazione su più pagine è verificata con fixture documentali.

### Coperture commerciali

**Coperture commerciali** (`/admin/coperture`) consente a entrambi i ruoli
attivi di registrare periodi e importi per una o più postazioni della stessa
azienda. L’importo è applicato a ciascuna postazione; la creazione multipla
è interamente annullata se una delle selezioni non è valida.

La fine è l’ultimo giorno coperto, incluso. Non sono ammesse sovrapposizioni;
le date non vengono ricavate da GravityZone. **Periodo e storico** mostra
separatamente scadenza tecnica aziendale, copertura acquistata e assegnazioni.
**Correggi copertura** aggiorna esplicitamente date, importo e riferimenti;
**Trasferisci a un altro PC** conserva il periodo acquistato e registra
il passaggio alla postazione sostitutiva. I periodi precedenti non vengono
sostituiti automaticamente e le coperture non vengono eliminate dal pannello.

Importi, estremi inclusivi, atomicità e storico sono documentati in
[docs/commercial-coverages.md](docs/commercial-coverages.md).

### Schede commerciali e prodotti

La scheda **Aziende** conserva anche i prodotti/edizioni descritti localmente,
con il prezzo annuale abituale specifico di quell’azienda. Gli add-on sono
compresi nel prezzo totale. Il contratto API verificato per l’inventario non
identifica l’edizione commerciale acquistata per ciascun endpoint: il prodotto
viene quindi scelto o descritto manualmente, senza dedurlo dalla piattaforma
tecnica o dal tipo di licenza aziendale.

Nella scheda **Postazioni** si possono indicare prodotto aziendale, descrizione
commerciale, data di installazione/attivazione e note anche senza endpoint
GravityZone. I dati tecnici restano in sola lettura, separati dai periodi,
dagli importi e dai riferimenti fattura/preventivo delle coperture.

Se nella nuova copertura tutte le postazioni selezionate hanno lo stesso
prodotto aziendale, viene proposto il suo prezzo annuale abituale. L’operatore
controlla il periodo e può modificare l’importo per ogni nuova copertura;
con prodotti diversi o non indicati deve inserirlo manualmente. Il suggerimento
non calcola ancora un pro-rata e non cambia coperture già registrate.
Cambiare il prezzo abituale o il prodotto di una postazione non riscrive
gli importi o i riferimenti degli acquisti precedenti.

### Scadenzario e avvisi interni

**Panoramica** raccoglie gli avvisi correnti distinguendo licenze tecniche
aziendali, coperture commerciali delle postazioni ed endpoint da verificare.
I filtri permettono di scegliere azienda, tipo, stato e periodo. La scadenza
odierna è ancora coperta; gli avvisi di prossima scadenza considerano i 30 giorni
successivi. Le date mancanti restano segnalate separatamente, senza inventarle.

I periodi commerciali contigui già acquistati vengono letti come un intervallo
continuo: un vecchio periodo rinnovato non continua a comparire scaduto.
Una copertura futura separata da un intervallo scoperto non nasconde il problema;
la data indicata in quel caso è il prossimo inizio, spiegato nella riga.

Gli avvisi sono calcolati dai dati locali e aggiornati con il caricamento della
pagina o **Aggiorna avvisi**. Non vengono accodate copie di notifiche nel database,
eseguite chiamate API o inviate email ai clienti. Non servono un nuovo Cron,
polling o worker. L’elenco non crea proposte e non conferma rinnovi automaticamente.

### Controlli automatici

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
