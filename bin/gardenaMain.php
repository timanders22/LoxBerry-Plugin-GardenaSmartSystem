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

/*
 * Nur installiert - oder wenn der Aufrufer Wurzel UND Ordner ausdruecklich
 * nennt (gardena_lage()). Aus einem ausgepackten Archiv heraus erkennt das
 * SDK keinen Pluginordner; bis 1.2.9 lief dieser Dienst trotzdem und schrieb
 * mit den Pfaden der Anlage (in WSL gemessen,
 * Pruefung-GardenaSmartSystem-1.2.10, Fall L1).
 */
if (gardena_lage() === '') {
    fwrite(STDERR, 'gardenaMain.php: nicht installiert - dieses Skript liegt nicht unter '
        . '<LoxBerry-Wurzel>/bin/plugins/<ordner>, und LBHOMEDIR und LBPPLUGINDIR sind nicht '
        . 'beide gesetzt. Es wurde nichts abgerufen, nichts gesendet und nichts geschrieben.' . "\n");
    exit(1);
}

/*
 * uninstall/uninstall leert hierueber die zurueckbehaltenen MQTT-Themen der
 * Linie (gardena_mqtt_leeren()) - ohne Abruf und ohne Zustand.
 *
 * Vorher wird bis 30 s auf die Sperre des Abrufs gewartet und sie waehrend
 * des Leerens gehalten. Den Cron-Eintrag entfernt der Installer schon VOR
 * dem Deinstallationsskript (plugininstall.pl, purge_installation Schritt 1
 * vor Schritt 3; Geraet/2026-09-05/08_plugininstall.pl:1535-1577) - ein Lauf,
 * der vorher begann, sendet aber noch und stellte die geleerten Zustaende
 * wieder in den Broker (in WSL gemessen, Pruefung-GardenaSmartSystem-1.2.10,
 * Fall S1). Wird die Sperre nicht frei, wird trotzdem geleert und es gesagt.
 */
if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === '--mqtt-leeren') {
    $gl_bis = microtime(true) + 30;
    while (($gl_sperre = gardena_sperre('main')) === false && microtime(true) < $gl_bis) {
        usleep(500000);
    }
    if ($gl_sperre === false) {
        echo '<WARNING> MQTT: ein Abruf lief nach 30 s noch - es wird trotzdem geleert; was er '
           . 'danach sendet, bleibt stehen (von Hand: mosquitto_pub -r -n -t <thema>).' . "\n";
    }
    exit(gardena_mqtt_leeren($lbpconfigdir));
}

// Protokoll seit 1.2.8 ueber gardena_log() in EINE Datei (gardena.log) - Begruendung
// bei der Funktion in functions.inc.php.

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
    gardena_log('INF', 'Ein Abruf laeuft bereits - dieser Durchlauf entfaellt.');
    exit(0);
}

