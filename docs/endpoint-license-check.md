# Verifica stato licenza endpoint — #33

Data: 04/11/2026. Ramo: `issue/33-endpoint-license-status`, da `main`
`615ac84f60356ea6414de2c1e9526e943d40d56c`. Richiesta approvata in chat:
integrare lo stato licenza endpoint, anche nella UI, e fornire accesso in sviluppo.

## Risultato

La sincronizzazione legge `getManagedEndpointDetails` solo per i computer
gestiti presenti, con controllo di identità endpoint/azienda e gestione
centralizzata di credenziali, errori e cooldown. Lo stato tecnico è conservato
sul Computer, con ultima lettura ed errore, separato da date, prezzi, note,
versione e storico commerciali. Gli errori puntuali non annullano l’inventario
correttamente acquisito; rete/HTTP/429 interrompono le successive richieste.

Licenze mostra **Stato licenza GZ** in tabella e **Stato licenza GravityZone**
in modale, usando componenti nativi Filament. Data italiana, stati mancanti,
PC manuali/non gestiti/assenti e dati precedenti sono espliciti. Un dettaglio
riuscito rimane aggiornato anche quando un altro PC della stessa azienda
fallisce. La riconciliazione acquisisce questi dati tecnici; il trasferimento
commerciale non li sposta fra PC. Nessuna nuova dipendenza o pipeline.

Rifinitura richiesta nei commenti browser: il tooltip direttamente sul badge
contiene solo data e ora della lettura, senza “i” aggiuntiva e senza tooltip
quando manca la data. Etichetta aggiornata in “Licenza Scaduta”. Rimossa la descrizione commerciale
e di origine sotto il nome; rimangono gli avvisi di spostamento e assenza.
La piccola vista Blade riusa il badge Filament; CSS statico per
l’allineamento, con versione del foglio per aggiornare la cache del browser.
Il clic sul badge mostra il tooltip senza aprire la modale.
Verifica finale: `docker compose exec -T app dev php artisan test --compact --filter='EndpointLicenseUiTest|EndpointSynchronizationTest'`
superato (17 test, 189 asserzioni); `badge-tooltip-browser.cjs` verifica data/ora
senza altro testo, assenza della “i”, focus/tocco e vista tutte le aziende,
senza errori JS, overflow o violazioni axe. Anteprime `badge-tooltip-desktop.png`
e `badge-tooltip-mobile.png` nella directory di verifica.
Anche nella modale la spiegazione dello stato è nel tooltip di una “i” nella
riga dell’etichetta, senza testo aggiuntivo sotto il badge. Verifica finale:
`docker compose exec -T app dev php artisan test --compact --filter=EndpointLicenseUiTest`
superato (5 test, 85 asserzioni); `modal-tooltip-browser.cjs` nella directory
di anteprima verifica focus, tocco e testo nascosto, senza errori JS, overflow
o violazioni axe. Screenshot: `modal-tooltip-desktop.png` e `modal-tooltip-mobile.png`.

Ulteriore rifinitura: il riepilogo aziendale ha sfondo rosso tenue e badge
“Sospesa” solo quando `Company.is_suspended` è vero; non dipende dalla scadenza.
`node /tmp/bitlicense-endpoint-license/suspended-browser.cjs` ha verificato
stato normale/sospeso, vista singola/tutte le aziende, desktop/mobile e axe
senza errori, con il solo database SQLite di anteprima e ripristino della fixture.
Anteprime: `suspended-desktop.png` e `suspended-mobile.png` nella stessa directory.

## Attivazione dal primo rilevamento — richiesta aggiuntiva del 04/11/2026

Il committente ha scelto esplicitamente la compilazione automatica delle date
mancanti con `details.firstSeen`. Validazione del timestamp documentato,
inizializzazione una tantum, date già inserite/scadenze/prezzi/note conservati.
Il record conserva data remota e origine automatica: riconciliazione e
sostituzione restano disponibili per gli import senza altri dati locali.
Una data cancellata o trasferita non viene ripristinata dalle sincronizzazioni.
Modale modificabile con indicazione dell’origine e gestione dei salvataggi
obsoleti tramite la versione commerciale esistente.

