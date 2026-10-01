<?php
/**
 * Kommando-/Status-Endpunkt fuer den Loxone Miniserver (Virtueller Ausgang).
 * Neu geschrieben fuer die GARDENA smart system API v2.
 *
 * Aufrufe (HTTP GET):
 *   ?action=list&token=...           -> Geraete/Services als JSON (aus dem Zwischenspeicher)
 *   ?action=refresh&token=...        -> Daten sofort abholen und versenden (wie Cron)
 *   ?action=command&token=...&device=NAME&type=MOWER_CONTROL&cmd=START_SECONDS_TO_OVERRIDE&seconds=3600
 *   ?action=command&token=...&device=NAME&type=VALVE_CONTROL&cmd=STOP_UNTIL_NEXT_TASK
 *
 * Seit 1.2.13 zusaetzlich (HTTP POST, nur vom LoxBerry selbst, ab Werk aus):
 *   action=ventil&token=<Ventil-Token>&ventil=NAME&befehl=oeffnen&minuten=10
 *   action=ventil&token=<Ventil-Token>&ventil=NAME&befehl=schliessen|zustand
 * - die Schnittstelle fuer das Plugin Bewaesserung (Gardena-1), siehe unten.
 *
 * Alles, was etwas ausloest (command, refresh), verlangt das Token aus der
 * gardena.cfg. Ohne diese Pruefung koennte jedes Geraet im Netz - und ueber
 * eine unbedacht weitergeleitete Portfreigabe auch jeder von aussen - den
 * Maeher starten oder die Bewaesserung aufdrehen. Das Token steht in der
 * Plugin-Oberflaeche, dort gibt es auch die fertigen Loxone-URLs.
 *
 * Gaengige Kommandos (API v2):
 *   MOWER_CONTROL:        START_SECONDS_TO_OVERRIDE (+seconds), START_DONT_OVERRIDE,
 *                         PARK_UNTIL_NEXT_TASK, PARK_UNTIL_FURTHER_NOTICE
 *   VALVE_CONTROL:        START_SECONDS_TO_OVERRIDE (+seconds), STOP_UNTIL_NEXT_TASK, PAUSE, UNPAUSE
 *   POWER_SOCKET_CONTROL: START_SECONDS_TO_OVERRIDE (+seconds), START_OVERRIDE, STOP_UNTIL_NEXT_TASK
 */

// Die Bibliotheken liegen seit 1.1.0 in bin/, ausserhalb des
// Apache-Wurzelverzeichnisses. Diese Datei hier MUSS erreichbar bleiben -
// sie ist der Endpunkt, den der Miniserver anspricht.
require_once 'loxberry_system.php';
require_once 'loxberry_log.php';
require_once 'loxberry_io.php';

header('Content-Type: text/plain; charset=utf-8');

/*
 * Welcher bin/-Ordner gilt, entscheidet der eigene Ablageort: installiert
 * liegt diese Datei unter <Wurzel>/webfrontend/html/plugins/<ordner>, die
 * Bibliothek unter <Wurzel>/bin/plugins/<ordner>; im ausgepackten Archiv
 * unter <archiv>/webfrontend/html und <archiv>/bin. Bis 1.2.9 stand hier
 * $lbpbindir - aus einem Archiv, in dem das SDK keinen Pluginordner erkennt,
 * zeigte er auf bin/plugins/ der Anlage ohne Ordnernamen (in WSL gemessen,
 * Pruefung-GardenaSmartSystem-1.2.10, Fall L8). Der gesuchte Pfad geht ins
 * Fehlerprotokoll des Webservers, nicht in die Antwort: der Aufrufer hat sich
 * hier noch nicht ausgewiesen.
 */
if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'html') {
    $gbindir = dirname(dirname(dirname(dirname(__DIR__)))) . '/bin/plugins/' . basename(__DIR__);
} else {
    $gbindir = dirname(dirname(__DIR__)) . '/bin';
}
if (!is_file($gbindir . '/functions.inc.php') || !is_file($gbindir . '/gardena.class.inc.php')) {
    error_log('GARDENA smart system: Bibliothek nicht gefunden unter ' . $gbindir);
    http_response_code(500);
    echo "FEHLER: Die Bibliothek des Plugins fehlt - Plugin neu installieren.\n";
    exit;
}
require_once $gbindir . '/gardena.class.inc.php';
require_once $gbindir . '/functions.inc.php';

/*
 * Nur installiert (oder ausdruecklich mit LBHOMEDIR und LBPPLUGINDIR) wirkt
 * dieser Endpunkt - gardena_lage(). Er protokolliert, ruft ab und schaltet;
 * aus einem ausgepackten Archiv heraus haette er das mit den Pfaden der
 * Anlage getan (Fall L9 in der Eichung).
 */
if (gardena_lage() === '') {
    http_response_code(503);
    echo "FEHLER: Dieses Plugin ist hier nicht installiert - der Endpunkt tut nichts.\n";
    exit;
}

/**
 * Ein einziger Ausgang - und jeder Weg schreibt eine Zeile.
 *
 * Bis 1.2.5 hatte diese Datei zehn Ausgaenge und NULL Protokolleintraege
 * (gemessen: dasselbe Suchmuster findet in gardenaMain.php 43 Treffer).
 */
function gardena_ende($code, $text, $protokoll, $gebremst = false)
{
    // Eine noch gehaltene Sperre der Gleichwert-Unterdrueckung (1.2.13, X-7)
    // wird hier freigegeben - jeder Weg endet in dieser Funktion.
    if (isset($GLOBALS['ggw']) && is_array($GLOBALS['ggw'])) {
        gardena_gleichwert_schliessen($GLOBALS['ggw']);
        $GLOBALS['ggw'] = null;
    }
    if ($code !== 200) { http_response_code($code); }
    gardena_endpunkt_log($protokoll, $gebremst);
    echo $text;
    exit;
}

$g = gardena_cfg_read($lbpconfigdir . '/gardena.cfg');

/* ==================================================================
 * Schnittstelle fuer die Bewaesserung (1.2.13, Gardena-1; ab Werk AUS)
 *
 * Das Plugin Bewaesserung soll die GARDENA-Ventile direkt schalten koennen,
 * statt ueber Loxone zu gehen. Beschrieben in der README (Abschnitt
 * "Schnittstelle fuer die Bewaesserung") und in
 * Pruefung-Durchgang-2026-09-29/GARDENA1_SCHNITTSTELLE.md:
 *
 *   POST http://127.0.0.1/plugins/<ordner>/index.php
 *     action=ventil   token=<Ventil-Token>   ventil=<Geraetename oder -kennung>
 *     befehl=oeffnen|schliessen|zustand      minuten=<1..Hoechstdauer> (nur oeffnen)
 *     quelle=<Name des Aufrufers> (freiwillig, nur fuers Protokoll)
 *
 * Antwort: EINE Zeile "GARDENA_VENTIL;OK=1;..." bzw. "GARDENA_VENTIL;OK=0;
 * GRUND=..." mit passendem HTTP-Code. Schutz:
 *   - nur POST - das Token steht nie in einer Adresse und nie im
 *     Zugriffsprotokoll des Webservers (405) -, nur vom LoxBerry selbst
 *     (127.0.0.1 / ::1, sonst 403);
 *   - eine Einstellung, ab Werk aus (409 SCHNITTSTELLE_AUS);
 *   - ein EIGENES Token, behandelt wie ein Kennwort (zeitkonstant
 *     verglichen, nie protokolliert), getrennt vom Token der Loxone-Adressen
 *     (403);
 *   - "Plugin aktiv: Nein" sperrt oeffnen/schliessen (Entscheidung 10, 409);
 *   - Hoechstdauer je Oeffnen aus der Einstellung (1-180 min), zusaetzlich
 *     die feste Grenze von 3 Stunden (Entscheidung 10) - 400, nichts geraten:
 *     ohne minuten wird nicht geoeffnet;
 *   - Geraete mit mehreren Ventilen werden abgewiesen (Entscheidung 10, 409);
 *   - dieselben Bremsen wie ?action=command: Gleichwert-Unterdrueckung (X-7),
 *     30 Befehle je Stunde, Abrufsperre nach HTTP 429.
 * ================================================================== */
