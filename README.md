# BitLicense

Applicazione italiana per seguire le scadenze Bitdefender: scegli l’azienda,
clicchi un computer, modifichi le date e salvi. La scadenza tecnica aziendale
GravityZone resta distinta dalla scadenza commerciale locale di ogni PC.

L’issue #26 sostituisce il precedente flusso a quattro entità con **Azienda +
Computer** e una sola vista operativa **Licenze**. Le #26 e #17 sono integrate;
la #30 aggiunge la **Dashboard** separata richiesta dal committente, in Blade.
Le issue #10–#15 restano
sospese: il loro perimetro va riesaminato con il committente.
La #16 resta chiusa; nessun rilascio in produzione è previsto.

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
L'amministratore crea, modifica e disabilita gli altri utenti dalla scheda **Utenti** della pagina **Impostazioni**;
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

Accedere come amministratore e aprire **Impostazioni** (`/admin/impostazioni`),
poi la scheda **API** (`/admin/impostazioni/gravityzone`). Inserire l'URL base API della propria console,
fino a `/api`, e la chiave nel campo protetto; premere **Salva configurazione**.
Il salvataggio non effettua richieste API. La chiave è cifrata a riposo,
non viene ricaricata nel browser e il campo viene svuotato dopo ogni risposta.
Lasciarlo vuoto in un salvataggio successivo conserva la chiave esistente.

**Verifica connessione** richiede una conferma ed esegue esclusivamente
la lettura `getCompaniesList` con la configurazione già salvata. Non importa
dati e non modifica GravityZone. La scheda **Utenti** (`/admin/impostazioni/utenti`) è nella stessa pagina.
Gli operatori non accedono a Impostazioni né alle sue schede.
Le credenziali non vanno comunicate in chat o inserite nei file del progetto.
Il collaudo reale richiede l'inserimento della chiave da parte del committente
e il suo comando esplicito di ripresa. Fino ad allora sono usate soltanto fixture
documentali, con i limiti descritti in [docs/gravityzone-api.md](docs/gravityzone-api.md).

### Licenze: un’unica vista operativa

Dopo il login si apre **Licenze** (`/admin`). Il selettore ricercabile permette
di scegliere un’azienda; la stessa tabella mostra il riepilogo della sua licenza
tecnica GravityZone e i computer con prodotto aziendale da API, attivazione, scadenza commerciale
e stato. Il riepilogo tecnico resta separato dalle date locali delle righe PC.
**Tutte le Aziende** mostra una tabella distinta per ogni azienda, in ordine
alfabetico, con nome, riepilogo e controlli propri. Il pulsante **Aggiungi PC
manuale** di ciascuna tabella usa quella azienda.

Il filtro **Licenza**, accanto ad Azienda, mostra tutte le aziende con il
prodotto aziendale GravityZone selezionato e restringe le opzioni del selettore
aziendale. Svuotandolo si torna a tutte le licenze, anche quelle sconosciute.

Un nuovo computer GravityZone compare automaticamente come **Da completare**.
La matita o il clic sulla riga apre la modale di modifica, senza espansioni. Nome tecnico e prodotto aziendale
sono in sola lettura; date, prezzo, riferimenti fattura/preventivo e note sono
modificabili e facoltativi. **Salva** aggiorna direttamente quel computer.
La colonna **Licenza** mostra sempre il prodotto aziendale GravityZone per
ogni PC, anche nelle tabelle di **Tutte le Aziende**; se manca, compare
**Non fornito dall’API**. Il tooltip chiarisce che è la licenza aziendale.
La colonna **Validità** mostra attivazione sopra e scadenza sotto. **Prezzo**
resta visibile; **Rif. Preventivo** e **Rif. Fattura** compaiono solo quando
almeno un computer dell’azienda ha il rispettivo valore. Le icone di filtri e
colonne sono accanto alla ricerca, allineata a sinistra. Il gestore colonne
permette di riordinare e scegliere le informazioni aggiuntive.
Non occorre creare postazioni, prodotti di catalogo o coperture separate.
Il prodotto viene aggiornato dalla lettura della licenza aziendale già prevista;
se GravityZone non lo espone, compare **Non fornito dall’API**. Eventuali
annotazioni prodotto precedenti restano conservate e visibili nella modale.

