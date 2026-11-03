# Licenze per azienda e computer

La vista `/admin` segue un unico percorso: scegli l’azienda, clicchi il computer,
modifichi e salvi. Nel riepilogo della tabella compare la licenza tecnica
aziendale GravityZone, in sola lettura; le righe riportano le date commerciali
locali dei singoli PC. I due tipi di scadenza sono indipendenti.

## Record e campi modificabili

`Company` conserva i dati aziendali; `Computer` riunisce il riferimento
GravityZone opzionale e i dati locali. Non esistono più modelli separati per
endpoint, postazioni, coperture e assegnazioni, né un catalogo prodotti/prezzi.

Ogni computer ha prodotto libero, data di attivazione, scadenza, prezzo,
descrizione commerciale, riferimenti fattura/preventivo e note, tutti
facoltativi. Un PC manuale richiede il nome e usa l’azienda selezionata.
Il nome tecnico GravityZone è in sola lettura. Le informazioni tecniche non
permettono di dedurre il prodotto acquistato o le date commerciali.

Un import crea automaticamente il computer con date vuote, **Da completare**.
Gli aggiornamenti tecnici usano l’ID remoto e conservano ogni dato commerciale.
La scomparsa non cancella il record; la ricomparsa dello stesso ID lo aggiorna.
Una reinstallazione con un nuovo ID resta distinta, anche a parità di nome.
Se cambia azienda remota, il computer mantiene quella locale e mostra l’anomalia.

## Date, stati e importi

Attivazione e scadenza sono i valori correnti, modificabili direttamente.
È possibile indicarne soltanto una o cancellarle. Quando entrambe esistono,
la scadenza deve essere uguale o successiva all’attivazione; date inesistenti
sono rifiutate. Un periodo di un giorno è valido. La scadenza è l’ultimo giorno
incluso: il 20/04/2027 un PC con quella scadenza è ancora coperto.

Se l’operatore cambia l’attivazione e la scadenza è vuota, la modale suggerisce
un anno dopo senza sovrascrivere una scadenza già scelta. Da 29/02/2028 propone
28/02/2029. Il suggerimento resta modificabile; non esistono periodi aggiuntivi
da creare, rinnovi automatici o calcoli pro-rata.

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

## Sostituzione del computer

Dal menu della riga, **Trasferisci licenza al sostitutivo** richiede un periodo
completo sul computer originale e un altro computer della stessa azienda senza
dati commerciali da sovrascrivere. Passano al sostituto attivazione, scadenza,
prodotto, prezzo, descrizione commerciale e riferimenti fattura/preventivo.
Quei campi vengono svuotati sul computer originale, evitando due copie della
stessa licenza. Nome, riferimenti tecnici e note restano propri di ciascun PC.
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
dell’azione. Lo storico è consultabile nella stessa modale, senza un registro
operativo o nuove entità da gestire.

`commercial_version` cambia quando mutano i dati locali. La pagina conserva
la versione ricevuta all’apertura della modale e il modello la controlla sotto
lock prima del salvataggio: se un altro intervento ha cambiato il record,
richiede di riaprire il computer. Una sincronizzazione dei soli dati tecnici
non cambia la versione commerciale e non aggiunge snapshot.

## Limiti di questa issue

Non sono implementati modifica massiva delle date, import commerciale iniziale,
CRM, cataloghi, magazzino licenze, pro-rata, proposte, PDF, email o risposte
pubbliche. La modifica su più PC era facoltativa nella #26. Le issue #10–#15 e
#17 restano sospese fino al riesame del committente; la #16 resta chiusa.

Lo schema di sviluppo può essere ricreato senza backup solo dopo aver verificato
che la connessione effettiva punti al database locale autorizzato. Non è prevista
una migrazione conservativa del vecchio modello; la procedura e la perdita di
utenti/impostazioni locali sono spiegate nel [README](../README.md).