function gardena_ventil_ende($code, array $felder, $protokoll, $befehl = '', $gebremst = false)
{
    if ($befehl !== '') { gardena_ventil_letzter_merken($befehl, $code); }
    $teile = array();
    foreach ($felder as $k => $v) {
        $teile[] = $k . '=' . preg_replace('/[^A-Za-z0-9_.:,|\-]/', '_', (string) $v);
    }
    gardena_ende($code, 'GARDENA_VENTIL;' . implode(';', $teile) . "\n", 'ventil: ' . $protokoll, $gebremst);
}

$gv_in_adresse = (isset($_GET['action']) && is_string($_GET['action'])) ? $_GET['action'] : '';
if ($gv_in_adresse === 'ventil') {
    header('Allow: POST');
    gardena_ventil_ende(405, array('OK' => 0, 'GRUND' => 'NUR_POST'),
        'abgewiesen: action=ventil in der Adresse - nur per POST, das Token gehoert in keine Adresse');
}
if (isset($_POST['action']) && $_POST['action'] === 'ventil') {
    $gv_wer = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    if (!in_array($gv_wer, array('127.0.0.1', '::1', '::ffff:127.0.0.1'), true)) {
        gardena_ventil_ende(403, array('OK' => 0, 'GRUND' => 'NUR_LOKAL'),
            'abgewiesen: Aufruf nicht vom LoxBerry selbst');
    }
    // Felder: eine Positivliste, nur Zeichenketten, Laengengrenze. Was nicht
    // passt, wird gemeldet, nicht zurechtgebogen (Regeln/03).
    $gv = array('token' => '', 'ventil' => '', 'befehl' => '', 'minuten' => '', 'quelle' => '');
    foreach ($_POST as $gv_k => $gv_w) {
        $gv_k = (string) $gv_k;
        if (!in_array($gv_k, array('action', 'token', 'ventil', 'befehl', 'minuten', 'quelle'), true)) {
            gardena_ventil_ende(400, array('OK' => 0, 'GRUND' => 'UNBEKANNTES_FELD'),
                'abgewiesen: unbekanntes Feld (' . strlen($gv_k) . ' Zeichen)');
        }
        if (!is_string($gv_w)) {
            gardena_ventil_ende(400, array('OK' => 0, 'GRUND' => 'FELD', 'FELD' => $gv_k),
                'abgewiesen: ' . $gv_k . ' ist ein Feld, keine Zeichenkette');
        }
        if (strlen($gv_w) > 200) {
            gardena_ventil_ende(400, array('OK' => 0, 'GRUND' => 'ZU_LANG', 'FELD' => $gv_k),
                'abgewiesen: ' . $gv_k . ' laenger als 200 Zeichen');
        }
        $gv[$gv_k] = $gv_w;
    }
    // Leerraum am Rand faellt still weg (Entscheidung 19); sonst nichts.
    $gv_ventil = trim($gv['ventil']);
    $gv_minuten = trim($gv['minuten']);
    if (preg_match('/^[A-Za-z0-9_\-]{0,40}$/', $gv['quelle']) !== 1) {
        gardena_ventil_ende(400, array('OK' => 0, 'GRUND' => 'QUELLE', 'ERLAUBT' => 'A-Z,a-z,0-9,_,-'),
            'abgewiesen: quelle unzulaessig (' . strlen($gv['quelle']) . ' Zeichen)');
    }
    $gv_von = ($gv['quelle'] !== '') ? ' (quelle ' . $gv['quelle'] . ')' : '';
    if ((string) $g['VENTIL_SCHNITTSTELLE'] !== '1') {
        gardena_ventil_ende(409, array('OK' => 0, 'GRUND' => 'SCHNITTSTELLE_AUS'),
            'abgewiesen: die Schnittstelle fuer die Bewaesserung ist ausgeschaltet' . $gv_von);
    }
    if ((string) $g['VENTIL_TOKEN'] === '') {
        gardena_ventil_ende(403, array('OK' => 0, 'GRUND' => 'KEIN_TOKEN'),
            'abgewiesen: kein Ventil-Token eingerichtet' . $gv_von);
    }
    if (!gardena_token_ok((string) $g['VENTIL_TOKEN'], $gv['token'])) {
        gardena_ventil_ende(403, array('OK' => 0, 'GRUND' => 'TOKEN'),
            'abgewiesen: falsches Ventil-Token (' . strlen($gv['token']) . ' Zeichen)' . $gv_von);
    }
    // Ab hier ist der Aufrufer angemeldet; jede Antwort wird fuer den Reiter
    // Test gemerkt (Zeit, Befehl, HTTP-Code - nie das Token).
    $gv_befehl = $gv['befehl'];
    if (!in_array($gv_befehl, array('oeffnen', 'schliessen', 'zustand'), true)) {
        gardena_ventil_ende(400, array('OK' => 0, 'GRUND' => 'BEFEHL', 'ERLAUBT' => 'oeffnen,schliessen,zustand'),
            'abgewiesen: unbekannter Befehl (' . strlen($gv_befehl) . ' Zeichen)' . $gv_von, 'unbekannt');
    }
    if ($gv_befehl !== 'zustand') {
        // Nur das Lesen bleibt bei "Plugin aktiv: Nein" erlaubt - wie ?action=list.
        if ((string) $g['ENABLED'] !== '1') {
            gardena_ventil_ende(409, array('OK' => 0, 'GRUND' => 'PLUGIN_AUS'),
                $gv_befehl . ' abgewiesen: Plugin ist ausgeschaltet (ENABLED=0)' . $gv_von, $gv_befehl);
        }
        if (empty($g['CLIENT_ID']) || empty($g['CLIENT_SECRET'])) {
            gardena_ventil_ende(409, array('OK' => 0, 'GRUND' => 'KEINE_ZUGANGSDATEN'),
                $gv_befehl . ' abgewiesen: keine Zugangsdaten hinterlegt' . $gv_von, $gv_befehl);
        }
    }
    if ($gv_ventil === '') {
        gardena_ventil_ende(400, array('OK' => 0, 'GRUND' => 'VENTIL_FEHLT'),
            $gv_befehl . ' abgewiesen: ventil fehlt' . $gv_von, $gv_befehl);
    }
    $gv_sek = null;
    if ($gv_befehl === 'oeffnen') {
        $gv_max = gardena_ventil_max_min($g);
        if ($gv_max < 1) {
            gardena_ventil_ende(409, array('OK' => 0, 'GRUND' => 'HOECHSTDAUER_UNGUELTIG'),
                'oeffnen abgewiesen: die eingestellte Hoechstdauer ist unbrauchbar' . $gv_von, $gv_befehl);
        }
        if (preg_match('/^[0-9]{1,4}$/', $gv_minuten) !== 1 || (int) $gv_minuten < 1 || (int) $gv_minuten > $gv_max) {
            gardena_ventil_ende(400, array('OK' => 0, 'GRUND' => 'MINUTEN', 'ERLAUBT' => '1..' . $gv_max),
                'oeffnen abgewiesen: minuten fehlt oder liegt ausserhalb 1..' . $gv_max
                . ' (' . strlen($gv_minuten) . ' Zeichen)' . $gv_von, $gv_befehl);
        }
        $gv_sek = (int) $gv_minuten * 60;
        // Die feste Grenze aus Entscheidung 10 gilt unabhaengig von der Einstellung.
        if ($gv_sek > gardena_sekunden_grenze('VALVE_CONTROL')) {
            gardena_ventil_ende(400, array('OK' => 0, 'GRUND' => 'MINUTEN',
                'ERLAUBT' => '1..' . (int) (gardena_sekunden_grenze('VALVE_CONTROL') / 60)),
                'oeffnen abgewiesen: ueber der festen Grenze' . $gv_von, $gv_befehl);
        }
    } elseif ($gv_minuten !== '') {
        gardena_ventil_ende(400, array('OK' => 0, 'GRUND' => 'MINUTEN_UNNOETIG'),
            $gv_befehl . ' abgewiesen: minuten gehoert nur zu oeffnen' . $gv_von, $gv_befehl);
    }
    $gv_cache_datei = $lbpconfigdir . '/devices_cache.json';
    $gv_cache = is_file($gv_cache_datei) ? json_decode((string) @file_get_contents($gv_cache_datei), true) : null;
    if (!is_array($gv_cache)) {
        gardena_ventil_ende(503, array('OK' => 0, 'GRUND' => 'KEIN_ABBILD'),
            $gv_befehl . ' abgewiesen: noch kein Geraete-Abbild' . $gv_von, $gv_befehl);
    }
    $gv_v = gardena_ventil_suchen($gv_cache, $gv_ventil);
    if ($gv_v['grund'] === 'UNBEKANNT' || $gv_v['grund'] === 'KEIN_VENTIL') {
        gardena_ventil_ende(404, array('OK' => 0, 'GRUND' => ($gv_v['grund'] === 'UNBEKANNT') ? 'VENTIL_UNBEKANNT' : 'KEIN_VENTIL'),
            $gv_befehl . ' abgewiesen: ' . (($gv_v['grund'] === 'UNBEKANNT') ? 'kein Geraet' : 'Geraet ohne Ventil')
            . ' (ventil ' . strlen($gv_ventil) . ' Zeichen)' . $gv_von, $gv_befehl);
    }
    if ($gv_v['grund'] === 'MEHRVENTIL') {
        gardena_ventil_ende(409, array('OK' => 0, 'GRUND' => 'MEHRVENTIL', 'ANZAHL' => (int) $gv_v['mehrfach']),
            $gv_befehl . ' abgewiesen: Geraet mit ' . (int) $gv_v['mehrfach'] . ' Ventilen (Entscheidung 10)' . $gv_von,
            $gv_befehl);
    }

    if ($gv_befehl === 'zustand') {
        /* Der Zustand stammt aus dem letzten Abruf (Takt), nicht aus einer
         * Live-Abfrage. OK=0 mit GRUND=VERALTET, wenn der letzte gelungene
         * Abruf aelter ist als das Dreifache des Takts (Entscheidung 4); die
         * Werte stehen trotzdem daneben. LETZTER_BEFEHL ist der zuletzt von
         * der Wolke angenommene Befehl an dieses Ventil (aus dem Merker der
         * Gleichwert-Unterdrueckung, hoechstens eine Stunde alt). */
        $gv_akt = (isset($gv_v['attr']['activity']['value']) && is_scalar($gv_v['attr']['activity']['value']))
            ? strtoupper((string) $gv_v['attr']['activity']['value']) : '';
        if (in_array($gv_akt, array('MANUAL_WATERING', 'SCHEDULED_WATERING'), true)) {
            $gv_offen = '1';
        } elseif ($gv_akt === 'CLOSED') {
            $gv_offen = '0';
        } else {
            $gv_offen = '-';
        }
        $gv_st = gardena_status_lesen($lbpconfigdir);
        $gv_alter = !empty($gv_st['letzter_erfolg']) ? time() - (int) $gv_st['letzter_erfolg'] : -1;
        $gv_frisch = ($gv_alter >= 0 && $gv_alter <= 3 * 60 * gardena_intervall($g));
        $gv_lb = '-';
        $gv_lm = '-';
        $gv_ls = -1;
        $gv_l = gardena_gleichwert_lesen($gv_v['dienst']);
        if (is_array($gv_l) && $gv_l['w'] === 'BELEGT') {
            $gv_lb = 'laeuft';
        } elseif (is_array($gv_l)) {
            $gv_teil = explode('|', $gv_l['w'], 2);
            if ($gv_teil[0] === 'START_SECONDS_TO_OVERRIDE') {
                $gv_lb = 'oeffnen';
            } elseif ($gv_teil[0] === 'STOP_UNTIL_NEXT_TASK') {
                $gv_lb = 'schliessen';
            } else {
                $gv_lb = strtolower($gv_teil[0]);
            }
            if (isset($gv_teil[1])) { $gv_lm = (string) (int) floor((int) $gv_teil[1] / 60); }
            $gv_ls = time() - (int) $gv_l['t'];
        }
        $gv_f = array('OK' => $gv_frisch ? 1 : 0);
        if (!$gv_frisch) { $gv_f['GRUND'] = 'VERALTET'; }
        $gv_f += array('BEFEHL' => 'zustand', 'OFFEN' => $gv_offen, 'AKTIVITAET' => ($gv_akt === '') ? '-' : $gv_akt,
            'ALTER' => $gv_alter, 'LETZTER_BEFEHL' => $gv_lb, 'LETZTE_MINUTEN' => $gv_lm,
            'LETZTER_BEFEHL_VOR_S' => $gv_ls);
        gardena_ventil_ende(200, $gv_f, 'zustand beantwortet' . $gv_von, 'zustand', true);
    }

    $gv_cmd = ($gv_befehl === 'oeffnen') ? 'START_SECONDS_TO_OVERRIDE' : 'STOP_UNTIL_NEXT_TASK';
    $gv_wert = gardena_gleichwert_wert($gv_cmd, $gv_sek);
    $gv_antwort = array('OK' => 1, 'BEFEHL' => $gv_befehl);
    if ($gv_sek !== null) { $gv_antwort['MINUTEN'] = (int) ($gv_sek / 60); }
    $ggw = gardena_gleichwert_oeffnen();
    if ($ggw === null) {
        gardena_log_gebremst('gleichwert_merker', 'ERR', 'Der Merker der Gleichwert-Unterdrueckung ('
            . gardena_log_datei('gardena_gleichwert.merker') . ') laesst sich nicht oeffnen - Ventilbefehle '
            . 'werden abgewiesen, bis das behoben ist (Platz und Rechte im Protokollordner).');
        gardena_ventil_ende(503, array('OK' => 0, 'GRUND' => 'BREMSE_MERKER'),
            $gv_befehl . ' abgewiesen: Merker der Gleichwert-Unterdrueckung nicht nutzbar' . $gv_von, $gv_befehl);
    }
    $gv_seit = gardena_gleichwert_seit($ggw, $gv_v['dienst'], $gv_wert);
    if ($gv_seit >= 0) {
        gardena_ventil_ende(200, $gv_antwort + array('UNVERAENDERT' => 1, 'SEIT_S' => $gv_seit),
            $gv_befehl . ' unveraendert (derselbe Befehl vor ' . $gv_seit . ' s angenommen), nichts gesendet' . $gv_von,
            $gv_befehl);
    }
    $gsperre_bis = gardena_sperre_bis($lbpconfigdir);
    if ($gsperre_bis > 0) {
        gardena_ventil_ende(503, array('OK' => 0, 'GRUND' => 'ABRUFSPERRE', 'BIS' => date('H:i', $gsperre_bis)),
            $gv_befehl . ' abgewiesen: Abrufsperre bis ' . date('H:i', $gsperre_bis) . $gv_von, $gv_befehl);
    }
    $gzuviel = gardena_bremse_befehl();
    if ($gzuviel < 0) {
        gardena_log_gebremst('bremse_merker', 'ERR', 'Der Merker der Befehlsbremse ('
            . gardena_log_datei('gardena_befehle.merker') . ') laesst sich nicht oeffnen oder schreiben - '
            . 'Befehle werden abgewiesen, bis das behoben ist (Platz und Rechte im Protokollordner).');
        gardena_ventil_ende(503, array('OK' => 0, 'GRUND' => 'BREMSE_MERKER'),
            $gv_befehl . ' abgewiesen: Merker der Befehlsbremse nicht nutzbar' . $gv_von, $gv_befehl);
    }
    if ($gzuviel > 0) {
        gardena_ventil_ende(429, array('OK' => 0, 'GRUND' => 'BREMSE', 'GRENZE' => gardena_bremse_befehle_h()),
            $gv_befehl . ' abgewiesen: Obergrenze ' . gardena_bremse_befehle_h() . ' je Stunde erreicht' . $gv_von,
            $gv_befehl);
    }
    $gardena = new gardena($g['CLIENT_ID'], $g['CLIENT_SECRET'], $lbpconfigdir);
    if (!$gardena->authenticate()) {
        if ($gardena->last_http === 429) { gardena_kontingent_vermerken($lbpconfigdir, $gardena->retry_after); }
        gardena_ventil_ende(502, array('OK' => 0, 'GRUND' => ($gardena->last_http === 429) ? 'KONTINGENT' : 'ANMELDUNG',
            'HTTP' => (int) $gardena->last_http),
            $gv_befehl . ' gescheitert: Anmeldung an der Wolke (HTTP ' . (int) $gardena->last_http . ')' . $gv_von,
            $gv_befehl);
    }
    if ($gardena->sendCommand($gv_v['dienst'], 'VALVE_CONTROL', $gv_cmd, $gv_sek)) {
        gardena_gleichwert_schliessen($ggw, $gv_v['dienst'], $gv_wert);
        $ggw = null;
        $gv_antwort['GESENDET'] = 1;
        if ($gv_sek !== null) { $gv_antwort['BIS'] = time() + $gv_sek; }
        gardena_ventil_ende(200, $gv_antwort,
            $gv_befehl . ($gv_sek !== null ? ' ' . (int) ($gv_sek / 60) . ' min' : '') . ' an ' . $gv_v['dienst']
            . ' abgesetzt (von der Wolke angenommen)' . $gv_von, $gv_befehl);
    }
    if ($gardena->last_http === 429) { gardena_kontingent_vermerken($lbpconfigdir, $gardena->retry_after); }
    gardena_ventil_ende(502, array('OK' => 0, 'GRUND' => ($gardena->last_http === 429) ? 'KONTINGENT' : 'WOLKE',
        'HTTP' => (int) $gardena->last_http),
        $gv_befehl . ' gescheitert (HTTP ' . (int) $gardena->last_http . ')' . $gv_von, $gv_befehl);
}

