#!/usr/bin/php
<?php
/**
 * GardenaMain - wird alle 5 Minuten per Cron aufgerufen.
 * Holt alle Geraetedaten von der GARDENA smart system API v2 und sendet sie
 * per UDP an den Miniserver und/oder per MQTT (LoxBerry MQTT Gateway).
 */

include 'header.inc.php';

$log = LBLog::newLog(array('name' => 'GardenaLog', 'package' => $lbpplugindir, 'logdir' => $lbplogdir));
LOGSTART('GardenaMain gestartet');

$gcfg = @parse_ini_file($lbpconfigdir . '/gardena.cfg', true, INI_SCANNER_RAW);
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
        $devName = $device['name'];
        foreach ($device['services'] as $type => $attrs) {
            foreach ($attrs as $attrName => $attr) {
                if ($attrName === '_service_id') { continue; }
                $value = $attr['value'];
                if (is_bool($value)) { $value = $value ? 1 : 0; }
                if (is_array($value)) { $value = json_encode($value); }

                // UDP-Format (kompatibel zum alten Plugin):
                // DeviceTyp.DeviceName.Service.Attribut:Wert
                if ($udp_enabled) {
                    $dataToSend = $type . '.' . $devName . '.' . $attrName . ':' . $value;
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

// Geraete-Cache fuer die Admin-Oberflaeche
@file_put_contents($lbpconfigdir . '/devices_cache.json', json_encode($statuscache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

LOGOK($sent . ' Werte versendet.');
LOGEND('GardenaMain fertig');