**Aggiungi PC manuale** opera nella stessa pagina e richiede soltanto il nome.
L’azienda è quella selezionata. Il menu contestuale della riga consente di
collegare esplicitamente un PC manuale a una rilevazione GravityZone e di
trasferire una licenza pagata a un sostituto della stessa azienda. Nessuna di
queste azioni modifica GravityZone. Lo storico essenziale si consulta nella
modale del computer. Date, importi, vincoli e casi limite sono descritti in
[docs/licenses.md](docs/licenses.md).

Lo stato e i filtri distinguono **Da completare**, **Scaduta**, **In scadenza**
entro 30 giorni, **Attiva** e **Da attivare**. La scadenza odierna è ancora
valida. Il caricamento della pagina e i filtri leggono soltanto il database
locale: non inviano email, non sincronizzano e non creano notifiche duplicate.
Non esistono scadenzari o archivi commerciali paralleli.

### Dashboard

La voce **Dashboard** (`/admin/dashboard`) offre una panoramica in sola lettura
ad amministratori e operatori attivi. La hero usa glow e animazione CSS,
disattivata con la preferenza di sistema per il movimento ridotto, senza pulsante pausa.
Statistiche, sezioni e badge usano i componenti nativi Filament; fuori dalla hero
la dashboard segue la palette del pannello, anche per i grafici.
Non richiede build frontend, librerie grafiche o chiamate esterne.

Il selettore **In Scadenza** nell’elenco offre scadenze entro 15, 30 e 60
giorni, 3 mesi, 6 mesi e 1 anno, oltre a **Scadute** e **Tutte le aziende**
(anche con data sconosciuta). Oggi e il giorno finale sono inclusi; mesi e anni
sono di calendario, senza oltrepassare l’ultimo giorno del mese quando manca
il giorno corrispondente. La vista iniziale mostra le aziende in scadenza entro
30 giorni. La ricerca per nome riguarda solo l’elenco.
I contatori aziendali usano la **scadenza tecnica GravityZone**: quello delle
aziende in scadenza segue il periodo aziendale selezionato, oppure 30 giorni
quando si mostrano scadute o tutte le aziende. I record non più rilevati
restano segnalati. I contatori aprono l’elenco corrispondente; il clic
sull’azienda apre Licenze con quella selezione (`/admin?company=ID`).
Per i mensili la data aziendale è la fine abbonamento `endSubscription`,
visibile con l’etichetta **Fine abbonamento** nel riepilogo di Licenze; se manca, è
**Non disponibile**. Gli altri tipi usano `expiryDate`. La distinzione
riguarda soltanto la scadenza aziendale letta da GravityZone.
Il totale computer apre tutte le aziende. I badge aziendali mostrano la distanza
in giorni, poi in mesi e anni interi, con la data esatta nel tooltip.

I grafici descrivono invece i **computer e le date commerciali locali**.
Cliccando uno stato si apre Licenze con quel filtro: «In scadenza» mantiene la
definizione operativa a 30 giorni, indipendentemente dal periodo scelto.
Il grafico mensile ha un selettore autonomo nella propria intestazione, con
gli stessi sei intervalli. Il suo periodo non modifica l’elenco aziende o i
contatori tecnici. Include le scadenze note anche
senza attivazione; questi PC restano «Da completare» nel grafico degli stati.
Ogni barra apre Licenze con le date del mese limitate al periodo scelto.
Le tabelle mostrano il filtro applicato, modificabile o rimovibile da Filament.
Non sono proiezioni di rinnovo. Date e limiti usano Europe/Rome.
L’apertura non sincronizza, invia email o modifica dati.

### Sincronizzazione in sola lettura

**Aggiorna da GravityZone**, riservato agli amministratori con conferma,
legge prima aziende e licenze tecniche, poi l’inventario computer. Le chiavi
richiedono i permessi di lettura Network e Licensing. Gli operatori possono
modificare i dati locali ma non avviare questa lettura né accedere alle credenziali.

Se l’account vede dei partner, l’importazione segue anche i loro clienti e gli
eventuali partner annidati per cui è condivisa la gestione della sicurezza.
Ogni cliente compare come azienda distinta, con i propri PC. Un partner non
condiviso resta visibile con un avviso; i suoi clienti e il suo inventario
non sono leggibili con quell’accesso. Un errore durante la scoperta conserva
i clienti precedenti invece di dichiararli assenti.