/*
 * Die Parameter EINMAL einsammeln - und abweisen, was nicht ins Muster passt.
 *
 * PHP macht aus ?device[]=x ein Feld. trim() auf ein Feld ist unter PHP 8 ein
 * TypeError: die Anfrage endete mit HTTP 500 und LEEREM Rumpf, der Miniserver
 * bekam also statt "FEHLER: ..." gar nichts zu lesen. Unter 7.4 lief dieselbe
 * Anfrage mit einer Warnung weiter und schaltete womoeglich etwas. Beides ist
 * falsch: was nicht ins Muster passt, wird gemeldet, nicht zurechtgebogen.
 *
 * Die Laengengrenze ist grosszuegig - Geraetenamen vergibt der Anwender in
 * der Gardena-App frei, mit Leerzeichen und Umlauten. Ein enges Muster wuerde
 * gueltige Namen abweisen; eine Grenze weist nur Unsinn ab.
 */
$gpar = array();
foreach (array('action', 'token', 'device', 'type', 'cmd', 'seconds') as $gname) {
    if (!isset($_GET[$gname])) { $gpar[$gname] = ''; continue; }
    if (!is_string($_GET[$gname])) {
        gardena_ende(400, "FEHLER: Der Parameter '" . $gname . "' muss eine Zeichenkette sein, kein Feld.\n",
            'ABGEWIESEN Parameter ' . $gname . ' ist ein Feld');
    }
    if (strlen($_GET[$gname]) > 200) {
        gardena_ende(400, "FEHLER: Der Parameter '" . $gname . "' ist laenger als 200 Zeichen.\n",
            'ABGEWIESEN Parameter ' . $gname . ' zu lang (' . strlen($_GET[$gname]) . ' Zeichen)');
    }
    $gpar[$gname] = $_GET[$gname];
}

