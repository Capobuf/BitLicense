# Vendite, rinnovi e upgrade — issue #10

Perimetro approvato il 04/11/2026: un periodo commerciale identifica la vendita
o il rinnovo che lo ha generato. Le operazioni sono aziendali; ogni computer
collegato conserva le proprie date e il proprio periodo precedente. I gruppi
possono cambiare a ogni rinnovo. Nessuna modifica viene inviata a GravityZone.

## Percorso operativo

**Aziende** mostra computer, operazioni registrate e ultima scadenza. Il nome azienda in **Licenze**, la riga
Aziende e **Vendite e rinnovi** sul computer aprono lo stesso registro.
Il registro riepiloga computer da rinnovare entro 30 giorni o già scaduti,
e computer da associare.

**Vendita o rinnovo** richiede data dell’operazione, quantità e almeno un PC.
La data coincide con la fatturazione del rinnovo; non è necessariamente l’inizio
del periodo acquistato. Scegliere i computer della stessa azienda e verificare
le date nella tabella dei computer selezionati. Anche su mobile le righe
restano tabellari, con scorrimento orizzontale. **Date comuni** permette di applicare un intervallo
a tutti, lasciando possibili correzioni individuali. Per un rinnovo viene
proposto l’inizio successivo all’ultima scadenza; la nuova scadenza è esplicita.

Si possono registrare più vendite nella stessa azienda, rinnovare solo alcuni
computer, oppure riunire computer provenienti da vendite diverse. L’operazione
ha un riferimento V/R/U seguito dal suo ID. La quantità può superare i PC
collegati; in seguito **Correggi** permette aggiunte esplicite, conservando i
collegamenti esistenti e rispettando la quantità. Non viene creato un magazzino.

Il registro non gestisce stati di fatturazione, contatori o filtri di fatture da
emettere. Il totale è facoltativo per vendite/rinnovi e non viene ripartito sui
computer. I riferimenti a fattura e preventivo sono annotazioni facoltative.
Nessuna fattura viene generata, inviata o dedotta dall’API. Un periodo futuro già
registrato evita di mostrare il PC come ancora da rinnovare.

## Prima vendita dal primo rilevamento

Il primo rilevamento GravityZone identifica la prima vendita. I PC della stessa
azienda locale e dello stesso mese/anno vengono collegati automaticamente a
un'unica vendita. La data iniziale dell'operazione è il primo rilevamento più
antico del gruppo; ogni PC mantiene le proprie date. Le date già corrette,
i prezzi unitari e i riferimenti locali restano conservati e consultabili.
La scadenza non viene calcolata e l’importo non viene inventato. Un periodo senza scadenza resta **Da completare**: completarlo
prima di registrare un rinnovo o un upgrade che ne utilizza il residuo.

**Sposta PC** sulla vendita permette di selezionare i computer e un'altra vendita
della stessa azienda. Esempio: 7 PC a febbraio, 3 a marzo; spostando i 3 nella
vendita di febbraio si ottiene una vendita con 10 PC, ciascuno con le sue date.
L'azione sposta i periodi originali, conserva i rinnovi successivi e non somma
importi o fatture. Lo storico di entrambe le operazioni registra il passaggio.
Un gruppo svuotato rimane leggibile, con riferimenti correggibili,
ma non conta più nelle operazioni attive. Una successiva sincronizzazione non
riporta i PC al mese originario.

La sincronizzazione riconosce le nuove prime vendite. Per i rilevamenti già
salvati, `php artisan bitlicense:detect-sales` esegue lo stesso collegamento
locale, ripetibile, senza contattare GravityZone. Nessuna ricostruzione di
rinnovi passati o collegamento per nome. I periodi già collegati, i trasferimenti
e le date cancellate deliberatamente restano rispettati.

## Consultazione del computer

La matita mostra origine, validità, licenza assegnata in sola lettura, rinnovi
registrati, periodo futuro e storico degli upgrade. Lo storico
conta solo i rinnovi registrati, non le correzioni o gli aggiornamenti prodotto.
La licenza tecnica GravityZone resta visibile separatamente.

Le date collegate sono la fonte effettiva per tabella, filtri, ordinamento e
Dashboard: un rinnovo futuro non sostituisce in anticipo la validità corrente.
L’ultimo giorno è incluso e due periodi della stessa licenza non possono
sovrapporsi; un’interruzione fra periodi resta scoperta. Il caricamento non
aggiorna database, non sincronizza e non richiede job per cambiare ciclo.

Nome manuale e note restano modificabili sul computer. Date, importi e riferimenti
dei periodi collegati si correggono nell’operazione. Il totale collettivo non è
mostrato come prezzo unitario duplicato su ogni PC.

## Upgrade aziendale

**Upgrade aziendale** registra decorrenza e differenza complessiva inserita
manualmente per il residuo dei periodi. La licenza viene acquisita dal dato già
mostrato per ciascun PC, senza chiedere un prodotto all’operatore. Le scadenze restano invariate e
non si aggiunge un rinnovo. Il dettaglio conserva i computer interessati, il
prodotto precedente e la loro scadenza residua. Un PC con periodo attivo non
ancora collegato deve essere associato prima di registrare l’upgrade.

Vendite, rinnovi e prime vendite automatiche acquisiscono la licenza assegnata
al PC nel momento della registrazione. Si usa lo stesso `company_product` della
scheda PC: azienda rilevata per gli importati, azienda locale per i manuali.
Non è un nuovo campo API né una diversa edizione commerciale dedotta. Se manca,
resta **Non disponibile**. Un gruppo può conservare licenze differenti per PC.

