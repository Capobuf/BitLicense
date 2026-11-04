# Dashboard Blade — issue #30

Stato al 04/11/2026: dashboard #30 già integrata in `main` (`bb2ab8f`).
Le ulteriori rifiniture, sviluppate sul branch `issue/30-dashboard-filters`,
sono state approvate dal committente, che ha autorizzato esplicitamente push
e merge. Le sezioni seguenti documentano le verifiche e i limiti dei singoli
passaggi, compresi i precedenti stati di attesa dell’approvazione.

Verifica finale del contenuto approvato: `php artisan test --compact`,
290 test e 1.903 asserzioni superati su SQLite in memoria con HTTP simulato;
`vendor/bin/pint --test` e `git diff --check` superati. Le verifiche API reali
già autorizzate e le anteprime anonime sono distinte nel rapporto. Nessun
nuovo test API reale in questa verifica finale; nessun deployment.

## Rifiniture del 04/11/2026

- Rimossi i sottotitoli dei grafici e dell’elenco, le didascalie indicate,
  il conteggio nel titolo dell’elenco e la nota sulle date sconosciute.
- Un solo selettore aziende, come confermato dal committente: scadenze entro
  15, 30, 60 giorni, 3 mesi, 6 mesi, 1 anno, scadute o tutte. Vista iniziale
  entro 30 giorni; oggi e il limite inclusi, scadute escluse dagli intervalli.
- Selettore autonomo nell’intestazione del grafico mensile, al posto del badge;
  eliminato il controllo globale duplicato. Tre mesi usa il calendario,
  sostituendo i precedenti 90 giorni.
- Il contatore aziende in scadenza segue il periodo aziendale; nelle viste
  scadute/tutte usa 30 giorni. Il grafico non cambia contatori o paginazione
  aziende. I link dei contatori conservano il periodo del grafico.
- Etichetta «In Scadenza» e opzioni brevi («15 Giorni», «1 Anno», ecc.).
  Badge relativi in giorni, mesi o anni interi, senza settimane; gestione
  singolare/plurale, scadenza odierna e date mancanti. Tooltip nativo Filament
  con la data esatta in formato italiano. Filtri e date salvate invariati.
- Riutilizzati Blade, Livewire, componenti Filament e calcolo delle scadenze.
  Nessuna modifica a database, sincronizzazioni o dati commerciali.

Verifiche della rifinitura:

| Verifica | Risultato |
| --- | --- |
| `php artisan test --compact` | 264 test, 1.747 asserzioni superati, SQLite in memoria; nessuna API live |
| `php artisan test --compact tests/Feature/DashboardTest.php tests/Feature/DashboardNavigationTest.php` | 37 test, 295 asserzioni superati; inclusi 13 casi sui badge (giorni, mesi, anni, oggi, date mancanti e passate) e contenuto tooltip |
| `vendor/bin/pint --test` e `git diff --check` | Superati |
| `php /tmp/bitlicense30/filters-mysql-check.php` | Tutti gli otto filtri coerenti con i conteggi su MySQL locale verificato, sole query SELECT |
| `node /tmp/bitlicense30/filters-browser.cjs` | Chromium con fixture anonime: sei intervalli di entrambi i selettori, indipendenza, ricerca, reset/conservazione della paginazione, date sconosciute, link contatore e rimozioni superati |
| `node /tmp/bitlicense30/badges-browser.cjs` dopo il secondo feedback | Etichetta e opzioni brevi, badge mesi/anni e tooltip esatto al passaggio mouse verificati; desktop/mobile senza overflow, errori JS o violazioni axe WCAG 2 A/AA |
| Anteprime scure desktop 1669, tablet 768 e mobile 390 px | Controllate, nessun overflow della pagina o selettore tagliato; il grafico mantiene il proprio scorrimento orizzontale |
| axe, WCAG 2 A/AA nei tre formati | Nessuna violazione rilevata, nessun errore JavaScript; controllo automatico, non certificazione |

