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

$g = gardena_cfg_read($lbpconfigdir . '/gardena.cfg');

$action = isset($_GET['action']) ? $_GET['action'] : '';

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
    $given = isset($_GET['token']) ? $_GET['token'] : '';
    if (!gardena_token_ok(isset($g['TOKEN']) ? $g['TOKEN'] : '', $given)) {
        header('HTTP/1.1 403 Forbidden');
        if (empty($g['TOKEN'])) {
            echo "FEHLER: Es ist noch kein Token hinterlegt. Bitte einmal die Plugin-Oberflaeche\n"
               . "oeffnen - dort wird eines erzeugt und die fertige Loxone-URL angezeigt.\n";
        } else {
            echo "FEHLER: Ungueltiges oder fehlendes Token.\n"
               . "Aufruf: ?action=" . $action . "&token=... (Token steht in der Plugin-Oberflaeche)\n";
        }
        exit;
    }
}

if (empty($g['CLIENT_ID']) || empty($g['CLIENT_SECRET'])) {
    echo "FEHLER: Application Key/Secret nicht konfiguriert (Plugin-Oberflaeche oeffnen).\n";
    exit;
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
    $roh = @file_get_contents($lbpconfigdir . '/devices_cache.json');
    $daten = ($roh !== false) ? json_decode($roh, true) : null;
    if (!is_array($daten)) {
        echo json_encode(array('error' => 'Noch keine Daten - bitte einmal ?action=refresh aufrufen.'));
        exit;
    }
    echo json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

// ---------- Sofort-Abruf ----------
if ($action === 'refresh') {
    // gardenaMain liegt seit 1.1.0 in bin/, nicht mehr neben dieser Datei.
    $skript = $lbpbindir . '/gardenaMain.php';
    if (!is_file($skript)) {
        echo "FEHLER: " . $skript . " nicht gefunden - Plugin neu installieren.\n";
        exit;
    }
    // gardenaMain nimmt selbst eine Sperre. Laeuft schon ein Abruf, endet
    // der neue von sich aus - hier wird deshalb nichts zusaetzlich geprueft.
    $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : '/usr/bin/php';
    shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($skript) . ' > /dev/null 2>&1 &');
    echo "OK: Abruf gestartet (Ergebnis im Protokoll und unter ?action=list).\n";
    exit;
}

// ---------- Kommando ----------
if ($action === 'command') {
    $devQuery = isset($_GET['device']) ? trim($_GET['device']) : '';
    $type = isset($_GET['type']) ? strtoupper(trim($_GET['type'])) : 'MOWER_CONTROL';
    $cmd = isset($_GET['cmd']) ? strtoupper(trim($_GET['cmd'])) : '';
    $seconds = (isset($_GET['seconds']) && ctype_digit($_GET['seconds'])) ? (int) $_GET['seconds'] : null;

    $allowed_types = array('MOWER_CONTROL', 'VALVE_CONTROL', 'POWER_SOCKET_CONTROL');
    $allowed_cmds = array('START_SECONDS_TO_OVERRIDE', 'START_DONT_OVERRIDE', 'START_OVERRIDE',
        'PARK_UNTIL_NEXT_TASK', 'PARK_UNTIL_FURTHER_NOTICE', 'STOP_UNTIL_NEXT_TASK', 'PAUSE', 'UNPAUSE');
    if (!in_array($type, $allowed_types, true)) { echo "FEHLER: Ungueltiger type.\n"; exit; }
    if (!in_array($cmd, $allowed_cmds, true)) {
        echo "FEHLER: Ungueltiges cmd. Erlaubt: " . implode(', ', $allowed_cmds) . "\n"; exit;
    }
    if ($cmd === 'START_SECONDS_TO_OVERRIDE' && $seconds === null) { $seconds = 3600; }

    // Service-ID im Cache suchen (Geraetename oder Geraete-ID)
    $cache = json_decode((string) @file_get_contents($lbpconfigdir . '/devices_cache.json'), true);
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
        echo "FEHLER: Kein passendes Geraet/Service gefunden (device='" . $devQuery . "', type=" . $type . "). Erst ?action=refresh ausfuehren; Geraetenamen zeigt ?action=list.\n";
        exit;
    }

    $gardena = new gardena($g['CLIENT_ID'], $g['CLIENT_SECRET'], $lbpconfigdir);
    if (!$gardena->authenticate()) { echo 'FEHLER: Anmeldung fehlgeschlagen: ' . $gardena->last_error . "\n"; exit; }
    if ($gardena->sendCommand($serviceId, $type, $cmd, $seconds)) {
        echo 'OK: ' . $cmd . ' an ' . $serviceId . " gesendet.\n";
    } else {
        echo 'FEHLER: ' . $gardena->last_error . "\n";
    }
    exit;
}

// ---------- Hilfe ----------
echo "GARDENA smart system Plugin - Endpunkte:\n\n";
echo "Alle Endpunkte verlangen das Token aus der Plugin-Oberflaeche:\n";
echo "  ?action=list&token=...      Geraeteliste als JSON\n";
echo "  ?action=refresh&token=...   Daten sofort abrufen und an Miniserver/MQTT senden\n";
echo "  ?action=command&token=...&device=NAME&type=MOWER_CONTROL&cmd=PARK_UNTIL_NEXT_TASK\n";
echo "  ?action=command&token=...&device=NAME&type=MOWER_CONTROL&cmd=START_SECONDS_TO_OVERRIDE&seconds=3600\n";
echo "  ?action=command&token=...&device=NAME&type=VALVE_CONTROL&cmd=START_SECONDS_TO_OVERRIDE&seconds=1800\n";