$action = $gpar['action'];

/*
 * Weissliste. Bis 1.2.5 fiel eine unbekannte Aktion in den Hilfetext und
 * wurde mit HTTP 200 beantwortet - ein Virtueller Ausgang wertet die Antwort
 * nicht aus, also sah ein Tippfehler in der Adresse aus wie Erfolg.
 * Der Hausstandard verlangt: abweisen und melden, nicht zurechtbiegen.
 */
$gerlaubt = array('', 'list', 'refresh', 'command');
if (!in_array($action, $gerlaubt, true)) {
    gardena_ende(400,
        "FEHLER: Unbekannte Aktion. Erlaubt: list, refresh, command"
        . " (oder ?selftest=1).\n",
        'ABGEWIESEN unbekannte Aktion (' . strlen($action) . ' Zeichen)');
}

/* ---------- Selbsttest: antwortet, OHNE etwas auszuloesen ----------
 *
 * Hausstandard seit dem 16.08.2026, hier ab 1.2.0. Ohne ihn laesst sich nicht
 * feststellen, ob das in Loxone eingetragene Token noch stimmt, ohne
 * WIRKLICH zu schalten - also den Maeher loszuschicken oder ein Ventil
 * aufzudrehen. Genau davor soll das Token schuetzen.
 *
 * Der Zweig steht VOR der gemeinsamen Tokenpruefung, weil er die beiden
 * Faelle unterscheiden muss: gar kein Token eingerichtet (dann hilft ein
 * Blick in die Oberflaeche) gegen falsches Token (dann stimmt die Adresse in
 * Loxone nicht mehr). Er liest ausschliesslich - kein API-Aufruf, kein
 * Versand, kein Schreiben.
 */