I test includono limiti inclusivi a 15/30/60 giorni, tre/sei mesi senza overflow
a fine mese e anno bisestile. Le anteprime usano le fixture SQLite già presenti
in `/tmp/bitlicense30/preview.sqlite`, senza modificare i dati applicativi.
Screenshot: `/tmp/bitlicense30/filters-desktop.png`, `filters-tablet.png`,
`filters-mobile.png`. Dopo il secondo feedback, la copia isolata
`/tmp/bitlicense30/badges.sqlite` aggiunge due aziende dimostrative per mostrare
mesi/anni. Anteprime aggiornate: `/tmp/bitlicense30/badges-desktop.png`,
`badges-mobile.png` e `badges-detail.png` (tooltip aperto).
Script browser e verifica MySQL restano fuori dal repo.
Queste prime rifiniture sono state verificate in Chromium scuro con fixture.
La successiva verifica API autorizzata è riportata sotto; nessuna email o deployment. Le vecchie URL con `period=90` o
`companyState=attention/expiring` non sono più accettate; usare i nuovi selettori.

### Verifica API autorizzata del 04/11/2026

Dopo il confronto con la console, il committente ha autorizzato esplicitamente
una verifica live. Eseguite 16 letture `getLicenseInfo`, una per azienda locale
attualmente presente, con `companyId` esplicito, credenziale protetta esistente,
lock condiviso e almeno 250 ms fra richieste. Nessuna sincronizzazione avviata;
hash dei record aziendali prima/dopo identici. Nessuna credenziale o risposta
integrale salvata nel repo.

Risultati: 15 aziende mensili (`subscriptionType: 3`) restituiscono la stessa
`expiryDate` tecnica; 13 espongono anche `endSubscription` in formato
`YYYY-MM-DD` e `autoRenewPeriod: 0`, due omettono entrambi i campi.
La sedicesima azienda è una prova (`subscriptionType: 1`), con `expiryDate`
distinta e senza `endSubscription`. Il confronto puntuale con la data di fine
abbonamento visibile nella console conferma la corrispondenza con
`endSubscription`.

La verifica ha rilevato che usare soltanto `expiryDate` non rappresentava
la fine dell’abbonamento mensile. La verifica iniziale non ha modificato
client, schema o record; il committente ha poi autorizzato la correzione.

### Correzione e aggiornamento autorizzati

Il client ora legge `endSubscription` per `subscriptionType: 3`, senza ripiego
su `expiryDate`. Il campo locale esistente `technical_expires_on` conserva
la scadenza aziendale pertinente: nessuna migrazione o nuova colonna.
Per gli altri tipi rimane `expiryDate`. Data mensile assente/nulla significa
**Non disponibile**; formato errato o data impossibile interrompono quella
lettura conservando il precedente dato e segnalando l’errore. Nessuna data
viene dedotta dal rinnovo automatico. In Dashboard e Licenze il riepilogo
mensile usa l’etichetta **Fine abbonamento**.

| Verifica finale | Risultato |
| --- | --- |
| `php artisan test --compact` | 278 test, 1.817 asserzioni superati; SQLite in memoria e HTTP simulato |
| `vendor/bin/pint --test` e `git diff --check` | Superati |
| `/tmp/bitlicense30/sync-subscription-fix.php`, che invoca il normale comando `bitlicense:sync-companies` | Sincronizzazione reale di 16 aziende sul MySQL locale verificato, senza errori; nessuna modifica a GravityZone |
| Confronto SHA-256 dei record prima/dopo | Tutti i PC, inclusi versione/storico, contatti e note aziendali, chiave API salvata invariati |
| Date e riepiloghi reali | 13 mensili con fine disponibile, 2 senza data, una prova con la propria scadenza; 3 aziende scadute e 2 in scadenza entro 30 giorni al 04/11/2026 |
| `php /tmp/bitlicense30/filters-mysql-check.php` | Tutti gli otto filtri coerenti con i contatori e sole query SELECT |
| `node /tmp/bitlicense30/subscription-browser.cjs` | Chromium, import simulato → Dashboard → Licenze, fine mensile e tooltip, data mancante, prova e filtri verificati |
| Anteprime scure 1669/768/390 px e axe WCAG 2 A/AA | Controllate, nessun overflow della pagina, nessuna violazione rilevata o errore JavaScript |

I nuovi test coprono priorità della fine mensile, campi assenti/nulli senza
fallback, formati/tipi errati, giorni impossibili e bisestili, errore senza
perdita dell’ultima lettura, aggiornamento/rimozione della fine, sync ripetuto
e conservazione di computer/storico. La fixture `license-monthly-end.json`
proietta campi/tipi verificati live con date fittizie e nessun dato cliente.

