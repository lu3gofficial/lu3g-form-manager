# Registro delle modifiche

## 1.0.0
- Prima versione.
- **Scansione** dei form di Elementor Pro in pagine, articoli, template e popup (`_elementor_data`), con nome, ID, posizione, link a Elementor, numero di invii e stato del webhook; pulsante "Riscansiona".
- **Override per form** senza toccare `_elementor_data`: Email Admin (A, Oggetto, Da, Da nome, Rispondi a, Cc, Ccn), thank you page, webhook. Un campo vuoto lascia il valore di Elementor.
- **Webhook** in JSON (POST, PUT, PATCH o GET) a ogni invio, con log (stato HTTP, risposta, errore, durata), "Reinvia" e "Invia test"; webhook di default per i form senza un webhook proprio.
- **Richieste** salvate in una tabella propria, con ricerca, filtro per data, dettaglio, eliminazione ed export CSV; retention automatica in mesi.
- **Email Admin ed Email Utente** personalizzate col template LU3G (logo, colore, footer), placeholder cliccabili, anteprima live e invio di test (con Elementor Pro 4.0.3 o successivo).
- **Multisite**: attivazione di rete o per sito, tabelle per sito (anche sui siti nuovi, cancellate con il sito), impostazioni di rete ereditate dai siti con blocco dei campi, pannello di rete con i form e le richieste di tutti i siti, scansione a blocchi e cron.
- Interfaccia LU3G animata; nel menu "LU3G" se c'è LU3G Hub. Aggiornamenti dalle release di GitHub (updater comune lu3g-updater).