if (isset($_GET['selftest'])) {
    $gsoll = isset($g['TOKEN']) ? (string) $g['TOKEN'] : '';
    $gist = $gpar['token'];
    if ($gsoll === '') {
        gardena_ende(403, "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n",
            'SELFTEST abgewiesen: kein Token eingerichtet');
    }
    if (!gardena_token_ok($gsoll, $gist)) {
        gardena_ende(403, "SELFTEST;OK=0;ERR=TOKEN\n",
            'SELFTEST abgewiesen: falsches Token');
    }
    // Ab hier ist das Token in Ordnung. Die uebrigen Felder sagen, ob der
    // Endpunkt auch etwas ausrichten koennte - alle drei aus vorhandenen
    // Dateien gelesen, nichts wird angestossen.
    $gst = gardena_status_lesen($lbpconfigdir);
    $gzugang = (!empty($g['CLIENT_ID']) && !empty($g['CLIENT_SECRET'])) ? 1 : 0;
    $gabbild = is_file($lbpconfigdir . '/devices_cache.json') ? 1 : 0;
    $galter = !empty($gst['letzter_erfolg']) ? (time() - (int) $gst['letzter_erfolg']) : -1;
    /*
     * TOKEN traegt seit 1.2.6 eine ZAHL. Bis 1.2.5 stand hier als einziges
     * Feld einer sonst durchweg numerischen Zeile der Text "OK" - ein
     * virtueller Eingang mit dem Suchtext ";TOKEN=\\v" liest daraus 0, und
     * das ist von einem gemessenen 0 nicht zu unterscheiden.
     */
    gardena_ende(200,
        'SELFTEST;OK=1;TOKEN=1'
        . ';ZUGANG=' . $gzugang
        . ';ABBILD=' . $gabbild
        . ';LETZTER_ERFOLG=' . $galter
        . ';WERTE=' . (int) $gst['werte']
        . ';SOCKETS=' . (gardena_udp_moeglich() ? 1 : 0)
        . "\n",
        'SELFTEST beantwortet', true);
}

// ---------- Zugriffsschutz ----------
//
// Seit 1.1.0 verlangt AUCH ?action=list das Token.
//
// Bis 1.0.2 war die Geraeteliste ohne jede Pruefung abrufbar - und diese
// Datei liegt im unangemeldeten Bereich. Darin stehen die Klarnamen aller
// Geraete, ihre Ladezustaende, die Verbindungsguete und vor allem die
// Service-Kennungen. Genau diese Kennungen braucht ein Schaltbefehl. Wer
// die Liste lesen konnte, kannte also alles ausser dem Token - und wusste
// zugleich, welche Geraete es ueberhaupt zu schalten gibt. Ein
// Diagnose-Endpunkt rechtfertigt das nicht; das Token steht in der
// Oberflaeche und in den dort angezeigten Adressen ohnehin schon drin.
if ($action === 'command' || $action === 'refresh' || $action === 'list') {
    $given = $gpar['token'];
    if (!gardena_token_ok(isset($g['TOKEN']) ? $g['TOKEN'] : '', $given)) {
        if (empty($g['TOKEN'])) {
            gardena_ende(403,
                "FEHLER: Es ist noch kein Token hinterlegt. Bitte einmal die Plugin-Oberflaeche\n"
                . "oeffnen - dort wird eines erzeugt und die fertige Loxone-URL angezeigt.\n",
                'ABGEWIESEN ' . $action . ': kein Token eingerichtet');
        }
        gardena_ende(403,
            "FEHLER: Ungueltiges oder fehlendes Token.\n"
            . "Aufruf: ?action=" . $action . "&token=... (Token steht in der Plugin-Oberflaeche)\n",
            'ABGEWIESEN ' . $action . ': falsches Token (' . strlen($given) . ' Zeichen)');
    }
}

/*
 * Diese Auskunft gehoert HINTER das Tokentor.
 *
 * Bis 1.2.5 stand sie davor und wurde auch bei einem Aufruf ganz ohne
 * 'action' erreicht - also ohne Token. An der Antwort liess sich damit von
 * aussen ablesen, ob das Plugin eingerichtet ist. Eine kleine Auskunft, aber
 * eine, die niemand braucht.
 */
if ($action !== '' && (empty($g['CLIENT_ID']) || empty($g['CLIENT_SECRET']))) {
    gardena_ende(409,
        "FEHLER: Application Key/Secret nicht konfiguriert (Plugin-Oberflaeche oeffnen).\n",
        'ABGEWIESEN ' . $action . ': keine Zugangsdaten hinterlegt');
}

/*
 * "Plugin aktiv: Nein" sperrt auch Befehle und den Sofortabruf (1.2.11, C3/C4;
 * Entscheidung des Hausherrn Nr. 10 vom 30.09.2026).
 *
 * Bis 1.2.10 schaltete ENABLED=0 nur den Abruf ab: ein Ventilbefehl aus
 * Loxone ging weiter an die Wolke, und ?action=refresh meldete "Abruf
 * gestartet", ohne dass etwas abgerufen wurde (in WSL gemessen, code Befunde 3
 * und 4). Die Geraeteliste bleibt lesbar - sie loest nichts aus.
 */
if (($action === 'refresh' || $action === 'command') && (string) $g['ENABLED'] !== '1') {
    gardena_ende(409,
        "FEHLER: Plugin ist ausgeschaltet (Plugin aktiv: Nein) - es wird nichts abgerufen und nichts geschaltet.\n",
        'ABGEWIESEN ' . $action . ': Plugin ist ausgeschaltet (ENABLED=0)');
}

// ---------- Geraeteliste aus dem Zwischenspeicher ----------
if ($action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    // Nicht die Datei durchreichen, sondern einlesen und neu ausgeben.
    // Damit ist sichergestellt, dass wirklich gueltiges JSON hinausgeht -
    // ein halb geschriebener oder beschaedigter Zwischenspeicher wuerde
    // sonst unveraendert an Loxone weitergegeben. JSON_HEX_TAG maskiert
    // zusaetzlich spitze Klammern in den Geraetenamen; die vergibt der
    // Anwender in der Gardena-App frei, und die Ausgabe koennte irgendwo
    // in einer Oberflaeche landen, die sie als HTML deutet.
    // is_file() davor: vor dem ersten Abruf fehlt die Datei zu Recht, und ein
    // gesetzter Fehlerbehandler laesst sich vom '@' nicht aufhalten.
    $gcache = $lbpconfigdir . '/devices_cache.json';
    $roh = is_file($gcache) ? @file_get_contents($gcache) : false;
    $daten = ($roh !== false) ? json_decode($roh, true) : null;
    if (!is_array($daten)) {
        // 503, nicht 200: es gibt noch keine Daten (Regeln/07 - "auch vor dem
        // ersten Abruf ... weder mit 200 noch mit 404"). Bis 1.2.7 kam die
        // Fehlermeldung mit 200 und sah aus wie eine gueltige Antwort.
        gardena_ende(503,
            json_encode(array('error' => 'Noch keine Daten - bitte einmal ?action=refresh aufrufen.')),
            'list: noch kein Geraete-Abbild vorhanden', true);
    }
    gardena_ende(200,
        json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        'list beantwortet', true);
}

