# Collaudo Licenze — issue #17

Verifica del 03/11/2026 sul branch `issue/17-collaudo-licenze`, basato su
`main` dopo #26/#27. Perimetro approvato: solo Azienda + Computer e rifinitura
della pagina Licenze. Le #10–#15 restano sospese; la #16 resta chiusa.

## Correzioni

- Sostituzione e collegamento mostrano solo candidati compatibili: anche un
  prezzo zero è un dato da conservare. I controlli al salvataggio restano attivi
  se un altro operatore modifica un candidato dopo l’apertura.
- Lo storico mostra la descrizione commerciale precedente come testo sicuro;
  descrizioni e riferimenti lunghi vanno a capo anche su mobile.
- Migliorato il contrasto dei testi mancanti e dell’ordinamento mobile con
  poche regole CSS statiche, caricate tramite Filament e i suoi colori nativi.
- Tradotte le etichette accessibili per espandere/comprimere le sezioni native.
- Configurazione GravityZone nel menu **Impostazioni → API**, accessibile
  soltanto agli amministratori, come richiesto nella revisione.
- Gestione utenti nel medesimo gruppo **Impostazioni → Utenti**, riservata
  agli amministratori.
- Prodotto aziendale letto dai campi documentati della licenza GravityZone,
  in sola lettura in tabella/modale; nessuna chiamata aggiuntiva. Valore assente
  esplicito, vecchie annotazioni commerciali conservate e PC spostati associati
  al prodotto della loro azienda rilevata.
- Rimosso **Descrizione commerciale** dalle modali di creazione e modifica,
  conservando i valori già presenti e lo storico.
- Verificati i limiti ufficiali: licenze/inventario 5 richieste/s per chiave,
  elenco aziende 10 richieste/s. Mantenute le pause da 250 ms e il lock esistenti;
  aggiunta un’attesa condivisa dopo 429, rispettata da tutte le letture anche
  su nuovi clic/CLI. `Retry-After` guida la durata; in sua assenza/non validità
  si applicano 60 secondi prudenziali. Nessun retry automatico.

Nessuna nuova schermata, entità, dipendenza o compilazione frontend.

## Controlli automatici

| Comando | Esito |
| --- | --- |
| `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= MAIL_MAILER=array CACHE_STORE=array SESSION_DRIVER=array php artisan test --compact --no-ansi` | 209 test, 1.261 asserzioni, tutti superati |
| `vendor/bin/pint --test` | Superato |
| `composer validate --strict` | Superato |
| `composer check-platform-reqs` | Superato, PHP 8.3.6 |
| `git diff --check` | Superato |
| `php artisan migrate:status` | Tutte le migration applicate nel MySQL locale |

Tre test aggiunti coprono candidati ammessi/esclusi, destinazione modificata
nel frattempo e descrizione storica escapata. Il test di sincronizzazione
esistente verifica ora tutti i campi commerciali anche con storico non vuoto.
La suite include date facoltative/bisestili, limiti inclusivi, importi esatti,
stati, salvataggi obsoleti, rollback, riconciliazione e autorizzazioni.

Per il prodotto aziendale sono stati aggiunti casi sui tipi/modelli documentati,
campi assenti e sconosciuti, tipi JSON errati, aggiornamento/rimozione del dato,
conservazione dei dati commerciali e tentata modifica dal browser. I 71 test
mirati su licenze API, sincronizzazione aziende e pagina Licenze sono passati
(421 asserzioni), prima della suite completa aggiornata sopra.

Dopo il feedback sul menu, rieseguiti `GravityZoneSettingsTest`,
`PanelAccessTest` e `LicenzeTest`: 27 test, 299 asserzioni, tutti superati.
Riconfermati Pint e controllo diff; navigazione **Impostazioni → API** verificata
nel browser desktop/mobile senza salvare configurazioni o contattare GravityZone.

Dopo lo spostamento di Utenti sotto Impostazioni, rieseguiti `UserManagementTest`
e `PanelAccessTest`: 17 test, 119 asserzioni, tutti superati; Pint e controllo
diff superati. Nel browser desktop/mobile verificati entrambi i collegamenti
API e Utenti nel medesimo gruppo, con apertura corretta della gestione utenti.

Dopo la rimozione del campo Descrizione commerciale, eseguito lo stesso comando
di test con `--filter=LicenzeTest`: 15 test, 202 asserzioni, tutti superati;
Pint e controllo diff superati. Il test storico verifica anche che il campo
non sia più modificabile e che una modifica delle note non cancelli il valore
già salvato. Verificate nel browser le modali di creazione/modifica desktop e
mobile: campo assente, note presenti, nessun overflow o errore JavaScript.
Fixture temporanee rimosse con impronte dei dati originali invariate.

La gestione rate limit è verificata con 429 simulati: `Retry-After` numerico,
data HTTP, zero/data passata, header assente/non valido; nuova istanza client
e tutti e tre i metodi restano bloccati senza HTTP fino alla scadenza, poi
la lettura riparte. La suite completa aggiornata sopra include questi casi;
Pint e controllo diff superati. Non è stato provocato un rate limit reale.
Altri programmi che usano la stessa chiave possono consumare la quota.

