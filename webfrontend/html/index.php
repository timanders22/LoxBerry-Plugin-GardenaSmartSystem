<?php
/**
 * Kommando-/Status-Endpunkt fuer den Loxone Miniserver (Virtueller Ausgang).
 * Neu geschrieben fuer die GARDENA smart system API v2.
 *
 * Aufrufe (HTTP GET):
 *   ?action=list                     -> Geraete/Services als JSON (aus dem Cache), rein lesend
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

include 'header.inc.php';

header('Content-Type: text/plain; charset=utf-8');

$gcfg = @parse_ini_file($lbpconfigdir . '/gardena.cfg', true, INI_SCANNER_RAW);
$g = (is_array($gcfg) && !empty($gcfg['GARDENA'])) ? $gcfg['GARDENA'] : array();

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ---------- Zugriffsschutz fuer alles, was etwas ausloest ----------
// ?action=list bleibt lesend und ohne Token erreichbar (Diagnose im Browser).
if ($action === 'command' || $action === 'refresh') {
    $given = isset($_GET['token']) ? $_GET['token'] : '';
    if (!gardena_token_ok(isset($g['TOKEN']) ? $g['TOKEN'] : '', $given)) {
        header('HTTP/1.1 403 Forbidden');
        if (empty($g['TOKEN'])) {
            echo "FEHLER: Es ist noch kein Token hinterlegt. Bitte einmal die Plugin-Oberflaeche\n"
               . "oeffnen - dort wird eines erzeugt und die fertige Loxone-URL angezeigt.\n";
        } else {
            echo "FEHLER: Ungueltiges oder fehlendes Token.\n"
               . "Aufruf: ?action=" . ($action === 'command' ? 'command' : 'refresh')
               . "&token=... (Token steht in der Plugin-Oberflaeche)\n";
        }
        exit;
    }
}

if (empty($g['CLIENT_ID']) || empty($g['CLIENT_SECRET'])) {
    echo "FEHLER: Application Key/Secret nicht konfiguriert (Plugin-Oberflaeche oeffnen).\n";
    exit;
}

// ---------- Geraeteliste aus Cache ----------
if ($action === 'list') {
    $cache = @file_get_contents($lbpconfigdir . '/devices_cache.json');
    header('Content-Type: application/json; charset=utf-8');
    echo ($cache !== false) ? $cache : '{"error":"Noch kein Cache - bitte einmal ?action=refresh aufrufen."}';
    exit;
}

// ---------- Sofort-Abruf ----------
if ($action === 'refresh') {
    // gardenaMain im Hintergrund starten (schreibt Log + Cache, sendet UDP/MQTT)
    $php = PHP_BINARY !== '' ? PHP_BINARY : '/usr/bin/php';
    shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/gardenaMain.php') . ' > /dev/null 2>&1 &');
    echo "OK: Abruf gestartet (Ergebnis im Log und unter ?action=list).\n";
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
    if (is_array($cache) && !empty($cache['locations'])) {
        foreach ($cache['locations'] as $loc) {
            foreach ($loc['devices'] as $devId => $dev) {
                if ($devQuery !== '' && strcasecmp($dev['name'], $devQuery) !== 0 && strcasecmp($devId, $devQuery) !== 0) { continue; }
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
echo "Ohne Token (nur lesend):\n";
echo "  ?action=list      Geraeteliste als JSON\n\n";
echo "Mit Token (loest etwas aus - Token steht in der Plugin-Oberflaeche):\n";
echo "  ?action=refresh&token=...   Daten sofort abrufen und an Miniserver/MQTT senden\n";
echo "  ?action=command&token=...&device=NAME&type=MOWER_CONTROL&cmd=PARK_UNTIL_NEXT_TASK\n";
echo "  ?action=command&token=...&device=NAME&type=MOWER_CONTROL&cmd=START_SECONDS_TO_OVERRIDE&seconds=3600\n";
echo "  ?action=command&token=...&device=NAME&type=VALVE_CONTROL&cmd=START_SECONDS_TO_OVERRIDE&seconds=1800\n";
