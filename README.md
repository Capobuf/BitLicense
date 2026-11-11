# BitLicense

Applicazione italiana per seguire le scadenze Bitdefender: scegli l’azienda,
clicchi un computer, modifichi le date e salvi. La scadenza tecnica aziendale
GravityZone resta distinta dalla scadenza commerciale locale di ogni PC.

L’issue #26 sostituisce il precedente flusso a quattro entità con **Azienda +
Computer** e una sola vista operativa **Licenze**. Le #26 e #17 sono integrate;
la #30 aggiunge la **Dashboard** separata richiesta dal committente, in Blade.
La #10, nel perimetro approvato il 04/11/2026, collega vendite, rinnovi e upgrade
ai periodi dei computer. Le #11–#15 restano sospese.
La #16 resta chiusa; il deploy su CloudPanel è descritto in fondo.

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

Dopo il login si apre **Licenze** (`/admin`). Accanto alla tabella, l’elenco
delle aziende è sempre visibile: ricerca immediata anche senza accenti, un
pallino per lo stato della licenza aziendale GravityZone (attiva, in scadenza
entro 30 giorni, scaduta o sospesa, non disponibile) e il numero di computer.
Un clic apre l’azienda e aggiorna l’URL (`/admin?company=ID`). La tabella mostra
il riepilogo della licenza tecnica GravityZone e i computer con prodotto
aziendale da API, attivazione, scadenza commerciale e stato. Il riepilogo
tecnico resta separato dalle date locali delle righe PC.

Sopra l’elenco, i pulsanti **Licenza** (Tutte e le licenze aziendali presenti)
restringono le aziende al prodotto GravityZone scelto. Se l’azienda aperta ha
quella licenza resta aperta; altrimenti si passa a **Tutte le aziende**.
Quando lo spazio non basta per elenco e tabella affiancati (schermi stretti,
mobile, o circa 1.600 px con la barra laterale espansa), l’elenco si apre da una
barra compatta sopra la tabella. La barra laterale del pannello si può ridurre
a icone per recuperare spazio.

**Tutte le aziende** mostra una sezione richiudibile per azienda: chiusa,
riporta prodotto, scadenza tecnica e contatori dei PC scaduti, in scadenza e
da completare; aperta, la tabella completa dell’azienda con riepilogo, controlli
e **Aggiungi PC manuale** propri. Le aziende con PC scaduti o in scadenza sono
in cima, poi le altre in ordine alfabetico. I link della Dashboard con stato o
date aprono soltanto le aziende con computer corrispondenti.

Un nuovo computer GravityZone compare automaticamente come **Da completare**.
La matita o il clic sulla riga apre la modale di modifica, senza espansioni. Nome tecnico e prodotto aziendale
sono in sola lettura. Per i computer ancora non associati, date e dati commerciali
sono facoltativi e modificabili. Per quelli collegati, il ciclo mostra vendita,
rinnovi e upgrade: date e riferimenti si correggono nell’operazione
tramite **Vendite e rinnovi**; nome manuale e note restano modificabili sul PC.
La colonna **Licenza** mostra sempre il prodotto aziendale GravityZone per
ogni PC, anche nelle tabelle di **Tutte le aziende**; se manca, compare
**Non fornito dall’API**. Il tooltip chiarisce che è la licenza aziendale.
La colonna **Stato licenza GZ** mostra lo stato tecnico del singolo PC
(licenza attiva, scaduta, autenticazione in attesa, assente/non applicabile),
con data dell’ultima lettura e indicazione degli aggiornamenti falliti.
È disponibile anche nella modale, in sola lettura. Non indica lo stato
complessivo della protezione e non viene dedotto dalle date commerciali.
I PC manuali sono **Non collegati**, quelli non gestiti **Non gestiti**;
dati assenti o codici sconosciuti restano **Non disponibili**.
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
Il filtro **Da rinnovare** esclude i computer con un periodo futuro già registrato.

### Vendite e rinnovi

Il primo rilevamento GravityZone identifica la prima vendita: raggruppamento
per azienda e mese/anno e date individuali conservate.
L'azione **Sposta PC** riunisce esplicitamente computer di mesi diversi nella
vendita corretta. Per applicare la regola ai primi rilevamenti già salvati,
eseguire dopo la migrazione `php artisan bitlicense:detect-sales`; non contatta
GravityZone ed è ripetibile. Le sincronizzazioni successive applicano la stessa
regola ai nuovi computer, senza annullare le correzioni manuali.

In **Aziende**, aprire **Vendite e rinnovi**; lo stesso registro è accessibile
dal nome azienda e dal computer in Licenze. Selezionare i PC, verificare i
periodi individuali oppure applicare date comuni. I rinnovi possono unire PC
provenienti da vendite diverse. La licenza è quella già assegnata al PC, mostrata
in sola lettura: non va inserito alcun prodotto. **Upgrade aziendale** acquisisce
la licenza assegnata e registra la differenza manuale per i residui, mantenendo
le scadenze e lo storico delle licenze registrate.

Il registro gestisce periodi, rinnovi e storico delle licenze. Importi e riferimenti
a fatture o preventivi restano semplici annotazioni: nessuno stato di fatturazione,
contatore di fatture da emettere o obbligo di inserire un riferimento. Le vecchie
registrazioni senza computer si completano con **Collega computer**, conservando
la stessa operazione.

Flusso, correzioni e limiti: [docs/company-renewals.md](docs/company-renewals.md).

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

