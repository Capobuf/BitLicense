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

## Rifinitura dopo il merge — 03/11/2026

Su richiesta del committente, le modali di modifica e creazione manuale sono
divise in **Computer**, **Licenza Commerciale** e **Riferimenti e Note**, con
icone native. Preventivo e fattura sono affiancati su desktop e impilati su
mobile. Nome tecnico più evidente, etichetta nascosta, sistema operativo sotto
l’hostname; prodotto indicato come **Licenza**, date **Attivazione** e **Scadenza**
con esempi nei campi vuoti, importo **Prezzo**. Rimossi l’aiuto sotto la scadenza
e i dettagli con ID GravityZone. L’intestazione della modale mostra l’azienda
rilevata per gli importati, quella locale per i manuali e una icona informativa
con tooltip sull’ultimo rilevamento, utilizzabile con mouse, tastiera e tocco.

Il riepilogo della stessa tabella presenta licenza aziendale, tipo e scadenza
tecnica, senza ripetere il nome già nel selettore. I quattro KPI contano tutti
i computer locali, dati da completare, scadute e in scadenza entro 30 giorni,
riutilizzando lo stato esistente, indipendentemente da ricerca/filtri/pagine.
Restano visibili errori e assenza di dati API; l’ultimo aggiornamento aziendale
è nel tooltip dell’icona informativa.

Il committente ha chiesto esplicitamente di eliminare freccetta e sotto-riga:
la modifica resta nella modale aperta dalla matita o dal clic sulla riga.
La tabella usa interamente il componente nativo Filament. **Validità** riunisce
attivazione sopra e scadenza commerciale sotto, con ordinamento per scadenza.
**Prezzo** resta visibile, anche vuoto o zero. **Rif. Preventivo** e **Rif. Fattura**
compaiono separatamente solo se almeno un computer dell’azienda ha il relativo
valore non vuoto. Una sola query aggregata per richiesta considera tutta
l’azienda; ricerca, filtri e pagina corrente non cambiano questa regola.
Salvataggio e cambio azienda aggiornano la visibilità. Lo stato è sotto il nome.

La ricerca parte da sinistra. Filtri e colonne hanno solo icone con etichette
accessibili e tooltip. Il gestore mantiene riordino tramite trascinamento,
preferenze in sessione e ripristino; prodotto, note e dettagli tecnici restano
selezionabili. Prezzo e riferimenti seguono le regole automatiche sopra.
Completate le traduzioni dei comandi e degli annunci accessibili del riordino.
Solo CSS statico e componenti installati, senza dipendenze o build frontend.

- Suite completa con il comando SQLite isolato riportato sopra: **213 test,
  1.333 asserzioni**, tutti superati. `LicenzeTest`: 19 test, 274 asserzioni.
  Nuovi controlli per intestazione con azienda rilevata distinta dalla locale,
  nomi escapati, tooltip, timestamp assente, PC manuali, KPI ai confini delle
  date e indipendenti da ricerca/filtri, date impilate e ordinate per scadenza,
  riferimenti assenti/vuoti/solo spazi, aziende diverse e comparsa/scomparsa
  immediata dopo il salvataggio. Rimangono i test di validazione, storico,
  permessi, sostituzione, riconciliazione e versioni obsolete della modale.
- Chromium desktop 1440×1100 e mobile touch 390×844, su MySQL locale:
  date impilate, prezzo zero e 79,90 €, riferimenti aggiunti/rimossi dalla
  modale, visibilità indipendente dalla ricerca, cambio azienda, matita e
  assenza di espansioni. Ricerca allineata a sinistra, comandi a icona,
  trascinamento reale e ripristino colonne. Salvataggio dalla modale mobile;
  nessun errore JavaScript o overflow orizzontale.
- Scansione axe-core WCAG 2 A/AA della tabella desktop: nessuna violazione
  rilevata; non sostituisce una verifica manuale con screen reader.
- `vendor/bin/pint --test` e `git diff --check`: superati.
- Anteprime in chat: `licenze-compatte/desktop.png`, `mobile.png` e
  `senza-riferimenti.png`. Fixture dimostrative locali; nessuna chiamata API.
  Fixture rimosse dopo le prove, con impronte SHA-256 dei dati originali invariate.

Rifinitura locale sul branch `issue/17-collaudo-licenze`, ricreato da `main`
dopo il merge precedente. Modifiche in attesa di revisione; nessun push o merge.
Questi richiedono le approvazioni separate previste da `AGENTS.md`.

### Scadenza tecnica nella griglia KPI