Gli upgrade conservano la licenza precedente del periodo e quella assegnata
al momento dell’aggiornamento, per ogni PC interessato. Un nuovo rinnovo acquisisce
nuovamente la licenza assegnata. Correzioni dei riferimenti, spostamenti fra vendite,
sostituzioni e sincronizzazioni successive conservano gli snapshot storici.
Il dato tecnico non viene modificato. I vecchi prodotti salvati restano nello storico.

Per proteggere il residuo già registrato, non è possibile cambiare date/
data operazione dei periodi coinvolti in un upgrade, né aggiungere retroattivamente
periodi al suo perimetro. Si possono correggere importo e riferimenti.
Le correzioni degli upgrade rispettano l’ordine degli aggiornamenti successivi.
Non sono inclusi calcoli automatici di differenze o pro-rata.

## Dati precedenti e passaggi

La migrazione non inventa vendite o fatture: i vecchi aggregati rimangono senza
computer. **Collega computer** completa la stessa
registrazione con data e collegamenti espliciti; conserva
ID e snapshot del dato precedente. Una semplice correzione resta disponibile.

Le date locali non collegate restano utilizzabili. Per collegarle occorre
confermare il loro periodo corrente prima di aggiungere un rinnovo futuro.
Prezzo, prodotto, descrizione e riferimenti precedenti sono conservati negli
snapshot consultabili. Il primo rilevamento GravityZone identifica la prima vendita
secondo la regola mensile approvata descritta qui sotto.

La sostituzione trasferisce la successione dei periodi, anche futuri, conservando
computer di origine e collegamenti precedenti. Non crea un rinnovo. La riconciliazione
mantiene il PC manuale e i suoi periodi e rimuove soltanto l’import vuoto compatibile.
Dopo il primo collegamento, sincronizzazioni ripetute non modificano periodi,
riferimenti o versioni dei computer già collegati. Gli import
automatici ancora privi di dati commerciali restano riconciliabili e sostituibili;
il collegamento automatico ridondante viene rimosso nella stessa transazione.

## Protezioni e schema

Amministratori e operatori attivi; autorizzazione server, appartenenza all’azienda,
date e importi validati, centesimi interi senza arrotondamenti. Le mutazioni sono
transazionali e confrontano le versioni dell’operazione e dei computer ricevute
all’apertura. I lock ordinano azienda e computer; errori e conflitti non producono
salvataggi parziali. Le correzioni conservano snapshot dell’operazione.

`company_renewals` conserva le operazioni; `computer_periods` contiene le date,
la licenza registrata, il computer attuale, quello originale e il periodo precedente. Nessun ritorno
alla vecchia architettura Endpoint/Workstation/Coverage. La migrazione
`2026_11_04_000005_link_commercial_cycles.php` aggiunge schema e sostituisce
l’unicità del vecchio registro con un indice compatibile con le foreign key MySQL.
Il rollback verso la vecchia unicità può rifiutare operazioni nuove con date
identiche, prima di eliminare i periodi: non è un percorso di downgrade dei dati.

La migrazione `2026_11_04_000006_detect_initial_sales.php` aggiunge il mese
identificativo univoco per azienda, la provenienza automatica del periodo e
il riconoscimento dei raggruppamenti svuotati. Consente scadenze sconosciute;
il rollback mantiene tale possibilità per non perdere i dati importati.

La migrazione `2026_11_04_000007_capture_assigned_license.php` aggiunge la licenza
registrata al periodo. Conserva soltanto i prodotti già memorizzati nelle vecchie
operazioni: non ricostruisce prodotti storici usando la licenza tecnica attuale.

Gli stati di fatturazione delle registrazioni precedenti restano conservati
nel database e negli snapshot, senza essere mostrati, modificati o usati per
le decisioni operative. I nuovi salvataggi non richiedono né accettano tale stato.

## Verifica in sviluppo

- Suite PHP: `php artisan test --compact` — 382 test, 2.775 asserzioni superate.
- Licenza assegnata: acquisizione per PC, azienda rilevata distinta dalla locale,
  dato mancante, rifiuto di un prodotto inviato dal client, correzioni dei riferimenti
  senza riscrivere lo storico e import automatici ancora riconciliabili.
- Stati di fatturazione rimossi: salvataggi senza stato o riferimento obbligatorio,
  vecchi stati non esposti e dati storici conservati.
- Browser con SQLite anonima: Aziende, registro, correzione e scheda PC senza
  stati di fatturazione, salvataggio con riferimento vuoto; vendita e upgrade senza campo prodotto, licenza
  in sola lettura e dettaglio storico precedente/assegnata; prime vendite mensili, spostamento 7+3 con date
  conservate, anteprime desktop/mobile senza errori JavaScript o overflow;
  vendita, rinnovo con gruppi e date diversi,
  applicazione date comuni e upgrade,
  importo invalido e modifica obsoleta da due schede; desktop 1440 e mobile 390,
  nessun errore JavaScript né overflow.
- Migrazione eseguita su MySQL locale e sul database del pannello di sviluppo
  porta 8110, dopo verifica delle destinazioni. Nessun reset. Prima vendita
  collegata a 112 PC usando i dati locali già salvati; seconda esecuzione: 0
  nuovi collegamenti, nessun duplicato o chiamata API.
- Smoke MySQL con dati temporanei e rollback: gruppi mensili, spostamento,
  scadenze sconosciute, vendita, upgrade, date effettive,
  filtri (anche dopo due sostituzioni) e aggregazione dei grafici con `ONLY_FULL_GROUP_BY`.

Nessuna nuova verifica live GravityZone o invio email. Nessun calcolo automatico
di differenze, emissione fatture, PDF o comunicazione. Branch locale
`issue/33-endpoint-license-status`, con accumulo precedente autorizzato #33/#10.
Push e merge richiedono approvazioni separate; nessun deploy.
