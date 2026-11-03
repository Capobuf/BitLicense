# Dashboard Blade — issue #30

Stato al 03/11/2026: implementazione locale sul branch `issue/30-dashboard-blade`,
da `main` (`51f3dd5`). In attesa di revisione e approvazione del push/PR.
Nessun push, merge o deployment eseguito.

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