Su ulteriore richiesta del committente, la scadenza tecnica è il quinto riquadro
della stessa griglia dei quattro contatori, con data più evidente. Su mobile
occupa una riga intera; restano l'etichetta distinta e gli avvisi di scadenza.
Verifiche: `LicenzeTest` con il comando SQLite isolato sopra, 19 test e 274
asserzioni superati. Chromium desktop/mobile: quinto riquadro allineato ai
contatori, adattamento mobile, valore assente e nessun overflow o errore JS.
Anteprime: `licenze-kpi-scadenza/desktop.png` e `mobile.png`. Nessuna API live.


### Impostazioni con schede API e Utenti

Un'unica voce **Impostazioni** apre `/admin/impostazioni`, che porta alla
scheda API. API e Utenti usano le schede native Filament, con intestazione
comune e selettore compatto su mobile; non sono più voci separate nella sidebar.
Riutilizzati la pagina di configurazione e il resource utenti, senza duplicare
form, tabella, gestione credenziali o autorizzazioni. Nuovi URL:
`/admin/impostazioni/gravityzone` e `/admin/impostazioni/utenti`.

Prima della richiesta di accorpare le verifiche sono passati i 26 test mirati
(224 asserzioni) e la suite completa di 215 test (1.356 asserzioni), oltre a
Pint e controllo diff. La prova desktop ha verificato navigazione, schede,
chiave vuota e apertura/annullamento della creazione utente senza salvataggi.
La prova mobile resta da completare nella verifica finale: il menu nativo
espone le destinazioni con ruolo accessibile `menuitem`, non `link`.
Le fixture sono state rimosse e le impronte dei dati originali sono invariate.
Nessuna chiamata API o modifica alla configurazione salvata.

Su istruzione del committente, da questo punto le verifiche vengono raccolte
per la fine della sequenza di modifiche, senza riesecuzioni a ogni passaggio.
Nessun push o merge eseguito.


### Tutte le Aziende — modifiche in attesa della verifica finale

Aggiunta la voce **Tutte le Aziende** al selettore. La pagina mostra una
tabella nativa distinta per ogni azienda, in ordine alfabetico, con nome,
licenza, KPI e creazione manuale contestuale. Le tabelle riutilizzano la stessa
implementazione di Licenze: nessuna duplicazione dei form o delle regole di
salvataggio. Ogni istanza blocca il proprio ambito aziendale e usa un diverso
identificatore di paginazione/query string. La sincronizzazione esplicita
aggiorna anche le tabelle incorporate tramite l’evento nativo di refresh.

Come richiesto dal committente, nessun test o controllo browser è stato
rilanciato in questo passaggio. Alla verifica finale controllare: selezione
completa/singola e ritorno; aziende vuote; dati e KPI separati; ricerca, filtri,
colonne e pagine indipendenti; comparsa dei riferimenti per singola azienda;
modifica, creazione, versioni obsolete, sostituzione e riconciliazione nelle
tabelle incorporate; autorizzazioni e aggiornamento dopo la sincronizzazione
simulata; resa desktop/mobile e assenza di nuove chiamate API al caricamento.
Nessun push o merge.

### Selettore azienda con icona, stato e scadenza — verifica differita

Le opzioni mostrano icona, nome escapato, stato Attiva/Sospesa e scadenza
tecnica. La ricerca considera il testo, non il markup HTML, mantenendo nomi
con accenti e caratteri speciali. **Tutte le Aziende** ha una propria icona.
Campo nuovo `companies.is_suspended` nullable, alimentato esclusivamente dalla
lettura documentata `getCompanyDetails` nel servizio Companies. Nessuno stato
è dedotto dalla scadenza o dalla presenza; errori mantengono l’ultimo dato
con indicazione esplicita. Il client scarta gli altri dettagli remoti.

Applicata solo la migration aggiuntiva dello stato dopo aver verificato
ambiente `local`, database `bitlicense`, socket e datadir MySQL locali già
autorizzati. Nessun reset e nessuna chiamata a GravityZone. La chiave salvata
non è stata letta o modificata per questa attività; lo stato delle aziende
esistenti resta sconosciuto fino a una sincronizzazione autorizzata.

Preparati i casi automatici su campi mancanti/nulli, booleani, valori malformati,
identità aziendale, passaggio attiva/sospesa, errore con conservazione della
licenza appena letta e dei dati commerciali, nomi escapati e ricerca. Test,
controlli statici e anteprime browser restano differiti alla fine della
sequenza, come richiesto. Da verificare anche i permessi della chiave per
Companies prima di dichiarare verificata la nuova lettura live.

