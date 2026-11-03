# Contratto GravityZone verificato

Verifica documentale: 3 novembre 2026. Il client espone soltanto quattro letture
esplicite: `getCompaniesList`, `getNetworkInventoryItems` (Network),
`getLicenseInfo` (Licensing) e `getCompanyDetails` (Companies).
Non contiene un invocatore RPC generico o metodi che modificano GravityZone.

La base configurata termina in `/api`. Le richieste POST usano JSON-RPC 2.0,
HTTP Basic con la chiave come utente e password vuota, TLS verificato,
redirect disabilitati e timeout di connessione/richiesta di 5/20 secondi.
Errori HTTP/RPC, autenticazione, rete e formati non validi producono messaggi
locali senza corpo remoto, chiavi o eccezioni concatenate.
Fonte: [esempi API](https://www.bitdefender.com/business/support/en/77211-141188-api-usage-examples.html).

## Aziende

`getCompaniesList` usa `/v1.0/jsonrpc/network` e `params: []`. Il risultato
è una lista, anche vuota, di oggetti con `id` e `name`. ID vuoti, duplicati,
oltre 255 caratteri o record incompleti invalidano l’intero elenco prima
del salvataggio. Nessuna paginazione è documentata per questo metodo;
non vengono aggiunti parametri presunti.
Fonte: [getCompaniesList](https://www.bitdefender.com/business/support/en/77211-128478-getcompanieslist.html).

`getCompanyDetails` usa `/v1.0/jsonrpc/companies` con `companyId` esplicito.
Si controlla che l’oggetto restituito abbia lo stesso ID richiesto e si conserva
solo `isSuspended`: `true` significa **Sospesa**, `false` **Attiva**. Campo
assente/nullo significa **Stato non disponibile**; stringhe/numeri non sono
convertiti implicitamente. Presenza nell’inventario e scadenza della licenza
non determinano lo stato dell’account. Altri dettagli, contatti e indirizzi
remoti non vengono salvati.

La sincronizzazione legge lo stato dopo la licenza, con pausa di 250 ms.
Se questa lettura fallisce, conserva la licenza appena acquisita e lo stato
precedente, segnala l’aggiornamento incompleto e mostra quest’ultimo come
**ultimo stato rilevato**. Come per gli altri errori di sincronizzazione, il
pannello segnala il fallimento prima di avviare l’import dei computer.
La chiave deve consentire la lettura nel servizio Companies. Nessuna lettura
avviene all’apertura del selettore. La nuova chiamata non è ancora stata
eseguita contro GravityZone reale; i casi simulati sono proiezioni anonime
dei campi documentati, non payload cliente.
Fonte: [getCompanyDetails](https://www.bitdefender.com/business/support/en/77211-126239-getcompanydetails.html).

## Licenza tecnica aziendale

`getLicenseInfo` usa `/v1.0/jsonrpc/licensing` con il `companyId` esplicito.
Legge la licenza principale; non richiede `returnAllProducts` e non aggrega
le diverse scadenze di `additionalLicenses`. Il risultato degli esempi è
un oggetto, benché la prosa del contratto lo definisca Array.

Vengono conservati il tipo di abbonamento `subscriptionType`
(1 prova, 2 licenza, 3 mensile, 4 licenza mensile di prova, 5 abbonamento
mensile di prova, 6 FRAT) e la parte calendario di `expiryDate`.
La data può mancare o essere nulla: viene mostrata come **Non disponibile**.
Il formato documentato è `YYYY-MM-DDTHH:mm:ss`, privo di fuso dichiarato;
non viene convertito in UTC o Europe/Rome. Una data non valida provoca un
errore e conserva il precedente dato noto, segnalato come non aggiornato.
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
cliente e questa aggiunta non è ancora stata verificata con l’API reale.

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

Si conservano soltanto `id`, `name` e i dettagli disponibili
`operatingSystemVersion`, `isManaged`, `isIsolated`. I campi mancanti sono
sconosciuti; non si inventano stato online, prodotto commerciale o attivazione.
Non si acquisiscono IP, MAC, SID o `firstSeen`. Le richieste sono distanziate
di 250 ms; eventuali 429 interrompono le successive, senza retry.
Fonte: [getNetworkInventoryItems](https://www.bitdefender.com/business/support/en/77211-128494-getnetworkinventoryitems.html).

L’ID API è univoco; il nome è aggiornabile e non provoca associazioni locali.
Prima di cambiare i dati si controllano i conflitti di appartenenza fra tutte
le aziende lette. Gli inventari ambigui conservano i dati precedenti.
Gli aggiornamenti sono transazionali per azienda. Ogni elemento importato
crea direttamente un `Computer` con `gravityzone_id` univoco, nome tecnico,
azienda remota e altri dettagli tecnici; le date commerciali iniziano vuote.
Non vengono più creati o associati record di postazioni/coperture separate.
L’upsert aggiorna soltanto i campi remoti: non cambia azienda locale,
date commerciali, prodotto, prezzo, riferimenti, note, versione o storico.

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
il nuovo flusso `Computer`. Il contratto e i metodi remoti restano invariati;
nessun nuovo test live del refactor #26 è documentato qui.

## Ripetibilità, errori e limiti

L’elenco viene acquisito e validato prima di una transazione locale.
L’upsert usa l’ID esterno univoco e modifica esclusivamente campi tecnici.
Aziende non restituite vengono segnate assenti, senza cancellarle.
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
I test non usano credenziali reali e bloccano richieste HTTP non simulate.

**Collaudo storico, precedente a #26:** dopo inserimento della chiave nel
pannello e autorizzazione del cliente, `getCompaniesList` e `getLicenseInfo`
sono stati verificati realmente su 16 aziende: import dal pannello e successiva sincronizzazione da CLI senza
duplicati, con contatto e nota locali temporanei conservati e poi rimossi.
Tutte le 16 licenze hanno esposto una scadenza; nessun errore di lettura.
Le fixture restano documentali: nessun payload cliente è salvato nel repository.
I casi di errore, assenza e date mancanti sono verificati con fixture;
non vengono provocati errori sul servizio reale.
Non sono state eseguite operazioni mutanti su GravityZone.
