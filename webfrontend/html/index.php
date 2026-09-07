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
require_once $lbpbindir . '/gardena.class.inc.php';
require_once $lbpbindir . '/functions.inc.php';

header('Content-Type: text/plain; charset=utf-8');

/**
 * Ein einziger Ausgang - und jeder Weg schreibt eine Zeile.
 *
 * Bis 1.2.5 hatte diese Datei zehn Ausgaenge und NULL Protokolleintraege
 * (gemessen: dasselbe Suchmuster findet in gardenaMain.php 43 Treffer).
 */
function gardena_ende($code, $text, $protokoll, $gebremst = false)
{
    if ($code !== 200) { http_response_code($code); }
    gardena_endpunkt_log($protokoll, $gebremst);
    echo $text;
    exit;
}

$g = gardena_cfg_read($lbpconfigdir . '/gardena.cfg');

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
        gardena_ende(200,
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
    $skript = $lbpbindir . '/gardenaMain.php';
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
    $gprobe = gardena_sperre('main');
    if ($gprobe === false) {
        gardena_ende(200, "OK: Es laeuft bereits ein Abruf - dieser Aufruf startet keinen zweiten.\n",
            'refresh: laeuft bereits, kein zweiter Lauf gestartet');
    }
    flock($gprobe, LOCK_UN);
    fclose($gprobe);
    $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : '/usr/bin/php';
    shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($skript) . ' > /dev/null 2>&1 &');
    gardena_ende(200, "OK: Abruf gestartet (Ergebnis im Protokoll und unter ?action=list).\n",
        'refresh: Abruf gestartet');
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

    // Service-ID im Cache suchen (Geraetename oder Geraete-ID)
    $gcache = $lbpconfigdir . '/devices_cache.json';
    $cache = is_file($gcache)
        ? json_decode((string) @file_get_contents($gcache), true) : null;
    $serviceMap = array('MOWER_CONTROL' => 'MOWER', 'VALVE_CONTROL' => 'VALVE', 'POWER_SOCKET_CONTROL' => 'POWER_SOCKET');
    $serviceId = '';
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
                    break 2;
                }
            }
        }
    }
    if ($serviceId === '') {
        gardena_ende(404,
            "FEHLER: Kein passendes Geraet/Service gefunden (device='" . $devQuery . "', type=" . $type . "). Erst ?action=refresh ausfuehren; Geraetenamen zeigt ?action=list.\n",
            'command: kein passendes Geraet (device ' . strlen($devQuery) . ' Zeichen, type ' . $type . ')');
    }

    $gardena = new gardena($g['CLIENT_ID'], $g['CLIENT_SECRET'], $lbpconfigdir);
    if (!$gardena->authenticate()) {
        gardena_ende(502, 'FEHLER: Anmeldung fehlgeschlagen: ' . $gardena->last_error . "\n",
            'command: Anmeldung an der Wolke fehlgeschlagen (HTTP ' . (int) $gardena->last_http . ')');
    }
    if ($gardena->sendCommand($serviceId, $type, $cmd, $seconds)) {
        gardena_ende(200, 'OK: ' . $cmd . ' an ' . $serviceId . " gesendet.\n",
            'command ' . $type . '/' . $cmd . ' abgesetzt');
    }
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
gardena_endpunkt_log('Hilfetext ausgegeben (kein action-Parameter)', true);
