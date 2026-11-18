# LU3G Form Manager

Tutti i form di **Elementor Pro** del sito in un unico pannello: destinatari, thank you page e webhook si cambiano
senza aprire Elementor; le richieste vengono raccolte (con export CSV e retention), i webhook hanno un log con reinvio,
le email Admin e Utente si personalizzano con un template LU3G e l'anteprima live. Funziona anche su **Multisite**.

Plugin WordPress di LU3G Agenzia Web. Requisiti: PHP 7.4+, WordPress 6.0+, Elementor Pro (le email personalizzate
richiedono Elementor Pro 4.0.3 o successivo).

## Struttura
```
lu3g-form-manager/
├─ lu3g-form-manager.php   intestazione (Update URI), avvio, controllo di Elementor Pro, attivazione
├─ uninstall.php           all'eliminazione: tabelle e opzioni di ogni sito e della rete
├─ includes/
│  ├─ class-updater.php    GENERATO da lu3gofficial/lu3g-updater (.lu3g-updater): non modificarlo a mano
│  ├─ class-db.php         tabelle {prefix}lu3g_fm_submissions e {prefix}lu3g_fm_webhook_log, retention, siti nuovi/eliminati
│  ├─ class-settings.php   impostazioni base → rete → sito (con blocchi) e override per form
│  ├─ class-scanner.php    scansione di _elementor_data (pagine, articoli, template, popup), cache
│  ├─ class-runtime.php    aggancio agli invii di Elementor Pro
│  ├─ class-webhook.php    payload JSON, invio, log, reinvio, test
│  ├─ class-emails.php     placeholder, template HTML, anteprima, invio
│  ├─ class-admin.php      pannello del sito, azioni AJAX, export CSV
│  └─ class-network.php    pannello di rete (Multisite), scansione a blocchi, cron
└─ assets/                 CSS/JS dell'interfaccia, logo e icona LU3G, font Manrope (SIL OFL)
```

## Impostazioni di Elementor (dalla 1.2.0)
La scheda **Impostazioni Elementor** legge e modifica le impostazioni vere del form in `_elementor_data`: nome del modulo,
azioni dopo l'invio, Email, Email 2, Webhook; la scheda **Thank you page** scrive il Redirect. Il salvataggio passa da
`\Elementor\Plugin::$instance->documents->get( $post_id )->save( [ 'elements' => … ] )` (come l'editor: CSS e cache
rigenerati); si inviano solo i campi cambiati; una copia delle impostazioni precedenti resta nel meta `_lu3g_fm_backup`
del post ("Annulla l'ultima modifica"). Un form in un template globale cambia ovunque è usato, come in Elementor.

## Archivio degli invii di Elementor
Elementor Pro salva ogni invio in `{prefix}e_submissions` e `{prefix}e_submissions_values` (post_id + element_id del form).
La scheda Richieste lo legge (anche gli invii di prima dell'installazione) e "Sposta nel cestino" usa lo stato `trash` di
Elementor. Se l'archivio non c'è, si vedono le richieste raccolte dal plugin.

## Come si aggancia a Elementor Pro (invio)
Flusso di un invio in Elementor Pro (verificato sul codice della 4.4, `Ajax_Handler::ajax_send_form`):
validazione → `elementor_pro/forms/record/actions_before` → `elementor_pro/forms/submit_actions` (dalla 4.0.3) →
azioni (email, email2, redirect, webhook…) → `elementor_pro/forms/new_record` → risposta.

| Funzione | Come | Versione di Elementor Pro |
|---|---|---|
| Override Email Admin (A, Oggetto, Da, Da nome, Cc, Ccn) | `actions_before`: si cambiano le impostazioni del form nel record (`$record->set( 'form_settings', … )`), da cui legge l'azione Email | tutte |
| Rispondi a (email di Elementor) | id di un campo email del form: è l'unico formato che Elementor accetta | tutte |
| Email Admin LU3G / Email Utente LU3G | inviate dal plugin su `new_record`; l'Email / Email 2 di Elementor vengono tolte con `submit_actions` | 4.0.3+ |
| Thank you page | `new_record`: `redirect_url` nella risposta (come fa l'azione Redirect, quindi funziona anche senza e vince su quella) | tutte |
| Webhook, richieste | `new_record` | tutte |

Un campo di override vuoto lascia il valore impostato in Elementor. Errori interni vengono catturati: il form del
visitatore non fallisce mai per colpa del plugin.

## Log del webhook di Elementor (dalla 1.4.0)
Il webhook resta quello di Elementor (scheda Impostazioni Elementor: URL e Dati avanzati). Il plugin lo osserva con
`elementor_pro/forms/webhooks/request_args` (annota dati e ora, restituisce gli stessi argomenti) e
`elementor_pro/forms/webhooks/response` (scrive il log: stato, risposta, errore, durata). "Reinvia" rimanda gli stessi
dati allo stesso URL in POST come Elementor. Il vecchio "Webhook LU3G" (1.0–1.3) è stato tolto.

## Placeholder delle email
`{{id_campo}}` per ogni campo, `{{all_fields}}` (tabella), `{{form_name}}`, `{{page_url}}`, `{{page_title}}`,
`{{date}}`, `{{time}}`, `{{site_name}}`, `{{ip}}`. Valori escapati nel corpo HTML. Funzionano anche in A, Cc, Ccn,
Rispondi a (email LU3G) e nell'URL della thank you page (valori codificati).

## Multisite
- Attivazione di rete o per sito. Tabelle per sito (`$wpdb->prefix`): create all'attivazione di rete (fino a 500 siti
  subito, gli altri al primo caricamento), sui siti nuovi (`wp_initialize_site`), cancellate con il sito
  (`wp_uninitialize_site`).
- Impostazioni generali (template email, webhook di default, retention): base → rete (`get_site_option`) → sito
  (`get_option`). Ogni campo mostra "Ereditato dalla rete", "Personalizzato" (con "Torna al valore della rete") o
  "Bloccato dalla rete". La rete blocca i campi con "Blocca sui siti".
- Network Admin → LU3G Form: form di tutti i siti (colonna Sito, filtro, richieste, stato webhook, link alla gestione
  nel sito), richieste di tutti i siti con export CSV, impostazioni di rete. Permessi: `manage_network_options` per la
  rete, `manage_options` per il sito.
- Scansione: cache per sito; "Riscansiona la rete" a blocchi di 10 siti; cron giornaliero su 20 siti con cache vecchia.

## Limiti noti
- **Email personalizzate solo con Elementor Pro 4.0.3+**: prima non esiste il filtro per togliere l'email di Elementor.
  Sulle versioni vecchie il pannello lo dice e restano gli override dell'email di Elementor.
- **"Rispondi a" con l'email di Elementor** accetta solo un campo email del form, non un indirizzo scritto (limite di
  Elementor). Con l'email LU3G accetta anche indirizzi e placeholder.
