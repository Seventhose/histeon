# Fondamenta

Tema a blocchi padre che prende il posto di XB Blank (doc 25 di Histeon Publish, §8-§11; `docs/spec/fondamenta.md`).

- Con il costruttore attivo (`XB_VERSION`) non registra nulla: template e parti sono scritti con i suoi blocchi e li disegna lui.
- Senza, è «ferma»: in amministrazione un avviso dice quale plugin manca e cosa resta senza pagina.

I template si scrivono in `src/` e si compilano con `theme/build.sh`, che copia gli stessi file in XB Blank.
