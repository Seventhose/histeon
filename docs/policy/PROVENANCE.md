# Registro di provenienza

Ogni dipendenza o porzione di codice non scritta nel progetto va registrata qui.

## Dipendenze

| Pacchetto | Versione | Licenza | Uso | Motivo |
|---|---|---|---|---|
| `@wordpress/block-serialization-default-parser` | 5.57.0 | GPL-2.0-or-later | runtime (`packages/dsl`) | Parser ufficiale della grammatica a blocchi, identico a `parse_blocks()` |
| `typescript` | 7.0.2 | Apache-2.0 | dev | Type-check |
| `vitest` | 5.0.5 | MIT | dev | Test JS |
| `ajv` | 8.20.0 | MIT | dev | Validazione JSON Schema |
| `@types/node` | 26.6.5 | MIT | dev | Tipi Node per i test |
| `phpunit/phpunit` | 12.5.x | BSD-3-Clause | dev | Test PHP |
| `phpstan/phpstan` | 2.1.x | MIT | dev | Analisi statica PHP |
| `php-stubs/wordpress-stubs` | 6.9.x | MIT | dev | Firme WordPress per PHPStan |
| `php-stubs/wp-cli-stubs` | 2.12.x | MIT | dev | Firme WP-CLI per PHPStan |
| `svelte` | 5.57.x | MIT | runtime (`editor`) | Interfaccia del builder |
| `codemirror`, `@codemirror/*`, `@lezer/highlight` | 6.x | MIT | runtime (`editor`) | Editor di codice dei pannelli HTML e CSS |
| `vite` | 8.3.x | MIT | dev | Build del builder |
| `@sveltejs/vite-plugin-svelte` | 7.3.x | MIT | dev | Compilazione Svelte |
| `svelte-check` | 4.7.x | MIT | dev | Type-check dei componenti |
| `@jsquash/avif`, `@jsquash/webp`, `@jsquash/resize` | 2.1.1, 1.5.0, 2.1.1 (esatte) | Apache-2.0 | runtime (`editor`, worker in `plugin/assets/builder/media/`) | Codifica AVIF e WebP e ridimensionamento delle immagini nel browser prima del caricamento, gli stessi pacchetti di Histeon Publish. Nei moduli WebAssembly: libavif (BSD-2-Clause) con libaom (BSD-2-Clause e Alliance for Open Media Patent License 1.0, gratuita), libwebp (BSD-3-Clause), il crate Rust `resize` (MIT). I testi sono copiati in `plugin/assets/builder/media/licenses/` a ogni build (`editor/vite.media.config.ts`); quelli delle librerie che i pacchetti non portano stanno in `editor/licenses/`. Nessun codec commerciale, nessuna chiave. Niente JPEG XL nel builder (`@jsquash/jxl` resta solo in Publish): un file unico in un `<img>` non si vede in Chrome e Firefox |
| `wasm-feature-detect` | 1.9.0 (esatta) | Apache-2.0 | runtime (`editor`) | Sceglie il codec WebP con SIMD quando il browser lo supporta |
| `@playwright/test` (con `playwright`, `playwright-core`) | 1.64.1 | Apache-2.0 | dev (`e2e`) | Test end-to-end del builder nel browser; i browser si scaricano a parte (`npx playwright install`) e non entrano nel repository né nei pacchetti |

Verifica del 2026-10-19 su tutto `node_modules`: 78 pacchetti MIT, 6 Apache-2.0, 4 GPL, 3 MPL-2.0, 2 BSD-3-Clause, 1 ISC. Tutte le licenze sono compatibili con GPL-3.0-or-later.

Le versioni esatte sono fissate da `package-lock.json` e `plugin/composer.lock`.

## Testi e asset

| Elemento | Fonte | Licenza |
|---|---|---|
| `LICENSE` | Testo ufficiale GPL-3.0 della FSF (copia di sistema `/usr/share/common-licenses/GPL-3`) | — |

## Codice originale

Tutti i file in `packages/`, `editor/`, `plugin/`, `theme/` e `dev/` sono stati scritti da zero per Histeon (Fasi 0–2, 2026-10-19).