- **Webhook sincrono**: un endpoint lento (fino a 8 secondi) rallenta la risposta del form.
- **Form nuovi**: compaiono nell'elenco dopo "Riscansiona" (o dal cron di rete); gli invii però vengono già salvati e
  i webhook di default partono anche prima.
- **Widget globali** e form in template: la chiave è quella del template; lo stesso form usato in più pagine ha un'unica
  scheda (come in Elementor).
- **Allegati (campo upload)**: nel webhook e nelle richieste c'è l'URL del file, il file non viene copiato.
- **Richieste di rete**: con molti siti la vista unica legge ogni sito a ogni pagina; per reti molto grandi conviene
  filtrare per sito.
- Provato su un WordPress locale con un **Elementor Pro simulato** che riproduce il flusso della 4.4 (Elementor Pro è a
  pagamento). Va provato su un sito reale con Elementor Pro.

## Come provarlo
1. **Scansione**: LU3G → Form (o LU3G Form) → l'elenco mostra i form con pagina, link a Elementor e invii; crea un form
   nuovo in Elementor e premi "Riscansiona".
2. **Impostazioni Elementor**: scheda del form → cambia nome, A, Oggetto, Cc… → "Salva in Elementor" → apri il form in
   Elementor: i valori sono quelli nuovi; "Annulla l'ultima modifica" li rimette com'erano.
3. **Email LU3G**: Email personalizzate → accendi l'interruttore, scrivi il corpo cliccando i placeholder, guarda
   l'anteprima → Salva → "Invia email di test" → invia il form dal sito: arriva una sola email (quella LU3G).
4. **Email Utente**: Email personalizzate → Email di conferma → Attiva → invia il form con il tuo indirizzo.
5. **Thank you page**: nell'elenco c'è la colonna con quella di ogni form; nella scheda vedi quella attuale e da dove viene, scegli un'altra pagina dal menu (o un URL, anche con `?nome={{nome}}`) → Salva → dopo l'invio il visitatore ci arriva. "Torna a quella di Elementor" annulla.
6. **Log webhook**: invia il form dal sito → scheda Log webhook del form: c'è la chiamata del webhook di Elementor
   con esito, dati inviati e risposta; "Reinvia" la rimanda. Nell'elenco la colonna Webhook mostra l'ultimo esito.
7. **Richieste**: scheda Richieste → "Archivio di Elementor" mostra anche gli invii vecchi; ricerca, date, Dettaglio,
   Esporta CSV (si apre in Excel).
8. **Retention**: Impostazioni → mesi; le richieste più vecchie spariscono al passaggio giornaliero del cron.
9. **Multisite**: attiva in rete, crea un sito nuovo (le tabelle compaiono), imposta i valori di rete e blocca un campo,
   controlla nelle Impostazioni del sito le etichette; Network Admin → LU3G Form → "Riscansiona la rete", Richieste,
   Esporta CSV.

## Pubblicare una nuova versione
1. Aumenta la versione in `lu3g-form-manager.php` (`Version:` e `LU3G_FM_VERSION`) e `Stable tag` in `readme.txt`.
2. Aggiungi la sezione in `CHANGELOG.md` (diventa la nota della release).
3. Commit, poi `git tag vX.Y.Z && git push && git push --tags`, oppure da GitHub: Actions → Release → Run workflow.
