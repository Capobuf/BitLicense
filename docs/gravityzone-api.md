# Contratto GravityZone verificato

Verifica documentale: 4 novembre 2026. Il client espone soltanto cinque letture
esplicite: `getCompaniesList`, `getNetworkInventoryItems` (Network),
`getLicenseInfo` (Licensing), `getCompanyDetails` (Companies) e `getManagedEndpointDetails` (Network).
Non contiene un invocatore RPC generico o metodi che modificano GravityZone.

La base configurata termina in `/api`. Le richieste POST usano JSON-RPC 2.0,
HTTP Basic con la chiave come utente e password vuota, TLS verificato,
redirect disabilitati e timeout di connessione/richiesta di 5/20 secondi.
Errori HTTP/RPC, autenticazione, rete e formati non validi producono messaggi
locali senza corpo remoto, chiavi o eccezioni concatenate.
Fonte: [esempi API](https://www.bitdefender.com/business/support/en/77211-141188-api-usage-examples.html).

## Aziende

`getCompaniesList` usa `/v1.0/jsonrpc/network`: `params: []` per le radici e
`params: {parentId: ID}` per i clienti di un partner accessibile. Il risultato
è una lista, anche vuota, di oggetti con `id` e `name`. ID vuoti, duplicati,
oltre 255 caratteri o record incompleti invalidano l’intero elenco prima
del salvataggio. Nessuna paginazione è documentata per questo metodo.
La scoperta segue anche i partner annidati, deduplicando gli ID già letti.
Fonte: [getCompaniesList](https://www.bitdefender.com/business/support/en/77211-128478-getcompanieslist.html).

`getCompanyDetails` usa `/v1.0/jsonrpc/companies` con `companyId` esplicito.
Si controlla che l’oggetto restituito abbia lo stesso ID richiesto. Si conservano
`type` (0 partner, 1 cliente), `canBeManagedByAbove` (gestione della sicurezza
condivisa) e `isSuspended`: `true` significa **Sospesa**, `false` **Attiva**. Campo
assente/nullo significa **Stato non disponibile**; stringhe/numeri non sono
convertiti implicitamente. Presenza nell’inventario e scadenza della licenza
non determinano lo stato dell’account. Altri dettagli, contatti e indirizzi
remoti non vengono salvati.

Un partner con sicurezza condivisa viene esplorato usando `parentId`; uno
non condiviso resta visibile con un avviso, senza richiederne clienti o
inventario. Tipo mancante o condivisione sconosciuta per un partner impediscono
di dichiarare completa la scoperta. Le aziende non viste vengono segnate
assenti soltanto dopo una sincronizzazione aziendale interamente riuscita.
I due campi di ambito sono salvati da una migrazione additiva locale.

La sincronizzazione legge lo stato dopo la licenza, con pausa di 250 ms.
Se questa lettura fallisce, conserva la licenza appena acquisita e lo stato
precedente, segnala l’aggiornamento incompleto e mostra quest’ultimo come
**ultimo stato rilevato**. Come per gli altri errori di sincronizzazione, il
pannello segnala il fallimento prima di avviare l’import dei computer.
La chiave deve consentire la lettura nel servizio Companies. Nessuna lettura
avviene all’apertura del selettore. La chiamata è stata eseguita nel collaudo autorizzato del 04/11/2026 su
16 aziende, senza errori. I casi simulati sono proiezioni anonime dei campi
documentati, non payload cliente.
Fonte: [getCompanyDetails](https://www.bitdefender.com/business/support/en/77211-126239-getcompanydetails.html).

## Licenza tecnica aziendale

`getLicenseInfo` usa `/v1.0/jsonrpc/licensing` con il `companyId` esplicito.
Legge la licenza principale; non richiede `returnAllProducts` e non aggrega
le diverse scadenze di `additionalLicenses`. Il risultato degli esempi è
un oggetto, benché la prosa del contratto lo definisca Array.

Vengono conservati il tipo di abbonamento `subscriptionType`
(1 prova, 2 licenza, 3 mensile, 4 licenza mensile di prova, 5 abbonamento
mensile di prova, 6 FRAT). La scadenza aziendale locale `technical_expires_on`
usa **`endSubscription` per il tipo 3 (Mensile)**, nel formato `YYYY-MM-DD`
verificato dal vivo. Questa è la fine dell’abbonamento impostata nella console;
`expiryDate` può avere un valore differente e non viene usata come ripiego.
Se `endSubscription` manca o è nulla, la scadenza resta **Non disponibile**.
Non si deducono date dal rinnovo automatico e non si estendono periodi.

Per gli altri tipi rimane la parte calendario di `expiryDate`, nel formato
`YYYY-MM-DDTHH:mm:ss`. Date mancanti/nulle sono sconosciute. Entrambi i formati
sono validati rigorosamente, inclusi giorni reali e anni bisestili, senza
conversione di fuso. Una data non valida provoca un errore e conserva il
precedente dato noto, segnalato come non aggiornato.
Chiavi licenza e altri dettagli non necessari vengono scartati.
Nessun campo descrive le date commerciali locali dei singoli computer.
La chiave deve avere accesso anche al servizio Licensing.
Fonte: [getLicenseInfo](https://www.bitdefender.com/business/support/en/77211-127105-getlicenseinfo.html).

Su richiesta del committente, si legge anche il **prodotto aziendale**:
`assignedProductType` (0 Endpoint Security, 3 Bitdefender EDR, 5 Bitdefender PHASR),
documentato per abbonamenti mensili e prove mensili. Per Endpoint Security
mensile si aggiunge `assignedProtectionModel`, quando noto: À la carte,
MSP Secure Essentials, MSP Secure, MSP Secure Plus o MSP Secure Extra.
`additionalProductTypes` e `additionalProtectionModels` sono possibilità di
assegnazione ai clienti, non prodotti attualmente acquistati: non vengono usati.
Campi mancanti/nulli o codici prodotto sconosciuti producono **Non fornito
dall’API**; non si deduce un’edizione commerciale dai moduli installati.
Un modello sconosciuto non nasconde il tipo prodotto noto. Tipi JSON errati
provocano un errore sanitizzato e conservano i dati precedenti.
La lettura usa la chiamata già esistente, senza richieste aggiuntive. I casi
dei test derivano dai valori/condizioni del contratto ufficiale; non sono payload
cliente; la lettura è stata eseguita anche nell’aggiornamento reale autorizzato
del 04/11/2026.

## Inventario endpoint

`getNetworkInventoryItems` usa `/v1.1/jsonrpc/network`, `parentId`, `page`
e `perPage: 100`. Per ogni azienda legge ricorsivamente computer, macchine
virtuali e istanze EC2 (tipi 5/6/7). Esegue inoltre la ricerca documentata
dei gruppi Active Directory e la lettura ricorsiva dai relativi ID;
le sovrapposizioni fra azienda e gruppi vengono deduplicate per ID.

`hasMoreRecords` guida la continuazione; `page`, `perPage` e lista `items`
sono validati. Totali e numero di pagine non sono necessari sulle pagine
successive. Una pagina incoerente, un ID duplicato entro lo stesso contenitore
o un errore successivo impediscono l’import parziale dell’azienda. Oltre
1000 pagine per contenitore si segnala un errore, senza troncare l’import.
Si verifica `companyId` prima di attribuire i dati all’azienda locale.
L’inventario ricorsivo di un partner può includere i PC dei suoi clienti:
vengono esclusi da quella lettura soltanto i record appartenenti ad altre
aziende già scoperte, che vengono lette separatamente sotto il proprio ID.
Un proprietario sconosciuto resta un errore; la verifica non viene rimossa.
I partner non condivisi conservano l’eventuale inventario locale precedente,
senza dichiarare una nuova lettura riuscita o spostare dati commerciali.

Si conservano soltanto `id`, `name` e i dettagli disponibili
`operatingSystemVersion`, `isManaged`, `isIsolated` e `firstSeen`. I campi mancanti sono
sconosciuti; non si inventano stato online o prodotto commerciale.
Non si acquisiscono IP, MAC o SID. Le richieste sono distanziate
di 250 ms; eventuali 429 interrompono le successive, senza retry.
Fonte: [getNetworkInventoryItems](https://www.bitdefender.com/business/support/en/77211-128494-getnetworkinventoryitems.html).

L’ID API è univoco; il nome è aggiornabile e non provoca associazioni locali.
Prima di cambiare i dati si controllano i conflitti di appartenenza fra tutte
le aziende lette. Gli inventari ambigui conservano i dati precedenti.
Gli aggiornamenti sono transazionali per azienda. Ogni elemento importato
crea direttamente un `Computer` con `gravityzone_id` univoco, nome tecnico,
azienda remota e altri dettagli tecnici; la scadenza commerciale inizia vuota.
Non vengono più creati o associati record di postazioni/coperture separate.
L’upsert aggiorna soltanto i campi remoti: non cambia azienda locale,
date commerciali, prodotto, prezzo, riferimenti, note, versione o storico.

Su richiesta esplicita del committente del 04/11/2026, `details.firstSeen`
inizializza automaticamente **solo l’attivazione mancante**. Il timestamp
`YYYY-MM-DDTHH:mm:ss` documentato viene validato rigorosamente e se ne conserva
la data calendario, senza attribuire un fuso non dichiarato dall’API.
Si conserva in `first_seen_on` la prima data valida acquisita; assenza/null/stringa vuota
non producono date sostitutive. La stringa vuota è stata osservata nella
lettura reale autorizzata del 04/11/2026. Un formato errato invalida l’inventario
aziendale, conservando i dati precedenti.

Questa inizializzazione è l’unica eccezione esplicitamente richiesta alla
separazione dei dati commerciali: avviene sotto lock, incrementa la versione
e conserva lo snapshot precedente quando non vuoto. `activation_from_first_seen`
distingue una data automatica da una data locale, così un import altrimenti
vuoto rimane riconciliabile e utilizzabile come sostituto. Le date già presenti,
quelle cancellate/trasferite e le scadenze sono conservate. Se il primo
rilevamento supera una scadenza locale già inserita, l’attivazione rimane vuota.
Il primo rilevamento viene acquisito una sola volta: sincronizzazioni successive
non correggono né ripristinano l’attivazione. Non si aggiungono richieste API.

`company_id` conserva l’appartenenza locale, mentre `gravityzone_company_id`
segue quella rilevata. Le assenze si calcolano sull’azienda remota: uno
spostamento fra aziende non deve segnare assente un computer appena rilevato
nell’altra azienda. Il pannello segnala la differenza senza trasferire dati
commerciali automaticamente. I computer manuali hanno riferimento remoto nullo.
La riconciliazione esplicita di un PC manuale con un import vuoto della stessa
azienda avviene in transazione, mantenendo un solo record con l’ID remoto.
I vincoli locali sono descritti in [licenses.md](licenses.md).

Le fixture `inventory-ad-folders.json`, `inventory-page-1.json` e
`inventory-page-2.json` sono proiezioni anonime dell’esempio AD ufficiale;
i tre computer sono distribuiti intenzionalmente su due pagine simulate.
Non sono snapshot reali né riproduzioni delle incongruenze dell’esempio
v1.1 generale.

**Collaudo storico, precedente a #26:** il primo import reale aveva letto
102 endpoint unici di 16 aziende senza errori. Un secondo import aveva
mantenuto gli ID e il collegamento a una postazione manuale creata dal browser,
conservandone nome e note; il record di prova era stato poi rimosso dal solo DB
di sviluppo. Questi risultati riguardano il vecchio schema e non certificano
il nuovo flusso `Computer`. Il contratto e i metodi remoti restano invariati.
Il collaudo aggiornato sul modello Computer è riportato nella sezione seguente.

**Collaudo aggiornato del 04/11/2026:** dopo la sostituzione della chiave dal
pannello e l’autorizzazione del committente, scoperti 4 partner e 18 clienti
accessibili. Due partner dichiarano la sicurezza non condivisa: sono visibili,
ma il loro inventario e i loro clienti non vengono richiesti. Sincronizzazione
aziende e inventario completata con 115 PC, nessun errore. I 102 PC precedenti
mantengono ID, proprietà e tutti i dati commerciali/storico; 13 nuovi PC
iniziano con campi commerciali vuoti. Confrontati anche contatti/note aziendali
e credenziale cifrata, invariati. Database MySQL locale verificato, nessuna
modifica a GravityZone; dettagli e limiti in [dashboard-check.md](dashboard-check.md).

## Stato licenza endpoint (#33)

`getManagedEndpointDetails` usa `/v1.0/jsonrpc/network` con il solo `endpointId`.
Si verificano `result.id` e `result.companyId` contro endpoint e azienda
rilevati, prima di leggere `agent.licensed`: 0 autenticazione in attesa,
1 licenza attiva, 2 scaduta, 6 assente/non applicabile. Campi mancanti/nulli
e codici interi sconosciuti restituiscono stato non disponibile. Oggetti o
tipi errati vengono rifiutati senza esporre la risposta remota.
Non si conservano IP, utenti, moduli, chiavi o altri dati del dettaglio.
`state` indica lo stato della macchina e non viene usato come stato licenza.

La lettura avviene solo per endpoint presenti e `isManaged: true`, dopo
l’import transazionale dell’inventario e la verifica dei conflitti aziendali.
Ogni risultato aggiorna soltanto `gravityzone_license_status`,
`license_checked_at` e `license_sync_error`, usando l’ID GravityZone anche
quando il PC è stato riconciliato. Nessuna modifica a versione, dati o storico
commerciali. Se l’endpoint non è più gestito o lo stato di gestione manca,
lo stato licenza precedente viene svuotato perché non è applicabile alla
rilevazione corrente; gli endpoint assenti conservano invece l’ultimo dato.

Un errore di dettaglio conserva stato e data precedenti, salva un errore
sanificato e non annulla l’inventario già acquisito. Le altre letture proseguono
solo per errori puntuali; rete, HTTP, autenticazione e 429 interrompono le
successive chiamate. Gli endpoint saltati sono segnalati come non aggiornati.
Il riepilogo aziendale/globale segnala la sincronizzazione incompleta, anche
se l’inventario è stato importato. Un’interruzione anomala lascia il messaggio
di tentativo incompleto già presente sull’azienda.

Ogni richiesta aggiuntiva è distanziata di 250 ms e riusa lock e cooldown
condivisi. Non si effettuano chiamate al rendering. Nessuna nuova dipendenza
o servizio: resta disponibile il comando sincrono `bitlicense:sync-endpoints`.
Il pannello richiede il ciclo completo in background tramite la cache condivisa;
il Cron esegue `bitlicense:sync` alle 03:00 Europe/Rome oppure alla prima
scansione al minuto dopo una richiesta manuale. Non dipende dal timeout
della richiesta web. Apertura e navigazione non avviano sincronizzazioni.

La fixture `endpoint-license.json` è una proiezione anonima dell’esempio
ufficiale, non una risposta cliente; errori e varianti sono simulati nei test.
Dopo il via libera esplicito del committente, collaudo reale del 04/11/2026:
115 PC importati, 108 dettagli di endpoint gestiti acquisiti (89 con codice 1,
19 con codice 2), 7 PC non gestiti senza richiesta di dettaglio. Nessun errore
API/aziendale; dati commerciali, storico, proprietà locale e credenziale salvata
invariati. La fixture resta anonima/documentale; non si conserva il payload reale.
Gli altri codici e i casi di errore sono verificati soltanto con risposte simulate.
Fonti: [getManagedEndpointDetails](https://www.bitdefender.com/business/support/en/77211-128484-getmanagedendpointdetails.html),
[Network](https://www.bitdefender.com/business/support/en/77211-128476-network.html),
[limiti API](https://www.bitdefender.com/business/support/en/77211-394430-api-rate-limits.html).

## Ripetibilità, errori e limiti

L’elenco viene acquisito e validato prima di una transazione locale.
L’upsert usa l’ID esterno univoco e modifica esclusivamente campi tecnici.
Aziende non restituite vengono segnate assenti, senza cancellarle, soltanto
al completamento della scoperta e delle letture aziendali. Un errore intermedio
conserva la presenza precedente dei clienti non ancora verificati.
Contatti e note locali non rientrano negli aggiornamenti di sincronizzazione.
Le licenze sono acquisite separatamente: errori puntuali non bloccano le
altre aziende; errori di rete, HTTP e 429 interrompono le letture successive.
La mancata acquisizione dell’elenco non modifica le aziende esistenti.

I limiti documentati sono per chiave API: cinque richieste al secondo per
`getLicenseInfo` e `getNetworkInventoryItems`, dieci per `getCompaniesList`
(limite predefinito). Le letture di licenze/inventario sono sequenziali e
distanziate di almeno 250 ms, quindi al massimo quattro al secondo nel singolo
import; il lock condiviso impedisce sincronizzazioni sovrapposte.
Non vengono eseguiti retry automatici. HTTP 429 interrompe l’import e salva
in cache il termine di attesa indicato da `Retry-After`, in secondi o data
RFC 7231. Fino a quel momento tutte le letture, incluse verifica connessione
e nuovi avvii da pannello/CLI, sono rifiutate localmente senza chiamare l’API.
Se l’header manca o non è valido, BitLicense applica una pausa prudenziale di
60 secondi: è una scelta applicativa, non un limite dichiarato da Bitdefender.
Il messaggio mostra il tempo residuo; non mantiene la richiesta browser in
attesa e non richiede Redis o worker. Altre applicazioni con la stessa chiave
possono comunque consumare la quota; le loro richieste non sono controllate
da BitLicense. Non si usa la coda burst remota per aumentare la frequenza.
Fonte: [limiti API](https://www.bitdefender.com/business/support/en/77211-394430-api-rate-limits.html).

Un lock cache condiviso da comandi e pannello impedisce avvii sovrapposti
anche fra import aziende e endpoint.
Il lock scade dopo un’ora in caso di terminazione anomala. Il riepilogo aziendale mostra lo stato di sincronizzazione
disponibile; il database conserva tentativi, letture
riuscite ed errori globali/per azienda, ultima presenza e lettura licenza.
Un processo interrotto lascia visibile lo stato non completato. La cache database nativa
non richiede Redis o worker.

## Fixture e collaudo

Le fixture `companies-list.json`, `license-yearly.json` e `license-monthly.json`
in `tests/Fixtures/GravityZone` riproducono gli esempi ufficiali completi,
sostituendo nomi/ID aziendali e chiavi licenza con dati fittizi. Tutte usano
l’ID RPC fisso `103d7b05-ec02-481b-9ed6-c07b97de2b7a` delle richieste.
La fixture `license-monthly-end.json` è una proiezione minima dei campi e
tipi effettivamente verificati tramite letture API autorizzate il 04/11/2026:
`subscriptionType: 3`, `expiryDate` con ora, `endSubscription` come data e
`autoRenewPeriod: 0`. Le date sono sostituite con valori fittizi; nessun nome,
ID azienda, chiave o payload cliente è conservato nella fixture. Mancanza di
`endSubscription` e `autoRenewPeriod` osservata dal vivo; null e valori invalidi
sono casi limite simulati. La fixture documentale mensile precedente resta
invariata. I test non usano credenziali reali e bloccano richieste HTTP non simulate.

**Collaudo storico, precedente a #26:** dopo inserimento della chiave nel
pannello e autorizzazione del cliente, `getCompaniesList` e `getLicenseInfo`
sono stati verificati realmente su 16 aziende: import dal pannello e successiva sincronizzazione da CLI senza
duplicati, con contatto e nota locali temporanei conservati e poi rimossi.
Tutte le 16 licenze hanno esposto una scadenza; nessun errore di lettura.
Le fixture restano documentali: nessun payload cliente è salvato nel repository.
I casi di errore, assenza e date mancanti sono verificati con fixture;
non vengono provocati errori sul servizio reale.
Non sono state eseguite operazioni mutanti su GravityZone.