### Verifica finale della sequenza — 03/11/2026

Dopo il riscontro «per me ok», completate insieme le verifiche differite.
Corretto l’identificatore delle tabelle incorporate: l’API nativa `identifier`
separa sia le preferenze in sessione sia i parametri di paginazione per azienda;
il solo `queryStringIdentifier` non separava le preferenze. Un test verifica
che 25 righe per pagina nella prima azienda e 50 nella seconda rimangano
indipendenti anche montando nuovamente i componenti.

- Suite completa SQLite isolata con il comando riportato sopra: **227 test,
  1.452 asserzioni**, tutti superati. Inclusi stato aziendale API, valori
  mancanti/malformati, identità, conservazione dei dati, selettore escapato,
  ricerca con accenti/caratteri speciali, tabelle separate, creazione/modifica
  contestuali, ambito aziendale bloccato, accessi disabilitati e sessioni.
- `vendor/bin/pint --test` e `git diff --check`: superati dopo aver corretto
  la sola formattazione di `Licenze.php`.
- Chromium desktop 1440×1100 e smartphone touch 390×844: selettore con icona,
  stato e scadenza tecnica; valore assente; date impilate, prezzo zero e 79,90 €;
  comparsa/scomparsa dei riferimenti dopo salvataggio; ricerca a sinistra,
  comandi a icona e riordino reale delle colonne. Verificati il quinto KPI
  sulla stessa riga su desktop e a tutta larghezza su mobile.
- **Tutte le Aziende**: ricerca, riferimenti e righe per pagina indipendenti;
  creazione nella prima azienda, modifica dalla relativa matita, salvataggio
  touch e ritorno alla vista singola. Aziende vuote e richieste verso record
  di un’altra azienda sono coperte dai test automatici.
- **Impostazioni**: unica voce, schede API/Utenti su desktop e selettore nativo
  su mobile; campo chiave vuoto, apertura/annullamento creazione utente,
  nessun salvataggio di configurazione o utente. Nessun errore JavaScript
  o overflow orizzontale nei flussi verificati. Scansione axe-core WCAG 2 A/AA
  della tabella desktop: nessuna violazione rilevata.
- Anteprime e diff completo in `verifica-finale` negli allegati locali della
  chat. Fixture temporanee rimosse; conteggi e SHA-256 delle righe originali
  di aziende, computer, utenti e configurazione coincidono prima/dopo.

Nessuna nuova chiamata reale a GravityZone, email o operazione in produzione.
La nuova lettura `getCompanyDetails` è verificata con simulazioni documentali;
permesso Companies della chiave e risposta reale restano da verificare con
l’autorizzazione prevista. Safari, Firefox e dispositivi fisici non verificati.
Branch `issue/17-collaudo-licenze`, nessun commit/push/PR di queste modifiche.
Prima del push è richiesta la revisione del diff completo e l’approvazione
esplicita prevista da AGENTS.md; il merge richiede approvazione separata.

## Selezione aziende e licenze — issue #36 (05/11/2026)

Perimetro approvato in chat: elenco laterale ricercabile al posto dei menu
Azienda/Licenza, licenza come pulsanti a scelta singola, selettore compatto
quando lo spazio non basta, sezioni richiudibili per **Tutte le aziende**.

| Verifica | Esito |
| --- | --- |
| Suite completa SQLite isolata (`vendor/bin/phpunit`) | 383 test, 2.789 asserzioni, tutti superati |
| `vendor/bin/pint --test`, `git diff --check` | Superati |
| Chromium 1920×1080, 1440×1000 e 390×844, tema scuro, dati fittizi anonimi su SQLite isolato | Elenco affiancato a 1920; barra compatta a 1440 con barra laterale espansa e su mobile; nessun errore JS |
| Ricerca «caffe» → «Caffè Centrale»; clic su azienda, URL `?company=ID` | Superato |
| Pulsante licenza: azienda compatibile resta aperta, altrimenti **Tutte le aziende** | Superato (test automatico e browser) |
| Sezioni chiuse, ordine per PC scaduti/in scadenza, apertura e chiusura dalla riga e dalla freccia | Superato |
| Link Dashboard `?company=all&status=expired`: aperte solo le aziende con PC scaduti | Superato |
| Modale di modifica da tabella singola e da sezione aperta | Superato |

Nessuna chiamata API o modifica dati durante caricamento e selezione.
Non verificato: database MySQL/MariaDB di sviluppo e dati reali; screen reader reale.
