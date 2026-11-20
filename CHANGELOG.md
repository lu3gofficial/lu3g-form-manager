# Registro delle modifiche

## 1.6.0
- Tolto il **tracciamento per Google Tag Manager** introdotto nella 1.5.0: via la scheda *Tracciamento* dei form, la sezione nelle Impostazioni (sito e rete), lo script nel sito, il codice GTM facoltativo, l'`event_id` nelle richieste e nel webhook e il placeholder `{{event_id}}`. Il plugin torna a non caricare nulla nelle pagine del sito; gli eventi dei form restano a GTM e agli script già presenti nei siti.

## 1.5.0
- **Tracciamento per Google Tag Manager** (spento di serie; Impostazioni → *Tracciamento*, ereditabile dalla rete su Multisite). Dopo ogni invio riuscito il plugin mette nel `dataLayer` un evento uguale su tutti i siti: `event` (`lead_submit` o il nome scelto per il form, es. `contract_submit`), `event_id`, `form_name`, `form_id`, `page_url`, `page_title`, `site_domain` e, se attivo, `email` e `phone` anche dentro `user_data` (Enhanced Conversions, server-side). Il plugin non manda niente a Google o Meta: lo fa GTM.
- **Scheda Tracciamento** in ogni form: acceso/spento, nome dell'evento, campi email e telefono (riconosciuti da soli dal tipo di campo, o scelti a mano), anteprima di quello che arriva nel dataLayer.
- **Telefono normalizzato** in formato internazionale: gestisce `+`, `00`, numeri esteri e i cellulari che iniziano per 39 (prefisso di default impostabile).
- **event_id condiviso**: lo stesso ID dell'evento nel browser viene salvato nella richiesta e aggiunto ai dati del webhook di Elementor (campo `event_id`), per la deduplica con Meta CAPI e CRM. Disponibile anche il placeholder `{{event_id}}` nelle email.
- **Click sui contatti** (WhatsApp, telefono, email): evento `contact_click` con tipo, URL e testo del link.
- **Codice GTM facoltativo** (head e dopo `<body>`) per incollare lo snippet del sito, anche server-side; lo può cambiare solo chi ha il permesso di inserire HTML libero.
- **Controlla la pagina iniziale**: segnala GTM caricato più volte, gtag.js o Meta Pixel diretti, script scritti a mano che ascoltano gli invii (lead contati due volte) e se lo script del plugin è nella pagina.
- Lo script non dipende da jQuery per partire (funziona con WP Rocket "ritarda JS"), legge i valori prima che Elementor svuoti il form, non duplica lo stesso invio e traccia anche gli invii successivi dello stesso form. **Modalità debug**: eventi nella console (o `?lu3g_debug=1`). Non si carica nell'editor di Elementor.

## 1.4.0
- **Log webhook**: il log registra le chiamate del **webhook di Elementor** (quello impostato nel form, es. SaveMyLeads): URL, dati inviati, stato HTTP, risposta, errore, durata, collegati alla richiesta; con **Reinvia** (stessi dati allo stesso URL). Il webhook continua a partire da Elementor come prima: il plugin lo osserva con i due agganci ufficiali (`elementor_pro/forms/webhooks/request_args`, che restituisce i dati invariati, e `elementor_pro/forms/webhooks/response`).
- Tolto il **Webhook LU3G** (secondo webhook del plugin, un doppione di quello di Elementor): via la configurazione nel form, il webhook di default nelle impostazioni e "Invia test". Le vecchie chiamate restano nel log.
- Tolte le **conversioni** (diventeranno un plugin a parte): via la scheda, le impostazioni e lo script nel sito; il reindirizzamento alla thank you page torna sempre quello di Elementor.
- Elenco dei form: la colonna *Webhook* mostra il servizio del webhook di Elementor, se l'azione è attiva o spenta e l'esito dell'ultima chiamata.

