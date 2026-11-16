# Importare un sito Etch

Se il tuo sito è stato costruito con Etch, non devi rifarlo da capo. Histeon legge pagine, componenti, template, stili e query salvati con Etch e li trasforma in contenuto Histeon. Il risultato arriva come **versione di sviluppo**: lo controlli, lo pubblichi quando ti convince e puoi sempre tornare indietro.

## Prima di cominciare

Alcune cose da sapere:

- **I dati di Etch non vengono toccati.** Opzioni, meta e contenuti di Etch restano dove sono; l'importazione scrive solo nelle versioni di sviluppo di Histeon.
- **Niente va online da solo.** Il sito pubblico cambia solo quando pubblichi una release.
- **Il sito funziona anche senza importare.** Se Etch non è attivo, Histeon disegna da sé i contenuti salvati con Etch, al momento della richiesta, e il sito resta com'era. Importare resta consigliato, ma non è mai obbligatorio.
- **L'importazione è stata provata su un sito di prova** costruito con Etch 1.7.1 e Automatic.css: le pagine importate sono risultate uguali all'originale, salvo le piccole differenze descritte più avanti, con gli script funzionanti.

> Fai un backup del sito prima di iniziare, come per ogni cambiamento importante. L'importazione è reversibile, ma un backup non costa nulla.

## Il promemoria

Finché sul sito ci sono contenuti salvati da Etch e non ancora importati, gli amministratori vedono nelle schermate di amministrazione l'avviso **Histeon: importazione consigliata**, con due pulsanti:

- **Importa ora** porta alla sezione di importazione;
- **Più tardi** nasconde l'avviso per 14 giorni, solo per te.

Lo stesso avviso compare anche quando Automatic.css non è attivo e i fogli già presenti nel sito restano caricati (vedi più avanti).

Anche la schermata **Primo avvio** di Histeon, al passo «Da dove arrivi», segnala i contenuti di Etch con il pulsante **Prepara l'importazione**.

## Importare passo per passo

1. Apri **Histeon → Impostazioni** e vai alla sezione **Importa da Etch**. Compare solo se Histeon trova dati di Etch sul sito.
2. Se il sito usa Automatic.css, scegli dove portare il suo sistema di design (vedi sotto).
3. Premi **Anteprima dell'importazione**. Histeon legge tutto e ti mostra il rapporto, **senza modificare nulla**.
4. Leggi il rapporto, soprattutto la colonna **Da verificare**.
5. Premi **Prepara in sviluppo**. Ora l'importazione è scritta, ma solo in sviluppo.
6. Apri le pagine nel builder e controllale in anteprima.
7. Pubblica dal builder con **Pubblica…**. Nella finestra di pubblicazione trovi **Differenze** e **Confronta** per vedere cosa cambia.

Dopo la pubblicazione le pagine sono disegnate da Histeon.

## Il rapporto

Il rapporto riassume quanti documenti, componenti, query e byte di CSS sono stati trovati, poi elenca ogni documento:

| Colonna | Cosa dice |
|---|---|
| **Documento** | Titolo, tipo e numero del contenuto |
| **Fiducia** | `trusted` se l'autore e l'ultimo a modificarlo potevano scrivere codice, altrimenti `restricted` |
| **Convertiti** | Quanti blocchi sono stati convertiti |
| **Script** | Quanti script degli elementi sono stati portati |
| **Da verificare** | Quello che Histeon non ha saputo convertire e che va sistemato a mano |

Sotto la tabella compaiono gli avvisi generali, per esempio i tipi di contenuto non pubblici che le query importate usano e che Histeon ha ammesso nei loop.

> **Perché la fiducia conta.** In Histeon ogni contenuto mantiene il livello di fiducia di **chi l'ha scritto**, non di chi importa. Il JavaScript di autori senza il permesso `unfiltered_html` non viene importato. Così un contenuto pericoloso salvato da un autore non diventa «fidato» solo perché lo importa un amministratore.

## Cosa si converte

| Da Etch | In Histeon |
|---|---|
| Pagine, articoli | Documenti Histeon, con la stessa struttura |
| Componenti | Componenti Histeon, con una chiave ricavata dal nome in Etch (`SchedaEvento` diventa `scheda-evento`) |
| Template e parti di template | Template Histeon, con versioni come il resto |
| Elementi e testi | Elementi Histeon, con tag, attributi ed etichette |
| Script degli elementi | Il JavaScript del documento (solo da autori fidati) |
| Loop e query | Query predefinite del loop, utilizzabili come le altre |
| Immagini dinamiche | Immagini con dimensioni e versioni responsive lette dalla libreria media |
| Stili per selettore | Stili per selettore di Histeon |
| Fogli globali | Fogli globali di Histeon |
| Espressioni tra graffe | Espressioni Histeon |
| Blocchi di WordPress e di altri plugin | Restano come sono, con i blocchi Etch al loro interno convertiti |

Le classi aggiunte dagli script vengono protette, così il CSS su richiesta non le scarta.

Se Histeon non ha ancora un framework CSS scelto e trova Automatic.css (o Core Framework), sceglie quello. L'importazione attiva anche il reset CSS di Histeon.

## Cosa non si converte

Histeon non indovina mai: quello che non riconosce resta com'era e finisce nella colonna **Da verificare**.