L’aggiornamento automatico parte ogni giorno alle **03:00 Europe/Rome**,
legge prima aziende e licenze tecniche, poi l’inventario computer e lo stato licenza di ogni endpoint gestito.
Le pagine **Licenze** e **Aziende** hanno anche un’icona discreta
**Aggiorna da GravityZone**, riservata agli amministratori: registra la richiesta,
risponde subito e avvia l’aggiornamento appena la risposta è stata inviata
(`defer()` di Laravel, nello stesso processo PHP). Se l’avvio non riesce, il Cron
prende in carico la richiesta al minuto successivo; clic ripetuti vengono accorpati.
La conferma indica che l’aggiornamento è avviato, non che sia già terminato.
Dopo circa un minuto ricaricare la pagina per consultare i nuovi dati. Le chiavi
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
accessibile con il comando completo o con i comandi separati:

```sh
php artisan bitlicense:sync
php artisan bitlicense:sync-companies
php artisan bitlicense:sync-endpoints
php artisan schedule:list
```

I comandi restituiscono un codice di errore se il rispettivo import non è
completo. Un lock condiviso impedisce sovrapposizioni fra import aziende e
computer; dopo un’interruzione anomala può restare occupato fino a un’ora.
Le letture partono a round di richieste parallele, al massimo un round al secondo
per metodo: quattro per licenze aziendali e inventario (limite documentato cinque),
otto per dettagli aziendali e stato licenza dei PC (limite dieci). L’inventario di
ogni azienda resta letto pagina dopo pagina, ma più aziende avanzano insieme.
Le connessioni HTTPS sono riusate durante l’aggiornamento. Un 429 interrompe
l’import e applica un’attesa condivisa anche a nuovi clic e comandi, secondo
`Retry-After` (60 secondi se assente/non valido), senza retry automatici.
Il Scheduler controlla ogni minuto se sono le 03:00 oppure se esiste una
richiesta esplicita. Negli altri minuti non contatta GravityZone. Un solo
aggiornamento completo sostituisce le precedenti pianificazioni separate.
La richiesta è conservata nella cache database condivisa tra pannello e CLI;
un lock protegge il ciclo completo, inclusi gli avvii dei comandi separati.
In caso di errore non parte un ciclo di tentativi ogni minuto: rimangono
gli errori consultabili in Licenze e nel database, fino a un nuovo avvio
esplicito o al giro del giorno seguente.

Solo dopo l’autorizzazione ai collegamenti reali, in sviluppo si può usare
`php artisan schedule:work`, oppure un Cron che esegua ogni minuto
`php /percorso/BitLicense/artisan schedule:run` come utente dell’applicazione.
Il Cron resta la riserva per i pulsanti: senza di esso una richiesta il cui
avvio immediato non è riuscito resta in attesa. La lettura dello stato aggiunge
una richiesta per PC gestito. L’avvio immediato richiede PHP-FPM (o LiteSpeed),
che chiude la risposta prima di proseguire; il pool deve consentire qualche
minuto di esecuzione (`request_terminate_timeout`). Con `php artisan serve` la
richiesta del pulsante resta aperta fino al termine dell’aggiornamento.
Non servono queue worker, Redis o nuovi servizi. La cache deve essere
persistente e condivisa (configurazione predefinita: `CACHE_STORE=database`).
Contratto API, fixture e verifiche storiche sono descritti in
[docs/gravityzone-api.md](docs/gravityzone-api.md).

**Sviluppo del 04/11/2026:** il Cron locale
`/etc/cron.d/bitlicense-dev-scheduler` esegue ogni minuto il Scheduler
nel container di sviluppo `bitlicense-dev-app-1`, già accessibile sulla porta
8110. Il processo Cron e il container devono essere avviati. L’output è in
`storage/logs/development-scheduler.log` sull’host; non è un deployment.
Il primo giro giornaliero successivo all’attivazione è il 05/11/2026 alle 03:00.
Non è stata forzata una nuova lettura API reale durante questa modifica.

Verifica della rifinitura: `php artisan test --compact` passa con 356 test e
2.519 asserzioni; `vendor/bin/pint --test` e `git diff --check` passano.
Browser desktop/mobile: icone accessibili, clic da tastiera, deduplicazione e
richiesta persistente fra processo web e CLI verificati su SQLite isolato,
con client/sincronizzatori reali e HTTP simulato; dati commerciali e periodi
aziendali conservati. Login e presenza delle icone verificati anche sulla
porta 8110, senza premere l’aggiornamento sui dati reali.
Il giro notturno reale resta da osservare; push e merge restano da approvare.

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

Lo sviluppo segue `AGENTS.md` e le issue GitHub.

## Deploy su CloudPanel

Ogni push su `main` (o un avvio manuale da *Actions → Deploy*) esegue i test
e, se passano, pubblica su `bitlicense.bydot.it` tramite
[`.github/workflows/deploy.yml`](.github/workflows/deploy.yml): manutenzione,
`rsync`, `migrate --force`, `optimize`, ripristino del sito.

- Segreti nell'environment GitHub `production`, limitato a `main`:
  `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH`, `DEPLOY_SSH_KEY` (chiave
  dedicata, la pubblica è in `~/.ssh/authorized_keys` dell'utente del sito),
  `DEPLOY_KNOWN_HOSTS`.
- Sul server restano propri e non vengono sovrascritti `.env` e `storage/`.
- La document root del sito CloudPanel deve essere `…/bitlicense.bydot.it/public`.
- Il Cron del sito esegue ogni minuto
  `php /home/bydot-bitlicense/htdocs/bitlicense.bydot.it/artisan schedule:run`.