## 1.3.0
- **Conversioni**: a ogni invio riuscito partono gli eventi verso gli strumenti già presenti nel sito: **Google Tag Manager** (dataLayer, evento `lu3g_form_submit`), **Google Analytics 4** (`generate_lead`), **Google Ads** (`conversion` con send_to, valore e valuta) e **Meta Pixel** (`Lead` o un evento personalizzato). Impostazioni generali (ereditate dalla rete su Multisite) e, per ogni form, scheda *Conversioni*: generali, sue o nessuna. Nessun dato personale del form viene inviato agli strumenti.
- Sui form con conversioni il passaggio alla thank you page avviene 700 ms dopo gli eventi, così non si perdono (Elementor cambierebbe pagina subito).
- Per controllare: nella console del browser `LU3G_FM_CONV.debug = true` e invia il form; per integrazioni proprie c'è l'evento DOM `lu3g:form-conversion`.
- **Elenco dei form**: la colonna *Webhook* mostra il webhook di Elementor (servizio, es. savemyleads.com, e se l'azione è attiva o spenta) e quello LU3G con l'esito dell'ultima chiamata. Anche nel pannello di rete.

## 1.2.0
- Nuova scheda **Impostazioni Elementor** (la prima del form): tutte le impostazioni vere del form, lette da Elementor e salvate in Elementor: **nome del modulo**, azioni dopo l'invio (Email, Email 2, Redirect, Webhook; le altre, es. Mailchimp, restano), **Email** ed **Email 2** (A, Oggetto, Messaggio, Da Email, Da Nome, Rispondi a, Cc, Ccn, Invia come, Metadati) e **Webhook** di Elementor (URL, Dati avanzati). Un campo vuoto mostra in grigio il predefinito di Elementor; i campi con un tag dinamico sono segnalati e restano dinamici finché non li cambi.
- Il salvataggio passa da Elementor (`Document::save`), come dall'editor: si vede subito anche aprendo Elementor, CSS e cache della pagina si rigenerano. Si salvano solo i campi cambiati. Prima di ogni modifica si tiene una copia: **"Annulla l'ultima modifica"**.
- **Thank you page** scritta direttamente nel Redirect di Elementor (l'azione Redirect si accende da sola); "Togli la thank you page" la spegne. I placeholder `{{campo}}` diventano `[field id="campo"]`.
- **Richieste**: c'è l'**archivio di Elementor** (tabelle `e_submissions`), con tutti gli invii anche di prima dell'installazione del plugin, con ricerca, date, dettaglio, export CSV e "Sposta nel cestino" (quello di Elementor). Le richieste raccolte dal plugin restano in una seconda vista. Il numero di invii nell'elenco viene dall'archivio di Elementor.
- Le **email LU3G** (scheda Email personalizzate) prendono destinatari e mittente dall'Email di Elementor; dal plugin arrivano oggetto e testo.
- Le vecchie modifiche "a runtime" della 1.0/1.1 su destinatari e thank you page vengono tolte al primo salvataggio in Elementor del form.

## 1.1.0
- Scheda *Thank you page*: in evidenza la thank you page attuale del form (titolo della pagina, indirizzo con "Apri" e provenienza: impostata in Elementor, impostata qui, tag dinamico di Elementor, indirizzo in Elementor con l'azione Redirect spenta, nessuna).
- Si cambia scegliendo tra le pagine del sito (in cima quelle di ringraziamento: "grazie", "thank you", "conferma", "ricevuta"...) o scrivendo un indirizzo; pulsante "Torna a quella di Elementor".
- Funziona anche sui form che in Elementor non hanno l'azione Redirect: scelta qui, la thank you page si attiva senza aprire Elementor.
- Elenco dei form: nuova colonna *Thank you page*.

## 1.0.0
- Prima versione.
- **Scansione** dei form di Elementor Pro in pagine, articoli, template e popup (`_elementor_data`), con nome, ID, posizione, link a Elementor, numero di invii e stato del webhook; pulsante "Riscansiona".
- **Override per form** senza toccare `_elementor_data`: Email Admin (A, Oggetto, Da, Da nome, Rispondi a, Cc, Ccn), thank you page, webhook. Un campo vuoto lascia il valore di Elementor.
- **Webhook** in JSON (POST, PUT, PATCH o GET) a ogni invio, con log (stato HTTP, risposta, errore, durata), "Reinvia" e "Invia test"; webhook di default per i form senza un webhook proprio.
- **Richieste** salvate in una tabella propria, con ricerca, filtro per data, dettaglio, eliminazione ed export CSV; retention automatica in mesi.
- **Email Admin ed Email Utente** personalizzate col template LU3G (logo, colore, footer), placeholder cliccabili, anteprima live e invio di test (con Elementor Pro 4.0.3 o successivo).
- **Multisite**: attivazione di rete o per sito, tabelle per sito (anche sui siti nuovi, cancellate con il sito), impostazioni di rete ereditate dai siti con blocco dei campi, pannello di rete con i form e le richieste di tutti i siti, scansione a blocchi e cron.
- Interfaccia LU3G animata; nel menu "LU3G" se c'è LU3G Hub. Aggiornamenti dalle release di GitHub (updater comune lu3g-updater).