Il sincronizzatore conserva date, prodotto, prezzo, riferimenti, note e storico
locali. Riconosce i computer dall’ID GravityZone, mai dal nome. Rinomina, assenza
e ricomparsa aggiornano i soli dati remoti. Un ID nuovo dopo reinstallazione
rimane distinto fino a una scelta esplicita dell’operatore. Uno spostamento
remoto di azienda conserva l’azienda locale e viene segnalato per verifica.
I contatti aziendali già memorizzati restano protetti dalla sincronizzazione;
questa issue non introduce un CRM o una nuova gestione contatti.

Gli errori di lettura e gli stati disponibili sono mostrati nel riepilogo
aziendale. I dati precedenti restano conservati; l’inventario non viene
applicato parzialmente se una sua pagina fallisce. Lo stesso processo è
accessibile con i comandi separati:

```sh
php artisan bitlicense:sync-companies
php artisan bitlicense:sync-endpoints
php artisan schedule:list
```

I comandi restituiscono un codice di errore se il rispettivo import non è
completo. Un lock condiviso impedisce sovrapposizioni fra import aziende e
computer; dopo un’interruzione anomala può restare occupato fino a un’ora.
Licenze e inventario sono letti con pause di almeno 250 ms. Un 429 interrompe
l’import e applica un’attesa condivisa anche a nuovi clic e comandi, secondo
`Retry-After` (60 secondi se assente/non valido), senza retry automatici.
Le pianificazioni native restano alle 03:00 per le aziende e alle 04:00 per
l’inventario, nel fuso Europe/Rome. Nessun Cron viene installato automaticamente.

Solo dopo l’autorizzazione ai collegamenti reali, in sviluppo si può usare
`php artisan schedule:work`, oppure un Cron che esegua ogni minuto
`php /percorso/BitLicense/artisan schedule:run` come utente dell’applicazione.
Le letture sono sincrone: per inventari ampi preferire la CLI e verificare i
limiti di durata PHP. Non servono queue worker, Redis o nuovi servizi.
Contratto API, fixture e verifiche storiche sono descritti in
[docs/gravityzone-api.md](docs/gravityzone-api.md).

### Aggiornamento dello schema locale per #26

Il nuovo schema contiene una sola tabella `computers` al posto di endpoint,
postazioni, coperture e assegnazioni. Le migration obsolete sono eliminate;
non è previsto un percorso conservativo per il vecchio schema sacrificabile.

Il committente ha autorizzato l’eliminazione **senza backup del solo database
locale di sviluppo**. Prima di azzerarlo verificare la connessione effettiva,
il database e l’host/socket, inclusi eventuali `DB_URL` o configurazioni in cache.
Se il database è remoto, di produzione o non identificabile, fermarsi e chiedere.
Solo dopo tale verifica, `php artisan migrate:fresh` ricrea lo schema locale.
L’operazione elimina anche utenti e impostazioni locali: ripetere il normale
bootstrap amministratore e inserire la chiave dal pannello protetto. Non
trasferire credenziali in chat, comandi o file versionati. Riprendere i test
live soltanto dopo l’intervento e l’autorizzazione esplicita del committente.
Questa autorizzazione non riguarda altri database, file o dati GravityZone.

### Controlli automatici

```sh
composer validate --strict
composer check-platform-reqs
php artisan test
vendor/bin/pint --test
```

I test coprono autenticazione, ruoli, gestione utenti e accessi disabilitati,
il percorso Licenze e i suoi vincoli, date/importi facoltativi, storico,
trasferimento, riconciliazione, salvataggi obsoleti e sync simulato.
I test con fixture non costituiscono un nuovo collaudo live del refactor #26.
Le migrazioni e la navigazione vanno verificate
anche sul database MySQL/MariaDB di sviluppo; SQLite non sostituisce il collaudo
del database destinato all'applicazione.

Esiti, anteprime e limiti della verifica #17 sono riepilogati in
[docs/development-check.md](docs/development-check.md).

Lo sviluppo segue `AGENTS.md` e le issue GitHub. La distribuzione in produzione
è esclusa da questa fase.