// ---------- Sofort-Abruf ----------
if ($action === 'refresh') {
    // gardenaMain liegt seit 1.1.0 in bin/, nicht mehr neben dieser Datei.
    $skript = $gbindir . '/gardenaMain.php';
    if (!is_file($skript)) {
        gardena_ende(500, "FEHLER: " . $skript . " nicht gefunden - Plugin neu installieren.\n",
            'refresh: gardenaMain.php nicht gefunden');
    }
    // gardenaMain nimmt selbst eine Sperre. Laeuft schon ein Abruf, endet
    // der neue von sich aus - hier wird deshalb nichts zusaetzlich geprueft.
    /*
     * Bis 1.2.0 antwortete dieser Zweig ausnahmslos "OK: Abruf gestartet" -
     * auch dann, wenn gardenaMain wegen der Sperre sofort wieder ausstieg.
     * Die Sperre wird deshalb hier einmal probeweise genommen und gleich
     * wieder freigegeben: laesst sie sich nicht nehmen, laeuft schon einer.
     * Zwischen Freigabe und Start bleibt ein Wimpernschlag, in dem sich zwei
     * Aufrufe treffen koennen - dann greift die Sperre in gardenaMain wie
     * bisher. Die Antwort ist damit nicht garantiert, aber sie ist nicht
     * mehr unabhaengig von der Wirklichkeit.
     */
    /*
     * Bremsen (seit 1.2.8, Regeln/03). Bis 1.2.7 loeste ein flatternder
     * Virtueller Ausgang mit jeder Flanke einen vollstaendigen Abruf aus.
     * Abgewiesen wird ausdruecklich und mit Grund.
     */
    $gsperre_bis = gardena_sperre_bis($lbpconfigdir);
    if ($gsperre_bis > 0) {
        gardena_ende(503, "FEHLER: Abrufsperre nach HTTP 429 bis " . date('H:i', $gsperre_bis)
            . " - vorher wird nicht abgerufen.\n",
            'refresh abgewiesen: Abrufsperre bis ' . date('H:i', $gsperre_bis));
    }
    $gst = gardena_status_lesen($lbpconfigdir);
    $gseit = time() - (int) $gst['letzter_lauf'];
    if ((int) $gst['letzter_lauf'] > 0 && $gseit >= 0 && $gseit < gardena_bremse_abruf_s()) {
        gardena_ende(429, "FEHLER: Der letzte Abruf liegt erst " . $gseit . " Sekunden zurueck. "
            . "Ein Sofortabruf ist fruehestens " . gardena_bremse_abruf_s() . " Sekunden nach dem letzten Lauf moeglich.\n",
            'refresh abgewiesen: zu frueh (' . $gseit . ' s nach dem letzten Lauf)');
    }
    $gprobe = gardena_sperre('main', $gprobe_grund);
    if ($gprobe === false && $gprobe_grund === 'nicht_zu_oeffnen') {
        // Nicht "laeuft bereits" (1.2.11, I6): die Sperrdatei ist nicht nutzbar.
        gardena_ende(500, "FEHLER: Die Sperrdatei des Abrufs laesst sich nicht oeffnen - es wurde kein Abruf "
            . "gestartet. Rechte im Protokollordner pruefen.\n",
            'refresh: Sperrdatei nicht zu oeffnen, kein Abruf gestartet');
    }
    if ($gprobe === false) {
        gardena_ende(200, "OK: Es laeuft bereits ein Abruf - dieser Aufruf startet keinen zweiten.\n",
            'refresh: laeuft bereits, kein zweiter Lauf gestartet');
    }
    flock($gprobe, LOCK_UN);
    fclose($gprobe);
    $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : '/usr/bin/php';
    /*
     * --sofort (1.2.11, C3): der Lauf ueberspringt den eingestellten Abstand.
     * "Abruf gestartet" steht erst da, wenn der Lauf seine Sperre wirklich hat
     * (gardena_sofort_warten()); meldet er sich binnen 5 s nicht, ist das ein
     * Fehler, keine Erfolgsmeldung.
     */
    gardena_sofort_vergessen();
    shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($skript) . ' --sofort > /dev/null 2>&1 &');
    $gsofort = gardena_sofort_warten(5.0);
    if ($gsofort === 'gestartet') {
        gardena_ende(200, "OK: Abruf gestartet (Ergebnis im Protokoll und unter ?action=list).\n",
            'refresh: Abruf gestartet');
    }
    if ($gsofort === 'belegt') {
        gardena_ende(200, "OK: Es laeuft bereits ein Abruf - dieser Aufruf startet keinen zweiten.\n",
            'refresh: laeuft bereits (der gestartete Lauf fand die Sperre belegt)');
    }
    gardena_ende(503, "FEHLER: Der Abruf ist nicht angelaufen (binnen 5 Sekunden keine Rueckmeldung) - "
        . "Grund im Protokoll (Reiter Logdateien, Fehler des Cron-Rahmens).\n",
        'refresh: Abruf nicht angelaufen (keine Rueckmeldung binnen 5 s)');
}

