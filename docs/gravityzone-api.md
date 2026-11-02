# Contratto GravityZone verificato

Verifica documentale: 2 novembre 2026. Nessun POC o payload live disponibile
durante questa implementazione. Le fixture derivano dalla documentazione
ufficiale, non dimostrano che le credenziali dell'installazione funzionino.

Il solo metodo implementato è `getCompaniesList`, lettura del servizio Network.
Il client non espone un invocatore RPC generico né operazioni mutanti.
La richiesta usa POST JSON-RPC 2.0 sul suffisso `/v1.0/jsonrpc/network`;
la base configurata termina in `/api`. L'autenticazione HTTP Basic usa la
chiave come nome utente e una password vuota. TLS rimane verificato e i
redirect sono rifiutati per non inoltrare le credenziali ad altre destinazioni.
La connessione ha timeout di 5 secondi, la richiesta di 20 secondi.
Fonte: [esempi API ufficiali](https://www.bitdefender.com/business/support/en/77211-141188-api-usage-examples.html).

La richiesta invia `params: []`. Il risultato documentato è una lista di aziende,
ciascuna con `id` e `name`; entrambi sono richiesti come stringhe non vuote.
Il client controlla versione RPC e corrispondenza dell'ID di richiesta,
ammette una lista vuota e restituisce soltanto questi due campi.
Non vengono ricavate scadenze tecniche o descrizioni commerciali da questo metodo.
`getCompaniesList` non documenta paginazione: non vengono aggiunti `page` o
`perPage`. La paginazione sarà gestita nella sincronizzazione (#6) soltanto
per metodi necessari che la supportano ufficialmente.
Fonte: [contratto getCompaniesList](https://www.bitdefender.com/business/support/en/77211-128478-getcompanieslist.html).

`tests/Fixtures/GravityZone/companies-list.json` riproduce esattamente la
struttura della risposta nell'esempio del contratto, sostituendo soltanto
ID e nomi delle aziende. L'ID RPC fisso dell'esempio
`103d7b05-ec02-481b-9ed6-c07b97de2b7a` è mantenuto anche nelle richieste.

Un HTTP 429 produce un errore leggibile; se presente, `Retry-After` viene
interpretato come secondi o data HTTP RFC 7231 e mostrato come attesa residua.
Non sono previsti attese bloccanti o tentativi automatici.
Errori di trasporto, autenticazione, HTTP, RPC e formato producono messaggi
locali senza corpo remoto, URL, header, chiavi o eccezioni concatenate.
Fonte: [limiti API ufficiali](https://www.bitdefender.com/business/support/en/77209-394430-api-rate-limits.html).

Prima del collaudo reale il cliente deve inserire la chiave nel pannello
amministrativo protetto e autorizzare esplicitamente la ripresa. Non inserire
credenziali nei test, nella chat, nei log o nel repository.
