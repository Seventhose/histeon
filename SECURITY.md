# Sicurezza

Histeon gira dentro WP con i permessi di chi lo usa: una falla può toccare siti veri. Se ne trovi una, segnalala in privato, non in una issue pubblica.

## Come segnalare

- Sul repository pubblico usa **Security → Report a vulnerability** (segnalazione privata di GitHub). Solo i manutentori la vedono.
- Scrivi la versione di Histeon (o il commit), cosa può fare chi sfrutta la falla, con quale ruolo WP, e i passi per riprodurla.
- Non provarla su siti che non sono tuoi.

Rispondiamo il prima possibile, diciamo se la confermiamo e, quando la correzione è pronta, la pubblichiamo con una nota nella release. Chi l'ha segnalata è citato, se lo desidera.

## Versioni coperte

Solo l'ultima release di Histeon (plugin `xb` e tema `xb-blank`). Le versioni precedenti non ricevono correzioni: aggiorna.

Lingua Bridge e Static Bridge, inclusi come moduli, hanno i loro repository (`Seventhose/histeon-lingua-bridge`, `Seventhose/histeon-static-bridge`): una falla nel loro codice va segnalata lì; se riguarda solo come Histeon li include, segnalala qui.

## Cosa Histeon considera una falla

Il modello delle minacce e l'ultimo audit sono in `docs/security/`, nei sorgenti allegati a ogni release. In breve:

- chi ha meno permessi riesce a fare ciò che il suo ruolo non permette (per esempio un collaboratore che esegue script, o che legge una bozza altrui);
- contenuto salvato da un utente che esegue codice nel browser di un altro (XSS);
- richieste fatte a nome di un amministratore senza il suo consenso (CSRF), o dal server verso indirizzi interni (SSRF);
- segreti (chiavi, token, credenziali di deploy) leggibili da chi non dovrebbe;
- un pacchetto o un aggiornamento non verificato che viene installato.

Non sono falle di Histeon ciò che può già fare un amministratore con `unfiltered_html` o accesso al database, né i problemi di plugin e temi di terzi.
