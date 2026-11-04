# Licenze per azienda e computer

La pagina **Aziende** e il nome azienda cliccabile in Licenze aprono il
[registro di vendite, rinnovi e upgrade](company-renewals.md). Ogni periodo
collegato identifica l’operazione che lo ha generato; gruppi e date possono
cambiare a ogni rinnovo. La scadenza tecnica GravityZone rimane indipendente.

La vista `/admin` segue un unico percorso: scegli l’azienda, clicchi il computer,
modifichi e salvi. Nel riepilogo della tabella compare la licenza tecnica
aziendale GravityZone, in sola lettura; le righe riportano le date commerciali
locali dei singoli PC. I due tipi di scadenza sono indipendenti.
La matita o il clic sulla riga apre la modale. Non ci sono freccette o
sotto-righe di modifica. **Salva** conferma, **Annulla** chiude senza salvare.

Nel selettore, **Tutte le Aziende** mostra le aziende in ordine alfabetico,
ciascuna con una tabella e un riepilogo separati, anche quando non ha computer.
Ricerca, filtri, colonne, riferimenti visibili e paginazione restano propri di
ogni tabella. **Aggiungi PC manuale** nel riepilogo opera sull’azienda di quella
tabella. Scegliendo una singola azienda si torna alla vista precedente.
Le stesse modali e gli stessi vincoli commerciali sono riutilizzati nelle
due viste, senza chiamare GravityZone durante il caricamento.

Accanto ad **Azienda**, il selettore **Licenza** elenca i prodotti aziendali
GravityZone presenti nel database. Selezionandone uno si apre **Tutte le
Aziende**, limitata alle aziende con quel prodotto; anche ricerca e opzioni
del selettore Azienda seguono il filtro. Si può poi scegliere una sola azienda.
Svuotare Licenza ripristina tutte le aziende, comprese quelle senza prodotto
noto. Il filtro usa la licenza aziendale, senza cambiare i dati dei PC.

Azienda, Licenza e filtri delle tabelle vengono conservati nella sessione:
restano selezionati ricaricando la pagina o tornando a Licenze dopo aver
visitato un’altra sezione. In **Tutte le Aziende**, ogni tabella conserva
i propri filtri. I collegamenti di dettaglio dalla Dashboard impostano
la selezione richiesta dal collegamento.

Ogni opzione azienda ha un’icona e mostra **Nome — Stato — Scadenza tecnica**.
Attiva/Sospesa proviene esclusivamente da `isSuspended` di GravityZone; finché
non acquisito compare **Stato non disponibile**. Un aggiornamento fallito o
un’azienda non più rilevata lascia visibile l’ultimo stato conosciuto, indicato
come tale. La data è quella tecnica aziendale, mai quella commerciale dei PC.
Nomi remoti escapati anche nelle etichette HTML del selettore.

## Record e campi modificabili

`Company` conserva i dati aziendali; `Computer` riunisce il riferimento
GravityZone opzionale e i dati locali. Non esistono più modelli separati per
endpoint, postazioni, coperture e assegnazioni, né un catalogo prodotti/prezzi.

Prima del collegamento a un’operazione, ogni computer ha data di attivazione, scadenza, prezzo,
riferimenti fattura/preventivo e note, tutti
facoltativi. Un PC manuale richiede il nome e usa l’azienda selezionata.
Il nome tecnico GravityZone è in sola lettura. La colonna **Licenza**, sempre
visibile per ogni PC accanto a Computer, mostra in sola lettura il **prodotto
aziendale GravityZone**, già presente nella modale; per un PC manuale usa l’azienda
selezionata, per uno importato l’azienda rilevata, anche se diversa da quella locale. Se il campo
non è restituito, compare **Non fornito dall’API**. Non si deducono il prodotto
commerciale del singolo PC o le sue date dai moduli tecnici.
Eventuali prodotti commerciali annotati prima di questa modifica rimangono
visibili come **Prodotto Commerciale Salvato** nella modale,
conservati negli snapshot e nei trasferimenti; non sono sostituiti dal prodotto aziendale.
Il campo **Descrizione commerciale** è stato rimosso dalla creazione e modifica
su richiesta del committente. I valori già salvati non vengono cancellati:
restano conservati nei dati commerciali e negli snapshot automatici.

Un import crea automaticamente il computer e compila l’attivazione mancante
con il primo rilevamento GravityZone, quando disponibile. Il rilevamento identifica
la prima vendita: i PC della stessa azienda e dello stesso mese/anno sono
raggruppati automaticamente. «Sposta PC» nel registro permette di correggere
il raggruppamento mantenendo le date individuali. La scadenza rimane
da inserire, quindi il PC resta **Da completare**. Le date già inserite
e gli altri dati commerciali sono conservati.
La scomparsa non cancella il record; la ricomparsa dello stesso ID lo aggiorna.
Una reinstallazione con un nuovo ID resta distinta, anche a parità di nome.
Se cambia azienda remota, il computer mantiene quella locale e mostra l’anomalia.