// gardena_ini_lesen() statt parse_ini_file(): die gardena.cfg kommentiert mit
// '#', das kennt PHPs INI-Zerleger nicht mehr - er gaebe false zurueck, und
// dieser Dienst braeche gleich darunter mit exit(1) ab. Begruendung samt
// Messung steht bei der Funktion in functions.inc.php.
// Geprueft wird, ob die Datei ueberhaupt lesbar ist - NICHT, ob sie einen
// Abschnitt [GARDENA] hat. gardena_cfg_read() liest eine Datei ohne
// Abschnittskopf ausdruecklich von der obersten Ebene (der Fall entsteht,
// wenn postupgrade.sh bis 1.0.2 im Fehlerfall eine Datei mit nur LOCALTIME=0
// anlegte). Bis 1.2.5 brach der Dienst bei genau der Datei ab, die die
// Oberflaeche anstandslos las und anzeigte - zwei Wahrheiten ueber dieselbe
// Datei, und im Protokoll stand "nicht lesbar".
$gcfg = gardena_ini_lesen($lbpconfigdir . '/gardena.cfg');
if (!is_array($gcfg)) {
    gardena_log('CRIT', 'Konfigurationsdatei nicht lesbar: ' . $lbpconfigdir . '/gardena.cfg');
    // Kein Lebenszeichen: wohin es gehen soll, steht in genau der Datei, die
    // sich nicht lesen laesst. Der Zustand wird trotzdem festgehalten, damit
    // die Selbstpruefung in der Oberflaeche den Grund nennen kann.
    gardena_status_schreiben($lbpconfigdir, array(
        'ok' => 0, 'letzter_lauf' => time(), 'werte' => 0, 'verloren' => 0,
        'fehler' => 'Konfigurationsdatei nicht lesbar.'));
    exit(1);
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
// Fehlende Schluessel EINMAL nachtragen (Hausstandard: vervollstaendigen,
// nicht nur beim Lesen ergaenzen). Beim Dienststart, nicht bei jedem Lauf -
// die Funktion schreibt nur, wenn wirklich etwas fehlt.
gardena_cfg_vervollstaendigen($lbpconfigdir . '/gardena.cfg');

$g = gardena_cfg_read($lbpconfigdir . '/gardena.cfg');

if (empty($g['ENABLED']) || $g['ENABLED'] == '0') {
    // Gebremst: bei ausgeschaltetem Plugin stuende die Zeile sonst 288-mal am Tag da.
    gardena_log_gebremst('aus', 'INF', 'Plugin ist ausgeschaltet (ENABLED=0) - es wird nichts abgerufen und nichts gesendet.');
    exit(0);
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
    gardena_log('CRIT', 'Die PHP-Erweiterung sockets fehlt - es laesst sich weder ueber UDP noch '
        . 'ueber MQTT senden. Nachinstallieren: sudo apt-get install -y php-sockets');
    gardena_status_schreiben($lbpconfigdir, array(
        'ok' => 0, 'letzter_lauf' => time(), 'werte' => 0, 'verloren' => 0,
        'fehler' => 'PHP-Erweiterung sockets fehlt - es kann nichts gesendet werden.'));
    exit(1);
}


// Miniserver fuer UDP ermitteln
$miniserverIP = '';
$udpport = !empty($g['UDPPORT']) ? (int) $g['UDPPORT'] : 5005;
if ($udp_enabled) {
    $msArray = LBSystem::get_miniservers();
    $msID = !empty($g['MINISERVER']) ? (int) $g['MINISERVER'] : 1;
    // Auch der Unterschluessel wird geprueft: ein unvollstaendig
    // eingerichteter Miniserver hat den Eintrag, aber keine Adresse. Bis
    // 1.2.5 blieb $udp_enabled dann wahr, das UDP-Ziel leer, und
    // gardena_wert_senden() uebersprang den UDP-Zweig wortlos - der Lauf
    // meldete "N Werte zugestellt (0 Zustellungen, keine gescheitert)".
    if (is_array($msArray) && isset($msArray[$msID])
        && !empty($msArray[$msID]['IPAddress'])) {
        $miniserverIP = $msArray[$msID]['IPAddress'];
        gardena_log('INF', 'UDP-Ziel: Miniserver ' . $msID . ' (' . $miniserverIP . ':' . $udpport . ')');
    } else {
        gardena_log('ERR', 'Konfigurierter Miniserver ' . $msID . ' nicht gefunden - UDP-Versand deaktiviert.');
        $udp_enabled = false;
    }
}
if ($mqtt_enabled) { gardena_log('INF', 'MQTT aktiv, Basis-Topic: ' . $mqtt_topic); }
if (!$udp_enabled && !$mqtt_enabled) {
    gardena_log('ERR', 'Weder UDP noch MQTT aktiv - nichts zu tun.');
    exit(0);
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
        gardena_log('ERR', 'Auch das Lebenszeichen kam nicht durch (' . $f . ' von ' . $v . ' Zustellungen).');
    } else {
        gardena_log('INF', 'Lebenszeichen mit ok=0 gesendet: ' . $grund);
    }
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
 *
 * Er sendet aber das Lebenszeichen MIT DEM GESPEICHERTEN STAND. Bis 1.2.7
 * ging in diesen beiden Faellen gar nichts hinaus - waehrend einer
 * Abrufsperre bis zu 24 Stunden lang. Oberflaeche und Anleitung versprachen
 * dagegen "bei jedem Lauf ein Lebenszeichen", und Regeln/07 verlangt es:
 * ohne 'ts' ist ein Dienst, der laeuft, aber nicht abrufen darf, von einem
 * toten nicht zu unterscheiden. 'ok' und 'zeitstempel' bleiben, was der
 * letzte echte Lauf ergeben hat.
 */
$gstand = gardena_status_lesen($lbpconfigdir);
$gjetzt = time();
if (!empty($gstand['sperre_bis']) && $gjetzt < (int) $gstand['sperre_bis']) {
    gardena_log_gebremst('sperre', 'INF', 'Das Abrufkontingent war erschoepft (HTTP 429). Naechster Versuch fruehestens '
        . date('H:i', (int) $gstand['sperre_bis']) . ' - bis dahin entfallen die Abrufe, das Lebenszeichen geht weiter hinaus.');
    gardena_lebenszeichen($mqtt_topic, $gstand, $gudp_ziel, $udpport, $mqtt_enabled);
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
        gardena_log_gebremst('takt', 'INF', 'Eingestellter Abstand ' . $gtakt . ' Minuten - '
            . 'Laeufe dazwischen rufen nicht ab und senden nur das Lebenszeichen.');
        gardena_lebenszeichen($mqtt_topic, $gstand, $gudp_ziel, $udpport, $mqtt_enabled);
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
    // Seit 1.2.8 in der Bibliothek: auch der Endpunkt vermerkt ein 429.
    global $lbpconfigdir;
    gardena_kontingent_vermerken($lbpconfigdir, $api->retry_after);
}

if (empty($g['CLIENT_ID']) || empty($g['CLIENT_SECRET'])) {
    gardena_log('CRIT', 'Application Key / Secret fehlt - bitte in der Plugin-Oberflaeche eintragen (developer.husqvarnagroup.cloud).');
    gardena_abbruch('Application Key / Secret fehlt - in der Plugin-Oberflaeche eintragen.');
}

// API v2
$gardena = new gardena($g['CLIENT_ID'], $g['CLIENT_SECRET'], $lbpconfigdir);
if (!$gardena->authenticate()) {
    // Auch die Anmeldung laeuft in HTTP 429. Bis 1.2.5 wurde der Fall nur an
    // den beiden Abrufstellen behandelt; hier klopfte der Cron danach alle
    // fuenf Minuten weiter an - und verlaengerte die Sperre, die er abwarten
    // sollte.
    if ($gardena->last_http === 429) { gardena_kontingent($gardena); }
    gardena_log('CRIT', 'Anmeldung an der Husqvarna/GARDENA-API fehlgeschlagen: ' . $gardena->last_error);
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
    gardena_log('DEB', count($locations) . ' Standorte aus dem Zwischenspeicher (' . (int) ($gloc_alter / 60) . ' Minuten alt).');
}
$gloc_frisch = false;
if (!$locations) {
    $locations = $gardena->getLocations();
    $gloc_frisch = true;
    if (empty($locations)) {
        if ($gardena->last_http === 429) { gardena_kontingent($gardena); }
        gardena_log('CRIT', 'Keine Locations gefunden: ' . $gardena->last_error);
        gardena_abbruch('Keine Location gefunden: ' . $gardena->last_error);
    }
}

$statuscache = array('updated' => date('d.m.Y H:i:s'), 'locations' => array());
$sent = 0;          // zugestellt
$versucht = 0;      // versucht
$verloren = 0;      // gescheitert
$ohne_inhalt = 0;   // von der Wolke ohne Wert geliefert, deshalb nicht gesendet
$ausgelassen = 0;   // Geraete, die der Anwender ausgenommen hat
$standort_fehl = 0; // Standorte, deren Abruf gescheitert ist

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
$gteil = array();    // derselbe Schluessel => array(Geraet, Dienst, Attribut)

foreach ($locations as $location) {
    if (!is_array($location) || empty($location['id'])) {
        gardena_log('ERR', 'Location ohne id in der Antwort - uebersprungen.');
        continue;
    }
    $locId = $location['id'];
    $locName = isset($location['attributes']['name']) ? $location['attributes']['name'] : $locId;
    gardena_log('INF', 'Location: ' . $locName);

    $devices = $gardena->getDevices($locId);
    if (empty($devices)) {
        if ($gardena->last_http === 429) {
            gardena_kontingent($gardena);
            gardena_abbruch('Abrufkontingent erschoepft (HTTP 429).');
        }
        gardena_log('ERR', 'Keine Geraete in Location ' . $locName . ': ' . $gardena->last_error);
        // Der Ausfall MUSS gezaehlt werden. Bis 1.2.5 hob ihn nur diese
        // Protokollzeile hervor; $vollstaendig blieb wahr, sobald ein
        // zweiter Standort Werte lieferte - das Lebenszeichen meldete
        // Normalbetrieb, waehrend ein ganzer Garten seit Stunden schwieg.
        $standort_fehl++;
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
            gardena_log('DEB', 'Ausgenommen, nichts gesendet: ' . $devName);
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
                    gardena_log('DEB', 'Ohne Inhalt, nicht gesendet: ' . $type . '.' . $devName . '.' . $attrName);
                    continue;
                }

                /*
                 * Der Schluessel bleibt "Geraet|DIENST|attribut" - daran haengt
                 * die Signatur, und die soll ueber das Update hinweg stabil
                 * bleiben. Die BESTANDTEILE werden aber nicht mehr aus ihm
                 * zurueckgerechnet: ein '|' im Geraetenamen (die App laesst es
                 * zu) zerlegte den Schluessel falsch, und der Wert ging auf ein
                 * Thema hinaus, das es nicht gibt. Sie werden hier gemerkt.
                 */
                $gschl = $devName . '|' . $type . '|' . $attrName;
                $gwerte[$gschl] = gardena_wert_flach($attr['value']);
                $gteil[$gschl] = array($devName, $type, $attrName);
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

/*
 * Altwerte im Broker abraeumen - nur, was der Broker wirklich noch haelt.
 *
 * Bis 1.2.7 ging jeder Geraetewert retained hinaus, bis 1.2.5 auch das
 * Lebenszeichen. Ein fluechtiges publish loescht keinen zurueckbehaltenen
 * Wert - er laege weiter im Broker und kaeme nach einem Neustart des
 * Miniservers oder des Gateways als frisch heraus.
 *
 * 1.2.8 und 1.2.9 raeumten EINMAL ab, in einem Block vor allen Werten, und
 * setzten danach den Merker retain_stand=2 - gestuetzt allein darauf, dass die
 * Datagramme den Rechner verlassen hatten. Der UDP-Eingang des Gateways
 * verwirft unter Last, und socket_sendto() meldet auch dann Erfolg
 * (Regeln/07, "Ein Absender merkt nichts davon", am Geraet belegt). Jetzt
 * fragt gardena_altlast() den Broker, raeumt nur ab, was er noch haelt, und
 * vermerkt nur, was er leer meldet; ist er nicht zu fragen, wird in jedem
 * Vollversand abgeraeumt. Die leere Nachricht geht UNMITTELBAR vor dem
 * gueltigen Wert hinaus (gardena_wert_senden(), gardena_lebenszeichen()).
 * In WSL gemessen, Pruefung-GardenaSmartSystem-1.2.10, Faelle A1-A13.
 */
$galtlast = array();
if ($gvoll && $mqtt_enabled) {
    $gkandidaten = array();
    foreach ($gteil as $gt) {
        if (!gardena_retain($gt[0], $gt[1], $gt[2])) {
            $gkandidaten[] = gardena_wert_thema($mqtt_topic, $gt[0], $gt[1], $gt[2]);
        }
    }
    foreach (gardena_altlast_status() as $gname) {
        $gkandidaten[] = gardena_wert_thema($mqtt_topic, 'Plugin', 'STATUS', $gname);
    }
    $galtlast = array_flip(gardena_altlast($gkandidaten));
}

if ($gvoll) {
    foreach ($gwerte as $gschluessel => $gwert) {
        list($gdev, $gtyp, $gattr) = $gteil[$gschluessel];
        list($v, $f) = gardena_wert_senden($mqtt_topic, $gdev, $gtyp, $gattr, $gwert,
                                           $gudp_ziel, $udpport, $mqtt_enabled, null,
                                           isset($galtlast[gardena_wert_thema($mqtt_topic, $gdev, $gtyp, $gattr)]));
        $versucht += $v;
        $verloren += $f;
        if ($f === 0) { $sent++; }
        if ($udp_enabled) { usleep(100000); } // Miniserver nicht fluten
    }
    gardena_log('INF', $gunveraendert
        ? 'Unveraendert, aber seit ' . (int) ($galter_meldung / 60) . ' Minuten nichts gesendet - Lebenszeichen mit allen Werten.'
        : count($gwerte) . ' Werte, davon mindestens einer geaendert - es wird gesendet.');
} else {
    gardena_log_gebremst('unveraendert', 'INF', 'Nichts geaendert seit dem letzten Lauf - es wird nichts gesendet '
        . '(die vollstaendige Meldung geht spaetestens alle 30 Minuten hinaus).');
}

/*
 * Weggefallene Themen aufraeumen.
 *
 * Wird ein Geraet umbenannt oder entfernt, bleibt sein altes Thema mit dem
 * letzten Wert dauerhaft im Broker stehen - retained heisst genau das. Ein
 * leerer retained-Wert loescht den Eintrag. Ueber UDP gibt es nichts
 * aufzuraeumen: dort merkt sich niemand etwas.
 */
/*
 * Gemerkt werden seit 1.2.6 die FERTIGEN THEMEN, nicht mehr die Schluessel.
 * Damit braucht das Aufraeumen kein explode('|') mehr (siehe oben), und ein
 * geaendertes Basisthema laesst die alten Themen nicht als Waisen zurueck.
 * Ein Stand aus 1.2.5 wird einmalig umgerechnet.
 */
$gthemen = array();
foreach ($gteil as $gschl => $gt) {
    $gthemen[] = gardena_wert_thema($mqtt_topic, $gt[0], $gt[1], $gt[2]);
}
$galt = array();
if (isset($gstand['themen']) && is_array($gstand['themen'])) {
    foreach ($gstand['themen'] as $ga) {
        $ga = (string) $ga;
        if (strpos($ga, '|') !== false) {          // Stand aus 1.2.5
            $gp = explode('|', $ga, 3);
            if (count($gp) !== 3) { continue; }
            $ga = gardena_wert_thema($mqtt_topic, $gp[0], $gp[1], $gp[2]);
        }
        $galt[] = $ga;
    }
}
$gweg = array_diff($galt, $gthemen);
/*
 * Aufgeraeumt wird NUR nach einem vollstaendigen Abruf.
 *
 * "Weggefallen" wurde bis 1.2.5 allein daraus geschlossen, dass ein Thema in
 * diesem Lauf nicht vorkam. Ein Standort, dessen Abruf scheiterte, liefert
 * genau das - und dann wurden die retained-Werte aller seiner Geraete
 * geleert, obwohl nur das Netz kurz weg war. Wer in diesem Zeitfenster den
 * Broker oder den Miniserver neu startet, steht danach ohne Werte da.
 *
 * Und es wird geloescht, bis der Broker es bestaetigt. Bis 1.2.9 ging die
 * Loeschung EINMAL ueber den UDP-Eingang hinaus, und das Thema fiel danach
 * aus der Liste - verwarf der Eingang das Datagramm, blieb der alte Wert fuer
 * immer im Broker (Regeln/07, "Ein Absender merkt nichts davon"). Jetzt
 * stehen die Themen in 'weg_offen', bis gardena_mqtt_behalten_liste() sie
 * leer meldet; ist der Broker nicht zu fragen, geht die Loeschung in drei
 * Laeufen hinaus, danach faellt das Thema aus der Liste (README, Grenze).
 * In WSL gemessen, Pruefung-GardenaSmartSystem-1.2.10, Faelle W1-W5.
 */
$gweg_offen = array();
if (isset($gstand['weg_offen']) && is_array($gstand['weg_offen'])) {
    foreach ($gstand['weg_offen'] as $gthema => $gn) { $gweg_offen[(string) $gthema] = (int) $gn; }
}
if ($mqtt_enabled && $standort_fehl === 0) {
    foreach ($gweg as $gthema) {
        if (!isset($gweg_offen[$gthema])) { $gweg_offen[$gthema] = 0; }
    }
    // Wieder da (Geraet zurueck, Basisthema zurueckgestellt): nicht loeschen.
    foreach ($gthemen as $gthema) { unset($gweg_offen[$gthema]); }
    if ($gweg_offen) {
        $gf = gardena_mqtt_behalten_liste(array_keys($gweg_offen));
        $gneu_offen = array();
        if ($gf['lage'] === 'ok') {
            $gzu = array_keys($gf['belegt']);
            foreach ($gzu as $gthema) { $gneu_offen[$gthema] = 0; }
        } else {
            $gzu = array_keys($gweg_offen);
            foreach ($gweg_offen as $gthema => $gn) {
                if ($gn + 1 < 3) { $gneu_offen[$gthema] = $gn + 1; }
            }
        }
        foreach ($gzu as $gthema) {
            // Ueber die eigene Loeschfunktion: mqttPublish() schickt einen
            // leeren Wert absichtlich NIE retained hinaus.
            gardena_mqtt_loeschen($gthema);
        }
        if ($gzu) {
            gardena_log('INF', count($gzu) . ' weggefallene MQTT-Themen geleert (Geraet umbenannt oder entfernt)'
                . ($gf['lage'] === 'ok' ? ' - der naechste Lauf fragt beim Broker nach.'
                                        : ' - der Broker war nicht zu fragen.'));
        }
        if ($gf['lage'] !== 'ok' && count($gneu_offen) < count($gweg_offen)) {
            gardena_log('INF', (count($gweg_offen) - count($gneu_offen)) . ' weggefallene MQTT-Themen '
                . 'dreimal ohne Rueckfrage beim Broker geleert - sie werden nicht weiter verfolgt.');
        }
        $gweg_offen = $gneu_offen;
    }
} elseif ($gweg && $mqtt_enabled) {
    gardena_log('INF', count($gweg) . ' Themen fehlen in diesem Lauf - NICHT geleert, weil '
        . $standort_fehl . ' Standort(e) nicht geantwortet haben.');
}

// Geraete-Zwischenspeicher fuer die Admin-Oberflaeche.
//
// Unteilbar geschrieben und mit 0640: darin stehen die Klarnamen aller
// Geraete, die Service-Kennungen und die Ladezustaende. Bis 1.0.2 wurde er
// mit den Vorgaberechten angelegt und die Oberflaeche konnte ihn halb
// geschrieben lesen, waehrend der Cron ihn ersetzte.
/*
 * Nur schreiben, wenn KEIN Standort ausgefallen ist.
 *
 * Bis 1.2.5 stand dieser Aufruf ausserhalb jeder Bedingung. Bei einem
 * Standort und einem Netzfehler ersetzte er das bis dahin gueltige Abbild
 * durch eine leere Liste - danach fand ?action=command keine Dienstkennung
 * mehr ("Kein passendes Geraet/Service gefunden"), der Reiter Geraete war
 * leer und die Vorlagenknoepfe verschwanden, bis der naechste Lauf glueckte.
 * Dieselbe Datei traegt in functions.inc.php den Grundsatz, dass eine halb
 * gueltige Datei GAR NICHTS ueberschreibt.
 */
if ($standort_fehl > 0) {
    gardena_log('ERR', $standort_fehl . ' Standort(e) ohne Antwort - der Geraete-Zwischenspeicher '
        . 'bleibt unveraendert, damit die bisherigen Dienstkennungen erhalten bleiben.');
} elseif (!gardena_json_write($lbpconfigdir . '/devices_cache.json', $statuscache, 0640)) {
    gardena_log('ERR', 'Geraete-Zwischenspeicher konnte nicht geschrieben werden.');
}

/*
 * Zustand fortschreiben und das Lebenszeichen senden.
 *
 * 'letzter_erfolg' wird NUR bei einem vollstaendigen Lauf gesetzt - er
 * ueberlebt damit jeden gescheiterten und beantwortet in der Oberflaeche und
 * in Loxone die Frage, wie lange schon nichts mehr angekommen ist.
 */
$vollstaendig = ($verloren === 0 && $standort_fehl === 0 && count($gwerte) > 0);
$gneuer_stand = array(
    'ok' => $vollstaendig ? 1 : 0,
    'letzter_lauf' => time(),
    'werte' => $gvoll ? $sent : (int) $gstand['werte'],
    'verloren' => $verloren,
    'ohne_inhalt' => $ohne_inhalt,
    // Die Signatur nur nach einem vollstaendigen Lauf fortschreiben.
    // Bis 1.2.5 stand sie hier unbedingt: gingen Zustellungen verloren, galt
    // der Bestand beim naechsten Lauf trotzdem als "unveraendert", es wurde
    // nichts nachgesendet - und weil dann nichts mehr scheiterte, meldete
    // derselbe Lauf ok=1. Bis zu 30 Minuten alte Werte im Miniserver bei
    // voller Erfolgsmeldung.
    'signatur' => $vollstaendig ? $gsignatur : (string) $gstand['signatur'],
    'themen' => $gthemen,
    'weg_offen' => $gweg_offen,
    // Nach einem geglueckten Lauf ist die Ruecknahme aufgehoben.
    'sperre_bis' => 0,
    'fehler' => $vollstaendig ? '' :
        ($standort_fehl > 0
            ? ($standort_fehl . ' Standort(e) haben nicht geantwortet.')
            : (count($gwerte) === 0 ? 'Kein einziger Wert zu senden - Antwort der Wolke leer?'
                              : ($verloren . ' von ' . $versucht . ' Zustellungen gescheitert.'))),
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
} elseif (!$gloc_frisch && $standort_fehl > 0) {
    // Die Liste kam aus dem Zwischenspeicher und hat nicht getragen. Der
    // Kommentar bei ihrer Ablage sagt zu, sie werde dann neu geholt - bis
    // 1.2.5 wurde 'locations_stand' dabei gar nicht angefasst, und eine
    // veraltete Standortkennung wurde bis zu 24 Stunden lang weiter
    // abgefragt. Jetzt wird sie ausdruecklich als alt markiert.
    $gneuer_stand['locations_stand'] = 0;
    gardena_log('INF', 'Die Standortliste kam aus dem Zwischenspeicher und hat nicht getragen - '
        . 'sie wird beim naechsten Lauf neu geholt.');
}
if (!gardena_status_schreiben($lbpconfigdir, $gneuer_stand)) {
    // Ohne diesen Zustand gibt es keine Ausfallerkennung, keine Signatur und
    // keine 429-Ruecknahme. Der Grund steht durch gardena_json_write() schon
    // im Protokoll; hier steht die Folge.
    gardena_log('ERR', 'Der Zustand liess sich nicht fortschreiben - Ausfallerkennung, '
        . 'Aenderungsvergleich und Abrufsperre greifen bis auf Weiteres nicht.');
}

list($glz_v, $glz_f) = gardena_lebenszeichen($mqtt_topic, gardena_status_lesen($lbpconfigdir),
                                             $gudp_ziel, $udpport, $mqtt_enabled, $galtlast);
if ($glz_f > 0) {
    gardena_log('ERR', 'Lebenszeichen: ' . $glz_f . ' von ' . $glz_v . ' Zustellungen gescheitert.');
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
    gardena_log('OK', count($gwerte) . ' Werte gelesen, keiner geaendert - nichts gesendet.' . $gohne . $gaus);
} elseif ($vollstaendig) {
    // "abgeschickt", nicht "zugestellt": gemessen wird, dass das Datagramm
    // den Rechner verlassen hat. Ob das MQTT-Gateway laeuft und der
    // Miniserver es annimmt, sagt die UDP-Schnittstelle nicht zurueck.
    gardena_log('OK', $sent . ' Werte abgeschickt (' . $versucht . ' Sendeversuche, keiner gescheitert).' . $gohne . $gaus);
} elseif (count($gwerte) === 0) {
    gardena_log('ERR', 'Kein einziger Wert zu senden - die Antwort der Wolke enthielt nichts Verwertbares.' . $gaus);
} else {
    gardena_log('ERR', $verloren . ' von ' . $versucht . ' Sendeversuchen gescheitert; '
        . $sent . ' Werte vollstaendig abgeschickt. Ursache steht in den Zeilen darueber.');
}
flock($sperre, LOCK_UN);
fclose($sperre);
