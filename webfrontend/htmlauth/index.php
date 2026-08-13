<?php
/**
 * GARDENA smart system - Admin-Oberflaeche (LoxBerry 3/4, PHP 7.4+/8.x)
 *
 * Neu ab 1.1.0: Reiter nach Hausstandard, zweisprachig (de/en mit Englisch
 * als Rueckfallebene), und die Konfiguration wird beim Speichern
 * ZUSAMMENGEFUEHRT statt ueberschrieben.
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-Globalvariablen (u.a. $cfg aus der
 * general.json als stdClass) und wuerde gleichnamige Plugin-Variablen
 * ueberschreiben - deshalb tragen hier ALLE Variablen ein g-Praefix.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

require_once 'loxberry_system.php';
require_once 'loxberry_log.php';
require_once 'loxberry_web.php';

global $lbpconfigdir, $lbpplugindir, $lbplogdir, $lbpbindir;

// Die Bibliotheken liegen seit 1.1.0 in bin/. loxberry_log.php wird hier
// mitgeladen, damit gardena_log() auch aus der Oberflaeche heraus wirklich
// schreibt - bis 1.0.2 fehlte es, und jede Meldung verschwand still.
require_once $lbpbindir . '/functions.inc.php';
require_once $lbpbindir . '/gardena.class.inc.php';

$gconfigfile = $lbpconfigdir . '/gardena.cfg';
$gcachefile = $lbpconfigdir . '/devices_cache.json';
$glogfile = $lbplogdir . '/gardena_ui.log';

/* Die Kurzformen gt()/ge() sind seit 1.1.6 aufgeloest (Sprachmechanik-
 * Vereinheitlichung 13.08.2026): ueberall stehen die vollen Namen
 * gardena_t() (aus bin/functions.inc.php) und gardena_e(). */
function gardena_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/* ==================================================================
 * Reiter
 *
 * Wer einen Reiter hinzufuegt, muss DREI Stellen mitziehen: die
 * Reiterleiste, den Bereich (sm-pane mit gleicher id) und diese
 * Positivliste. Fehlt der Name hier, springt die Seite nach jedem Absenden
 * zurueck auf Einstellungen.
 * ================================================================== */
