# Dati e-commerce: WooCommerce e FluentCart — v0.2

Implementazione:
- `plugin/src/Data/ProductNode.php`;
- in `LoopPresets`, l'esclusione dei prodotti nascosti.

Test: `plugin/tests/Integration/WooCommerceTest.php`, che viene saltato se WooCommerce non è attivo. Verificato con WooCommerce 11.2.1.

Un prodotto si legge con `this.product.*` nel modello del prodotto e con `item.product.*` in un loop di contenuti di tipo `product`:

| Chiave | Valore |
|---|---|
| `price`, `regularPrice`, `salePrice` | numeri |
| `onSale`, `inStock` | sì/no |
| `priceText` | il prezzo formattato dal negozio (valuta, decimali), come testo |
| `currency`, `currencySymbol` | valuta del negozio |
| `sku`, `type`, `stockStatus` | testo |
| `shortDescription` | il riassunto, senza markup |
| `addToCartUrl`, `addToCartText` | il link e il testo del pulsante «aggiungi al carrello» |
| `rating`, `reviewCount` | valutazione media e numero di recensioni |
| `gallery` | immagini (`.url`, `.alt`…) |
| `categories`, `tags` | termini di `product_cat` e `product_tag` |

Regole:
- **Sola lettura**, solo dati del catalogo: mai ordini, clienti o movimenti di magazzino.
- **Prodotti nascosti:** un prodotto con visibilità «nascosto» vale `null`, e i loop non elencano mai i prodotti esclusi dal catalogo.
- **Pagine statiche:** carrello e cassa restano dinamici in WooCommerce: l'export statico (zip e deploy) lascia fuori le pagine di carrello, cassa e account. I link verso di esse diventano relativi alla radice come gli altri, quindi funzionano dove WordPress serve ancora quei percorsi (Static Bridge sullo stesso dominio), non su un hosting solo statico. Lo stesso vale per i link «aggiungi al carrello» (`?add-to-cart=`): con Static Bridge le URL con parametri tornano a WordPress; su un hosting solo statico, come il deploy su Cloudflare, il negozio non funziona e va lasciato dinamico. Il sito statico di Histeon Publish fa lo stesso: il link porta al prodotto sul sito del negozio (`?product=<slug>`), dove si aggiunge al carrello.

## Prodotti dal CMS

Implementazione: `plugin/src/Remote/ProductProfile.php`, con `ContentProfileAdapter` e `PageProfile`. Test: `plugin/tests/Unit/ProductProfileTest.php`, `plugin/tests/Integration/CmsProductsTest.php` (saltato senza WooCommerce), e in Histeon Publish `npm run test:commerce:wordpress:local`. Verificato con WooCommerce 11.2.1.

Un progetto di Histeon Publish (anche un prototipo) può avere il tipo `product`: con WooCommerce attivo i suoi record diventano **prodotti semplici** di WooCommerce, e il ponte ([cms-bridge.md](cms-bridge.md) §15) li porta nei due sensi. È ancora solo catalogo: il ponte non scrive mai ordini, clienti, carrelli, pagamenti, quantità o movimenti di magazzino.

| Nel progetto | In WooCommerce |
|---|---|
| tipo `product` (campi fissi, sotto) | il tipo `product` di WooCommerce; Histeon non lo registra |
| `title`, `slug`, `status`, `publishedAt` | nome, slug, stato e data del prodotto, come per ogni record |
| `excerpt` | descrizione breve (testo, senza `<` e `>`) |
| `regular_price`, `sale_price` | prezzo pieno e scontato: numeri da 0 a 999.999.999, al massimo 4 decimali, scontato minore del pieno |
| `sku` | SKU: lettere, cifre, `.` `_` `-` `/`, al massimo 64 caratteri, unico nel negozio |
| `stock_status` | `instock`, `outofstock` o `onbackorder`; la gestione delle quantità resta com'è |
| `image`, `gallery` | immagine e galleria (al massimo 20), allegati della libreria |
| tassonomie `product_cat`, `product_tag` | le categorie e i tag dei prodotti di WooCommerce; un termine con lo stesso slug e senza progetto viene adottato |
| template del tipo (`singleTemplateRef`, `archiveTemplateRef`) | i template `single-product` e `archive-product` del tema attivo, che quindi leggono `this.product.*` e i loop di prodotti; `archive-product` vale anche per le pagine delle categorie e dei tag dei prodotti |

