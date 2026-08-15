#!/usr/bin/php
<?php
/**
 * GardenaMain - wird alle 5 Minuten per Cron aufgerufen.
 * Holt alle Geraetedaten von der GARDENA smart system API v2 und sendet sie
 * per UDP an den Miniserver und/oder per MQTT (LoxBerry MQTT Gateway).
 *
 * Liegt seit 1.1.0 in bin/. Bis 1.0.2 lag diese Datei unter
 * webfrontend/html/ und war damit von jedem Geraet im Netz ohne Anmeldung
 * aufrufbar. Jeder Aufruf loeste einen vollstaendigen API-Durchlauf aus
 * (die Husqvarna-API hat ein Abrufkontingent) und schickte anschliessend
 * den gesamten Datenbestand als UDP-Schwall an den Miniserver. In bin/ ist
 * die Datei fuer den Apache nicht erreichbar.
 */

require_once __DIR__ . '/header.inc.php';

$log = LBLog::newLog(array('name' => 'GardenaLog', 'package' => $lbpplugindir, 'logdir' => $lbplogdir));
LOGSTART('GardenaMain gestartet');

/*
 * Nur ein Durchlauf gleichzeitig.
 *
 * Angestossen wird dieses Skript vom Cron alle fuenf Minuten UND von
 * ?action=refresh im Endpunkt. Ein Durchlauf dauert bei einem groesseren
 * Garten leicht eine halbe Minute: Netzanfragen plus 100 ms Pause je
 * UDP-Wert. Ohne Sperre laufen zwei Durchlaeufe uebereinander, verdoppeln
 * die Abrufe gegen das Kontingent der Husqvarna-API und schicken dem
 * Miniserver alles doppelt - im ungueltigsten Fall in verschraenkter
 * Reihenfolge, so dass ein alter Wert nach einem neuen ankommt.
 */
$sperre = gardena_sperre('main');
if ($sperre === false) {
    LOGINF('Ein Abruf laeuft bereits - dieser Durchlauf entfaellt.');
    LOGEND('Ende');
    exit(0);
}

// gardena_ini_lesen() statt parse_ini_file(): die gardena.cfg kommentiert mit
// '#', das kennt PHPs INI-Zerleger nicht mehr - er gaebe false zurueck, und
// dieser Dienst braeche gleich darunter mit exit(1) ab. Begruendung samt
// Messung steht bei der Funktion in functions.inc.php.
$gcfg = gardena_ini_lesen($lbpconfigdir . '/gardena.cfg');
if (!is_array($gcfg) || empty($gcfg['GARDENA'])) {
    LOGCRIT('Konfigurationsdatei nicht lesbar: ' . $lbpconfigdir . '/gardena.cfg');
    LOGEND('Abbruch'); exit(1);
}
$g = $gcfg['GARDENA'];

if (empty($g['ENABLED']) || $g['ENABLED'] == '0') {
    LOGINF('Plugin ist deaktiviert (ENABLED=0).');
    LOGEND('Ende'); exit(0);
}
if (empty($g['CLIENT_ID']) || empty($g['CLIENT_SECRET'])) {
    LOGCRIT('Application Key / Secret fehlt - bitte in der Plugin-Oberflaeche eintragen (developer.husqvarnagroup.cloud).');
    LOGEND('Abbruch'); exit(1);
}

$udp_enabled = !isset($g['UDP_ENABLED']) || $g['UDP_ENABLED'] != '0';
$mqtt_enabled = isset($g['MQTT_ENABLED']) && $g['MQTT_ENABLED'] == '1';
$mqtt_topic = !empty($g['MQTT_TOPIC']) ? rtrim($g['MQTT_TOPIC'], '/') : 'gardena';

// Miniserver fuer UDP ermitteln
$miniserverIP = '';
$udpport = !empty($g['UDPPORT']) ? (int) $g['UDPPORT'] : 5005;
if ($udp_enabled) {
    $msArray = LBSystem::get_miniservers();
    $msID = !empty($g['MINISERVER']) ? (int) $g['MINISERVER'] : 1;
    if (is_array($msArray) && isset($msArray[$msID])) {
        $miniserverIP = $msArray[$msID]['IPAddress'];
        LOGINF('UDP-Ziel: Miniserver ' . $msID . ' (' . $miniserverIP . ':' . $udpport . ')');
    } else {
        LOGERR('Konfigurierter Miniserver ' . $msID . ' nicht gefunden - UDP-Versand deaktiviert.');
        $udp_enabled = false;
    }
}
if ($mqtt_enabled) { LOGINF('MQTT aktiv, Basis-Topic: ' . $mqtt_topic); }
if (!$udp_enabled && !$mqtt_enabled) {
    LOGERR('Weder UDP noch MQTT aktiv - nichts zu tun.');
    LOGEND('Ende'); exit(0);
}