L’anteprima usa `/tmp/bitlicense30/subscription-preview.sqlite`, copia delle
precedenti fixture anonime: import simulato di otto aziende tramite lo stesso
client/sincronizzatore, con 96 PC invariati. Nessuna chiamata API dal browser.
Screenshot: `/tmp/bitlicense30/subscription-desktop.png`,
`subscription-tablet.png`, `subscription-mobile.png`, `subscription-tooltip.png`
e `subscription-licenses-desktop.png`. I controlli automatici di accessibilità
non sono una certificazione; nessun nuovo controllo su altri browser/tema chiaro.
Non è stato eseguito un nuovo import reale dell’inventario PC.

File modificati: `app/Filament/Pages/Dashboard.php`,
`app/Integrations/GravityZone/GravityZoneClient.php`,
`resources/views/filament/pages/dashboard.blade.php`,
`resources/views/filament/licenses-company.blade.php`, `public/css/dashboard.css`,
`tests/Feature/DashboardTest.php`, `tests/Feature/DashboardNavigationTest.php`,
`tests/Feature/GravityZoneLicenseTest.php`, `tests/Feature/CompanySynchronizationTest.php`,
`tests/Fixtures/GravityZone/license-monthly-end.json`, `README.md`,
`docs/gravityzone-api.md`, `docs/dashboard-check.md`.

Prossimo passo: revisione del diff completo e delle anteprime aggiornate;
attendere approvazione esplicita prima di push/PR. Merge con approvazione
successiva separata, secondo `AGENTS.md`. Nessun push, merge o deployment.

### Partner e clienti condivisi — 04/11/2026

Dopo la sostituzione della chiave dal pannello, le quattro radici restituite
da `getCompaniesList` sono partner. Verificati documentazione ufficiale e
letture reali: due consentono la gestione della sicurezza e restituiscono
rispettivamente 16 e 2 clienti con `parentId`; due dichiarano
`canBeManagedByAbove: false` e rifiutano la lettura dei clienti/inventario.
L’inventario ricorsivo dei partner accessibili include anche PC dei clienti:
la precedente verifica di appartenenza bloccava correttamente l’attribuzione
al partner, ma mancava la scoperta e lettura separata dei clienti.

La sincronizzazione ora segue i partner condivisi, anche annidati, e importa
una sola volta ogni ID aziendale. Tipo e condivisione sono conservati in due
colonne nullable aggiunte da `2026_11_04_000001_add_gravityzone_company_scope`.
I partner non condivisi restano visibili con un avviso, senza richiederne
l’inventario o dichiararne una nuova lettura riuscita. Le aziende assenti
vengono aggiornate soltanto dopo una scoperta interamente riuscita.
La lettura inventario del partner esclude i PC delle altre aziende già scoperte,
lette separatamente; un proprietario sconosciuto continua a invalidarla.

| Verifica aggiornata | Risultato |
| --- | --- |
| `php artisan test --compact` | 288 test, 1.858 asserzioni superati; SQLite in memoria, HTTP simulato |
| `vendor/bin/pint --test` e `git diff --check` | Superati |
| Revisione indipendente e controllo diretto del diff | Validazione, scoperta, deduplicazione, attribuzione e conservazione locale controllate |
| Migrazione sul MySQL locale verificato | Applicata senza reset o cancellazioni, credenziale esistente conservata |
| `php /tmp/bitlicense30/sync-partner-scope.php` | Normali comandi aziende e inventario: 22 aziende presenti (4 partner + 18 clienti), 115 PC rilevati, nessun errore globale o aziendale |
| Confronto prima/dopo sul database reale | Proprietà locale, date, prodotto, importi, riferimenti, note, versione e storico di tutti i 102 PC precedenti invariati; contatti/note aziendali e chiave salvata invariati; 13 nuovi PC con dati commerciali vuoti |
| `php /tmp/bitlicense30/partner-preview.php` con SQLite isolato | Due sync simulati completi: 2 partner + 8 clienti, 9 PC importati senza duplicati, 96 PC preesistenti invariati |
| `node /tmp/bitlicense30/partner-browser.cjs` | Import simulato → Dashboard → Licenze: clienti visibili, link e inventari per azienda corretti, avviso partner non condiviso verificato |
| Chromium scuro desktop 1669 e mobile 390 px | Anteprime controllate; nessun overflow pagina o errore JavaScript; axe WCAG 2 A/AA Dashboard senza violazioni rilevate |
| Servizio sviluppo | HTTP 302 al login su `0.0.0.0:8109`; resta disponibile per la revisione del committente |