Controlli: `docker compose exec -T app dev php artisan test --compact --no-ansi`
**337 test, 2.259 asserzioni superate**, SQLite in memoria e HTTP simulato.
`vendor/bin/pint --test --dirty` e `git diff --check` superati.
La revisione indipendente ha individuato il caso del PC manuale con attivazione
vuota durante la riconciliazione: corretto e coperto dal test UI.
Migrazione additiva `2026_11_04_000003_add_endpoint_first_seen.php` applicata
al solo database Docker `bitlicense_dev` (ambiente local, host db verificato)
e al database SQLite isolato di anteprima, senza reset.
La lettura reale ha rivelato `firstSeen: ""` per date non disponibili: aggiunta
la gestione esplicita come valore sconosciuto, con regressione automatizzata.
Dopo la correzione, i 66 test mirati passano con 540 asserzioni.
`first-seen-browser.cjs`: data salvata visibile in tabella e modale, origine
mostrata, scadenza vuota, nessun errore JS o overflow a 1600 e 390 px.
Anteprime controllate: `first-seen-desktop.png`, `first-seen-mobile.png` nella
directory di verifica; browser con fixture anonime, senza chiamate GravityZone.
`first-seen-access.cjs`: accesso nativo al Docker su porta 8110, badge reali e
data compilata/origine visibili nella modale, senza sincronizzazioni dal browser.
Collaudo reale concluso sul Docker: 115 computer, **112 attivazioni automatiche**
e **3 date mancanti** perché il primo rilevamento non è fornito. Nessun errore
aziendale o globale; le 112 attivazioni coincidono con la data remota salvata.
Riscontro aggregato in `/tmp/bitlicense-endpoint-license/first-seen-live-result.json`.
Il primo tentativo aveva preservato le due aziende con stringhe vuote;
la sincronizzazione successiva, corretta, le ha recuperate.


## Verifiche

| Comando / controllo | Esito |
| --- | --- |
| `php artisan test --compact --no-ansi` | 330 test, 2.189 asserzioni superate; 19,50 s; SQLite in memoria e HTTP simulato |
| `vendor/bin/pint --test --dirty` | Superato |
| `git diff --check` | Superato |
| `php /tmp/bitlicense-endpoint-license/migrate-local.php` | Migrazione additiva applicata; confrontate impronte dei dati preesistenti, invariati |
| Fixture `/tmp/bitlicense-endpoint-license/preview-fixtures.php` | HTTP simulato → client reale → sincronizzatore reale → SQLite isolato: 8 importati e 1 manuale |
| `node /tmp/bitlicense-endpoint-license/browser.cjs` | Login, tutti gli stati, indipendenza commerciale, modale in sola lettura, Tutte le Aziende; Chromium 1600/768/390 px, nessun overflow o errore JS, zero violazioni axe WCAG 2 A/AA rilevate |
| `node /tmp/bitlicense-endpoint-license/access-check.cjs` | Login nativo con account esistente e badge reali attivi/scaduti sul MySQL locale; nessuna sincronizzazione aggiuntiva invocata |
| `php /tmp/bitlicense-endpoint-license/live-sync.php` dopo approvazione esplicita | 115 PC importati; 108 dettagli letti: 89 licenze attive, 19 scadute; 7 PC non gestiti; nessun errore; 159,39 s |
| `docker compose exec -T app dev php artisan test --compact --filter='EndpointLicenseUiTest\|LicenzeTest'` dopo rifinitura | 25 test, 388 asserzioni superate; SQLite in memoria, HTTP simulato |
| `node /tmp/bitlicense-endpoint-license/refined-browser.cjs` | Tooltip con focus e tocco, icona accanto al badge, nessuna apertura modale, descrizione ridondante assente; desktop/tablet/mobile senza overflow, errori JS o violazioni axe rilevate |

Anteprime della rifinitura: `/tmp/bitlicense-endpoint-license/refined-tooltip.png`,
`refined-desktop.png`, `refined-tablet.png`, `refined-mobile.png` nella stessa
directory. Nessuna nuova chiamata GravityZone durante questa rifinitura.

I test coprono codici 0/1/2/6, assenza/null/codici ignoti, tipi malformati,
identità errate, errori HTTP/RPC/rete, segreti non esposti e cooldown 429.
Sono verificati import ripetuti, conservazione dell’ultimo stato dopo errore,
ripresa, nessuna richiesta per PC manuali/non gestiti/assenti, rollback,
riconciliazione, trasferimenti e tentativi di modifica dei campi tecnici.
La suite esistente mantiene i controlli di autorizzazione e versionamento.