// API v2
$gardena = new gardena($g['CLIENT_ID'], $g['CLIENT_SECRET'], $lbpconfigdir);
if (!$gardena->authenticate()) {
    LOGCRIT('Anmeldung an der Husqvarna/GARDENA-API fehlgeschlagen: ' . $gardena->last_error);
    LOGEND('Abbruch'); exit(1);
}

$locations = $gardena->getLocations();
if (empty($locations)) {
    LOGCRIT('Keine Locations gefunden: ' . $gardena->last_error);
    LOGEND('Abbruch'); exit(1);
}

$statuscache = array('updated' => date('d.m.Y H:i:s'), 'locations' => array());
$sent = 0;

foreach ($locations as $location) {
    if (!is_array($location) || empty($location['id'])) {
        LOGERR('Location ohne id in der Antwort - uebersprungen.');
        continue;
    }
    $locId = $location['id'];
    $locName = isset($location['attributes']['name']) ? $location['attributes']['name'] : $locId;
    LOGINF('Location: ' . $locName);

    $devices = $gardena->getDevices($locId);
    if (empty($devices)) {
        LOGERR('Keine Geraete in Location ' . $locName . ': ' . $gardena->last_error);
        continue;
    }
    $statuscache['locations'][] = array('id' => $locId, 'name' => $locName, 'devices' => $devices);

    foreach ($devices as $deviceId => $device) {
        $devName = isset($device['name']) && $device['name'] !== '' ? $device['name'] : $deviceId;
        if (!isset($device['services']) || !is_array($device['services'])) { continue; }
        foreach ($device['services'] as $type => $attrs) {
            foreach ($attrs as $attrName => $attr) {
                if ($attrName === '_service_id') { continue; }
                if (!is_array($attr) || !array_key_exists('value', $attr)) { continue; }
                $value = $attr['value'];
                if (is_bool($value)) { $value = $value ? 1 : 0; }
                if (is_array($value)) { $value = json_encode($value); }

                // UDP-Format (kompatibel zum alten Plugin):
                // DeviceTyp.DeviceName.Service.Attribut:Wert
                if ($udp_enabled) {
                    // Umbrueche raus: Loxone wertet den UDP-Eingang
                    // zeilenweise aus. Der Geraetename kommt aus der
                    // Gardena-App, der Wert aus einer fremden Wolke - beides
                    // ist ungeprueft.
                    $dataToSend = gardena_mqtt_nutzlast(
                        $type . '.' . $devName . '.' . $attrName . ':' . $value);
                    LOGDEB('UDP: ' . $dataToSend);
                    sendUDP($dataToSend, $miniserverIP, $udpport);
                    usleep(100000); // Miniserver nicht fluten
                }
                // MQTT: gardena/<DeviceName>/<SERVICE>/<attribut>
                if ($mqtt_enabled) {
                    mqttPublish($mqtt_topic . '/' . $devName . '/' . $type . '/' . $attrName, $value, true);
                }
                $sent++;
            }
        }
    }
}

// Geraete-Zwischenspeicher fuer die Admin-Oberflaeche.
//
// Unteilbar geschrieben und mit 0640: darin stehen die Klarnamen aller
// Geraete, die Service-Kennungen und die Ladezustaende. Bis 1.0.2 wurde er
// mit den Vorgaberechten angelegt und die Oberflaeche konnte ihn halb
// geschrieben lesen, waehrend der Cron ihn ersetzte.
if (!gardena_json_write($lbpconfigdir . '/devices_cache.json', $statuscache, 0640)) {
    LOGERR('Geraete-Zwischenspeicher konnte nicht geschrieben werden.');
}

LOGOK($sent . ' Werte versendet.');
LOGEND('GardenaMain fertig');
flock($sperre, LOCK_UN);
fclose($sperre);
