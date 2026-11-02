# Coperture commerciali

Una copertura conserva azienda, postazione assegnata, primo e ultimo giorno
coperti, importo in centesimi di euro e riferimenti fattura/preventivo.
La scadenza tecnica GravityZone e la sua data di prima rilevazione non
impostano né modificano questi dati.

## Periodi e importi

Gli estremi sono inclusivi: 20/04/2026–20/04/2027 copre anche il 20/04/2027.
Il periodo successivo può iniziare dal 21/04/2027. Un periodo di un solo
giorno è valido; una fine precedente all’inizio o una data inesistente no.
Non possono sovrapporsi due coperture attualmente assegnate alla stessa
postazione, nemmeno per un giorno. I periodi precedenti restano record distinti:
registrare un nuovo acquisto non richiede di riscrivere quello precedente.
**Correggi copertura** è un’azione esplicita per correggere dati inseriti,
con nuova verifica delle sovrapposizioni; non è un rinnovo automatico.

Gli importi iniziali accettano virgola o punto decimale, senza separatori delle
migliaia, fino a due decimali e nove cifre intere. Valori negativi, notazione
esponenziale o decimali aggiuntivi vengono rifiutati, senza arrotondamento
implicito. Il valore salvato è un intero in centesimi: nessun calcolo monetario
usa numeri in virgola mobile. La formattazione della UI è in euro italiani.

Selezionare più postazioni crea una copertura distinta per ciascuna, applicando
**a ciascuna** periodo e importo indicati. Se una selezione è di un’altra azienda,
è duplicata o ha un periodo sovrapposto, l’intera creazione viene annullata.
Non esiste un magazzino o un lotto di licenze condivise.

## Trasferimenti e storico

Ogni copertura ha una sola postazione corrente. Il trasferimento richiede
una destinazione della stessa azienda e priva di coperture sovrapposte;
conserva ID della copertura, periodo, importo e riferimenti acquistati.
La postazione precedente resta nel database e nello storico delle assegnazioni.
Le date dello storico registrano quando è stata inserita o trasferita
l’associazione, non riscrivono il periodo commerciale acquistato.

Le operazioni usano transazioni e bloccano prima le postazioni in ordine di
ID, poi la copertura. Il trasferimento chiude l’assegnazione precedente e ne
apre una nuova. Ripetere lo stesso trasferimento non duplica lo storico.
Il pannello conserva lato server l’assegnazione presente all’apertura della
modale: se è cambiata nel frattempo, richiede di ricaricare i dati.

La sincronizzazione tecnica non crea o trasferisce coperture. Rinomina,
assenza, ricomparsa o spostamento remoto di azienda conservano i periodi,
gli importi e lo storico locali. Le postazioni precedenti sono visibili
nello storico anche quando non hanno più la copertura corrente.