// ---------- Kommando ----------
if ($action === 'command') {
    $devQuery = trim($gpar['device']);
    $cmd = strtoupper(trim($gpar['cmd']));

    /*
     * type wird NICHT mehr geraten.
     *
     * Bis 1.2.5 galt ohne Angabe MOWER_CONTROL - sechs Zeilen weiter wird ein
     * fehlendes 'device' ausdruecklich abgewiesen, mit der Begruendung, eine
     * fehlende Angabe werde nicht geraten. Ein Virtueller Ausgang, bei dem
     * '&type=' beim Abschreiben verlorenging, startete damit den MAEHER,
     * statt ein Ventil zu oeffnen - und wertete die Antwort nicht aus.
     */
    $type = strtoupper(trim($gpar['type']));
    if ($type === '') {
        gardena_ende(400,
            "FEHLER: Es fehlt die Angabe type=. Erlaubt: MOWER_CONTROL, VALVE_CONTROL,"
            . " POWER_SOCKET_CONTROL.\n",
            'command abgewiesen: type fehlt');
    }

    /*
     * seconds: ein GESETZTER, aber unlesbarer Wert wird abgewiesen.
     *
     * Bis 1.2.5 liefen "nicht angegeben" und "Unsinn angegeben" in denselben
     * Wert: 'abc', '-60', '99999999' und '1800.0' wurden alle zu null und
     * damit wortlos zu 3600 - der Maeher lief eine Stunde. Fuer die
     * 60er-Regel wurde dagegen sauber abgewiesen: dieselbe Fehlerklasse,
     * zwei verschiedene Antworten. Gemessen unter 7.4.33 und 8.4.24.
     */
    $seconds = null;
    if ($gpar['seconds'] !== '') {
        if (preg_match('/^[0-9]{1,7}$/', $gpar['seconds']) !== 1) {
            gardena_ende(400,
                "FEHLER: seconds muss eine ganze Zahl aus Ziffern sein (hoechstens 7 Stellen).\n",
                'command abgewiesen: seconds unlesbar (' . strlen($gpar['seconds']) . ' Zeichen)');
        }
        $seconds = (int) $gpar['seconds'];
        if ($seconds < 60) {
            gardena_ende(400,
                "FEHLER: seconds muss mindestens 60 betragen (angegeben: " . $seconds . ").\n",
                'command abgewiesen: seconds zu klein');
        }
    }

    /*
     * Ohne device wurde bis 1.2.0 das ERSTE Geraet mit passendem Dienst
     * geschaltet - bei mehreren Maehern oder Ventilen also irgendeines. Eine
     * fehlende Angabe wird abgewiesen, nicht geraten.
     */
    if ($devQuery === '') {
        gardena_ende(400, "FEHLER: Es fehlt die Angabe device=NAME. Die Geraetenamen zeigt ?action=list.\n",
            'command abgewiesen: device fehlt');
    }
    /*
     * Die Oberflaeche sagt seit jeher, seconds sei ein Vielfaches von 60 -
     * geprueft wurde es nie. Ein Satz in der Anleitung, den der Code nicht
     * einhaelt, ist eine der beiden Stellen falsch; hier ist es der Code.
     */
    if ($seconds !== null && $seconds % 60 !== 0) {
        gardena_ende(400, "FEHLER: seconds muss ein Vielfaches von 60 sein (angegeben: " . $seconds . ").\n",
            'command abgewiesen: seconds kein Vielfaches von 60');
    }

    /*
     * type und cmd werden GEGENEINANDER geprueft, nicht gegen zwei getrennte
     * flache Listen. Bis 1.2.5 bestand 'type=MOWER_CONTROL&cmd=PAUSE' die
     * Pruefung, kostete einen Abruf des Husqvarna-Kontingents und scheiterte
     * erst in der Wolke - das Plugin konnte es ohne jeden Netzzugriff wissen.
     * Die Zuordnung fuehrt die Oberflaeche im Reiter "Einbindung in Loxone"
     * ohnehin; sie steht jetzt an EINER Stelle in der Bibliothek.
     */
    $gbefehle = gardena_befehle();
    if (!isset($gbefehle[$type])) {
        gardena_ende(400,
            "FEHLER: Ungueltiger type. Erlaubt: " . implode(', ', array_keys($gbefehle)) . "\n",
            'command abgewiesen: type unbekannt');
    }
    if (!in_array($cmd, $gbefehle[$type], true)) {
        gardena_ende(400,
            "FEHLER: cmd '" . $cmd . "' gibt es fuer type " . $type . " nicht. Erlaubt: "
            . implode(', ', $gbefehle[$type]) . "\n",
            'command abgewiesen: cmd passt nicht zu type ' . $type);
    }
    // Die Vorgabe greift nur, wenn seconds WIRKLICH fehlt (siehe oben).
    if ($cmd === 'START_SECONDS_TO_OVERRIDE' && $seconds === null) { $seconds = 3600; }

    /*
     * Obergrenze je Dienst (1.2.11, C1; Entscheidung 10): Ventil 3 h, Maeher und
     * Steckdose 24 h je Befehl - gardena_sekunden_grenzen(). Bis 1.2.10 ging
     * seconds=9999960 (115 Tage) an die Wolke (in WSL gemessen, code Befund 1).
     */
    $ggrenze = gardena_sekunden_grenze($type);
    if ($seconds !== null && $ggrenze > 0 && $seconds > $ggrenze) {
        gardena_ende(400,
            "FEHLER: seconds ist fuer " . $type . " hoechstens " . $ggrenze . " (" . ($ggrenze / 3600)
            . " h je Befehl), angegeben: " . $seconds . ". Der Befehl wurde NICHT gesendet.\n",
            'command abgewiesen: seconds ueber der Grenze ' . $ggrenze . ' fuer ' . $type);
    }

    // Service-ID im Cache suchen (Geraetename oder Geraete-ID)
    $gcache = $lbpconfigdir . '/devices_cache.json';
    $cache = is_file($gcache)
        ? json_decode((string) @file_get_contents($gcache), true) : null;
    $serviceMap = array('MOWER_CONTROL' => 'MOWER', 'VALVE_CONTROL' => 'VALVE', 'POWER_SOCKET_CONTROL' => 'POWER_SOCKET');
    $serviceId = '';
    $gmehrfach = 1;
    if (is_array($cache) && !empty($cache['locations']) && is_array($cache['locations'])) {
        foreach ($cache['locations'] as $loc) {
            // Ohne diese Pruefung waere ein Zwischenspeicher ohne 'devices'
            // unter PHP 8 ein toedlicher Fehler (foreach ueber null), und der
            // Miniserver bekaeme eine leere Antwort statt einer Fehlermeldung.
            if (!is_array($loc) || !isset($loc['devices']) || !is_array($loc['devices'])) { continue; }
            foreach ($loc['devices'] as $devId => $dev) {
                if (!is_array($dev)) { continue; }
                $devName = isset($dev['name']) ? (string) $dev['name'] : '';
                if ($devQuery !== '' && strcasecmp($devName, $devQuery) !== 0 && strcasecmp((string) $devId, $devQuery) !== 0) { continue; }
                $svcType = $serviceMap[$type];
                if (isset($dev['services'][$svcType]['_service_id']['value'])) {
                    $serviceId = $dev['services'][$svcType]['_service_id']['value'];
                    if (isset($dev['mehrfach'][$svcType]) && is_numeric($dev['mehrfach'][$svcType])) {
                        $gmehrfach = (int) $dev['mehrfach'][$svcType];
                    }
                    break 2;
                }
            }
        }
    }
    if (!is_array($cache)) {
        // Noch kein Abbild: das ist fehlende Datenlage, kein unbekanntes
        // Geraet (Regeln/07). Bis 1.2.7 kam hier 404 "Kein passendes Geraet".
        gardena_ende(503,
            "FEHLER: Es gibt noch kein Geraete-Abbild - erst muss ein Abruf gelingen (?action=refresh).\n",
            'command abgewiesen: noch kein Geraete-Abbild');
    }
    /*
     * Mehrere Dienste gleichen Typs an einem Geraet (1.2.11, C10; Entscheidung
     * 10): Befehle werden abgewiesen, bis an echter Hardware gemessen ist, wie
     * die Wolke ein Mehrventil-Geraet liefert. Bis 1.2.10 ging der Befehl an
     * das zuletzt gelieferte Ventil (an der Attrappe gemessen, code Befund 10).
     */
    if ($serviceId !== '' && $gmehrfach > 1) {
        gardena_ende(409,
            "FEHLER: Das Geraet '" . $devQuery . "' hat " . $gmehrfach . " Dienste vom Typ " . $svcType
            . " (etwa mehrere Ventile). Befehle an ein solches Geraet werden abgewiesen, bis an echter"
            . " Hardware gemessen ist, welches Ventil die Wolke unter welcher Kennung fuehrt."
            . " Der Befehl wurde NICHT gesendet.\n",
            'command abgewiesen: Geraet mit ' . $gmehrfach . ' Diensten vom Typ ' . $svcType);
    }
    if ($serviceId === '') {
        gardena_ende(404,
            "FEHLER: Kein passendes Geraet/Service gefunden (device='" . $devQuery . "', type=" . $type . "). Erst ?action=refresh ausfuehren; Geraetenamen zeigt ?action=list.\n",
            'command: kein passendes Geraet (device ' . strlen($devQuery) . ' Zeichen, type ' . $type . ')');
    }

    /*
     * Gleichwert-Unterdrueckung fuer Ventil- und Steckdosenbefehle (1.2.13,
     * X-7; Entscheidung 19 vom 01.10.2026; Steckdose seit 1.2.14): derselbe
     * Befehl mit derselben Dauer an dasselbe Ventil bzw. dieselbe Steckdose
     * innerhalb von 60 s geht nicht erneut hinaus - Antwort 200 mit
     * UNVERAENDERT=1. Sie steht VOR der Abrufsperre und der Stundengrenze:
     * ein unterdrueckter Befehl zaehlt nicht mit. Derselbe Merker gilt fuer
     * die Schnittstelle der Bewaesserung. Der Maeher ist nicht betroffen
     * (gardena_gleichwert_gilt()). Bis 1.2.12 ging jede Wiederholung an die
     * Wolke, bis 1.2.13 jede Wiederholung an eine Steckdose.
     */
    $ggw = null;
    $gw_wert = '';
    if (gardena_gleichwert_gilt($type)) {
        $gw_wert = gardena_gleichwert_wert($cmd, $seconds);
        $ggw = gardena_gleichwert_oeffnen();
        if ($ggw === null) {
            gardena_log_gebremst('gleichwert_merker', 'ERR', 'Der Merker der Gleichwert-Unterdrueckung ('
                . gardena_log_datei('gardena_gleichwert.merker') . ') laesst sich nicht oeffnen - Ventil- und '
                . 'Steckdosenbefehle werden abgewiesen, bis das behoben ist (Platz und Rechte im Protokollordner).');
            gardena_ende(503, "FEHLER: Der Merker der Gleichwert-Unterdrueckung ist nicht nutzbar (Merkerdatei im "
                . "Protokollordner) - der Befehl wurde NICHT gesendet.\n",
                'command abgewiesen: Merker der Gleichwert-Unterdrueckung nicht nutzbar');
        }
        $gw_seit = gardena_gleichwert_seit($ggw, $serviceId, $gw_wert);
        if ($gw_seit >= 0) {
            gardena_ende(200, 'OK: ' . $cmd . ' an ' . $serviceId . ' nicht erneut gesendet - derselbe Befehl wurde vor '
                . $gw_seit . " s angenommen (UNVERAENDERT=1).\n",
                'command ' . $type . '/' . $cmd . ' unveraendert (vor ' . $gw_seit . ' s angenommen), nichts gesendet');
        }
    }

    /*
     * Bremsen VOR dem Netzzugriff (seit 1.2.8, Regeln/03).
     *
     * Bis 1.2.7 las dieser Zweig die Abrufsperre nach HTTP 429 nicht: der
     * Dienst wartete sie ab, ein Virtueller Ausgang klopfte trotzdem an und
     * verlaengerte sie. Und es gab keine Obergrenze - ein flatternder
     * Baustein schickte jede Sekunde einen Befehl.
     */
    $gsperre_bis = gardena_sperre_bis($lbpconfigdir);
    if ($gsperre_bis > 0) {
        gardena_ende(503, "FEHLER: Abrufsperre nach HTTP 429 bis " . date('H:i', $gsperre_bis)
            . " - der Befehl wurde NICHT gesendet.\n",
            'command abgewiesen: Abrufsperre bis ' . date('H:i', $gsperre_bis));
    }
    $gzuviel = gardena_bremse_befehl();
    if ($gzuviel < 0) {
        // Die Bremse faellt geschlossen aus (1.2.11, C2).
        gardena_log_gebremst('bremse_merker', 'ERR', 'Der Merker der Befehlsbremse ('
            . gardena_log_datei('gardena_befehle.merker') . ') laesst sich nicht oeffnen oder schreiben - '
            . 'Befehle werden abgewiesen, bis das behoben ist (Platz und Rechte im Protokollordner).');
        gardena_ende(503, "FEHLER: Die Befehlsbremse ist nicht nutzbar (Merkerdatei im Protokollordner) - "
            . "der Befehl wurde NICHT gesendet.\n",
            'command abgewiesen: Merker der Befehlsbremse nicht nutzbar');
    }
    if ($gzuviel > 0) {
        gardena_ende(429, "FEHLER: Mehr als " . gardena_bremse_befehle_h() . " Befehle in der letzten Stunde"
            . " - der Befehl wurde NICHT gesendet. Flattert ein Baustein am Virtuellen Ausgang?\n",
            'command abgewiesen: Obergrenze ' . gardena_bremse_befehle_h() . ' je Stunde erreicht');
    }

    $gardena = new gardena($g['CLIENT_ID'], $g['CLIENT_SECRET'], $lbpconfigdir);
    if (!$gardena->authenticate()) {
        if ($gardena->last_http === 429) { gardena_kontingent_vermerken($lbpconfigdir, $gardena->retry_after); }
        gardena_ende(502, 'FEHLER: Anmeldung fehlgeschlagen: ' . $gardena->last_error . "\n",
            'command: Anmeldung an der Wolke fehlgeschlagen (HTTP ' . (int) $gardena->last_http . ')');
    }
    if ($gardena->sendCommand($serviceId, $type, $cmd, $seconds)) {
        // Gemerkt wird nur, was die Wolke angenommen hat (X-7).
        if ($ggw !== null) {
            gardena_gleichwert_schliessen($ggw, $serviceId, $gw_wert);
            $ggw = null;
        }
        gardena_ende(200, 'OK: ' . $cmd . ' an ' . $serviceId . " gesendet.\n",
            'command ' . $type . '/' . $cmd . ' abgesetzt');
    }
    if ($gardena->last_http === 429) { gardena_kontingent_vermerken($lbpconfigdir, $gardena->retry_after); }
    gardena_ende(502, 'FEHLER: ' . $gardena->last_error . "\n",
        'command ' . $type . '/' . $cmd . ' gescheitert (HTTP ' . (int) $gardena->last_http . ')');
}

