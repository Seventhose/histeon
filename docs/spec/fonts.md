# Font locali — v0.1

Implementazione:
- `plugin/src/Fonts/LocalFonts.php`;
- sezione **Font locali** in Impostazioni (`SettingsScreen`);
- nel builder: `plugin/src/Fonts/Rest.php`, `editor/src/components/FontsPanel.svelte` dentro il Design system, l'elenco `plugin/assets/fonts/google-fonts.json`.

Test: `plugin/tests/Integration/LocalFontsTest.php`, `plugin/tests/Integration/FontsRestTest.php`, `e2e/specs/fonts.spec.ts`.

## Perché
Caricare i font dai server di Google fa arrivare a un terzo l'IP di chi visita. Con Histeon i font stanno nel sito e nessuna pagina li chiede ad altri.

## Come
- **Importa da Google Fonts:** famiglia, pesi e corsivo opzionale.
  - Il server scarica una sola volta il CSS da `fonts.googleapis.com` e i file WOFF2 da `fonts.gstatic.com`.
  - Le sorgenti di altri host vengono ignorate.
  - Niente redirect; al massimo 60 file da 2 MB.
- **Carica un file:** WOFF2 o WOFF, riconosciuto dalla firma (`wOF2` / `wOFF`) e non dal nome né dal tipo dichiarato. Al massimo 2 MB.
- **Dove finiscono i file:** in `uploads/xb-fonts/<famiglia>/`, con nomi scelti da Histeon (famiglia, peso, stile, hash).
- **Definizioni:** stanno nell'opzione `xb_local_fonts`. Ogni voce viene ricontrollata prima di essere stampata: famiglia, peso, stile, percorso, formato, `unicode-range`.
- **Dove arrivano le regole:** le `@font-face` (con `font-display: swap`) vanno nel `<head>` delle pagine, nell'editor a blocchi e nella tela del builder. L'esportazione statica copia i file.
- **Permessi:** solo gli amministratori, con nonce.
- **Uso:** nel CSS, `font-family: "Inter"`.
- **Pesi che la famiglia non ha:** se la richiesta di tutti i pesi insieme non va a buon fine (per esempio perché un peso o il corsivo non esistono per quella famiglia), Histeon chiede ogni peso da solo e tiene quelli che arrivano.

## Nel builder
- `GET xb/v1/fonts` (`xb_build`): `{families: [{family, faces: [{weight, style}]}], css, canManage, catalog}`. `css` sono le stesse `@font-face` delle pagine, per le anteprime nel pannello. `catalog` è l'elenco incluso nel plugin (famiglia, genere, pesi, corsivo): il browser non chiede nulla a Google.
- `POST xb/v1/fonts/google` `{family, weights, italic}` e `DELETE xb/v1/fonts?family=…`: solo chi gestisce le impostazioni (`manage_options` e `xb_build`), come in Impostazioni.
- Il pannello **Font del sito** sta in cima al Design system: anteprime, **Copia** della pila (`"Inter", sans-serif`, con il genere del catalogo), e con Histeon CSS i pulsanti **Testo**, **Titoli**, **Codice**, **Accento**, **Display** che scrivono `font_body`, `font_heading`, `font_mono`, `font_accent`, `font_display` del generatore (anteprima nella tela, salvataggio in sviluppo come gli altri parametri).
- Dopo un'aggiunta o una rimozione la tela si ridisegna: il suo CSS globale contiene già le `@font-face`.

I font sono file e non hanno versioni: aggiungerli o rimuoverli vale subito.

## Precaricamento
Nell'head, prima delle `@font-face`, al massimo due `<link rel="preload" as="font" type="font/woff2" crossorigin>`: il font del testo al peso 400 e quello dei titoli al peso dei titoli, come li dice il design del sito (`font_body`, `font_heading`, `heading_weight`), dritti, woff2, il sottoinsieme latino quando Google li divide per intervallo; un font variabile («400 700») vale se copre il peso. Niente per i font di sistema. Di più non si precarica: un font non usato dalla pagina costerebbe più di quanto fa guadagnare. `LocalFonts::preloads()`; test: `plugin/tests/Integration/FontPreloadTest.php`.
