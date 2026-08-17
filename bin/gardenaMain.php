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
    // Kein Lebenszeichen: wohin es gehen soll, steht in genau der Datei, die
    // sich nicht lesen laesst. Der Zustand wird trotzdem festgehalten, damit
    // die Selbstpruefung in der Oberflaeche den Grund nennen kann.
    gardena_status_schreiben($lbpconfigdir, array(
        'ok' => 0, 'letzter_lauf' => time(), 'werte' => 0, 'verloren' => 0,
        'fehler' => 'Konfigurationsdatei nicht lesbar.'));
    LOGEND('Abbruch'); exit(1);
}

/*
 * Die Werte kommen seit 1.2.0 ueber gardena_cfg_read(), also MIT den
 * Vorgaben aus gardena_vorgaben() - genau wie in der Oberflaeche.
 *
 * Bis 1.1.9 las dieser Dienst den Abschnitt roh und pruefte selbst:
 * ein fehlendes MQTT_ENABLED bedeutete hier AUS, in der Oberflaeche AN. Wer
 * eine gardena.cfg ohne diesen Schluessel hatte, sah "Aktiv (empfohlen)" und
 * bekam nichts. Die Vorgaben stehen jetzt an einer Stelle; hier wird nur noch
 * gelesen, nicht mehr entschieden.
 */
$g = gardena_cfg_read($lbpconfigdir . '/gardena.cfg');

if (empty($g['ENABLED']) || $g['ENABLED'] == '0') {
    LOGINF('Plugin ist deaktiviert (ENABLED=0).');
    LOGEND('Ende'); exit(0);
}

$mqtt_topic = !empty($g['MQTT_TOPIC']) ? rtrim($g['MQTT_TOPIC'], '/') : 'gardena';
$udp_enabled = $g['UDP_ENABLED'] != '0';
$mqtt_enabled = $g['MQTT_ENABLED'] == '1';

/*
 * Ohne die Erweiterung sockets geht WEDER UDP NOCH MQTT - das Gateway wird
 * ebenfalls ueber UDP beschickt. Bis 1.1.9 fiel das nicht auf: sendUDP()
 * hatte nur ein @ vor socket_create(), und eine "Call to undefined function"
 * laesst sich damit nicht unterdruecken. Der Lauf starb beim ersten Wert,
 * mitten in der Schleife, ohne LOGEND und ohne Geraete-Zwischenspeicher.
 * Jetzt wird es vorher festgestellt und gesagt.
 */
if (!gardena_udp_moeglich()) {
    LOGCRIT('Die PHP-Erweiterung sockets fehlt - es laesst sich weder ueber UDP noch '
        . 'ueber MQTT senden. Nachinstallieren: sudo apt-get install -y php-sockets');
    gardena_status_schreiben($lbpconfigdir, array(
        'ok' => 0, 'letzter_lauf' => time(), 'werte' => 0, 'verloren' => 0,
        'fehler' => 'PHP-Erweiterung sockets fehlt - es kann nichts gesendet werden.'));
    LOGEND('Abbruch'); exit(1);
}


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

/*
 * Der gemeinsame Ausgang fuer jeden Abbruch.
 *
 * Steht hier und nicht weiter oben, weil er die Sendewege braucht. Er haelt
 * den Grund fest UND schickt das Lebenszeichen - genau dafuer ist es da:
 * Bis 1.1.9 endete ein gescheiterter Lauf mit exit(1) und schickte gar
 * nichts, und die virtuellen Eingaenge in Loxone behielten ihren letzten
 * Wert. In der App sah das aus wie Normalbetrieb.
 */
$gudp_ziel = $udp_enabled ? $miniserverIP : '';
function gardena_abbruch($grund)
{
    global $lbpconfigdir, $mqtt_topic, $gudp_ziel, $udpport, $mqtt_enabled, $sperre;
    gardena_status_schreiben($lbpconfigdir, array(
        'ok' => 0, 'letzter_lauf' => time(), 'fehler' => $grund));
    $st = gardena_status_lesen($lbpconfigdir);
    list($v, $f) = gardena_lebenszeichen($mqtt_topic, $st, $gudp_ziel, $udpport, $mqtt_enabled);
    if ($f > 0) {
        LOGERR('Auch das Lebenszeichen kam nicht durch (' . $f . ' von ' . $v . ' Zustellungen).');
    } else {
        LOGINF('Lebenszeichen mit ok=0 gesendet: ' . $grund);
    }
    LOGEND('Abbruch');
    if (is_resource($sperre)) { flock($sperre, LOCK_UN); fclose($sperre); }
    exit(1);
}