La prima esecuzione ha evidenziato un ID duplicato nella fixture del nuovo
test di riconciliazione/trasferimento: corretto usando un ID distinto per
il sostituto, quindi rieseguita l’intera suite con esito positivo.

Database applicativo identificato prima della migrazione: ambiente `local`,
MySQL 8.0.46, database `bitlicense`, socket `/tmp/bitlicense-mysql/mysql.sock`,
datadir `/tmp/bitlicense-mysql/`. Nessun reset: i 115 computer e tutti i campi
preesistenti, aziende, utenti e impostazioni sono invariati dopo la migrazione.

## Anteprime

Screenshot anonimi controllati personalmente:

- `/tmp/bitlicense-endpoint-license/desktop.png`
- `/tmp/bitlicense-endpoint-license/tablet.png`
- `/tmp/bitlicense-endpoint-license/mobile.png`
- `/tmp/bitlicense-endpoint-license/modal-desktop.png`
- `/tmp/bitlicense-endpoint-license/modal-mobile.png`

Browser automatizzato tramite gli strumenti già installati: Node è usato
solo dal driver di collaudo, senza dipendenze Node o build per BitLicense.
L’avatar remoto preesistente di Filament è sostituito nel browser di prova
con una risposta locale; non è una modifica dell’applicazione.

## File modificati

- `app/Integrations/GravityZone/GravityZoneClient.php`
- `app/Integrations/GravityZone/EndpointSynchronizer.php`
- `app/Models/Computer.php`
- `app/Filament/Pages/Licenze.php`
- `app/Providers/Filament/AdminPanelProvider.php`
- `resources/views/filament/computer-license-status.blade.php`
- `resources/views/filament/computer-license-info.blade.php`
- `resources/views/filament/licenses-company.blade.php`
- `public/css/bitlicense.css`
- `database/migrations/2026_11_04_000002_add_endpoint_license_status.php`
- `database/migrations/2026_11_04_000003_add_endpoint_first_seen.php`
- `tests/Feature/GravityZoneInventoryTest.php`
- `tests/Feature/GravityZoneClientTest.php`
- `tests/Feature/GravityZoneEndpointLicenseTest.php`
- `tests/Feature/EndpointSynchronizationTest.php`
- `tests/Feature/EndpointLicenseUiTest.php`
- `tests/Feature/ComputerTest.php`
- `tests/Fixtures/GravityZone/endpoint-license.json`
- `README.md`, `docs/gravityzone-api.md`, `docs/licenses.md`, questo rapporto

## Limiti e passaggio successivo

Il committente ha autorizzato esplicitamente la lettura reale con «Sì, esegui
la lettura reale». La sincronizzazione è riuscita: 89 endpoint con licenza
attiva, 19 con licenza scaduta, 7 non gestiti. Verificata l’immutabilità di tutti
i dati commerciali, storico, proprietà locale e credenziale cifrata preesistenti.
Non sono salvati payload, nomi o identificativi reali nelle fixture o nel rapporto.
Codici 0/6, dati mancanti/errati, errori e cooldown sono collaudati con fixture,
non provocati sul servizio reale.
Una licenza attiva non certifica che tutti i moduli di protezione siano attivi;
non si deduce la causa della scadenza o una sospensione dalle date commerciali.

Una richiesta aggiuntiva per PC gestito allunga la sincronizzazione: per
inventari ampi resta disponibile la CLI, senza introdurre worker permanenti.
Nessun collaudo su browser diversi da Chromium o su hosting di produzione.

Il servizio Docker è verificato su `http://127.0.0.1:8110/admin`, esposto su
`0.0.0.0:8110` e raggiungibile in rete come `http://10.0.0.30:8110/admin`;
l’ultimo indirizzo del browser del committente è `http://localhost:32901/admin`.
Il tunnel lato client non è interrogabile dall’host di esecuzione.

Diff completo da presentare: `/tmp/bitlicense-endpoint-license/review.diff`.
Nessun commit, push, PR, merge o deployment. Prossimo gate GitHub: approvazione
esplicita del diff per push/PR; merge soggetto a una successiva approvazione.
#10–#15 restano sospese e #16 chiusa.