Script e dati diagnostici restano fuori dal repository. Nessun payload cliente,
ID reale o chiave aggiunto al codice. Screenshot anonimi in
`/tmp/bitlicense30/partner-dashboard-desktop.png`, `partner-dashboard-mobile.png`,
`partner-client-desktop.png`, `partner-unshared-desktop.png` e
`partner-unshared-mobile.png`. Non sono screenshot del database reale.
Nessuna modifica a GravityZone, email o deployment; nessun nuovo controllo su
altri browser o tema chiaro. I clienti dei due partner non condivisi restano
non verificabili con questa chiave: è un limite dell’accesso restituito dall’API.
Le letture Licensing sono riuscite per tutte le quattro radici nell’import
reale; un errore licenza continua a interrompere la lettura di quella azienda
e segnala una sincronizzazione incompleta, conservando i clienti precedenti.

Ulteriori file interessati: `app/Models/Company.php`,
`app/Integrations/GravityZone/CompanySynchronizer.php`,
`app/Integrations/GravityZone/EndpointSynchronizer.php`, la migrazione sopra,
`tests/Feature/GravityZoneClientTest.php`, `tests/Feature/GravityZoneInventoryTest.php`
e `tests/Feature/EndpointSynchronizationTest.php`.

Prossimo passo: revisione del diff completo e delle anteprime, poi eventuale
approvazione esplicita per push/PR secondo `AGENTS.md`. Nessun push o merge eseguito.

### Ulteriore feedback: licenza nella riga PC — 04/11/2026

La colonna esistente `company_product` di Licenze, prima opzionale e nascosta,
è ora sempre visibile come **Licenza**, inizialmente tra Computer e Validità.
Riusa il prodotto aziendale già mostrato nella modale; il tooltip chiarisce
la fonte aziendale GravityZone. Campo mancante: **Non fornito dall’API**.
Nessuna nuova lettura API o modifica a schema/dati. La correzione vale anche
per Tutte le Aziende e per sessioni che conservavano la colonna nascosta.

`php artisan test --compact tests/Feature/LicenzeTest.php tests/Feature/CompanyLicensesTest.php`:
24 test, 346 asserzioni superati, HTTP bloccato/simulato. Pint e diff check
superati. Chromium scuro con fixture anonime: prodotto visibile su ogni riga,
ordine iniziale, tooltip, prodotto mancante e tabelle di tutte le aziende
verificati; desktop 1669/mobile 390 px senza overflow, errori JS o violazioni
axe WCAG 2 A/AA rilevate. Screenshot in `/tmp/bitlicense30/license-column-desktop.png`,
`license-column-mobile.png` e `license-column-tooltip.png`. Nessun nuovo test
API reale o controllo su altri browser; nessun push o deployment.

File di questo feedback: `app/Filament/Pages/Licenze.php`,
`tests/Feature/LicenzeTest.php`, `tests/Feature/CompanyLicensesTest.php`,
`docs/licenses.md`, `README.md` e questo rapporto.

### Ulteriore feedback: filtro Licenza accanto ad Azienda — 04/11/2026

Aggiunto il selettore nativo Filament **Licenza**, affiancato ad Azienda da
tablet/desktop e impilato su mobile. Le opzioni sono i prodotti aziendali
GravityZone distinti già presenti nel database. La selezione apre Tutte le
Aziende filtrata per prodotto e limita anche ricerca/opzioni di Azienda;
si può poi scegliere una singola azienda. Cambiare o svuotare Licenza torna
alla vista multipla. Svuotarla include anche le aziende senza prodotto noto;
un valore non presente mostra lo stato vuoto, un tipo malformato risponde 422.
Riutilizzata la vista multipla esistente; nessuna nuova API, migrazione o
modifica ai dati commerciali.