/*
 * Takt und Ruecknahme - beides VOR dem ersten Netzabruf.
 *
 * Der Cron laeuft alle fuenf Minuten. Wer einen groesseren Abstand einstellt,
 * bekommt ihn, indem Laeufe uebersprungen werden; ein kleinerer ist ueber den
 * Cron nicht erreichbar. Und wenn die Gegenstelle mit HTTP 429 geantwortet
 * hat, wird die Sperre ABGEWARTET: wer im gleichen Takt weiter anklopft,
 * verlaengert sie.
 *
 * Ein uebersprungener Lauf ist kein Fehler. Er ruehrt den Zustand nicht an
 * und sendet kein Lebenszeichen mit ok=0 - sonst saehe ein gestreckter Takt
 * in Loxone aus wie ein Ausfall.
 */
$gstand = gardena_status_lesen($lbpconfigdir);
$gjetzt = time();
if (!empty($gstand['sperre_bis']) && $gjetzt < (int) $gstand['sperre_bis']) {
    LOGINF('Das Abrufkontingent war erschoepft (HTTP 429). Naechster Versuch fruehestens '
        . date('H:i', (int) $gstand['sperre_bis']) . ' - dieser Durchlauf entfaellt.');
    LOGEND('Ende');
    flock($sperre, LOCK_UN); fclose($sperre);
    exit(0);
}
$gtakt = gardena_intervall($g);
if ($gtakt > 5 && !empty($gstand['letzter_lauf'])) {
    // 30 Sekunden Nachsicht: der Cron startet nicht auf die Sekunde genau,
    // und ohne sie wuerde bei einem eingestellten Takt von 10 Minuten jeder
    // zweite Lauf knapp verfehlt und erst nach 15 Minuten ausgefuehrt.
    $gfaellig = (int) $gstand['letzter_lauf'] + $gtakt * 60 - 30;
    if ($gjetzt < $gfaellig) {
        LOGINF('Eingestellter Abstand ' . $gtakt . ' Minuten - der naechste Abruf ist um '
            . date('H:i', $gfaellig) . ' faellig, dieser Durchlauf entfaellt.');
        LOGEND('Ende');
        flock($sperre, LOCK_UN); fclose($sperre);
        exit(0);
    }
}

/**
 * Nach HTTP 429: die Ruecknahme festhalten.
 *
 * Retry-After wird genommen, wenn die Gegenstelle sie mitschickt. Fehlt sie,
 * wird eine Stunde gewartet - eine Zahl, die NICHT gemessen ist und deshalb
 * bewusst grob gewaehlt ist: lieber eine Stunde zu lange warten als die
 * Sperre zu verlaengern.
 */
function gardena_kontingent($api)
{
    global $lbpconfigdir;
    $warte = ($api->retry_after > 0) ? (int) $api->retry_after : 3600;
    if ($warte > 86400) { $warte = 86400; }
    gardena_status_schreiben($lbpconfigdir, array('sperre_bis' => time() + $warte));
    LOGCRIT('Das Abrufkontingent der Husqvarna-API ist erschoepft (HTTP 429). '
        . 'Bis ' . date('H:i', time() + $warte) . ' wird nicht mehr abgerufen. '
        . 'Der Abstand laesst sich in der Plugin-Oberflaeche strecken.');
}

if (empty($g['CLIENT_ID']) || empty($g['CLIENT_SECRET'])) {
    LOGCRIT('Application Key / Secret fehlt - bitte in der Plugin-Oberflaeche eintragen (developer.husqvarnagroup.cloud).');
    gardena_abbruch('Application Key / Secret fehlt - in der Plugin-Oberflaeche eintragen.');
}