## Stato licenza del singolo endpoint

La colonna **Stato licenza GZ** e il dettaglio in modale mostrano
`agent.licensed` del PC: **Licenza attiva**, **Licenza Scaduta**,
**Autenticazione in attesa**, **Assente / non applicabile**, oppure
**Non disponibile**. Questa indicazione tecnica resta indipendente dalle date
e dallo stato commerciale. Non certifica che tutti i moduli di protezione
siano attivi e non attribuisce automaticamente una causa alla scadenza.

Il tooltip sul badge in tabella contiene solo data e ora di lettura in
Europe/Rome, quando disponibili, senza icona aggiuntiva. Errori, assenza del PC o dell’azienda e
perdita di accesso all’inventario lasciano distinguibile l’ultimo stato rilevato,
senza un badge verde che suggerisca un dato corrente. Un PC manuale è **Non
collegato**, un endpoint non gestito è **Non gestito**. Se il dato manca o
il codice non è documentato rimane sconosciuto. Caricare tabella o modale
non chiama GravityZone.

La riconciliazione conserva anche stato tecnico, data ed eventuale errore
del record importato. Un trasferimento commerciale non trasferisce lo stato
GravityZone: ciascun endpoint mantiene il proprio.

## Date, stati e importi

Per i computer collegati, attivazione e scadenza derivano dal periodo effettivo:
il più recente già iniziato, oppure il primo futuro quando nessuno è iniziato.
Un intervallo vuoto fra periodi resta scoperto. Filtri, ordinamenti e Dashboard
usano le stesse date senza scrivere dati al caricamento. Le correzioni passano
per l’operazione; la modale PC espone origine, rinnovi, upgrade e note.
Il prezzo di una vendita collettiva è il totale dell’operazione, mai replicato
come prezzo unitario per ogni PC. Il filtro **Da rinnovare** esclude i periodi
futuri già registrati; **Da associare** identifica i PC ancora privi di collegamento.

Per i computer ancora non collegati, attivazione e scadenza sono modificabili direttamente.
È possibile indicarne soltanto una o cancellarle. Quando entrambe esistono,
la scadenza deve essere uguale o successiva all’attivazione; date inesistenti
sono rifiutate. Un periodo di un giorno è valido. La scadenza è l’ultimo giorno
incluso: il 20/04/2027 un PC con quella scadenza è ancora coperto.

L’attivazione inizializzata automaticamente è indicata nella modale con
**Primo rilevamento GravityZone** ed è modificabile. La prima acquisizione
compila anche le date mancanti dei PC già importati, salvo date precedentemente
cancellate o trasferite e scadenze anteriori al primo rilevamento. Le
sincronizzazioni successive non ripristinano una data cancellata, non
sovrascrivono le correzioni e non calcolano automaticamente la scadenza.
Se GravityZone non fornisce il primo rilevamento, il campo resta vuoto.

Se l’operatore cambia l’attivazione e la scadenza è vuota, il modulo suggerisce
un anno dopo senza sovrascrivere una scadenza già scelta. Da 29/02/2028 propone
28/02/2029. Il suggerimento resta modificabile; non esistono rinnovi automatici o calcoli pro-rata. I periodi acquistati si
registrano esplicitamente da Vendite e rinnovi.

Gli stati usano la data corrente in Europe/Rome, in questo ordine:

- **Da completare**: manca almeno una delle due date.
- **Scaduta**: scadenza precedente a oggi.
- **Da attivare**: attivazione successiva a oggi.
- **In scadenza**: attivazione già avvenuta, scadenza da oggi a oggi + 30 giorni, inclusi.
- **Attiva**: attivazione già avvenuta, scadenza oltre i 30 giorni.

Quattro computer attivati il 20/04 e un quinto il 01/05 mantengono date diverse,
anche se appartengono alla stessa azienda tecnica. Stati e filtri non chiamano
GravityZone, non creano notifiche persistenti e non inviano email.

Il prezzo può essere vuoto o zero. Accetta punto o virgola decimale, fino a
due decimali e nove cifre intere, senza separatori delle migliaia. Importi
negativi, notazione esponenziale o precisione aggiuntiva sono rifiutati senza
arrotondamento implicito. Il salvataggio usa centesimi interi, non float.

La colonna **Validità** riunisce l’attivazione sopra e la scadenza sotto;
l’ordinamento usa la scadenza commerciale. Sotto il nome del PC restano soltanto
gli avvisi di spostamento o assenza. Il prezzo resta visibile, anche quando vuoto.
**Rif. Preventivo** e **Rif. Fattura** compaiono indipendentemente solo se
almeno un computer dell’azienda ha il rispettivo riferimento non vuoto.
La presenza dei riferimenti considera tutta l’azienda, senza dipendere da
ricerca, filtri o pagina corrente, e si aggiorna dopo il salvataggio.