Regole:
- **Capacità:** il sito dichiara `commerce:products:v1` (in `capabilities.commerce`) solo con WooCommerce attivo, e un trasferimento con il tipo `product` o con `product_cat`/`product_tag` la richiede. Senza WooCommerce l'invio si ferma prima di scrivere; Histeon Publish non lo manda nemmeno.
- **Permessi:** quelli di ogni invio, più `edit_products` per i prodotti e `manage_product_terms` per le loro categorie e i tag. Valgono anche alla release che applica la coda: se chi pubblica non li ha, i prodotti restano in coda e il Registro lo dice (`cms_queue_failed`).
- **Sviluppo e release:** come per ogni record. Un invio senza pubblicazione non cambia il negozio: prodotti, termini e template assegnati aspettano la prossima release di Histeon, che li applica; un prodotto pubblicato cambia online solo allora.
- **Le modifiche in WordPress vincono:** il ponte ricorda i dati che ha dato a WooCommerce (`_xb_page_profile_product`, con un hash); se qualcuno li cambia in WordPress, lo scaricamento mostra quelli di WooCommerce e il CMS vede il conflitto.
- **Scaricamento:** il tipo `product` viaggia solo dopo che un progetto l'ha inviato. Vengono i prodotti semplici con dati che il profilo sa portare; gli altri (variabili, raggruppati, esterni, con markup nella descrizione breve, prezzi con più decimali, SKU con altri caratteri) restano fuori, e se uno di questi è del progetto lo scaricamento si ferma e dice quale correggere. Le categorie e i tag vengono tutti, tranne la categoria predefinita di WooCommerce finché il progetto non la usa.
- **Prima di scrivere** si controllano SKU di altri prodotti, prodotti del progetto diventati non semplici, termini di altri progetti.


Implementazione: `plugin/src/Data/FluentCartProductNode.php`. Test: `plugin/tests/Integration/FluentCartTest.php`, saltato se FluentCart non è attivo. Verificato con FluentCart 1.6.4.

I prodotti FluentCart (tipo `fluent-products`) si leggono con le stesse espressioni: `this.product.*` nel modello del prodotto, `item.product.*` in un loop con `type: "fluent-products"`. Ci sono le stesse chiavi di WooCommerce dove hanno lo stesso significato, più le cose proprie di FluentCart:

| Chiave | Valore |
|---|---|
| `price`, `regularPrice`, `salePrice`, `onSale` | Della variante predefinita, in unità di valuta (`10` per 10 €). |
| `minPrice`, `maxPrice` | Prezzo più basso e più alto tra le varianti attive. |
| `priceText` | Il prezzo formattato dal negozio; un intervallo «10 € – 25 €» se le varianti costano diversamente. |
| `currency`, `currencySymbol`, `sku` | Come per WooCommerce. |
| `inStock` | Almeno una variante attiva è disponibile. |
| `stockStatus` | Lo stato di magazzino del prodotto. |
| `type` | `simple`, `simple_variations` o `advanced_variations`. |
| `fulfillment` | `physical`, `digital`, `service` o `mixed`. |
| `shortDescription` | Il riassunto, senza markup. |
| `buyNowUrl`, `addToCartUrl` | Il «compra ora» di FluentCart, che porta al checkout con un pezzo della variante predefinita. `addToCartUrl` è lo stesso link: per aggiungere al carrello restando sulla pagina si usa il pulsante di FluentCart (`variantId`, sotto). Il testo del pulsante è `addToCartText`. |
| `variantId` | Id della variante predefinita, per il pulsante «aggiungi al carrello» di FluentCart (`data-fluent-cart-add-to-cart-button data-cart-id={item.product.variantId}`). |
| `variations` | Le varianti attive, nel loro ordine: `{id, title, sku, price, regularPrice, onSale, priceText, inStock, buyNowUrl, subscription}`. |
| `subscription` | Della variante predefinita, `null` se non è un abbonamento: `{interval, times, trialDays, signupFee, text}`. `text` è la frase di FluentCart, quando sa costruirla. |
| `gallery`, `categories`, `brands` | Immagini; termini di `product-categories` e di `product-brands`. `tags` è vuoto, perché FluentCart non ha tag. |
| `cartUrl`, `checkoutUrl` | Le pagine di carrello e cassa del negozio. |
| `rating`, `reviewCount` | Non esistono in FluentCart: `null`. |

Regole:
- **Sola lettura**, solo dati del catalogo, come per WooCommerce: mai ordini, clienti, carrelli o abbonamenti attivi.
- **Varianti disattivate** non compaiono mai. Un prodotto in bozza resta invisibile a chi visita, come ogni contenuto.
- **Robustezza:** se FluentCart cambia o ha dati malformati, la chiave vale `null` e la pagina resta in piedi.
- **Pagine statiche:** come per WooCommerce, cassa e carrello restano dinamici in FluentCart; i link «compra ora» portano al sito.
- **Esportazione statica bloccata con FluentCart attivo** (verificato con 1.6.4). Con FluentCart attivo le pagine contengono un nonce di WordPress; la scansione dell'esportazione (`docs/spec/static.md`) lo riconosce come dato di sessione e ferma zip e deploy. È giusto così: un nonce scade dopo al massimo 24 ore e in una copia statica romperebbe il carrello. Un negozio FluentCart resta quindi dinamico, oppure va servito con Static Bridge escludendo le pagine del negozio. Altro da sapere: l'archivio dei prodotti (`/item/`) risponde solo dopo aver rigenerato i permalink.