// API v2
$gardena = new gardena($g['CLIENT_ID'], $g['CLIENT_SECRET'], $lbpconfigdir);
if (!$gardena->authenticate()) {
    LOGCRIT('Anmeldung an der Husqvarna/GARDENA-API fehlgeschlagen: ' . $gardena->last_error);
    gardena_abbruch('Anmeldung fehlgeschlagen: ' . $gardena->last_error);
}

/*
 * Die Standortliste aendert sich praktisch nie - sie jedes Mal zu holen
 * kostet die Haelfte aller Abrufe gegen das Kontingent von Husqvarna.
 * Deshalb wird sie einen Tag lang wiederverwendet. Schlaegt der Abruf der
 * Geraete danach fehl, wird sie beim naechsten Lauf neu geholt (siehe
 * 'locations_stand' unten).
 */
$locations = array();
$gloc_alter = $gjetzt - (int) $gstand['locations_stand'];
if (!empty($gstand['locations']) && is_array($gstand['locations']) && $gloc_alter < 86400) {
    foreach ($gstand['locations'] as $gl) {
        if (is_array($gl) && !empty($gl['id'])) {
            $locations[] = array('id' => $gl['id'],
                'attributes' => array('name' => isset($gl['name']) ? $gl['name'] : $gl['id']));
        }
    }
    LOGDEB(count($locations) . ' Standorte aus dem Zwischenspeicher (' . (int) ($gloc_alter / 60) . ' Minuten alt).');
}
$gloc_frisch = false;
if (!$locations) {
    $locations = $gardena->getLocations();
    $gloc_frisch = true;
    if (empty($locations)) {
        if ($gardena->last_http === 429) { gardena_kontingent($gardena); }
        LOGCRIT('Keine Locations gefunden: ' . $gardena->last_error);
        gardena_abbruch('Keine Location gefunden: ' . $gardena->last_error);
    }
}

$statuscache = array('updated' => date('d.m.Y H:i:s'), 'locations' => array());
$sent = 0;          // zugestellt
$versucht = 0;      // versucht
$verloren = 0;      // gescheitert
$ohne_inhalt = 0;   // von der Wolke ohne Wert geliefert, deshalb nicht gesendet
$ausgelassen = 0;   // Geraete, die der Anwender ausgenommen hat

/*
 * Erst SAMMELN, dann entscheiden, dann senden.
 *
 * Bis 1.2.0 ging jeder Wert sofort hinaus. Damit liess sich weder
 * feststellen, ob sich ueberhaupt etwas geaendert hat, noch, welche Themen
 * seit dem letzten Lauf weggefallen sind. Beides braucht den vollstaendigen
 * Bestand, bevor das erste Paket den Rechner verlaesst.
 */
$gausgenommen = gardena_ausgenommen($g);
$gwerte = array();   // "Geraet|DIENST|attribut" => Wert

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
        if ($gardena->last_http === 429) {
            gardena_kontingent($gardena);
            gardena_abbruch('Abrufkontingent erschoepft (HTTP 429).');
        }
        LOGERR('Keine Geraete in Location ' . $locName . ': ' . $gardena->last_error);
        continue;
    }
    $statuscache['locations'][] = array('id' => $locId, 'name' => $locName, 'devices' => $devices);

    foreach ($devices as $deviceId => $device) {
        $devName = isset($device['name']) && $device['name'] !== '' ? $device['name'] : $deviceId;
        if (!isset($device['services']) || !is_array($device['services'])) { continue; }
        /* Ausgenommene Geraete stehen weiterhin im Abbild - die Oberflaeche
         * soll sie zeigen koennen -, aber es geht nichts von ihnen hinaus. */
        if (in_array($devName, $gausgenommen, true) || in_array((string) $deviceId, $gausgenommen, true)) {
            $ausgelassen++;
            LOGDEB('Ausgenommen, nichts gesendet: ' . $devName);
            continue;
        }
        foreach ($device['services'] as $type => $attrs) {
            foreach ($attrs as $attrName => $attr) {
                if ($attrName === '_service_id') { continue; }
                if (!is_array($attr) || !array_key_exists('value', $attr)) { continue; }

                /*
                 * Ein Wert, den es nicht gibt, wird nicht gesendet.
                 *
                 * Bis 1.2.0 wurde ein 'value' von null zur leeren
                 * Zeichenkette, und die UDP-Zeile endete auf den Doppelpunkt
                 * - in Loxone nicht von einer gemessenen 0 zu unterscheiden.
                 * Der Eingang behaelt jetzt seinen letzten Wert; dass er alt
                 * ist, beantwortet das Lebenszeichen.
                 */
                if (gardena_wert_fehlt($attr['value'])) {
                    $ohne_inhalt++;
                    LOGDEB('Ohne Inhalt, nicht gesendet: ' . $type . '.' . $devName . '.' . $attrName);
                    continue;
                }

                $gwerte[$devName . '|' . $type . '|' . $attrName] = gardena_wert_flach($attr['value']);
            }
        }
    }
}