- **Condizioni, slot e blocchi SVG di Etch:** non sono ancora supportati e non si convertono. Vanno rifatti a mano nel builder.
- **Espressioni che non si traducono:** restano come testo, visibili nella pagina, e compaiono nel rapporto. I modificatori di Etch con un equivalente si traducono, anche i più recenti (`toSlug`, `isTruthy`, `isFalsy`, i confronti con i valori per vero e falso); quelli senza, come `round` o `toInt`, sono nominati nel rapporto.
- **JavaScript di autori non fidati:** non viene importato (vedi sopra).
- **Campi aggiunti da altri plugin ai dati di Etch** (per esempio `this.extra.campo`): funzionano solo se il plugin li offre anche a Histeon. È una riga di codice per chi mantiene quel plugin.
- **Il reset CSS:** non si copia. L'importazione usa il reset di Histeon, che lascia in linea i link messi direttamente in un contenitore. Se nel sito originale erano blocchi e ti serve lo stesso effetto, aggiungi una regola nel tuo CSS.

Controlla le pagine importate in anteprima: possono comparire piccole differenze nei testi, per esempio dove l'originale mostrava un'entità HTML codificata due volte e Histeon la mostra una volta sola.

## Il sistema di design: Automatic.css

Se il sito usa Automatic.css e i suoi fogli generati sono sul sito, la sezione **Importa da Etch** chiede «Il sito usa Automatic.css: dove va il suo sistema di design?»:

| Scelta | Cosa succede |
|---|---|
| **In Histeon CSS** (consigliato) | Il framework libero di Histeon, già incluso: i suoi token prendono i valori di Automatic.css, la palette parte dai tuoi colori, le classi equivalenti cambiano nome, il resto continua a valere. Nessun plugin esterno |
| **In Core Framework** | Compare se Core Framework è installato: un progetto con i colori e tutte le variabili di Automatic.css, più le sue regole che il sito usa |
| **Resta su Automatic.css** | Non cambia nulla: il sito continua a usare Automatic.css |

La scelta legge i valori dai fogli generati presenti nel sito.

## Da Automatic.css a Histeon CSS, senza Etch

Anche un sito che non usa Etch può passare da Automatic.css a Histeon CSS: si fa da **Histeon → Impostazioni → Cambio di framework**, con la stessa anteprima e lo stesso «Prepara in sviluppo». Il procedimento completo, con la tabella dei colori e dei nomi, è nel capitolo [Histeon CSS](#it-histos-css).

> **Se Automatic.css non è attivo.** I fogli già presenti nella cartella dei caricamenti del sito restano caricati finché non importi, e il sito resta com'era, senza eseguire codice di Automatic.css. Non cancellare quella cartella prima di aver importato.

## Dopo la pubblicazione

- Controlla il sito pubblico, su computer e telefono.
- I dati di Etch restano nel database: se dovessi tornare indietro, ripristini la release precedente in **Histeon → Release**.
- L'avviso «importazione consigliata» resta finché qualche contenuto fuori dal cestino contiene ancora blocchi di Etch: controlla quali pagine non hai pubblicato, oppure nascondilo con **Più tardi**.

## Esportare verso Etch

Se un sito deve restare su Etch, puoi riportare in Etch quello che hai costruito con Histeon: pagine, articoli, componenti, template, stili e loop pubblicati tornano nei dati di Etch **di questo sito**, con gli stessi indirizzi e gli stessi componenti. Si fa da **Histeon → Impostazioni → Esporta verso Etch**, che compare se sul sito ci sono dati di Etch o il plugin Etch.

1. Scegli i nomi dei token. **Histeon CSS** (consigliato) aggiunge due fogli globali con i token `--xb-*` e le classi di Histeon CSS, e il sito resta modificabile dai token. **Nomi di Automatic.css** compare solo se Automatic.css è già installato sul sito: i token che hanno un nome ACSS lo prendono (`--xb-space-m` diventa `--space-m`).
2. Premi **Anteprima dell'esportazione**. Non cambia nulla: vedi ogni documento, quanti blocchi passano, quanti restano fuori, se sostituisce contenuto che era già di Etch, e quali voci di Etch (query, fogli, stili) si aggiungono o si sostituiscono.
3. Se vuoi, premi **Scarica il file JSON** per tenere una copia di ciò che verrebbe scritto. Etch non importa questo file.
4. Spunta la conferma e premi **Scrivi in questo sito**. Histeon scrive solo ciò che hai visto nell'anteprima; se nel frattempo qualcosa è cambiato, si ferma e ti chiede di rifarla. Prima di scrivere fa una copia di sicurezza.
5. Se qualcosa non va, **Ripristina** rimette contenuti, chiavi dei componenti e opzioni di Etch come erano prima dell'ultima scrittura. Anche le versioni di Histeon restano: una release le riporta indietro.

Da WP-CLI: `wp xb export-etch --user=<amministratore>` per l'anteprima e l'impronta, poi `--apply --confirm=<impronta>` per scrivere e `--restore --yes` per tornare indietro.

**Cosa resta fuori**, e compare nell'anteprima: condizioni e slot (non ancora supportati), tipi di contenuto e record di Histeon e i loop che li elencano, loop su termini o utenti, i template di Histeon CMS, lo schema delle props dei componenti e le impostazioni di Histeon senza un corrispettivo in Etch. Il JavaScript di autori senza il permesso `unfiltered_html` non si esporta, come non si importa. Per scrivere serve un amministratore con `unfiltered_html`.

Per un altro sito Etch: porta prima il progetto su quel sito con Histeon installato (con il ponte o un file `.xbsite`), poi esporta lì. La prova finale va fatta su una copia del sito con Etch attivo.