Le sincronizzazioni, inclusi rinomina, assenza, ricomparsa, reinstallazione,
spostamento remoto, errori e ripetibilità, sono verificate con client simulato
e fixture documentate in [gravityzone-api.md](gravityzone-api.md).
Le richieste HTTP non simulate sono bloccate nei relativi test.

## Browser e database di sviluppo

Chromium desktop 1440×1000, mobile 390×844 e larghezza ridotta 360 px.
Database effettivo verificato: MySQL 8.0.46, `bitlicense`, socket
`/tmp/bitlicense-mysql/mysql.sock`, ambiente `local`. Applicata soltanto la
migration aggiuntiva `technical_product` nullable; nessun reset dello schema.

| Scenario | Esito |
| --- | --- |
| Login, stato iniziale, selettore azienda ricercabile | Superato |
| Quattro PC dal 20/04 e quinto dal 01/05, unica scadenza tecnica al 31/12 | Date indipendenti conservate |
| Suggerimento annuale, modifica, prezzo 79,90 €, descrizione e riferimenti | Salvataggio e rilettura corretti |
| PC manuale con solo nome; ricerca e cambio azienda | Superato; ricerca e filtri azzerati al cambio azienda |
| Importo con tre decimali e scadenza precedente all’attivazione | Rifiutati con errori italiani nei campi |
| Due schede aperte sulla stessa versione | Salvataggio obsoleto rifiutato, nota più recente conservata |
| Trasferimento al sostitutivo e collegamento esplicito | Campi acquistati trasferiti una sola volta; note e identità locale conservate; nessun doppione |
| Filtri mancanti/scadute/oggi/30 giorni/attive/future | Superati, inclusi i confini temporali |
| Modifica, salvataggio e riapertura da smartphone | Superato; nessun overflow orizzontale a 360/390 px |
| Storico con descrizione e riferimento lungo 255 caratteri | Visibile e a capo nella modale mobile |
| Operatore: modifica locale e accessi diretti amministrativi | Modifica consentita; `/admin/gravityzone` e `/admin/utenti` restituiscono 403; sincronizzazione nascosta |
| Prodotto aziendale da fixture, sola lettura e valore assente | Visibile desktop/mobile e nella creazione manuale; modifica note salvata/riletta senza cambiare prodotto; nessun overflow o errore JavaScript |

Nessun errore JavaScript rilevato nei flussi completati. Le anteprime desktop,
mobile, modifica, storico, trasferimento e collegamento sono allegate alla
revisione in chat. Un controllo finale sul database ha verificato date,
centesimi, riferimenti, note e storico delle operazioni eseguite dal browser.

Le sole fixture temporanee sono state rimosse. Dopo la pulizia restano le
16 aziende, 102 computer, 3 utenti e la configurazione preesistenti; conteggi
e impronte SHA-256 delle righe originali coincidono prima/dopo la pulizia.
Anche le fixture del prodotto aziendale sono state rimosse verificando le
impronte dei dati preesistenti; il nuovo campo sulle aziende reali resta nullo
fino alla prossima sincronizzazione autorizzata. Il campo non è garantito per
le licenze annuali: la documentazione lo prevede per mensili/prove mensili.

## Limiti e approvazioni

### Segnalazione dopo «Aggiorna da GravityZone»

Il committente ha segnalato una rotellina/finestra rimasta aperta. Lo stato
locale registra il suo aggiornamento dal 03/11/2026 alle 17:11:47 alle 17:12:19
(Europe/Rome), senza errori su aziende o computer e senza lock residui.
La pagina di accesso rispondeva HTTP 200 in circa 0,06 secondi. Dopo aver
ricaricato la scheda, il committente ha confermato che la pagina era utilizzabile.

Una simulazione separata, senza richieste a GravityZone, ha ritardato di
35 secondi la stessa azione: risposta HTTP 200 dopo 35,4 secondi, modale chiusa,
notifica di completamento visibile e pulsante nuovamente utilizzabile.
Non è stato riprodotto il difetto sulla connessione locale diretta; la causa
precisa nella scheda del committente rimane non identificata. Non sono state
introdotte modifiche applicative o infrastrutturali per una causa non verificata.
Server temporaneo di simulazione e relativo utente sono stati rimossi.

### Altre verifiche e approvazioni

Scansioni automatiche axe-core 4.12.1, regole WCAG 2 A/AA: nessuna violazione
rilevata nella tabella e nella modale di modifica, desktop e mobile. Rimangono
due controlli automatici inconclusivi su componenti dinamici/sfondi nativi.
Non è un collaudo completo con screen reader; Safari, Firefox e dispositivi
fisici non sono stati verificati.

Nessuna nuova verifica API live, email, configurazione SMTP o operazione in
produzione. L’integrazione reale richiede ancora l’intervento e l’autorizzazione
del committente previsti da AGENTS.md. I dati browser sono dimostrativi locali;
non costituiscono un collaudo live GravityZone.

Il lavoro è verificato localmente e attende la revisione del committente:
push/apertura PR richiedono approvazione esplicita, il merge un’approvazione
separata. La #17 resta aperta fino all’integrazione approvata e alla successiva
pulizia del branch. Il collaudo non riattiva le #10–#15.