/*
 * Senden - aber nur, wenn es etwas zu sagen gibt.
 *
 * Bis 1.2.0 ging bei JEDEM Lauf JEDER Wert hinaus, unveraendert oder nicht,
 * mit 100 ms Pause je UDP-Wert. Bei einem groesseren Garten war der Lauf
 * damit minutenlang mit Warten beschaeftigt, und der Miniserver bekam alle
 * fuenf Minuten dieselben Zahlen erneut.
 *
 * Jetzt entscheidet eine Signatur ueber den gesamten Bestand. Aendert sich
 * nichts, wird nichts gesendet - hoechstens alle 30 Minuten einmal als
 * Lebenszeichen. Der Wert im Miniserver bleibt derselbe: MQTT ist retained,
 * und ein virtueller Eingang behaelt ohnehin seinen letzten Wert. Dass die
 * Verbindung steht, sagt STATUS.Plugin.zeitstempel, nicht die Wiederholung.
 */
$gsignatur = gardena_signatur($gwerte);
$galter_meldung = $gjetzt - (int) $gstand['letzte_volle_meldung'];
$gunveraendert = ($gsignatur === (string) $gstand['signatur'] && $gstand['letzte_volle_meldung'] > 0);
$gvoll = (!$gunveraendert || $galter_meldung >= 1800);

if ($gvoll) {
    foreach ($gwerte as $gschluessel => $gwert) {
        list($gdev, $gtyp, $gattr) = explode('|', $gschluessel, 3);
        list($v, $f) = gardena_wert_senden($mqtt_topic, $gdev, $gtyp, $gattr, $gwert,
                                           $gudp_ziel, $udpport, $mqtt_enabled);
        $versucht += $v;
        $verloren += $f;
        if ($f === 0) { $sent++; }
        if ($udp_enabled) { usleep(100000); } // Miniserver nicht fluten
    }
    LOGINF($gunveraendert
        ? 'Unveraendert, aber seit ' . (int) ($galter_meldung / 60) . ' Minuten nichts gesendet - Lebenszeichen mit allen Werten.'
        : count($gwerte) . ' Werte, davon mindestens einer geaendert - es wird gesendet.');
} else {
    LOGINF('Nichts geaendert seit dem letzten Lauf - es wird nichts gesendet '
        . '(naechste vollstaendige Meldung spaetestens in '
        . (int) ((1800 - $galter_meldung) / 60) . ' Minuten).');
}

/*
 * Weggefallene Themen aufraeumen.
 *
 * Wird ein Geraet umbenannt oder entfernt, bleibt sein altes Thema mit dem
 * letzten Wert dauerhaft im Broker stehen - retained heisst genau das. Ein
 * leerer retained-Wert loescht den Eintrag. Ueber UDP gibt es nichts
 * aufzuraeumen: dort merkt sich niemand etwas.
 */