$g_muster = '/^tab-(settings|mqtt|devices|loxone|test|log)$/';
$g_tab = preg_match($g_muster, (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? (string) $_POST['activetab'] : 'tab-settings';
// Die Reiter sind echte Verweise. Wer sie anklickt oder ein Lesezeichen
// darauf setzt, landet ueber ?form= im richtigen Bereich - auch dann, wenn
// im Browser kein JavaScript laeuft.
if (isset($_GET['form'])) {
    $g_wunsch = 'tab-' . preg_replace('/[^a-z]/', '', (string) $_GET['form']);
    if (preg_match($g_muster, $g_wunsch)) { $g_tab = $g_wunsch; }
}
function gaktiv($id) { global $g_tab; return $g_tab === $id ? ' sm-active' : ''; }

/** Vorlage der Gateway-Eingaenge nach dem Heimkino-Kunstgriff (12.08.2026):
 *  VirtualInHttp mit Dummy-Adresse http://localhost und Abfragezyklus 604800 s,
 *  nur damit Loxone die richtig benannten Eingaenge anlegt - die Werte kommen
 *  vom MQTT-Gateway. Die Namen stammen aus dem Geraete-Zwischenspeicher
 *  (devices_cache.json), also aus der letzten echten Gardena-Antwort -
 *  nichts ist geraten. Nur Zahlenwerte; Textwerte legt das Gateway beim
 *  ersten Empfang selbst an. */
function gardena_vorlage($cachefile, $topic)
{
    $cache = json_decode((string) @file_get_contents($cachefile), true);
    $crlf = "\r\n";
    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" Title="Gardena smart system" Comment="Erzeugt vom LoxBerry-Plugin GardenaSmartSystem (' . date('d.m.Y') . '). Werte kommen vom MQTT-Gateway - Abo ' . htmlspecialchars($topic, ENT_QUOTES | ENT_XML1, 'UTF-8') . '/# noetig." Address="http://localhost" PollingTime="604800">' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    // Bekannte Wertebereiche; alles andere bekommt weite, aber endliche Grenzen.
    $grenzen = array(
        'batteryLevel' => array('0', '100', '<v.0> %'),
        'rfLinkLevel'  => array('0', '100', '<v.0> %'),
    );
    if (is_array($cache) && !empty($cache['locations'])) {
        foreach ($cache['locations'] as $loc) {
            if (empty($loc['devices']) || !is_array($loc['devices'])) { continue; }
            foreach ($loc['devices'] as $devId => $dev) {
                $devName = isset($dev['name']) && $dev['name'] !== '' ? $dev['name'] : $devId;
                if (empty($dev['services']) || !is_array($dev['services'])) { continue; }
                foreach ($dev['services'] as $type => $attrs) {
                    if (!is_array($attrs)) { continue; }
                    foreach ($attrs as $attrName => $attr) {
                        if ($attrName === '_service_id' || !is_array($attr) || !array_key_exists('value', $attr)) { continue; }
                        $value = $attr['value'];
                        if (is_bool($value)) { $value = $value ? 1 : 0; }
                        if (!is_numeric($value)) { continue; } // Textwerte legt das Gateway selbst an
                        $g = isset($grenzen[$attrName]) ? $grenzen[$attrName] : array('-1000000', '1000000', '<v.1>');
                        $titel = str_replace('/', '_', $topic . '/' . $devName . '/' . $type . '/' . $attrName);
                        $o .= "\t" . '<VirtualInHttpCmd Title="' . htmlspecialchars($titel, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" ';
                        $o .= 'Comment="' . htmlspecialchars($attrName . ' ' . $devName . ' (' . $type . ')', ENT_QUOTES | ENT_XML1, 'UTF-8') . '" Check=" " ';
                        $o .= 'Signed="true" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="' . $g[0] . '" MaxVal="' . $g[1] . '" Unit="' . htmlspecialchars($g[2], ENT_QUOTES | ENT_XML1, 'UTF-8') . '" HintText=""/>' . $crlf;
                    }
                }
            }
        }
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return array('VI_gardena.xml', $o);
}

/* ==================================================================
 * Verarbeitung
 * ================================================================== */
$gsaved = false;
$gtest = '';
$gtokenmsg = '';
$gfehler = array();

$gpost = ($_SERVER['REQUEST_METHOD'] === 'POST');

// Protokoll leeren
if ($gpost && isset($_POST['clearlog'])) {
    @file_put_contents($glogfile, '[' . date('Y-m-d H:i:s') . "] INF Protokoll geleert (Oberflaeche)\n");
    $g_tab = 'tab-log';
}

/**
 * Ein neues Token erzeugen und wegschreiben.
 *
 * gardena_token_new() bricht seit 1.1.0 mit einer Ausnahme ab, wenn das
 * System keinen sicheren Zufall liefert. Abgefangen wird sie HIER - sonst
 * zerlegte sie die Oberflaeche, und genau das war bis 1.0.2 der Fall.
 */
function gtoken_erzeugen($cfgfile, &$meldung)
{
    try {
        $t = gardena_token_new();
    } catch (RuntimeException $e) {
        $meldung = gardena_t('TOKEN.KEIN_ZUFALL');
        return '';
    }
    if (!gardena_cfg_set($cfgfile, 'TOKEN', $t)) {
        $meldung = gardena_t('TOKEN.SCHREIB_FEHLER') . ' ' . $cfgfile;
        return '';
    }
    return $t;
}

if ($gpost && isset($_POST['newtoken'])) {
    $gtokenmsg = gardena_t('TOKEN.NEU_FEHLER');
    $gneu = gtoken_erzeugen($gconfigfile, $gtokenmsg);
    if ($gneu !== '') { $gtokenmsg = gardena_t('TOKEN.NEU_OK'); }
    $g_tab = 'tab-settings';
}

/** VO-Vorlage (Steuerbefehle) nach dem Heimkino/Robonect-Muster:
 *  templateType 3, Token eingesetzt. Geraete und ihre Art stammen aus dem
 *  Abbild (devices_cache.json), die Befehle aus der Tabelle im
 *  Einbindungs-Reiter - nichts ist geraten. */
function gardena_vorlage_vo($cachefile, $token)
{
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    $ordner = getenv('LBPPLUGINDIR') ?: 'gardena';
    $basis = '/plugins/' . $ordner . '/index.php?action=command&token=' . rawurlencode((string) $token);
    $cache = json_decode((string) @file_get_contents($cachefile), true);
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="Gardena steuern (LoxBerry-Plugin)" Comment="Steuerbefehle ueber das Plugin ' . htmlspecialchars($ordner, ENT_QUOTES | ENT_XML1, 'UTF-8') . ' - enthaelt das Token." Address="http://' . htmlspecialchars($host, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    $befehle = array(
        'MOWER' => array(
            array('maehen (1 h)',            'MOWER_CONTROL', 'START_SECONDS_TO_OVERRIDE&seconds=3600'),
            array('Zeitplan folgen',         'MOWER_CONTROL', 'START_DONT_OVERRIDE'),
            array('parken bis naechste Zeit','MOWER_CONTROL', 'PARK_UNTIL_NEXT_TASK'),
            array('parken bis auf Widerruf', 'MOWER_CONTROL', 'PARK_UNTIL_FURTHER_NOTICE'),
        ),
        'VALVE' => array(
            array('bewaessern (30 min)',     'VALVE_CONTROL', 'START_SECONDS_TO_OVERRIDE&seconds=1800'),
            array('stoppen bis naechste Zeit','VALVE_CONTROL', 'STOP_UNTIL_NEXT_TASK'),
            array('Pause',                   'VALVE_CONTROL', 'PAUSE'),
            array('Pause beenden',           'VALVE_CONTROL', 'UNPAUSE'),
        ),
        'POWER_SOCKET' => array(
            array('einschalten (1 h)',       'POWER_SOCKET_CONTROL', 'START_SECONDS_TO_OVERRIDE&seconds=3600'),
        ),
    );
    if (is_array($cache) && !empty($cache['locations'])) {
        foreach ($cache['locations'] as $loc) {
            if (empty($loc['devices']) || !is_array($loc['devices'])) { continue; }
            foreach ($loc['devices'] as $devId => $dev) {
                $devName = isset($dev['name']) && $dev['name'] !== '' ? $dev['name'] : $devId;
                $dienste = isset($dev['services']) && is_array($dev['services']) ? array_keys($dev['services']) : array();
                foreach ($befehle as $dienst => $liste) {
                    // VALVE trifft auch VALVE_SET nicht - nur echte Ventile.
                    if (!in_array($dienst, $dienste, true)) { continue; }
                    foreach ($liste as $b) {
                        $adr = $basis . '&device=' . rawurlencode($devName) . '&type=' . $b[1] . '&cmd=' . $b[2];
                        $o .= "\t" . '<VirtualOutCmd Title="' . htmlspecialchars($devName . ' ' . $b[0], ENT_QUOTES | ENT_XML1, 'UTF-8') . '" Comment="" CmdOnMethod="GET" CmdOffMethod="GET" ';
                        $o .= 'CmdOn="' . htmlspecialchars($adr, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" ';
                        $o .= 'CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" ';
                        $o .= 'Analog="false" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
                    }
                }
            }
        }
    }
    $o .= '</VirtualOut>' . $crlf;
    return array('VQ_gardena_steuern.xml', $o);
}

// ---------- Loxone-Vorlage herunterladen (Hausstandard) ----------
if ($gpost && isset($_POST['vorlage'])) {
    $gc_v = gardena_cfg_read($gconfigfile);
    list($g_vname, $g_vinhalt) = ($_POST['vorlage'] === 'vo')
        ? gardena_vorlage_vo($gcachefile, isset($gc_v['TOKEN']) ? $gc_v['TOKEN'] : '')
        : gardena_vorlage($gcachefile, !empty($gc_v['MQTT_TOPIC']) ? $gc_v['MQTT_TOPIC'] : 'gardena');
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $g_vname . '"');
    echo $g_vinhalt;
    exit;
}

// ---------- MQTT speichern (eigener Reiter seit 1.1.6, Hausstandard) ----------
if ($gpost && isset($_POST['mqtt_save'])) {
    $gtopic_mq = preg_replace('#[^A-Za-z0-9_/\-]#', '', trim((string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : 'gardena')));
    if ($gtopic_mq === '') { $gtopic_mq = 'gardena'; }
    $gneu_mq = array(
        'MQTT_ENABLED' => (isset($_POST['mqtt_enabled']) && $_POST['mqtt_enabled'] === '1') ? '1' : '0',
        'MQTT_TOPIC' => $gtopic_mq,
    );
    if (gardena_cfg_write($gconfigfile, $gneu_mq)) {
        $gsaved = true;
        gardena_log('INF', 'MQTT-Einstellungen gespeichert (Oberflaeche).');
    } else {
        $gfehler[] = gardena_t('TOKEN.SCHREIB_FEHLER') . ' ' . $gconfigfile;
    }
    $g_tab = 'tab-mqtt';
}

if ($gpost && isset($_POST['save'])) {
    // Fehler werden GESAMMELT und am Stueck gemeldet, nicht beim ersten
    // Stolpern abgebrochen - sonst arbeitet man sich Feld fuer Feld durch.
    $gcid = trim((string) (isset($_POST['client_id']) ? $_POST['client_id'] : ''));
    $gsec = trim((string) (isset($_POST['client_secret']) ? $_POST['client_secret'] : ''));
    $gtopic = trim((string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : 'gardena'));
    $gtopic = preg_replace('#[^A-Za-z0-9_/\-]#', '', $gtopic);
    if ($gtopic === '') { $gtopic = 'gardena'; }
    $gport = (int) (isset($_POST['udpport']) ? $_POST['udpport'] : 5005);
    $genabled = (isset($_POST['enabled']) && $_POST['enabled'] === '1') ? '1' : '0';

    if ($gport < 1 || $gport > 65535) {
        $gfehler[] = gardena_t('EINST.UDPPORT') . ': 1 - 65535';
        $gport = 5005;
    }
    if ($genabled === '1' && ($gcid === '' || $gsec === '')) {
        $gfehler[] = gardena_t('EINST.CLIENT_ID') . ' / ' . gardena_t('EINST.CLIENT_SECRET');
    }

    // Das bestehende Token weiterverwenden - es darf beim Speichern der
    // uebrigen Felder nicht verlorengehen. Ist noch keines da, wird eines
    // erzeugt; scheitert das, bleibt das Feld leer und der Endpunkt weist
    // konsequenterweise alles ab.
    $galt = gardena_cfg_read($gconfigfile);
    $gtoken = (string) $galt['TOKEN'];
    if ($gtoken === '') {
        $gtoken = gtoken_erzeugen($gconfigfile, $gtokenmsg);
    }

    if (!$gfehler) {
        /*
         * ZUSAMMENFUEHREN, nicht ueberschreiben.
         *
         * Bis 1.0.2 baute die Oberflaeche die Datei aus ihren acht bekannten
         * Feldern neu zusammen und schrieb sie stumpf zurueck. Jeder
         * Schluessel, den sie nicht kannte, war danach weg - samt aller
         * erklaerenden Kommentare. gardena_cfg_write() ersetzt jetzt nur die
         * uebergebenen Schluessel und laesst alles andere stehen.
         */
        $gneu = array(
            'ENABLED' => $genabled,
            'CLIENT_ID' => $gcid,
            'CLIENT_SECRET' => $gsec,
            'MINISERVER' => max(1, (int) (isset($_POST['miniserver']) ? $_POST['miniserver'] : 1)),
            'UDP_ENABLED' => (isset($_POST['udp_enabled']) && $_POST['udp_enabled'] === '1') ? '1' : '0',
            'UDPPORT' => $gport,
            // MQTT_ENABLED/MQTT_TOPIC wohnen seit 1.1.6 im eigenen Reiter mit
            // eigenem Formular - gardena_cfg_write laesst nicht uebergebene
            // Schluessel stehen, deshalb hier bewusst weggelassen.
        );
        if ($gtoken !== '') { $gneu['TOKEN'] = $gtoken; }

        if (gardena_cfg_write($gconfigfile, $gneu)) {
            $gsaved = true;
            // Haben sich die Zugangsdaten geaendert, ist das
            // zwischengespeicherte OAuth2-Token wertlos.
            if ($gcid !== (string) $galt['CLIENT_ID'] || $gsec !== (string) $galt['CLIENT_SECRET']) {
                @unlink($lbpconfigdir . '/gardena_token.json');
            }
            gardena_log('INF', 'Konfiguration gespeichert (Oberflaeche).');
        } else {
            $gfehler[] = gardena_t('TOKEN.SCHREIB_FEHLER') . ' ' . $gconfigfile;
        }

        // Verbindungstest, wenn das Plugin aktiv ist
        if ($gsaved && $genabled === '1' && $gcid !== '' && $gsec !== '') {
            $gapi = new gardena($gcid, $gsec, $lbpconfigdir);
            if ($gapi->authenticate()) {
                $glocs = $gapi->getLocations();
                if (!empty($glocs)) {
                    $gnamen = array();
                    foreach ($glocs as $gl) {
                        $gnamen[] = isset($gl['attributes']['name']) ? $gl['attributes']['name']
                                  : (isset($gl['id']) ? $gl['id'] : '?');
                    }
                    $gtest = sprintf(gardena_t('TESTMELD.OK'), implode(', ', $gnamen));
                } else {
                    $gtest = sprintf(gardena_t('TESTMELD.KEINE_LOCATION'), $gapi->last_error);
                }
            } else {
                $gtest = sprintf(gardena_t('TESTMELD.FEHLER'), $gapi->last_error);
            }
        }
    }
}

/* ==================================================================
 * Laden
 * ================================================================== */
$gc = gardena_cfg_read($gconfigfile);

// Beim ersten Oeffnen der Oberflaeche ein Token erzeugen. Ohne Token weist
// der Endpunkt jeden Aufruf ab - das Plugin waere unbenutzbar.
if ((string) $gc['TOKEN'] === '' && !$gpost) {
    $gneu = gtoken_erzeugen($gconfigfile, $gtokenmsg);
    if ($gneu !== '') { $gc['TOKEN'] = $gneu; }
}
$gtokenurl = ((string) $gc['TOKEN'] !== '') ? '&amp;token=' . rawurlencode($gc['TOKEN']) : '&amp;token=TOKEN';

$gms = LBSystem::get_miniservers();
if (!is_array($gms)) { $gms = array(); }

$gcache = json_decode((string) @file_get_contents($gcachefile), true);
if (!is_array($gcache)) { $gcache = array(); }

$gloglines = array();
if (is_file($glogfile)) {
    $gloglines = array_slice(array_reverse(file($glogfile,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()), 0, 300);
}

$ghatcurl = gardena::hasCurl();
$ghatsockets = function_exists('socket_create');
$gpl = gardena_e($lbpplugindir);

LBWeb::lbheader('Gardena Smart System', 'https://developer.husqvarnagroup.cloud/', 'help.html');
?>
<style>
.sm-wrap { max-width: 940px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.sm-wrap input[type=text], .sm-wrap input[type=password], .sm-wrap input[type=number], .sm-wrap select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.95em; box-sizing: border-box; }
.sm-row { display: flex; gap: 12px; flex-wrap: wrap; }
.sm-row > div { flex: 1; min-width: 160px; }
.sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 6px; padding: 10px 22px; font-size: 1em; cursor: pointer; margin-top: 18px; font-weight: 600; }
.sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.sm-ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.sm-err { background: #ffebee; border: 1px solid #ef9a9a; }
.sm-warn { background: #fff8e1; border: 1px solid #ffe082; }
.sm-info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.9em; }
.sm-mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; word-break: break-all; }
.sm-small { font-size: 0.82em; color: #666; margin-top: 3px; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px; cursor: pointer;
  font-size: 0.95em; color: #444 !important; text-shadow: none !important; display: inline-block; text-decoration: none !important; }
.sm-tab:visited, .sm-tab:hover { text-decoration: none !important; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-log { text-shadow: none !important; background: #1e1e1e; color: #d4d4d4; font-family: ui-monospace, monospace; font-size: 0.82em;
  padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-step { margin: 10px 0; padding: 10px 14px; background: #fafafa; border-left: 4px solid #6dac20; border-radius: 0 8px 8px 0; }
.sm-tbl { border-collapse: collapse; margin: 8px 0; width: 100%; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }
.sm-wrap .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button { text-shadow: none !important; box-shadow: none !important; }
.sm-wrap a.sm-btn, .sm-wrap a.sm-btn:visited, .sm-wrap a.sm-btn:hover { color: #fff !important; text-decoration: none; }

/* --- Einheitliches Kachel-Raster im Reiter Test (Hausstandard) --- */
.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; text-shadow: none !important; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center; margin-top: 0;
  display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-btn.sm-b-lesen   { background: #6dac20; }
.sm-btn.sm-b-technik { background: #546e7a; }
.sm-btn.sm-b-aktion  { background: #e0620d; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
</style>
<div class="sm-wrap">

<?php if (!$ghatsockets) { ?>
<div class="sm-alert sm-err"><b><?= gardena_t('EINST.SOCKETS_TITEL') ?></b><br><?= gardena_t('EINST.SOCKETS_TEXT') ?></div>
<?php } ?>
<?php if (!$ghatcurl) { ?>
<div class="sm-alert sm-warn"><b><?= gardena_t('EINST.CURL_TITEL') ?></b><br><?= gardena_t('EINST.CURL_TEXT') ?></div>
<?php } ?>
<?php if ($gsaved) { ?><div class="sm-alert sm-ok"><b><?= gardena_t('ALLG.GESPEICHERT') ?></b></div><?php } ?>
<?php if ($gfehler) { ?>
<div class="sm-alert sm-err"><b><?= gardena_t('ALLG.FEHLER') ?></b><br><?= gardena_e(implode(' | ', $gfehler)) ?></div>
<?php } ?>
<?php if ($gtokenmsg !== '') { ?>
<div class="sm-alert <?= stripos($gtokenmsg, 'FEHLER') !== false || stripos($gtokenmsg, 'ERROR') !== false ? 'sm-err' : 'sm-ok' ?>"><?= $gtokenmsg ?></div>
<?php } ?>
<?php if ($gtest !== '') { ?>
<div class="sm-alert <?= strpos($gtest, 'OK') === 0 ? 'sm-ok' : 'sm-err' ?>"><?= gardena_e($gtest) ?></div>
<?php } ?>

<div class="sm-tabs">
    <a class="sm-tab<?= gaktiv('tab-settings') ?>" data-pane="tab-settings" href="index.php?form=settings"><?= gardena_t('REITER.EINSTELLUNGEN') ?></a>
    <a class="sm-tab<?= gaktiv('tab-mqtt') ?>" data-pane="tab-mqtt" href="index.php?form=mqtt"><?= gardena_t('REITER.MQTT') ?></a>
    <a class="sm-tab<?= gaktiv('tab-devices') ?>" data-pane="tab-devices" href="index.php?form=devices"><?= gardena_t('REITER.GERAETE') ?></a>
    <a class="sm-tab<?= gaktiv('tab-loxone') ?>" data-pane="tab-loxone" href="index.php?form=loxone"><?= gardena_t('REITER.LOXONE') ?></a>
    <a class="sm-tab<?= gaktiv('tab-test') ?>" data-pane="tab-test" href="index.php?form=test"><?= gardena_t('REITER.TEST') ?></a>
    <a class="sm-tab<?= gaktiv('tab-log') ?>" data-pane="tab-log" href="index.php?form=log"><?= gardena_t('REITER.LOG') ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-pane<?= gaktiv('tab-settings') ?>" id="tab-settings">

<div class="sm-alert sm-info"><b><?= gardena_t('EINST.ZUGANG_TITEL') ?></b> <?= gardena_t('EINST.ZUGANG_TEXT') ?></div>

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?= gardena_t('EINST.H_ALLGEMEIN') ?></h2>
<div class="sm-row">
    <div>
        <label><?= gardena_t('EINST.PLUGIN_AKTIV') ?></label>
        <select data-role="none" name="enabled">
            <option value="0"<?= $gc['ENABLED'] != '1' ? ' selected' : '' ?>><?= gardena_t('ALLG.NEIN') ?></option>
            <option value="1"<?= $gc['ENABLED'] == '1' ? ' selected' : '' ?>><?= gardena_t('EINST.PLUGIN_AKTIV_JA') ?></option>
        </select>
    </div>
    <div>
        <label><?= gardena_t('EINST.CLIENT_ID') ?></label>
        <input data-role="none" type="text" name="client_id" value="<?= gardena_e($gc['CLIENT_ID']) ?>">
    </div>
    <div>
        <label><?= gardena_t('EINST.CLIENT_SECRET') ?></label>
        <input data-role="none" type="password" name="client_secret" value="<?= gardena_e($gc['CLIENT_SECRET']) ?>">
    </div>
</div>
<div class="sm-small"><?= gardena_t('EINST.SECRET_HINWEIS') ?></div>

<h2><?= gardena_t('EINST.H_UDP') ?></h2>
<div class="sm-row">
    <div>
        <label><?= gardena_t('EINST.UDP_VERSAND') ?></label>
        <select data-role="none" name="udp_enabled">
            <option value="1"<?= $gc['UDP_ENABLED'] != '0' ? ' selected' : '' ?>><?= gardena_t('ALLG.AKTIV') ?></option>
            <option value="0"<?= $gc['UDP_ENABLED'] == '0' ? ' selected' : '' ?>><?= gardena_t('ALLG.AUS') ?></option>
        </select>
    </div>
    <div>
        <label><?= gardena_t('EINST.MINISERVER') ?></label>
        <select data-role="none" name="miniserver">
<?php if (empty($gms)) { ?>
            <option value="1"><?= gardena_t('EINST.KEIN_MINISERVER') ?></option>
<?php } foreach ($gms as $gnr => $gm) { ?>
            <option value="<?= (int) $gnr ?>"<?= (int) $gc['MINISERVER'] === (int) $gnr ? ' selected' : '' ?>><?= gardena_e($gm['Name'] . ' (' . $gm['IPAddress'] . ')') ?></option>
<?php } ?>
        </select>
    </div>
    <div>
        <label><?= gardena_t('EINST.UDPPORT') ?></label>
        <input data-role="none" type="number" name="udpport" value="<?= (int) $gc['UDPPORT'] ?>" min="1" max="65535">
    </div>
</div>
<div class="sm-small"><?= gardena_t('EINST.UDP_FORMAT') ?></div>

<button data-role="none" class="sm-btn" type="submit"><?= gardena_t('ALLG.SPEICHERN') ?></button>
</form>

<h2><?= gardena_t('TOKEN.H') ?></h2>
<div class="sm-alert sm-info"><?= gardena_t('TOKEN.TEXT') ?></div>
<label><?= gardena_t('TOKEN.LABEL') ?></label>
<input data-role="none" type="text" value="<?= gardena_e($gc['TOKEN']) ?>" readonly onclick="this.select();">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= gardena_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="newtoken" value="1"
            onclick="return confirm(<?= json_encode(html_entity_decode(gardena_t('TOKEN.NEU_FRAGE'), ENT_QUOTES, 'UTF-8')) ?>);"><?= gardena_t('TOKEN.NEU') ?></button>
</form>
</div>
</div>

<!-- ================= Reiter: Geraete ================= -->
<!-- ================= Reiter: MQTT (eigener Reiter seit 1.1.6, Hausstandard) ================= -->
<div class="sm-pane<?= gaktiv('tab-mqtt') ?>" id="tab-mqtt">
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<h2><?= gardena_t('EINST.H_MQTT') ?></h2>
<?php if (!function_exists('gard_hs_autostart')) { function gard_hs_autostart() { $h = getenv('LBHOMEDIR') ?: '/opt/loxberry'; $g = $h . '/config/system/general.json'; if (!is_file($g)) { return null; } $j = json_decode((string) @file_get_contents($g), true); if (!is_array($j) || !isset($j['Mqtt'])) { return null; } return !empty($j['Mqtt']['Gatewayautostart']); } } if (gard_hs_autostart() === false) { ?><div class="sm-alert sm-warn"><b>MQTT:</b> <?php echo gardena_t('EINST.W_AUTOSTART'); ?></div><?php } ?>
<div class="sm-row">
    <div>
        <label><?= gardena_t('EINST.MQTT_VERSAND') ?></label>
        <select data-role="none" name="mqtt_enabled">
            <option value="1"<?= $gc['MQTT_ENABLED'] == '1' ? ' selected' : '' ?>><?= gardena_t('EINST.MQTT_EMPFOHLEN') ?></option>
            <option value="0"<?= $gc['MQTT_ENABLED'] != '1' ? ' selected' : '' ?>><?= gardena_t('ALLG.AUS') ?></option>
        </select>
    </div>
    <div>
        <label><?= gardena_t('EINST.MQTT_TOPIC') ?></label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= gardena_e($gc['MQTT_TOPIC']) ?>" placeholder="gardena">
    </div>
</div>
<div class="sm-small"><?= gardena_t('EINST.MQTT_FORMAT') ?><br><?= gardena_t('EINST.MQTT_ZEICHEN') ?></div>

<button data-role="none" class="sm-btn" type="submit"><?= gardena_t('ALLG.SPEICHERN') ?></button>
</form>
</div>

<div class="sm-pane<?= gaktiv('tab-devices') ?>" id="tab-devices">
<h2><?= gardena_t('GERAETE.H') ?><?= !empty($gcache['updated']) ? ' (' . gardena_t('GERAETE.LETZTER_ABRUF') . ' ' . gardena_e($gcache['updated']) . ')' : '' ?></h2>
<?php if (empty($gcache['locations']) || !is_array($gcache['locations'])) { ?>
<div class="sm-alert sm-info"><?= gardena_t('GERAETE.KEINE_DATEN') ?></div>
<?php } else { foreach ($gcache['locations'] as $gloc) {
    if (!is_array($gloc) || empty($gloc['devices']) || !is_array($gloc['devices'])) { continue; } ?>
<p><b><?= gardena_e(isset($gloc['name']) ? $gloc['name'] : '?') ?></b></p>
<table class="sm-tbl">
<tr><th><?= gardena_t('GERAETE.SP_GERAET') ?></th><th><?= gardena_t('GERAETE.SP_SERVICES') ?></th><th><?= gardena_t('GERAETE.SP_STATUS') ?></th><th><?= gardena_t('GERAETE.SP_AKKU') ?></th></tr>
<?php foreach ($gloc['devices'] as $gdevid => $gdev) {
    if (!is_array($gdev)) { continue; }
    $gsvcs = (isset($gdev['services']) && is_array($gdev['services'])) ? implode(', ', array_keys($gdev['services'])) : '';
    $gstate = isset($gdev['services']['COMMON']['rfLinkState']['value']) ? $gdev['services']['COMMON']['rfLinkState']['value'] : '';
    $gbatt = isset($gdev['services']['COMMON']['batteryLevel']['value']) ? $gdev['services']['COMMON']['batteryLevel']['value'] . ' %' : '';
?>
<tr><td><?= gardena_e(isset($gdev['name']) ? $gdev['name'] : $gdevid) ?></td><td><?= gardena_e($gsvcs) ?></td><td><?= gardena_e($gstate) ?></td><td><?= gardena_e($gbatt) ?></td></tr>
<?php } ?>
</table>
<?php } } ?>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-pane<?= gaktiv('tab-loxone') ?>" id="tab-loxone">
<h2><?= gardena_t('LOX.H') ?></h2>

<div class="sm-step"><b><?= gardena_t('LOX.H_EMPFANG') ?></b><br>
<?= sprintf(gardena_t('LOX.EMPFANG'), (int) $gc['UDPPORT'], gardena_e($gc['MQTT_TOPIC'])) ?>
</div>

<div class="sm-step"><b><?= gardena_t('LOX.H_SENDEN') ?></b><br>
<?= gardena_t('LOX.SENDEN') ?>
<div class="sm-mono">/plugins/<?= $gpl ?>/index.php?action=command<?= $gtokenurl ?>&amp;device=NAME&amp;type=MOWER_CONTROL&amp;cmd=START_SECONDS_TO_OVERRIDE&amp;seconds=3600</div>
<div class="sm-mono">/plugins/<?= $gpl ?>/index.php?action=command<?= $gtokenurl ?>&amp;device=NAME&amp;type=MOWER_CONTROL&amp;cmd=PARK_UNTIL_NEXT_TASK</div>
<div class="sm-mono">/plugins/<?= $gpl ?>/index.php?action=command<?= $gtokenurl ?>&amp;device=NAME&amp;type=VALVE_CONTROL&amp;cmd=START_SECONDS_TO_OVERRIDE&amp;seconds=1800</div>
</div>

<h2><?= gardena_t('LOX.H_VORLAGE') ?></h2>
<div class="sm-hinweis"><?= gardena_t('LOX.H_VORLAGE_TEXT') ?> <?= gardena_t('LOX.H_VORLAGE_TEXT2') ?></div>
<?php if (!is_file($gcachefile)) { ?>
<div class="sm-alert sm-warn"><?= gardena_t('LOX.VORLAGE_KEIN_ABBILD') ?></div>
<?php } else { ?>
<div class="sm-knopfreihe" style="margin-bottom:14px;">
<form action="index.php" method="post" style="margin:0;">
  <input data-role="none" type="hidden" name="vorlage" value="1">
  <button data-role="none" class="sm-btn" type="submit" style="background:#546e7a;"><?= gardena_t('LOX.K_VORLAGE') ?></button>
</form>
<form action="index.php" method="post" style="margin:0;">
  <input data-role="none" type="hidden" name="vorlage" value="vo">
  <button data-role="none" class="sm-btn" type="submit" style="background:#546e7a;"><?= gardena_t('LOX.K_VORLAGE_VO') ?></button>
</form>
</div>
<?php } ?>

<div class="sm-step"><b><?= gardena_t('LOX.H_BEFEHLE') ?></b>
<table class="sm-tbl">
<tr><th><?= gardena_t('LOX.SP_TYP') ?></th><th><?= gardena_t('LOX.SP_CMD') ?></th><th><?= gardena_t('LOX.SP_BEDEUTUNG') ?></th></tr>
<tr><td rowspan="4"><span class="sm-mono">MOWER_CONTROL</span></td><td><span class="sm-mono">START_SECONDS_TO_OVERRIDE</span></td><td><?= gardena_t('LOX.C_MOWER_START') ?></td></tr>
<tr><td><span class="sm-mono">START_DONT_OVERRIDE</span></td><td><?= gardena_t('LOX.C_MOWER_DONT') ?></td></tr>
<tr><td><span class="sm-mono">PARK_UNTIL_NEXT_TASK</span></td><td><?= gardena_t('LOX.C_MOWER_PARK_NEXT') ?></td></tr>
<tr><td><span class="sm-mono">PARK_UNTIL_FURTHER_NOTICE</span></td><td><?= gardena_t('LOX.C_MOWER_PARK_FURTHER') ?></td></tr>
<tr><td rowspan="3"><span class="sm-mono">VALVE_CONTROL</span></td><td><span class="sm-mono">START_SECONDS_TO_OVERRIDE</span></td><td><?= gardena_t('LOX.C_VALVE_START') ?></td></tr>
<tr><td><span class="sm-mono">STOP_UNTIL_NEXT_TASK</span></td><td><?= gardena_t('LOX.C_VALVE_STOP') ?></td></tr>
<tr><td><span class="sm-mono">PAUSE</span> / <span class="sm-mono">UNPAUSE</span></td><td><?= gardena_t('LOX.C_VALVE_PAUSE') ?></td></tr>
<tr><td><span class="sm-mono">POWER_SOCKET_CONTROL</span></td><td><span class="sm-mono">START_SECONDS_TO_OVERRIDE</span> / <span class="sm-mono">START_OVERRIDE</span></td><td><?= gardena_t('LOX.C_SOCKET_START') ?></td></tr>
</table>
<div class="sm-small"><?= gardena_t('LOX.SEKUNDEN_HINWEIS') ?></div>
</div>

<div class="sm-step"><b><?= gardena_t('LOX.H_SICHER') ?></b><br>
<?= gardena_t('LOX.SICHER') ?>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-pane<?= gaktiv('tab-test') ?>" id="tab-test">
<h2><?= gardena_t('REITER.TEST') ?></h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= gardena_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= gardena_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= gardena_t('LEGENDE.AKTION') ?></span>
</div>

<h3 class="sm-h3"><?= gardena_t('TEST.H_ANSEHEN') ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen" href="/plugins/<?= $gpl ?>/index.php?action=list<?= $gtokenurl ?>" target="_blank"><?= gardena_t('TEST.K_LISTE') ?></a>
<a class="sm-btn sm-b-lesen" href="/plugins/<?= $gpl ?>/index.php" target="_blank"><?= gardena_t('TEST.K_UEBERSICHT') ?></a>
</div>

<h3 class="sm-h3"><?= gardena_t('TEST.H_TECHNIK') ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik" href="/admin/system/logmanager.cgi?package=<?= $gpl ?>" target="_blank"><?= gardena_t('TEST.K_LOGS') ?></a>
</div>

<h3 class="sm-h3"><?= gardena_t('TEST.H_AKTION') ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-aktion" href="/plugins/<?= $gpl ?>/index.php?action=refresh<?= $gtokenurl ?>" target="_blank"><?= gardena_t('TEST.K_ABRUF') ?></a>
</div>
<div class="sm-small"><?= gardena_t('TEST.ABRUF_HINWEIS') ?></div>
</div>

<!-- ================= Reiter: Protokoll ================= -->
<div class="sm-pane<?= gaktiv('tab-log') ?>" id="tab-log">
<h2><?= gardena_t('LOG.H') ?></h2>
<div class="sm-small"><?= gardena_t('LOG.TEXT') ?><br><?= gardena_t('LOG.DATEI') ?> <span class="sm-mono"><?= gardena_e($glogfile) ?></span></div>
<?php if ($gloglines) { ?>
<div class="sm-log"><?= gardena_e(implode("\n", $gloglines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?= gardena_t('LOG.LEER') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= gardena_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <?php /* Orange, nicht rot: Rot ist im Hausstandard nicht vorgesehen -
       es liest sich als Warnung vor einer Gefahr, und ein geleertes
       Protokoll ist keine. Die Farbe sagt nur: dieser Knopf veraendert etwas. */ ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= gardena_t('LOG.LEEREN') ?></button>
</form>
</div>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-pane').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
        document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
    }
    tabs.forEach(function (t) {
        t.addEventListener('click', function (ev) {
            // Ohne JavaScript folgt der Browser dem href, und der Server
            // liefert den richtigen Reiter. Mit JavaScript geht es schneller
            // ohne Neuladen - deshalb hier den Verweis abfangen.
            ev.preventDefault();
            activate(t.dataset.pane);
        });
    });
    activate(<?= json_encode($g_tab) ?>);
})();
</script>
<?php
LBWeb::lbfooter();
