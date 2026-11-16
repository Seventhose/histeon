# Histeon

Builder visuale per WP, libero e senza chiavi di licenza.

Questo repository pubblica le **release** e i **sorgenti** della versione più recente, un commit per versione; lo sviluppo quotidiano avviene altrove. Ogni release contiene `xb-<versione>-<commit>.zip` (il plugin), `xb-blank-…zip` (il tema di base), le firme `.minisig`, `SHA256SUMS` e l'archivio dei sorgenti `histeon-<versione>-sorgenti.tar.gz`.

## Aggiornamenti

Il plugin si aggiorna da solo da questo repository: in **Histeon → Impostazioni → Aggiornamenti** scrivi `Seventhose/histeon` e la chiave pubblica di firma. Accetta solo pacchetti firmati.

Chiave pubblica di rilascio di Histeon (formato minisign, id `7245FEACC0E94D12`):

```
RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W
```

Prima di installare, controlla l'impronta del file con `SHA256SUMS` (`sha256sum -c SHA256SUMS`) e la firma con `minisign -Vm xb-<versione>-<commit>.zip -P RWQSTenArP5FclHgI0qEErGDYUEQ6gL4cEhpgec49VKtqKfgl2TIAn5W`.

## Licenza e sorgenti

GPL-3.0-or-later: il testo è in `LICENSE`. I sorgenti della versione più recente sono in questo repository; quelli di ogni versione sono anche nell'archivio allegato alla sua release.

- Segnalare una falla, in privato: [SECURITY.md](SECURITY.md).
- Il nome Histeon e i marchi di altri: [TRADEMARKS.md](TRADEMARKS.md).

Fino alla versione 0.3.11 Histeon si chiamava Histos: quel nome resta negli identificatori (`data-histos`, `histos-css`, i formati dei file), e il prefisso tecnico resta `xb` (blocchi `xb/…`, API `xb/v1`, opzioni `xb_…`), così i siti e i progetti esistenti continuano a funzionare.

## Che cosa c'è nel repository

| Percorso | Contenuto |
|---|---|
| `plugin/` | Il plugin per WP: renderer, espressioni, dati, stili, filtro del markup, blocchi, importazioni ed esportazioni |
| `editor/` | Il builder (Svelte 5 + CodeMirror 6), compilato in `plugin/assets/builder/` |
| `packages/dsl` | Implementazione di riferimento del DSL: DSL ⇄ albero ⇄ grammatica a blocchi |
| `packages/spec` | JSON Schema dei blocchi e vettori di prova condivisi per le espressioni |
| `theme/` | I temi di base: Fondamenta e XB Blank |
| `dev/` | WP di sviluppo (Docker), dati di esempio e prove d'integrazione |
| `e2e/` | Prove end-to-end del builder nel browser (Playwright) |
| `scripts/` | Pacchetti, manuale, controlli |
| `docs/` | I documenti, sotto |

## Documenti

- **Manuale**, per chi costruisce siti: in italiano in [docs/manual/it/](docs/manual/it/) e in inglese in [docs/manual/en/](docs/manual/en/), un capitolo per file. `node scripts/build-manual.mjs` lo raccoglie in un solo file HTML che si apre offline ([docs/manual/README.md](docs/manual/README.md)).
- **Specifiche**, per chi sviluppa: [docs/spec/](docs/spec/), una per argomento. Fra le altre:
  - [overview.md](docs/spec/overview.md): il quadro d'insieme;
  - [blocks.md](docs/spec/blocks.md): i blocchi `xb/*`;
  - [dsl.md](docs/spec/dsl.md): la grammatica del pannello HTML e il linguaggio delle espressioni;
  - [styles.md](docs/spec/styles.md): pannello CSS, concatenazione BEM, quali stili finiscono in una pagina;
  - [framework.md](docs/spec/framework.md) e [histos-css.md](docs/spec/histos-css.md): i framework CSS e Histeon CSS;
  - [rest.md](docs/spec/rest.md): la REST API del builder (`xb/v1`);
  - [versioning.md](docs/spec/versioning.md): versioni, release, ripristino, anteprima firmata;
  - [static.md](docs/spec/static.md): sito statico, Cloudflare, form;
  - [modules.md](docs/spec/modules.md): i moduli Lingua Bridge e Static Bridge;
  - [ai.md](docs/spec/ai.md) e [connector.md](docs/spec/connector.md): agenti AI e connettore;
  - [signing.md](docs/spec/signing.md) e [updates.md](docs/spec/updates.md): firma e aggiornamenti.
- **Decisioni** (ADR): [docs/adr/](docs/adr/); le difese di sicurezza sono nella [0005](docs/adr/0005-revisione-sicurezza.md).
- **Note di versione**: [docs/releases/](docs/releases/), una per release.
- **Integrazione con Static Bridge**: [docs/integration/static-bridge.md](docs/integration/static-bridge.md).
- **Provenienza del codice**: [docs/policy/PROVENANCE.md](docs/policy/PROVENANCE.md), il registro delle dipendenze con le loro licenze.
- **Marchio**: [docs/brand.md](docs/brand.md).

## Dai sorgenti

```bash
npm install
```

```bash
npm test
```

```bash
cd plugin && composer install && composer test
```

```bash
XB_WITHOUT_MODULES=1 scripts/package.sh
```

`scripts/package.sh` crea in `dist/` gli ZIP del plugin e del tema e `SHA256SUMS`, dall'ultimo commit. Lingua Bridge e Static Bridge entrano come moduli dagli ZIP fissati in `modules.json`; senza quegli ZIP, `XB_WITHOUT_MODULES=1` crea il pacchetto senza moduli, che funziona lo stesso ([docs/spec/modules.md](docs/spec/modules.md)). Il WP di sviluppo parte con `dev/up.sh` (Docker) e risponde su http://localhost:8899.

Requisiti: WP 6.9 o superiore, PHP 8.3 o superiore (8.4 per Static Bridge).