`php artisan test --compact tests/Feature/CompanyLicensesTest.php tests/Feature/LicenzeTest.php tests/Feature/DashboardNavigationTest.php`:
31 test, 487 asserzioni superati, HTTP bloccato/simulato. Verificati prodotti
distinti, filtro sull’azienda, ricerca limitata, scelta singola, cambio/rimozione,
stato vuoto, input malformato e dati PC invariati. Pint e diff check superati.
`node /tmp/bitlicense30/license-filter-browser.cjs`: Chromium scuro con fixture
anonime, interazione reale con i selettori e tabelle corrispondenti verificata;
desktop 1669 e mobile 390 px senza overflow, errori JS o violazioni axe WCAG
2 A/AA rilevate. Anteprime controllate in `/tmp/bitlicense30/license-filter-desktop.png`
e `license-filter-mobile.png`. Nessun nuovo test API reale, altro browser,
push o deployment. File modificati: `app/Filament/Pages/Licenze.php`,
`tests/Feature/CompanyLicensesTest.php`, `docs/licenses.md`, `README.md` e questo rapporto.

### Ulteriore feedback: data soltanto nel tooltip — 04/11/2026

Rimosso dall’elenco Dashboard il blocco con data e dicitura Fine abbonamento/
Scadenza tecnica. Rimangono badge relativo e tooltip nativo con la data esatta.
Eliminati gli stili inutilizzati e riallineato il badge sotto il nome su mobile.
`php artisan test --compact tests/Feature/DashboardTest.php`: 32 test, 186
asserzioni superati; Pint e diff check superati. Chromium con fixture anonime:
data/dicitura assenti dalla riga, tooltip esatto verificato, desktop 1669/mobile
390 px senza overflow, errori JS o violazioni axe WCAG 2 A/AA rilevate.
Anteprime in `/tmp/bitlicense30/dashboard-date-desktop.png`, `dashboard-date-mobile.png`
e `dashboard-date-tooltip.png`. Nessuna nuova API o scrittura nei dati aziendali;
nessun push. File: Blade Dashboard, CSS Dashboard, README e questo rapporto.

## Implementazione e verifiche storiche del 03/11/2026

Le sezioni seguenti descrivono lo stato precedente alle rifiniture sopra.

## Modifica

Il committente ha richiesto una pagina Dashboard separata. È disponibile nel
menu a `/admin/dashboard`, con autenticazione Filament e accesso per entrambi
i ruoli attivi. Licenze resta a `/admin` e continua a gestire tutte le modifiche.

- Hero Blade con glow, sfondo animato CSS e orbita in movimento, senza pulsante
  pausa come richiesto nel feedback. Rispetto di `prefers-reduced-motion`.
- Statistiche (`Stat` in uno `Schema`), sezioni e badge nativi Filament. Fuori
  dalla hero, superfici e colori seguono il pannello; i grafici usano i token
  `primary`, `success`, `warning`, `danger` e `gray`.
- Periodi selezionabili: 30, 60, 90 giorni, 6 mesi e 1 anno, da oggi al giorno
  finale incluso; mesi/anni di calendario senza overflow a fine mese.
- Contatori cliccabili: aziende totali, scadute e in scadenza aprono l’elenco
  corrispondente; il totale computer apre Licenze con tutte le aziende.
- Grafico degli stati commerciali cliccabile, con la definizione operativa
  «In scadenza» sempre a 30 giorni, dichiarata nella didascalia.
- Grafico mensile nel periodo selezionato: ogni barra apre Licenze filtrando
  le date commerciali dal primo all’ultimo giorno effettivo di quel segmento.
  Le scadenze note senza attivazione sono incluse. Scorrimento del grafico
  quando tutti i mesi non entrano nella larghezza disponibile.
- Ricerca per nome azienda e selettore scadute/in scadenza/tutte, con date
  sconosciute disponibili in «Tutte le aziende», paginazione Filament e link
  all’azienda in Licenze. La ricerca non cambia i contatori globali.
- Filtro date commerciale Filament in Licenze, con limiti inclusivi, indicatori
  rimovibili e passaggio del filtro alle singole tabelle aziendali.
- Stato commerciale condiviso con Licenze nel modello Computer, senza cambiare
  la logica. Nessuna modifica a schema, salvataggi commerciali o sincronizzazione.
- Quattro query sui dati per la pagina, senza una query aggiuntiva per azienda.
  Nessuna nuova dipendenza, build frontend, chiamata API o email.

## Verifiche eseguite