// ---------- Hilfe ----------
echo "GARDENA smart system Plugin - Endpunkte:\n\n";
echo "Alle Endpunkte verlangen das Token aus der Plugin-Oberflaeche:\n";
echo "  ?selftest=1&token=...       prueft nur das Token - loest nichts aus\n";
echo "  ?action=list&token=...      Geraeteliste als JSON\n";
echo "  ?action=refresh&token=...   Daten sofort abrufen und an Miniserver/MQTT senden\n";
echo "  ?action=command&token=...&device=NAME&type=MOWER_CONTROL&cmd=PARK_UNTIL_NEXT_TASK\n";
echo "  ?action=command&token=...&device=NAME&type=MOWER_CONTROL&cmd=START_SECONDS_TO_OVERRIDE&seconds=3600\n";
echo "  ?action=command&token=...&device=NAME&type=VALVE_CONTROL&cmd=START_SECONDS_TO_OVERRIDE&seconds=1800\n";
echo "\nSchnittstelle fuer die Bewaesserung (ab Werk aus; nur POST und nur vom LoxBerry selbst):\n";
echo "  POST action=ventil&token=<Ventil-Token>&ventil=NAME&befehl=oeffnen&minuten=10\n";
echo "  POST action=ventil&token=<Ventil-Token>&ventil=NAME&befehl=schliessen  (oder befehl=zustand)\n";
gardena_endpunkt_log('Hilfetext ausgegeben (kein action-Parameter)', true);