$gthemen = array_keys($gwerte);
$galt = (isset($gstand['themen']) && is_array($gstand['themen'])) ? $gstand['themen'] : array();
$gweg = array_diff($galt, $gthemen);
if ($gweg && $mqtt_enabled) {
    foreach ($gweg as $gschluessel) {
        $gteile = explode('|', (string) $gschluessel, 3);
        if (count($gteile) !== 3) { continue; }
        mqttPublish(gardena_wert_thema($mqtt_topic, $gteile[0], $gteile[1], $gteile[2]), '', true);
    }
    LOGINF(count($gweg) . ' weggefallene MQTT-Themen geleert (Geraet umbenannt oder entfernt).');
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

/*
 * Zustand fortschreiben und das Lebenszeichen senden.
 *
 * 'letzter_erfolg' wird NUR bei einem vollstaendigen Lauf gesetzt - er
 * ueberlebt damit jeden gescheiterten und beantwortet in der Oberflaeche und
 * in Loxone die Frage, wie lange schon nichts mehr angekommen ist.
 */
$vollstaendig = ($verloren === 0 && count($gwerte) > 0);
$gneuer_stand = array(
    'ok' => $vollstaendig ? 1 : 0,
    'letzter_lauf' => time(),
    'werte' => $gvoll ? $sent : (int) $gstand['werte'],
    'verloren' => $verloren,
    'ohne_inhalt' => $ohne_inhalt,
    'signatur' => $gsignatur,
    'themen' => $gthemen,
    // Nach einem geglueckten Lauf ist die Ruecknahme aufgehoben.
    'sperre_bis' => 0,
    'fehler' => $vollstaendig ? '' :
        (count($gwerte) === 0 ? 'Kein einziger Wert zu senden - Antwort der Wolke leer?'
                              : ($verloren . ' von ' . $versucht . ' Zustellungen gescheitert.')),
);
if ($vollstaendig) { $gneuer_stand['letzter_erfolg'] = time(); }
if ($gvoll && $vollstaendig) { $gneuer_stand['letzte_volle_meldung'] = time(); }
// Die Standortliste nur dann als frisch vermerken, wenn sie in DIESEM Lauf
// geholt wurde UND Geraete dabei herauskamen. Sonst wird sie beim naechsten
// Lauf neu geholt, statt einen falschen Stand ueber Tage festzuschreiben.
if ($gloc_frisch && $vollstaendig) {
    $gliste = array();
    foreach ($statuscache['locations'] as $gl) {
        $gliste[] = array('id' => $gl['id'], 'name' => $gl['name']);
    }
    $gneuer_stand['locations'] = $gliste;
    $gneuer_stand['locations_stand'] = time();
}
gardena_status_schreiben($lbpconfigdir, $gneuer_stand);

list($glz_v, $glz_f) = gardena_lebenszeichen($mqtt_topic, gardena_status_lesen($lbpconfigdir),
                                             $gudp_ziel, $udpport, $mqtt_enabled);
if ($glz_f > 0) {
    LOGERR('Lebenszeichen: ' . $glz_f . ' von ' . $glz_v . ' Zustellungen gescheitert.');
}

/*
 * Die Schlussmeldung sagt, was WIRKLICH angekommen ist.
 *
 * Bis 1.1.9 stand hier ausnahmslos LOGOK('<n> Werte versendet.') - auch
 * dann, wenn das MQTT-Gateway gar nicht eingerichtet war und mqttPublish()
 * bei jedem Wert sofort ausstieg. Eine Zusammenfassung darf nicht besser
 * aussehen als ihr schlechtester Punkt.
 */
$gohne = $ohne_inhalt > 0
    ? ' ' . $ohne_inhalt . ' Attribute kamen ohne Wert und wurden nicht gesendet.' : '';
$gaus = $ausgelassen > 0 ? ' ' . $ausgelassen . ' Geraete sind ausgenommen.' : '';
if ($vollstaendig && !$gvoll) {
    LOGOK(count($gwerte) . ' Werte gelesen, keiner geaendert - nichts gesendet.' . $gohne . $gaus);
} elseif ($vollstaendig) {
    LOGOK($sent . ' Werte zugestellt (' . $versucht . ' Zustellungen, keine gescheitert).' . $gohne . $gaus);
} elseif (count($gwerte) === 0) {
    LOGERR('Kein einziger Wert zu senden - die Antwort der Wolke enthielt nichts Verwertbares.' . $gaus);
} else {
    LOGERR($verloren . ' von ' . $versucht . ' Zustellungen gescheitert; '
        . $sent . ' Werte vollstaendig zugestellt. Ursache steht in den Zeilen darueber.');
}
LOGEND('GardenaMain fertig');
flock($sperre, LOCK_UN);
fclose($sperre);