| Verifica | Risultato |
| --- | --- |
| `php artisan test --compact` dopo ricerca, periodi e navigazione | 248 test, 1.682 asserzioni superati; SQLite in memoria, HTTP simulato |
| `php artisan test --compact tests/Feature/DashboardTest.php tests/Feature/DashboardNavigationTest.php` | 21 test, 230 asserzioni superati |
| `php artisan test --compact --filter=test_dashboard_validates_query_parameters_and_livewire_filters` dopo semplificazione della validazione | 1 test, 8 asserzioni superati |
| `vendor/bin/pint --test` | Superato |
| `git diff --check` | Superato |
| Query Dashboard sul MySQL locale verificato | Superate; totali e conteggi dell’elenco coerenti, nessuna scrittura |
| Chromium, fixture anonime su SQLite separato | Ricerca anche dalla seconda pagina, nessun risultato, tutti i periodi, contatore scadute, aziende senza data, link stato su otto tabelle e link mese con 16 righe corrispondenti verificati; link azienda/modale verificati nella rifinitura precedente |
| Anteprime desktop 1440, tablet 768, mobile 390 px | Nessun overflow orizzontale rilevato; temi scuro e chiaro controllati |
| Animazione dopo il feedback | Trasformazioni di sfondo e punto orbitale diverse dopo 1,5 secondi; pulsante assente; animazioni disattivate con movimento ridotto |
| Componenti e palette | Statistiche, sezioni, campi e selettori nativi, stessa palette; CSS versionato per aggiornare la cache del browser |
| axe, WCAG 2 A/AA sulla Dashboard dopo la rifinitura | Nessuna violazione rilevata su desktop scuro/chiaro, tablet e mobile scuro; nessun errore JavaScript |

I nuovi test coprono accessi diretti/Livewire e revoca, date tecniche indipendenti
da quelle commerciali, limiti inclusivi, cambio anno/mese nel fuso italiano,
date mancanti e attivazioni future, dati non più rilevati, spostamenti remoti
senza cambio di proprietà locale, paginazione, escaping, parametri URL invalidi
e assenza di scritture/chiamate HTTP/email durante il rendering. La verifica
successiva copre tutti i periodi, fine mese e anno bisestile, ricerca e reset
della pagina, link e coerenza dei risultati, propagazione/rimozione dei filtri.
Parametri Dashboard non ammessi rispondono 422; parametri di navigazione
Licenze malformati sono ignorati, senza cambiare la selezione azienda.
Intervalli invalidi applicati direttamente alla tabella non restituiscono record.

Il test del trentesimo giorno ha rilevato un confronto SQL diverso tra le date
serializzate da SQLite e i campi DATE MySQL: l’aggregazione usa ora `DATE()`
come i filtri `whereDate`, mantenendo i limiti inclusivi.

Il MySQL verificato è `bitlicense`, versione 8.0.46, socket
`/tmp/bitlicense-mysql/mysql.sock`, dati in `/tmp/bitlicense-mysql/`.
La verifica ha interrogato soltanto i dati locali. L’anteprima browser usa
`/tmp/bitlicense30/preview.sqlite`, con otto aziende e 96 computer dimostrativi;
non ha usato credenziali GravityZone o modificato il database applicativo.

## File interessati

`app/Filament/Pages/Dashboard.php`, `resources/views/filament/pages/dashboard.blade.php`,
`public/css/dashboard.css`, `app/Providers/Filament/AdminPanelProvider.php`,
`app/Filament/Pages/Licenze.php`, `app/Models/Computer.php`,
`app/Livewire/CompanyLicenses.php`, `resources/views/filament/all-companies.blade.php`,
`tests/Feature/DashboardTest.php`, `tests/Feature/DashboardNavigationTest.php`,
`tests/Feature/PanelAccessTest.php`,
`AGENTS.md`, `README.md` e questo rapporto.

## Limiti e prossimo passo

Nessun test API live, invio email o deployment. Il controllo axe è automatico,
non una certificazione di accessibilità; browser verificato: Chromium.
La dashboard rappresenta i dati locali disponibili, non una nuova lettura di
GravityZone. Le scadenze note senza attivazione compaiono nel grafico mensile
ma restano «Da completare» nel grafico degli stati.

Presentare diff completo e anteprime al committente; attendere approvazione
esplicita prima di push/PR. Il merge richiede una successiva approvazione separata.
Le issue #10–#15 restano sospese.