La ricerca parte da sinistra; filtri e gestore colonne hanno soltanto icone
con etichette accessibili. Il gestore consente il riordino tramite trascinamento
e la selezione delle informazioni aggiuntive: note,
sistema operativo, azienda rilevata e ultimo rilevamento. Prezzo e riferimenti
seguono le regole automatiche sopra. Le preferenze restano nella sessione;
**Reimposta** ripristina la selezione iniziale.

## Sostituzione del computer

Quando esistono periodi collegati, la sostituzione trasferisce tutta la loro
successione (anche i rinnovi futuri) conservando il computer originale e i
collegamenti ai periodi precedenti. Il computer origine mostra lo storico del
trasferimento; non duplica la validità e non viene reinizializzato dal primo rilevamento.
Le regole seguenti sui campi correnti si applicano ai PC ancora non collegati.

Dal menu della riga, **Trasferisci licenza al sostitutivo** richiede un periodo
completo sul computer originale e un altro computer della stessa azienda senza
dati commerciali da sovrascrivere. Passano al sostituto attivazione, scadenza,
eventuale prodotto commerciale già salvato, prezzo, descrizione commerciale
e riferimenti fattura/preventivo. Il prodotto aziendale da API segue invece
l’azienda rilevata di ciascun computer e non viene trasferito.
Quei campi vengono svuotati sul computer originale, evitando due copie della
stessa licenza. Nome, riferimenti tecnici e note restano propri di ciascun PC.
Il selettore mostra solo sostituti senza dati commerciali da sovrascrivere.
La sola attivazione automatica da primo rilevamento non impedisce la sostituzione:
viene sostituita dal periodo trasferito, mentre il primo rilevamento tecnico resta proprio del PC.
Lo storico precedente non viene eliminato. La presenza di sole note sul
sostituto non impedisce il passaggio e le note sono conservate.

La transazione blocca entrambi i record in ordine di ID: un errore annulla
l’intero passaggio. Un secondo invio o una scheda aperta prima di una modifica
non può trasferire nuovamente i dati usando una versione obsoleta.

## Riconciliazione di un PC manuale

**Collega a PC GravityZone rilevato** è un’azione contestuale sul PC manuale.
L’operatore verifica l’ID GravityZone; il nome da solo non prova l’identità.
La rilevazione deve essere ancora presente, appartenere alla stessa azienda
locale/remota e non contenere dati commerciali, nome locale, note o storico.
In caso contrario l’operazione viene rifiutata per evitare perdita di dati.
Una sola attivazione automatica non impedisce il collegamento: si conserva
l’attivazione manuale esistente o si acquisisce quella automatica se mancante
e compatibile con la scadenza, senza ripristinare date cancellate.
Il selettore esclude già le rilevazioni incompatibili; il salvataggio ricontrolla
i vincoli nel caso siano cambiate dopo l’apertura della modale.

La transazione conserva il PC manuale e tutti i suoi dati locali, acquisisce
il riferimento e i campi tecnici remoti e rimuove soltanto la riga locale
importata vuota. La successiva sincronizzazione aggiorna il record risultante
per ID senza ricreare il doppione. Nessun dato GravityZone viene modificato.

## Storico automatico e salvataggi concorrenti

L’array `history` del computer conserva snapshot minimi dei precedenti valori
commerciali quando vengono modificati, oltre a trasferimenti e riconciliazioni.
La prima compilazione da valori commerciali vuoti non produce uno snapshot
vuoto; cambiare soltanto nome o note non aggiunge storico commerciale.
Per i passaggi sono conservati anche ID e nome del computer correlato al momento
dell’azione. Lo storico, compresa la descrizione commerciale precedente,
è consultabile nella modale, senza un registro
operativo o nuove entità da gestire.

`commercial_version` cambia quando mutano i dati locali. La pagina conserva
la versione ricevuta all’apertura della modale; il modello la
controlla sotto lock prima del salvataggio: se un altro intervento ha cambiato il record,
richiede di riaprire il computer. Una sincronizzazione dei soli dati tecnici
non cambia la versione commerciale e non aggiunge snapshot.

## Limiti di questa issue

Non sono implementati modifica massiva delle date, import commerciale iniziale,
CRM, cataloghi, magazzino licenze, pro-rata, proposte, PDF, email o risposte
pubbliche. La modifica su più PC era facoltativa nella #26. La #10 è stata approvata per i cicli commerciali descritti nel relativo documento;
le #11–#15 restano sospese; la #17 riguarda soltanto
il collaudo e la rifinitura UI/UX di questo flusso in sviluppo. La #16 resta chiusa.

Lo schema di sviluppo può essere ricreato senza backup solo dopo aver verificato
che la connessione effettiva punti al database locale autorizzato. Non è prevista
una migrazione conservativa del vecchio modello; la procedura e la perdita di
utenti/impostazioni locali sono spiegate nel [README](../README.md).
