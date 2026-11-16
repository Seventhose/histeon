# ADR 0014 — Histos CSS: il framework CSS libero di Histos, con l'importazione da Automatic.css

Data: 2026-10-21 · Stato: **accettato**

## Contesto
Histos supporta tre framework: Core Framework, Automatic.css (se l'utente lo ha installato) e Histos Tokens, un insieme minimo di 25 token. Serve un framework **completamente libero** incluso in Histos (GPL, nessuna chiave di licenza), alla pari per le funzioni di uso quotidiano, e un passaggio da Automatic.css **senza interventi manuali**.

Vincoli:
- di Automatic.css si usano solo, a runtime e sul sito dell'utente, l'API pubblica, le opzioni e i file che genera;
- niente SCSS, configurazioni o nomi di classi di Automatic.css come predefiniti di Histos; il marchio compare solo in frasi di compatibilità («importa da Automatic.css»).

## Decisioni

| # | Decisione | Motivazione |
|---|---|---|
| T1 | **Histos CSS** evolve *Histos Tokens* (stesso provider `xb-tokens`, stessi nomi `--xb-*`) invece di affiancargli un quarto framework | I siti e i componenti che usano già `--xb-*` restano validi; il livello semantico diventa il framework stesso |
| T2 | I **valori** si calcolano in PHP da pochi parametri (colori di base, dimensioni minime e massime, rapporti di scala, viewport): niente compilatore nel browser, niente file generati da scrivere | Rigenerazione headless, deterministica e testabile; si risolve il limite di Core Framework (CSS generato solo nel browser) |
| T3 | Le **classi** sono un foglio statico (`assets/framework/histos.css`) generato da una sola definizione PHP (`HistosCss\Utilities`), che serve anche all'autocompletamento del builder | Un solo elenco di verità; il foglio statico passa dal motore *CSS su richiesta* (ADR 0006): in pagina arriva solo ciò che la pagina usa |
| T4 | Nomenclatura propria: prefisso `xb-`, sempre trattino singolo (`xb-bg-primary-700`, `xb-text-l`, `xb-grid-3`, `xb-btn xb-btn-primary`), sfumature numeriche 50–950 | Nomi propri; i nomi numerici sono ordinabili. Niente `--`: durante il passaggio da un altro framework, i suoi selettori su una parte del nome di classe colpirebbero anche le classi Histos CSS |
| T5 | Solo **classi e variabili**, nessuno stile sugli elementi nudi (`h1`, `a`, `button`) salvo opt-in esplicito | Attivare Histos CSS su un sito esistente non cambia nulla finché non si usano le sue classi |
| T6 | L'**importazione da Automatic.css** legge i valori finali delle variabili dai fogli che Automatic.css ha generato e l'inventario delle sue classi, non le sue impostazioni interne | I valori calcolati sono il risultato visibile e sono stabili; le impostazioni interne di un altro prodotto possono cambiare fra versioni |
| T7 | I token Histos CSS **puntano ai valori importati** (foglio `carry-acss` con tutte le variabili di Automatic.css: stessi valori); i colori di base diventano i parametri della palette; i nomi usati nel sito si riscrivono con il motore di migrazione esistente; ciò che non ha corrispondenza resta servito dal foglio di compatibilità (Carry) | Nessuna pagina cambia aspetto; il lavoro passa da commit di sviluppo e da una release annullabile come ogni altra modifica |
| T8 | La conversione è un **elenco esplicito** di corrispondenze (nome Automatic.css → nome Histos CSS), con test, più regole per le famiglie numerate | Prevedibile e verificabile; la convenzione `a--b ↔ a-b` della migrazione generica non basta |

## Conseguenze
- Il provider `xb-tokens` cambia etichetta in **Histos CSS**; le opzioni e i dati esistenti restano validi.
- I token già noti (`--xb-space-xs … xxl`, `--xb-text-s … xxl`, colori semantici, raggi, ombre) restano con lo stesso nome; si aggiungono le scale complete e le palette.
- Dopo la release, le pagine di un sito importato da Automatic.css non dipendono più dai suoi fogli.
- Le pagine di impostazione del generatore (colori, scale) nel builder sono la fase 2: nella fase 1 i parametri si cambiano dal pannello Design system (override) e via REST.
