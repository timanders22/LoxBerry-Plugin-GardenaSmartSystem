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

/*
 * Ein eigenes Protokollobjekt fuer die Oberflaeche.
 *
 * Bis 1.2.0 fehlte es. Die Folge war still und vollstaendig: diese Datei
 * bindet loxberry_log.php ein, also gibt es LOGINF/LOGERR - gardena_log()
 * nimmt deshalb immer den SDK-Weg und erreicht seinen Ersatzschreiber nie.
 * Ohne Protokollobjekt hatte das SDK aber nichts, wohin es schreiben konnte.
 * Die Datei gardena_ui.log, die der Reiter Logdateien anzeigt, wurde damit
 * NIE beschrieben - ausser durch "Protokoll geleert". Der Reiter sagte
 * dauerhaft "Noch keine Eintraege vorhanden", waehrend die Hilfe versprach,
 * dort stehe die Antwort der Wolke im Wortlaut.
 *
 * Jetzt landen die Meldungen der Oberflaeche im Protokoll des Plugins, also
 * dort, wo auch der Dienst schreibt und wo der Log-Manager sie zeigt.
 */
if (class_exists('LBLog', false) && method_exists('LBLog', 'newLog')) {
    $glog = LBLog::newLog(array('name' => 'GardenaUI', 'package' => $lbpplugindir,
                                'logdir' => $lbplogdir, 'addtime' => 1));
}

/*
 * Der Ordnername des Plugins - EINE Quelle fuer alles, was eine Adresse
 * baut: die angezeigten Adressen, die Knoepfe im Reiter Test und die
 * Vorlage der Steuerbefehle.
 *
 * $lbpplugindir setzt das SDK. Fehlt es wider Erwarten, wird der Name aus
 * dem Konfigurationspfad abgeleitet - den kennt das SDK ebenfalls, und er
 * traegt denselben Ordnernamen. Erst danach die Umgebungsvariable. Kein
 * fester Name als Rueckfall: LoxBerry haengt bei einer Namenskollision eine
 * Nummer an (gardenasmartsystem01), ein geratener Name waere dann falsch.
 */
$gordner = (string) $lbpplugindir;
if ($gordner === '') { $gordner = basename((string) $lbpconfigdir); }
if ($gordner === '' || $gordner === '.') { $gordner = (string) getenv('LBPPLUGINDIR'); }

$gconfigfile = $lbpconfigdir . '/gardena.cfg';
$gcachefile = $lbpconfigdir . '/devices_cache.json';
$glogfile = $lbplogdir . '/gardena_ui.log';

/* Die Kurzformen gt()/ge() sind seit 1.1.6 aufgeloest (Sprachmechanik-
 * Vereinheitlichung 13.08.2026): ueberall stehen die vollen Namen
 * gardena_t() und gardena_e() - BEIDE seit 1.2.6 aus bin/functions.inc.php.
 * Der Escape-Helfer stand bis dahin hier, und damit konnte keine
 * Bibliotheksfunktion ihn benutzen: gardena_sicherung_lesen() maskierte
 * deshalb selbst, die Oberflaeche ein zweites Mal, und der Anwender las
 * "A&amp;B" statt "A&B". Die Hausform sieht ihn in der Bibliothek vor. */

/* ==================================================================
 * Selbstpruefung (Reiter Test)
 *
 * Beantwortet OHNE Loxone und ohne etwas auszuloesen: traegt die
 * Einrichtung? Je Zeile eine Frage mit Haekchen, Hinweis oder Kreuz.
 *
 * Zwei Regeln, an denen sich diese Seite messen lassen muss:
 *
 *   Die Ursache steht VOR der Wirkung. "Ist die Erweiterung sockets da?"
 *   kommt vor "Sind Werte angekommen?", weil die erste die zweite erklaert.
 *   Wer die Reihenfolge umdreht, schickt den Leser in die falsche Ecke.
 *
 *   Ein Hinweis ist fuer "geht mich nichts an" da, nicht fuer "ich weiss es
 *   nicht". Eine unklare Lage ist ein Kreuz. Die Zusammenfassung darf nicht
 *   besser aussehen als ihr schlechtester Punkt - sonst beruhigt sie genau
 *   dort, wo jemand hinsieht, weil etwas nicht stimmt.
 * ================================================================== */

/* ==================================================================
 * Schutz gegen fremde Absender (Muster aus dem Abfahrts-Assistenten)
 *
 * Der angemeldete Bereich ist durch die Anmeldung des LoxBerry geschuetzt -
 * gegen eine fremde Seite schuetzt das nicht: der Browser schickt die
 * hinterlegten Zugangsdaten bei einer Anfrage von aussen mit. Ein
 * untergeschobenes Formular haette damit "Neues Token erzeugen" ausloesen
 * koennen; danach beantwortet der Endpunkt jeden Virtuellen Ausgang in
 * Loxone mit 403 - und ein Virtueller Ausgang wertet die Antwort nicht aus,
 * der Ausfall bliebe still.
 *
 * Der Wert wird aus dem Zugriffstoken abgeleitet. Eine fremde Seite kann ihn
 * wegen der Gleiche-Herkunft-Regel nicht lesen und deshalb nicht mitschicken.
 * Geprueft wird EINMAL zentral, nicht in jedem Handler - einen Handler kann
 * man vergessen.
 * ================================================================== */
function gardena_formtoken($cfg)
{
    return hash_hmac('sha256', 'formular-v1', (string) $cfg['TOKEN']);
}

function gardena_formtoken_ok($cfg)
{
    $ist = isset($_POST['formtoken']) && is_string($_POST['formtoken'])
        ? $_POST['formtoken'] : '';
    // Fail closed: ohne hinterlegtes Token gibt es nichts zu vergleichen,
    // und hash_equals('', '') waere wahr.
    if ($ist === '' || (string) $cfg['TOKEN'] === '') { return false; }
    return hash_equals(gardena_formtoken($cfg), $ist);
}

/**
 * Steht das MQTT-Gateway auf Autostart?
 * true = ja, false = nein, null = nicht feststellbar.
 *
 * Stand bis 1.1.9 mitten in der Markup-Zeile des MQTT-Reiters. Damit war sie
 * erst ab dort definiert - die Selbstpruefung im Reiter Test haette sich
 * darauf verlassen muessen, dass die Reiter in einer bestimmten Reihenfolge
 * gerendert werden. Der Schluessel heisst Gatewayautostart, nicht
 * Mqtt.Autostart.
 */
function gard_hs_autostart()
{
    $h = getenv('LBHOMEDIR') ?: '/opt/loxberry';
    $g = $h . '/config/system/general.json';
    if (!is_file($g)) { return null; }
    $j = json_decode((string) @file_get_contents($g), true);
    if (!is_array($j) || !isset($j['Mqtt'])) { return null; }
    return !empty($j['Mqtt']['Gatewayautostart']);
}

/** $stand: 1 = Haekchen, 0 = Hinweis, -1 = Kreuz */
function gardena_pruefzeile($stand, $frage, $antwort)
{
    return array('stand' => (int) $stand, 'frage' => (string) $frage, 'antwort' => (string) $antwort);
}

/** Lesbare Altersangabe in Sekunden -> "vor 3 Minuten" */
function gardena_alter_text($sekunden)
{
    $sekunden = (int) $sekunden;
    if ($sekunden < 90) { return sprintf(gardena_t('TEST.ALTER_S'), $sekunden); }
    if ($sekunden < 5400) { return sprintf(gardena_t('TEST.ALTER_M'), (int) round($sekunden / 60)); }
    return sprintf(gardena_t('TEST.ALTER_H'), (int) round($sekunden / 3600));
}

/**
 * Alle Pruefzeilen. Liest nur - kein API-Aufruf, kein Versand, kein
 * Schreiben. Rueckgabe: array('zeilen' => …, 'gut' => n, 'gesamt' => m,
 * 'kreuze' => k)
 */
function gardena_selbstpruefung($cfg, $cfgdatei, $cachedatei, $cache, $bindir, $cfgdir, $ordner)
{
    $z = array();

    // ---- Voraussetzungen (die Ursachen) ----
    $z[] = gardena_pruefzeile(
        ($cfg['ENABLED'] == '1') ? 1 : -1,
        gardena_t('TEST.F_AKTIV'),
        ($cfg['ENABLED'] == '1') ? gardena_t('TEST.A_AKTIV_JA') : gardena_t('TEST.A_AKTIV_NEIN'));

    $zugang = ($cfg['CLIENT_ID'] !== '' && $cfg['CLIENT_SECRET'] !== '');
    $z[] = gardena_pruefzeile($zugang ? 1 : -1, gardena_t('TEST.F_ZUGANG'),
        $zugang ? gardena_t('TEST.A_ZUGANG_JA') : gardena_t('TEST.A_ZUGANG_NEIN'));

    $z[] = gardena_pruefzeile(($cfg['TOKEN'] !== '') ? 1 : -1, gardena_t('TEST.F_TOKEN'),
        ($cfg['TOKEN'] !== '') ? gardena_t('TEST.A_TOKEN_JA') : gardena_t('TEST.A_TOKEN_NEIN'));

    // Ohne sockets geht WEDER UDP NOCH MQTT - das Gateway wird ebenfalls
    // ueber UDP beschickt. Deshalb steht die Zeile vor allem, was sendet.
    $sock = gardena_udp_moeglich();
    $z[] = gardena_pruefzeile($sock ? 1 : -1, gardena_t('TEST.F_SOCKETS'),
        $sock ? gardena_t('TEST.A_SOCKETS_JA') : gardena_t('TEST.A_SOCKETS_NEIN'));

    // cURL ist ein Hinweis, kein Kreuz: es gibt den Ersatzweg ueber Streams.
    $z[] = gardena_pruefzeile(gardena::hasCurl() ? 1 : 0, gardena_t('TEST.F_CURL'),
        gardena::hasCurl() ? gardena_t('TEST.A_CURL_JA') : gardena_t('TEST.A_CURL_NEIN'));

    $z[] = gardena_pruefzeile(is_file($bindir . '/gardenaMain.php') ? 1 : -1,
        gardena_t('TEST.F_DIENST'),
        is_file($bindir . '/gardenaMain.php') ? gardena_t('TEST.A_DIENST_JA')
                                              : gardena_t('TEST.A_DIENST_NEIN'));

    // ---- Die Wege ----
    $udp = ($cfg['UDP_ENABLED'] != '0');
    $mqtt = ($cfg['MQTT_ENABLED'] == '1');
    $eigene = gardena_cfg_eigene_schluessel($cfgdatei);
    $quelle = array();
    foreach (array('UDP_ENABLED', 'MQTT_ENABLED') as $s) {
        if (!in_array($s, $eigene, true)) { $quelle[] = $s; }
    }
    $wege = array();
    if ($udp) { $wege[] = 'UDP'; }
    if ($mqtt) { $wege[] = 'MQTT'; }
    $wegtext = $wege ? implode(' + ', $wege) : gardena_t('TEST.A_WEGE_KEINER');
    if ($quelle) { $wegtext .= ' — ' . sprintf(gardena_t('TEST.A_WEGE_VORGABE'), implode(', ', $quelle)); }
    $z[] = gardena_pruefzeile($wege ? 1 : -1, gardena_t('TEST.F_WEGE'), $wegtext);

    // ---- MQTT-Gateway ----
    if ($mqtt) {
        $auto = gard_hs_autostart();
        $port = gardena_mqtt_udpport();
        if ($auto === false) {
            $z[] = gardena_pruefzeile(-1, gardena_t('TEST.F_GATEWAY'), gardena_t('TEST.A_GATEWAY_AUS'));
        } elseif (!$port) {
            $z[] = gardena_pruefzeile(-1, gardena_t('TEST.F_GATEWAY'), gardena_t('TEST.A_GATEWAY_KEINPORT'));
        } elseif ($auto === null) {
            // Unklar ist ein Kreuz, kein Hinweis.
            $z[] = gardena_pruefzeile(-1, gardena_t('TEST.F_GATEWAY'), gardena_t('TEST.A_GATEWAY_UNBEKANNT'));
        } else {
            $z[] = gardena_pruefzeile(1, gardena_t('TEST.F_GATEWAY'),
                sprintf(gardena_t('TEST.A_GATEWAY_OK'), (int) $port));
        }
    } else {
        $z[] = gardena_pruefzeile(0, gardena_t('TEST.F_GATEWAY'), gardena_t('TEST.A_GATEWAY_UNNOETIG'));
    }

    // ---- Takt und Ruecknahme ----
    $st = gardena_status_lesen($cfgdir);
    if (!empty($st['sperre_bis']) && time() < (int) $st['sperre_bis']) {
        // Das ist kein Fehler des Plugins, aber der Anwender bekommt bis
        // dahin keine neuen Werte - und genau danach sucht er.
        $z[] = gardena_pruefzeile(-1, gardena_t('TEST.F_TAKT'),
            sprintf(gardena_t('TEST.A_TAKT_SPERRE'), date('H:i', (int) $st['sperre_bis'])));
    } else {
        $z[] = gardena_pruefzeile(1, gardena_t('TEST.F_TAKT'),
            sprintf(gardena_t('TEST.A_TAKT'), gardena_intervall($cfg)));
    }

    // ---- Was wirklich passiert ist (die Wirkungen) ----
    if (empty($st['letzter_erfolg'])) {
        $z[] = gardena_pruefzeile(-1, gardena_t('TEST.F_ERFOLG'), gardena_t('TEST.A_ERFOLG_NIE'));
    } else {
        $alter = time() - (int) $st['letzter_erfolg'];
        // Der Takt ist fuenf Minuten. Ueber zwanzig Minuten sind vier
        // verpasste Durchlaeufe - das ist kein Ausreisser mehr.
        $z[] = gardena_pruefzeile($alter > 1200 ? -1 : 1, gardena_t('TEST.F_ERFOLG'),
            sprintf($alter > 1200 ? gardena_t('TEST.A_ERFOLG_ALT') : gardena_t('TEST.A_ERFOLG_OK'),
                    gardena_alter_text($alter)));
    }

    if (!empty($st['verloren'])) {
        $z[] = gardena_pruefzeile(-1, gardena_t('TEST.F_ZUSTELLUNG'),
            sprintf(gardena_t('TEST.A_ZUSTELLUNG_FEHL'), (int) $st['verloren'], (int) $st['werte']));
    } elseif (empty($st['werte'])) {
        $z[] = gardena_pruefzeile(-1, gardena_t('TEST.F_ZUSTELLUNG'), gardena_t('TEST.A_ZUSTELLUNG_NICHTS'));
    } else {
        $z[] = gardena_pruefzeile(1, gardena_t('TEST.F_ZUSTELLUNG'),
            sprintf(gardena_t('TEST.A_ZUSTELLUNG_OK'), (int) $st['werte']));
    }

    // Kein Kreuz: dass die Wolke ein Attribut ohne Wert liefert, ist nicht
    // der Fehler des Plugins. Es erklaert aber, warum ein virtueller Eingang
    // in Loxone nie einen Wert bekommt - und genau danach sucht man sonst
    // lange. Seit 1.2.0 wird so ein Attribut nicht mehr als leere Zeile
    // gesendet, die Loxone als 0 lesen wuerde.
    $z[] = gardena_pruefzeile(empty($st['ohne_inhalt']) ? 1 : 0, gardena_t('TEST.F_LEER'),
        empty($st['ohne_inhalt']) ? gardena_t('TEST.A_LEER_KEINE')
                                  : sprintf(gardena_t('TEST.A_LEER'), (int) $st['ohne_inhalt']));

    if (!empty($st['fehler'])) {
        $z[] = gardena_pruefzeile(-1, gardena_t('TEST.F_FEHLER'), (string) $st['fehler']);
    }

    // ---- Geraete und Namen ----
    $namen = array();
    $anzahl = 0;
    if (!empty($cache['locations']) && is_array($cache['locations'])) {
        foreach ($cache['locations'] as $loc) {
            if (empty($loc['devices']) || !is_array($loc['devices'])) { continue; }
            foreach ($loc['devices'] as $devid => $dev) {
                $anzahl++;
                $n = (is_array($dev) && isset($dev['name']) && $dev['name'] !== '')
                    ? (string) $dev['name'] : (string) $devid;
                if (gardena_name_umgeschrieben($n)) {
                    $namen[] = $n . ' → ' . gardena_mqtt_thema($n);
                }
            }
        }
    }
    $z[] = gardena_pruefzeile($anzahl > 0 ? 1 : -1, gardena_t('TEST.F_GERAETE'),
        $anzahl > 0 ? sprintf(gardena_t('TEST.A_GERAETE'), $anzahl) : gardena_t('TEST.A_GERAETE_KEINE'));

    // Kein Kreuz: umgeschriebene Namen sind zulaessig und funktionieren.
    // Der Anwender soll nur wissen, wonach er im Broker sucht.
    $z[] = gardena_pruefzeile($namen ? 0 : 1, gardena_t('TEST.F_NAMEN'),
        $namen ? implode(' · ', $namen) : gardena_t('TEST.A_NAMEN_OK'));

    // ---- Die Loxone-Vorlagen ----
    if (!is_file($cachedatei)) {
        $z[] = gardena_pruefzeile(0, gardena_t('TEST.F_VORLAGE'), gardena_t('TEST.A_VORLAGE_KEIN_ABBILD'));
    } elseif (!function_exists('simplexml_load_string')) {
        $z[] = gardena_pruefzeile(-1, gardena_t('TEST.F_VORLAGE'), gardena_t('TEST.A_VORLAGE_KEIN_XML'));
    } else {
        // Die erzeugte Datei wirklich durch den Zerleger schicken. Der
        // Anwender merkt eine kaputte Vorlage sonst erst in Loxone Config -
        // und sucht den Fehler dann bei sich.
        $kaputt = array();
        $vorher = libxml_use_internal_errors(true);
        $ausg = gardena_ausgenommen($cfg);
        list(, $xa) = gardena_vorlage($cachedatei,
            $cfg['MQTT_TOPIC'] !== '' ? $cfg['MQTT_TOPIC'] : 'gardena', $ausg);
        if (simplexml_load_string($xa) === false) { $kaputt[] = 'VI_gardena.xml'; }
        list(, $xb) = gardena_vorlage_vo($cachedatei, $cfg['TOKEN'], $ordner);
        if (simplexml_load_string($xb) === false) { $kaputt[] = 'VQ_gardena_steuern.xml'; }
        list(, $xc) = gardena_vorlage_udp($cachedatei,
            !empty($cfg['UDPPORT']) ? (int) $cfg['UDPPORT'] : 5005, '', $ausg);
        if (simplexml_load_string($xc) === false) { $kaputt[] = 'VI_gardena_udp.xml'; }
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);
        $z[] = gardena_pruefzeile($kaputt ? -1 : 1, gardena_t('TEST.F_VORLAGE'),
            $kaputt ? sprintf(gardena_t('TEST.A_VORLAGE_FEHL'), implode(', ', $kaputt))
                    : gardena_t('TEST.A_VORLAGE_OK'));
    }

    /* ---- Bauen Vorlage und Sender die UDP-Zeile GLEICH? ----
     *
     * Gemessen am Erzeugnis, nicht am Quelltext: fuer jedes Geraet des
     * Abbilds wird die Zeile gebildet, die der Dienst schicken WUERDE, und
     * geprueft, ob der Suchtext der Vorlage darin vorkommt. Bis 1.2.5 bauten
     * beide Seiten sie getrennt zusammen, und bei einem Geraetenamen mit zwei
     * Leerzeichen traf der Suchtext nie - der Eingang blieb auf DefVal="0".
     */
    $u_ges = 0;
    $u_fehl = array();
    if (is_array($cache) && !empty($cache['locations'])) {
        foreach ($cache['locations'] as $u_loc) {
            if (empty($u_loc['devices']) || !is_array($u_loc['devices'])) { continue; }
            foreach ($u_loc['devices'] as $u_id => $u_dev) {
                $u_name = isset($u_dev['name']) && $u_dev['name'] !== '' ? $u_dev['name'] : $u_id;
                if (empty($u_dev['services']) || !is_array($u_dev['services'])) { continue; }
                foreach ($u_dev['services'] as $u_typ => $u_attrs) {
                    if (!is_array($u_attrs)) { continue; }
                    foreach ($u_attrs as $u_a => $u_v) {
                        if ($u_a === '_service_id' || !is_array($u_v)
                            || !array_key_exists('value', $u_v)) { continue; }
                        if (!is_numeric($u_v['value'])) { continue; }
                        $u_ges++;
                        $u_gesendet = gardena_udp_zeile($u_typ, $u_name, $u_a, $u_v['value']);
                        $u_muster = gardena_udp_zeile($u_typ, $u_name, $u_a);
                        $u_suchteil = substr($u_muster, 0, strrpos($u_muster, ':') + 1);
                        if (strpos($u_gesendet, $u_suchteil) !== 0) {
                            $u_fehl[] = $u_name . '.' . $u_a;
                        }
                    }
                }
            }
        }
    }
    if ($u_ges === 0) {
        $z[] = gardena_pruefzeile(0, gardena_t('TEST.F_UDPNAME'), gardena_t('TEST.A_UDPNAME_LEER'));
    } else {
        $z[] = gardena_pruefzeile($u_fehl ? -1 : 1, gardena_t('TEST.F_UDPNAME'),
            $u_fehl ? sprintf(gardena_t('TEST.A_UDPNAME_FEHL'), count($u_fehl), $u_ges,
                              implode(', ', array_slice($u_fehl, 0, 5)))
                    : sprintf(gardena_t('TEST.A_UDPNAME_OK'), $u_ges));
    }

    /* ---- Kennt der Endpunkt jeden Befehl, den die Vorlage erzeugt? ----
     * Gemessen an der ERZEUGTEN Vorlage der Virtuellen Ausgaenge, nicht an
     * der Tabelle daneben. Bis 1.2.5 pruefte der Endpunkt type und cmd gegen
     * zwei getrennte flache Listen und nie gegeneinander.
     */
    if (!is_file($cachedatei)) {
        // Die Zeile darf nicht VERSCHWINDEN, nur weil ihre Menge leer ist -
        // eine fehlende Zeile sieht aus wie eine bestandene.
        $z[] = gardena_pruefzeile(0, gardena_t('TEST.F_BEFEHLE'),
                                  gardena_t('TEST.A_BEFEHLE_KEIN_ABBILD'));
    } else {
        list(, $b_xml) = gardena_vorlage_vo($cachedatei, $cfg['TOKEN'], $ordner);
        $b_erlaubt = gardena_befehle();
        $b_treffer = array();
        /* Im XML steht '&amp;', nicht '&' - die Adresse laeuft durch
         * htmlspecialchars(). Beim ersten Lauf dieser Zeile traf das Muster
         * deshalb NICHTS und meldete "kein Geraet zum Schalten erkannt": eine
         * gruene Zeile, die nichts gemessen hatte. Deshalb steht unten auch
         * die Zahl der gefundenen Stellen im Text - eine Null faellt dann auf. */
        preg_match_all('/type=([A-Z_]+)(?:&amp;|&)cmd=([A-Z_]+)/', $b_xml, $b_m, PREG_SET_ORDER);
        foreach ($b_m as $b_t) {
            if (!isset($b_erlaubt[$b_t[1]]) || !in_array($b_t[2], $b_erlaubt[$b_t[1]], true)) {
                $b_treffer[] = $b_t[1] . '/' . $b_t[2];
            }
        }
        if (!$b_m) {
            $z[] = gardena_pruefzeile(0, gardena_t('TEST.F_BEFEHLE'), gardena_t('TEST.A_BEFEHLE_LEER'));
        } else {
            $z[] = gardena_pruefzeile($b_treffer ? -1 : 1, gardena_t('TEST.F_BEFEHLE'),
                $b_treffer ? sprintf(gardena_t('TEST.A_BEFEHLE_FEHL'),
                                     implode(', ', array_unique($b_treffer)))
                           : sprintf(gardena_t('TEST.A_BEFEHLE_OK'), count($b_m)));
        }
    }

    /* ---- Ist jede benutzte CSS-Klasse auch definiert? ----
     * Die Klasse sm-warnung stand seit jeher im HTML und in keiner Regel des
     * Stilblocks - der Warnhinweis an der Sicherungsdatei war dadurch
     * gewoehnlicher Fliesstext. Kein Werkzeug der Pruefkette sah es, weil
     * ein Tippfehler in einem Selektor keine Fehlermeldung erzeugt, sondern
     * gar nichts. Diese Zeile liest ALLE Dateien der Oberflaeche.
     */
    $c_quelle = '';
    // array_unique: __FILE__ und __DIR__/index.php sind heute dieselbe
    // Datei. Sobald die Oberflaeche auf mehrere Dateien waechst (der
    // Normalfall, sobald die Selbstpruefung eine eigene bekommt), zaehlt
    // diese Zeile ohne Aenderung mit - eine Zahl, die nur die halbe
    // Grundmenge kennt, ist ein Nachweis, der nicht gilt.
    foreach (array_unique(array(__FILE__, __DIR__ . '/index.php')) as $c_datei) {
        if (is_file($c_datei)) { $c_quelle .= (string) @file_get_contents($c_datei); }
    }
    $c_def = array();
    if (preg_match_all('#<style[^>]*>(.*?)</style>#s', $c_quelle, $c_s)) {
        preg_match_all('/\.(sm-[A-Za-z0-9_-]+)/', implode('', $c_s[1]), $c_d);
        $c_def = array_unique($c_d[1]);
    }
    $c_benutzt = array();
    if (preg_match_all('/class\s*=\s*"([^"]*)"/', $c_quelle, $c_b)) {
        foreach ($c_b[1] as $c_wert) {
            // PHP-Bloecke im Attributwert ausblenden: was dort steht, ist
            // zusammengesetzt und von hier aus nicht messbar.
            foreach (explode(' ', preg_replace('/<\?.*?\?>/s', ' ', $c_wert)) as $c_k) {
                if (strpos($c_k, 'sm-') === 0) { $c_benutzt[$c_k] = true; }
            }
        }
    }
    $c_fehlt = array_diff(array_keys($c_benutzt), $c_def);
    if (!$c_def || !$c_benutzt) {
        $z[] = gardena_pruefzeile(0, gardena_t('TEST.F_CSS'), gardena_t('TEST.A_CSS_LEER'));
    } else {
        $z[] = gardena_pruefzeile($c_fehlt ? -1 : 1, gardena_t('TEST.F_CSS'),
            $c_fehlt ? sprintf(gardena_t('TEST.A_CSS_FEHL'), implode(', ', $c_fehlt))
                     : sprintf(gardena_t('TEST.A_CSS_OK'), count($c_benutzt), count($c_def)));
    }

    /* ---- Ist die Konfiguration heil? Jeder Zustand bekommt seinen Satz. ---- */
    $h_z = gardena_cfg_zustand($cfgdatei);
    $z[] = gardena_pruefzeile($h_z === 'unlesbar' ? -1 : ($h_z === 'neu' ? 0 : 1),
        gardena_t('TEST.F_CFG_HEIL'),
        gardena_t('TEST.A_CFG_' . strtoupper($h_z)));

    /* ---- Konfiguration vollstaendig? Zwei Zahlen, im Fehlerfall die Namen. ---- */
    $k_soll = array_keys(gardena_vorgaben());
    $k_da = gardena_cfg_eigene_schluessel($cfgdatei);
    $k_fehlt = array_diff($k_soll, $k_da);
    if (!$k_da) {
        $z[] = gardena_pruefzeile(0, gardena_t('TEST.F_VOLLST'), gardena_t('TEST.A_VOLLST_LEER'));
    } else {
        $z[] = gardena_pruefzeile($k_fehlt ? -1 : 1, gardena_t('TEST.F_VOLLST'),
            $k_fehlt ? sprintf(gardena_t('TEST.A_VOLLST_FEHL'),
                               count($k_soll) - count($k_fehlt), count($k_soll),
                               implode(', ', $k_fehlt))
                     : sprintf(gardena_t('TEST.A_VOLLST_OK'), count($k_soll), count($k_soll)));
    }

    $gut = 0;
    $kreuze = 0;
    foreach ($z as $zeile) {
        if ($zeile['stand'] === 1) { $gut++; }
        if ($zeile['stand'] === -1) { $kreuze++; }
    }
    return array('zeilen' => $z, 'gut' => $gut, 'gesamt' => count($z), 'kreuze' => $kreuze);
}

/* ==================================================================
 * Reiter
 *
 * Wer einen Reiter hinzufuegt, muss DREI Stellen mitziehen: die
 * Reiterleiste, den Bereich (sm-seite mit gleicher id) und diese
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
function gardena_vorlage($cachefile, $topic, $ausgenommen = array())
{
    $cache = json_decode((string) @file_get_contents($cachefile), true);
    $crlf = "\r\n";
    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" Title="Gardena smart system" Comment="Erzeugt vom LoxBerry-Plugin GardenaSmartSystem (' . date('d.m.Y') . '). Werte kommen vom MQTT-Gateway - Abo ' . htmlspecialchars($topic, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '/# noetig." Address="http://localhost" PollingTime="604800">' . $crlf;
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
                /* Ausgenommene Geraete gehoeren NICHT in die Vorlage.
                 * Bis 1.2.5 legte sie fuer sie virtuelle Eingaenge an, die der
                 * Dienst nie bedient - sie blieben auf DefVal="0" stehen, und
                 * in Loxone sieht das aus wie ein gemessener Wert. (Die
                 * Vorlage der Virtuellen AUSGAENGE behaelt sie: die Ausnahme
                 * betrifft nur das Senden, geschaltet wird weiterhin.) */
                if (in_array((string) $devName, $ausgenommen, true)
                    || in_array((string) $devId, $ausgenommen, true)) { continue; }
                if (empty($dev['services']) || !is_array($dev['services'])) { continue; }
                foreach ($dev['services'] as $type => $attrs) {
                    if (!is_array($attrs)) { continue; }
                    foreach ($attrs as $attrName => $attr) {
                        if ($attrName === '_service_id' || !is_array($attr) || !array_key_exists('value', $attr)) { continue; }
                        $value = $attr['value'];
                        if (is_bool($value)) { $value = $value ? 1 : 0; }
                        if (!is_numeric($value)) { continue; } // Textwerte legt das Gateway selbst an
                        $g = isset($grenzen[$attrName]) ? $grenzen[$attrName] : array('-1000000', '1000000', '<v.1>');
                        /* Der Titel MUSS derselbe Name sein, unter dem das
                         * Gateway den Eingang anlegt - also aus dem Thema,
                         * das wirklich gesendet wird, nicht aus dem rohen
                         * Geraetenamen. Bis 1.1.9 stand hier
                         *
                         *     str_replace('/', '_', $topic . '/' . $devName . ...)
                         *
                         * auf dem ROHEN Namen. Gesendet wurde aber ein ueber
                         * gardena_mqtt_thema() umgeschriebenes Thema. Sobald
                         * ein Geraetename ein Leerzeichen, einen Umlaut oder
                         * ein Sonderzeichen trug - der Normalfall -, legte
                         * die Vorlage Eingaenge an, die nie einen Wert
                         * bekamen und auf DefVal="0" stehenblieben.
                         * gardena_wert_eingang() ist jetzt die gemeinsame
                         * Quelle mit dem Sender in gardenaMain.php. */
                        $titel = gardena_wert_eingang($topic, $devName, $type, $attrName);
                        $o .= "\t" . '<VirtualInHttpCmd Title="' . htmlspecialchars($titel, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '" ';
                        $o .= 'Comment="' . htmlspecialchars($attrName . ' ' . $devName . ' (' . $type . ')', ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '" Check=" " ';
                        $o .= 'Signed="true" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="' . $g[0] . '" MaxVal="' . $g[1] . '" Unit="' . htmlspecialchars($g[2], ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '" HintText=""/>' . $crlf;
                    }
                }
            }
        }
    }
    /* Die Werte des Lebenszeichens gehen bei JEDEM Lauf hinaus und tragen
     * die Ausfallerkennung aus der Baustein-Liste. Bis 1.2.5 kamen sie in
     * keiner Vorlage vor - ausgerechnet die Eingaenge, ohne die ein toter
     * Dienst unbemerkt bleibt, musste der Anwender von Hand anlegen und ihre
     * Namen abtippen. 'fehler' bleibt draussen: ein Textwert bekommt keinen
     * virtuellen Eingang, den legt das Gateway beim ersten Empfang selbst an. */
    foreach (array('ok'          => array('0', '1', '<v.0>'),
                   'zeitstempel' => array('0', '2000000000', '<v.0>'),
                   'werte'       => array('0', '100000', '<v.0>')) as $gl_name => $gl_g) {
        $gl_titel = gardena_wert_eingang($topic, 'Plugin', 'STATUS', $gl_name);
        $gl_x = function ($t) {
            return htmlspecialchars((string) $t, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
        };
        $o .= "\t" . '<VirtualInHttpCmd Title="' . $gl_x($gl_titel) . '" ';
        $o .= 'Comment="' . $gl_x(gardena_t('LOX.LZ_' . strtoupper($gl_name))) . '" Check=" " ';
        $o .= 'Signed="true" Analog="true" SourceValLow="0" DestValLow="0" '
            . 'SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="' . $gl_g[0]
            . '" MaxVal="' . $gl_g[1] . '" Unit="' . $gl_x($gl_g[2]) . '" HintText=""/>' . $crlf;
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

/* Einmal zentral pruefen, bevor irgendein Handler laeuft. Faellt die
 * Pruefung durch, wird $gpost zurueckgenommen: dann greift KEIN Handler,
 * es wird nichts geschrieben und nichts erzeugt. Gemeldet wird es
 * trotzdem - ein Formular, das wortlos nichts tut, schickt den Anwender
 * auf die Suche nach einem Fehler, den es nicht gibt. */
/*
 * Der eine Fall, der KEIN fremder Absender ist.
 *
 * Ueberschreitet eine hochgeladene Datei post_max_size, liefert PHP ein
 * LEERES $_POST - auch ohne formtoken. Bis 1.2.5 bekam der Anwender dann die
 * Warnung "Kommt die Meldung wiederholt, wurde die Seite von ausserhalb
 * aufgerufen", also einen Angriffsverdacht, wo nur die Datei zu gross war.
 * Die eigens dafuer vorhandene Meldung stand hinter dem Wachposten und war
 * unerreichbar.
 */
$gzuviel = (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 0
            && !$_POST && !$_FILES);
if ($gzuviel) {
    $gfehler[] = gardena_t('EINST.SICH_ZU_GROSS');
    $gpost = false;
} elseif ($gpost && !gardena_formtoken_ok(gardena_cfg_read($gconfigfile))) {
    $gfehler[] = gardena_t('ALLG.FORMTOKEN');
    $gpost = false;
}

// Protokoll leeren
if ($gpost && isset($_POST['clearlog'])) {
    // Rueckgabe pruefen: scheitert das Schreiben (Rechte, Platz), tat der
    // Knopf bis 1.2.5 wortlos nichts, und der Reiter zeigte dieselben Zeilen
    // weiter. Genau das Muster, das der Wachposten daneben vermeiden will.
    if (@file_put_contents($glogfile,
            '[' . date('Y-m-d H:i:s') . "] INF Protokoll geleert (Oberflaeche)\n") === false) {
        $gfehler[] = gardena_t('LOG.LEEREN_FEHLER') . ' ' . gardena_e($glogfile);
    } else {
        $gsaved = true;
    }
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

/* Messerwechsel quittieren: der aktuelle Betriebsstundenstand wird zur neuen
 * Grundlage. Steht kein Stand im Abbild, wird NICHT auf 0 gesetzt - das waere
 * geraten; stattdessen bleibt alles, wie es ist, und es wird gemeldet. */
if ($gpost && isset($_POST['messer_quittieren'])) {
    $gstd = gardena_betriebsstunden(json_decode((string) @file_get_contents($gcachefile), true));
    if ($gstd === null) {
        $gfehler[] = gardena_t('WARTUNG.KEIN_STAND');
    } elseif (gardena_cfg_set($gconfigfile, 'MESSER_BASIS', (int) $gstd)) {
        $gsaved = true;
        gardena_log('INF', 'Messerwechsel quittiert bei ' . (int) $gstd . ' Betriebsstunden.');
    } else {
        $gfehler[] = gardena_t('TOKEN.SCHREIB_FEHLER') . ' ' . $gconfigfile;
    }
    $g_tab = 'tab-settings';
}

if ($gpost && isset($_POST['newtoken'])) {
    $gtokenmsg = gardena_t('TOKEN.NEU_FEHLER');
    $gneu = gtoken_erzeugen($gconfigfile, $gtokenmsg);
    if ($gneu !== '') { $gtokenmsg = gardena_t('TOKEN.NEU_OK'); }
    $g_tab = 'tab-settings';
}

/**
 * Vorlage fuer den UDP-Weg (VirtualInUdp).
 *
 * Bis 1.2.0 gab es sie nicht: das Plugin bot UDP als gleichwertigen Weg an,
 * die Vorlage erzeugte aber nur die MQTT-Fassung. Wer UDP waehlte, legte
 * weiterhin jeden Eingang von Hand an.
 *
 * Aufbau, Attributreihenfolge und Zeilenenden sind gegen die massgebliche
 * Ausfuhr aus Loxone Config vom 12.08.2026 gemessen
 * ("VIU_Wetter Loxberry W4L ..._Test.xml"): Wurzel VirtualInUdp mit
 * HintText, Title, Comment, Address, Port; als erstes Kindelement
 * <Info templateType="1" .../> - templateType 1 ist der UDP-Eingang, 2 der
 * HTTP-Eingang, 3 der Ausgang. Je Kindelement die Reihenfolge Title,
 * Comment, Address, Check, Signed, Analog, SourceValLow, DestValLow,
 * SourceValHigh, DestValHigh, DefVal, MinVal, MaxVal, Unit, HintText.
 *
 * Der Check-Ausdruck muss zu dem passen, was gardena_wert_senden() wirklich
 * schickt: SERVICE.Geraetename.attribut:wert - und zwar mit dem ROHEN
 * Geraetenamen, denn anders als beim MQTT-Thema wird er fuer UDP nicht
 * umgeschrieben.
 */
function gardena_vorlage_udp($cachefile, $port, $eigene_ip, $ausgenommen = array())
{
    $cache = json_decode((string) @file_get_contents($cachefile), true);
    $crlf = "\r\n";
    $x = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8'); };
    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInUdp HintText="" Title="Gardena smart system (UDP)" Comment="Erzeugt vom LoxBerry-Plugin GardenaSmartSystem ('
        . date('d.m.Y') . '). Der LoxBerry sendet die Werte an diesen Port." Address="'
        . $x($eigene_ip) . '" Port="' . (int) $port . '">' . $crlf;
    $o .= "\t" . '<Info templateType="1" minVersion="17010727"/>' . $crlf;
    $grenzen = array(
        'batteryLevel' => array('0', '100', '<v.0> %'),
        'rfLinkLevel'  => array('0', '100', '<v.0> %'),
    );
    if (is_array($cache) && !empty($cache['locations'])) {
        foreach ($cache['locations'] as $loc) {
            if (empty($loc['devices']) || !is_array($loc['devices'])) { continue; }
            foreach ($loc['devices'] as $devId => $dev) {
                $devName = isset($dev['name']) && $dev['name'] !== '' ? $dev['name'] : $devId;
                /* Ausgenommene Geraete gehoeren NICHT in die Vorlage.
                 * Bis 1.2.5 legte sie fuer sie virtuelle Eingaenge an, die der
                 * Dienst nie bedient - sie blieben auf DefVal="0" stehen, und
                 * in Loxone sieht das aus wie ein gemessener Wert. (Die
                 * Vorlage der Virtuellen AUSGAENGE behaelt sie: die Ausnahme
                 * betrifft nur das Senden, geschaltet wird weiterhin.) */
                if (in_array((string) $devName, $ausgenommen, true)
                    || in_array((string) $devId, $ausgenommen, true)) { continue; }
                if (empty($dev['services']) || !is_array($dev['services'])) { continue; }
                foreach ($dev['services'] as $type => $attrs) {
                    if (!is_array($attrs)) { continue; }
                    foreach ($attrs as $attrName => $attr) {
                        if ($attrName === '_service_id' || !is_array($attr) || !array_key_exists('value', $attr)) { continue; }
                        $value = $attr['value'];
                        if (is_bool($value)) { $value = $value ? 1 : 0; }
                        if (!is_numeric($value)) { continue; }
                        $g = isset($grenzen[$attrName]) ? $grenzen[$attrName] : array('-1000000', '1000000', '<v.1>');
                        $o .= "\t" . '<VirtualInUdpCmd Title="' . $x($devName . ' ' . $attrName) . '" ';
                        $o .= 'Comment="' . $x($attrName . ' ' . $devName . ' (' . $type . ')') . '" Address="" ';
                        /* Der Suchtext kommt aus DERSELBEN Funktion wie die
                         * gesendete Zeile (bin/functions.inc.php). Bis 1.2.5
                         * baute die Vorlage ihn selbst zusammen und liess dabei
                         * Mehrfachleerzeichen und Tabulatoren stehen, die der
                         * Sender zusammenfallen laesst - bei einem
                         * Geraetenamen mit zwei Leerzeichen traf der Suchtext
                         * nie, und der Eingang blieb auf DefVal="0" stehen. */
                        $o .= 'Check="' . $x(gardena_udp_zeile($type, $devName, $attrName)) . '" ';
                        $o .= 'Signed="true" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="'
                            . $g[0] . '" MaxVal="' . $g[1] . '" Unit="' . $x($g[2]) . '" HintText=""/>' . $crlf;
                    }
                }
            }
        }
    }
    $o .= '</VirtualInUdp>' . $crlf;
    return array('VI_gardena_udp.xml', $o);
}

/** VO-Vorlage (Steuerbefehle) nach dem Heimkino/Robonect-Muster:
 *  templateType 3, Token eingesetzt. Geraete und ihre Art stammen aus dem
 *  Abbild (devices_cache.json), die Befehle aus der Tabelle im
 *  Einbindungs-Reiter - nichts ist geraten. */
function gardena_vorlage_vo($cachefile, $token, $ordner)
{
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    /* Der Ordnername kommt vom Aufrufer, nicht aus einem Rueckfallwert.
     *
     * Bis 1.2.0 stand hier
     *
     *     $ordner = getenv('LBPPLUGINDIR') ?: 'gardena';
     *
     * Der Rueckfall konnte NIE stimmen: die plugin.cfg legt
     * FOLDER=gardenasmartsystem fest. Griff er - ob LBPPLUGINDIR im
     * Apache-Prozess gesetzt ist, war nie gemessen -, trugen alle Befehle
     * der heruntergeladenen Vorlage /plugins/gardena/index.php und liefen
     * auf 404, waehrend die Oberflaeche daneben die richtige Adresse
     * anzeigte. Ein Virtueller Ausgang wertet die Antwort nicht aus; der
     * Ausfall waere still geblieben.
     *
     * Jetzt gibt es fuer die Angabe genau eine Quelle ($gordner weiter
     * unten), und Anzeige wie Vorlage benutzen sie gemeinsam. */
    $ordner = (string) $ordner;
    $basis = '/plugins/' . $ordner . '/index.php?action=command&token=' . rawurlencode((string) $token);
    $cache = json_decode((string) @file_get_contents($cachefile), true);
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="Gardena steuern (LoxBerry-Plugin)" Comment="Steuerbefehle ueber das Plugin ' . htmlspecialchars($ordner, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . ' - enthaelt das Token." Address="http://' . htmlspecialchars($host, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
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
                        $o .= "\t" . '<VirtualOutCmd Title="' . htmlspecialchars($devName . ' ' . $b[0], ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '" Comment="" CmdOnMethod="GET" CmdOffMethod="GET" ';
                        $o .= 'CmdOn="' . htmlspecialchars($adr, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '" ';
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

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
// ---------- Loxone-Vorlage herunterladen (Hausstandard) ----------
if ($gpost && isset($_POST['vorlage'])) {
    $gc_v = gardena_cfg_read($gconfigfile);
    // Die eigene Adresse steht im Kopf der UDP-Vorlage. Sie ist ein
    // Vorschlag - der Nutzer wird in der Oberflaeche darauf hingewiesen,
    // dass er sie pruefen soll.
    $g_ip = (class_exists('LBSystem', false) && method_exists('LBSystem', 'get_localip'))
        ? (string) LBSystem::get_localip() : '';
    if ($_POST['vorlage'] === 'vo') {
        list($g_vname, $g_vinhalt) = gardena_vorlage_vo($gcachefile,
            isset($gc_v['TOKEN']) ? $gc_v['TOKEN'] : '', $gordner);
    } elseif ($_POST['vorlage'] === 'udp') {
        list($g_vname, $g_vinhalt) = gardena_vorlage_udp($gcachefile,
            !empty($gc_v['UDPPORT']) ? (int) $gc_v['UDPPORT'] : 5005, $g_ip,
            gardena_ausgenommen($gc_v));
    } else {
        list($g_vname, $g_vinhalt) = gardena_vorlage($gcachefile,
            !empty($gc_v['MQTT_TOPIC']) ? $gc_v['MQTT_TOPIC'] : 'gardena',
            gardena_ausgenommen($gc_v));
    }
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $g_vname . '"');
    echo $g_vinhalt;
    exit;
}

// ---------- MQTT speichern (eigener Reiter seit 1.1.6, Hausstandard) ----------
if ($gpost && isset($_POST['mqtt_save'])) {
    /*
     * Abweisen und melden, nicht still zurechtbiegen.
     *
     * Bis 1.2.5 schnitt preg_replace unerlaubte Zeichen wortlos heraus und
     * ein leeres Ergebnis wurde ebenso wortlos zu 'gardena'. Der Anwender
     * tippte "garten/nord #1", bekam "Konfiguration gespeichert." - und trug
     * danach im MQTT-Gateway das Abo ein, das er getippt hatte, nicht das,
     * das gespeichert wurde. Am Miniserver kam nichts an.
     */
    $gtopic_roh = trim((string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : ''));
    $gtopic_mq = $gtopic_roh;
    if ($gtopic_roh === '') {
        $gfehler[] = gardena_t('EINST.MQTT_TOPIC') . ': ' . gardena_t('EINST.MQTT_LEER');
    } elseif (preg_match('#^[A-Za-z0-9_/\-]{1,120}$#', $gtopic_roh) !== 1) {
        // MQTT_ZEICHEN_FEHL, nicht MQTT_ZEICHEN: dieser Text geht durch
        // gardena_e() in die Beanstandungsliste, und ein Text mit
        // <span class='sm-mono'> stuende dort im Wortlaut auf dem Bildschirm.
        $gfehler[] = gardena_t('EINST.MQTT_TOPIC') . ': ' . gardena_t('EINST.MQTT_ZEICHEN_FEHL');
    }
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
    /*
     * Ein leeres Kennwortfeld loescht NICHTS.
     *
     * Das Secret steht seit 1.2.6 nicht mehr im Seitenquelltext, das Feld
     * kommt also bei jedem Speichern leer zurueck. Leer heisst deshalb
     * "unveraendert lassen"; geloescht wird ueber den Haken daneben. Der
     * Browser fuellt ein type=password ohnehin nicht vor - wer nur den
     * Benutzernamen aendert, verloere sonst die Anmeldung.
     */
    $gsec_roh = trim((string) (isset($_POST['client_secret']) ? $_POST['client_secret'] : ''));
    $gsec_alt = (string) gardena_cfg_read($gconfigfile)['CLIENT_SECRET'];
    if (isset($_POST['secret_loeschen']) && $_POST['secret_loeschen'] === '1') {
        $gsec = '';
    } elseif ($gsec_roh === '') {
        $gsec = $gsec_alt;
    } else {
        $gsec = $gsec_roh;
    }
    $gport = (int) (isset($_POST['udpport']) ? $_POST['udpport'] : 5005);
    $genabled = (isset($_POST['enabled']) && $_POST['enabled'] === '1') ? '1' : '0';
    $gtakt_neu = (int) (isset($_POST['intervall']) ? $_POST['intervall'] : 5);
    $gmesser = (int) (isset($_POST['messer_intervall']) ? $_POST['messer_intervall'] : 0);

    if ($gport < 1 || $gport > 65535) {
        $gfehler[] = gardena_t('EINST.UDPPORT') . ': 1 - 65535';
        $gport = 5005;
    }
    if ($genabled === '1' && ($gcid === '' || $gsec === '')) {
        $gfehler[] = gardena_t('EINST.CLIENT_ID') . ' / ' . gardena_t('EINST.CLIENT_SECRET');
    }
    // Der Cron laeuft alle fuenf Minuten - weniger ist nicht erreichbar, und
    // das gehoert gesagt statt stillschweigend hochgesetzt.
    if ($gtakt_neu < 5 || $gtakt_neu > 1440) {
        $gfehler[] = gardena_t('EINST.INTERVALL') . ': 5 - 1440';
        $gtakt_neu = 5;
    }
    if ($gmesser < 0 || $gmesser > 100000) {
        $gfehler[] = gardena_t('EINST.MESSER_INTERVALL') . ': 0 - 100000';
        $gmesser = 0;
    }
    /*
     * Die Miniserver-Nummer gegen die WIRKLICH vorhandenen halten.
     *
     * Bis 1.2.5 wurde sie nur mit max(1, (int) ...) nach unten gekappt:
     * "99" wurde als 99 gespeichert, "abc" wurde zu 1. Steht dort eine
     * Nummer, die es nicht gibt, sendet der Dienst ins Leere - und weder
     * die Oberflaeche noch der Reiter Test sagten ein Wort dazu.
     */
    $gmsliste = LBSystem::get_miniservers();
    if (!is_array($gmsliste)) { $gmsliste = array(); }
    $gmsnr = (int) (isset($_POST['miniserver']) ? $_POST['miniserver'] : 1);
    if ($gmsnr < 1) {
        $gfehler[] = gardena_t('EINST.MINISERVER') . ': ' . gardena_t('EINST.MS_UNGUELTIG');
        $gmsnr = 1;
    } elseif ($gmsliste && !isset($gmsliste[$gmsnr])) {
        $gfehler[] = sprintf(gardena_t('EINST.MS_UNBEKANNT'), $gmsnr,
                             implode(', ', array_keys($gmsliste)));
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
            'MINISERVER' => $gmsnr,
            'UDP_ENABLED' => (isset($_POST['udp_enabled']) && $_POST['udp_enabled'] === '1') ? '1' : '0',
            'UDPPORT' => $gport,
            'INTERVALL' => $gtakt_neu,
            'MESSER_INTERVALL' => $gmesser,
            // MQTT_ENABLED/MQTT_TOPIC wohnen seit 1.1.6 im eigenen Reiter mit
            // eigenem Formular - gardena_cfg_write laesst nicht uebergebene
            // Schluessel stehen, deshalb hier bewusst weggelassen.
        );
        if ($gtoken !== '') { $gneu['TOKEN'] = $gtoken; }
        if (isset($_POST['ausgenommen_da'])) {
            $gliste = array();
            if (isset($_POST['ausgenommen']) && is_array($_POST['ausgenommen'])) {
                foreach ($_POST['ausgenommen'] as $gn) {
                    if (!is_string($gn)) { continue; }
                    $gn = trim($gn);
                    if ($gn === '') { continue; }
                    /*
                     * Ein Komma trennt die Liste in der Konfiguration - ein
                     * Geraetename mit Komma wuerde sie zerlegen. Bis 1.2.5
                     * fiel ein solcher Name WORTLOS heraus: der Anwender hakte
                     * an, bekam "Konfiguration gespeichert.", das Geraet sendete
                     * weiter, und beim naechsten Aufruf war das Haekchen wieder
                     * weg. Er versucht es dann zwei- oder dreimal.
                     */
                    if (strpos($gn, ',') !== false) {
                        $gfehler[] = sprintf(gardena_t('EINST.AUSG_KOMMA'), gardena_e($gn));
                        continue;
                    }
                    $gliste[] = $gn;
                }
            }
            $gneu['AUSGENOMMEN'] = implode(',', $gliste);
        }

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
// Fehlende Schluessel EINMAL nachtragen. Hausstandard: die Konfiguration
// wird vervollstaendigt, nicht nur beim Lesen ergaenzt - sonst steht in der
// Datei etwas anderes als das, womit gearbeitet wird. Bis 1.2.5 ergaenzte nur
// der Speichern-Handler, und auch der nur die Schluessel, die sein Formular
// gerade mitschickte (gemessen: 2 von 4 fehlenden).
/*
 * Zustand ZUERST feststellen und merken.
 *
 * Der zuerst festgestellte Zustand gilt fuer die Dauer dieses Aufrufs: eine
 * spaetere Lesefunktion sieht die Datei womoeglich schon wieder heil, und
 * dann haette der Bediener nie erfahren, dass etwas war.
 */
$gcfg_zustand = gardena_cfg_zustand($gconfigfile);
if ($gcfg_zustand === 'unlesbar') {
    $gfehler[] = sprintf(gardena_t('EINST.CFG_UNLESBAR'), gardena_e($gconfigfile));
    gardena_log('ERR', 'Die Konfigurationsdatei ist vorhanden, aber nicht lesbar: '
        . $gconfigfile . ' - es wird NICHTS geschrieben und kein Token erzeugt.');
} else {
    gardena_cfg_vervollstaendigen($gconfigfile);
}

$gc = gardena_cfg_read($gconfigfile);

// Beim ersten Oeffnen der Oberflaeche ein Token erzeugen. Ohne Token weist
// der Endpunkt jeden Aufruf ab - das Plugin waere unbenutzbar.
//
// NUR bei einer Datei, die es noch nicht gibt oder die heil ist. Ist sie da
// und unlesbar, wuerde ein neues Token jede im Miniserver eingetragene
// Adresse ungueltig machen - und der alte Wert steht vielleicht noch in der
// Datei, die man gerade nicht lesen kann.
if ((string) $gc['TOKEN'] === '' && !$gpost && $gcfg_zustand !== 'unlesbar') {
    $gneu = gtoken_erzeugen($gconfigfile, $gtokenmsg);
    if ($gneu !== '') { $gc['TOKEN'] = $gneu; }
}
$gtokenurl = ((string) $gc['TOKEN'] !== '') ? '&amp;token=' . rawurlencode($gc['TOKEN']) : '&amp;token=TOKEN';

$gms = LBSystem::get_miniservers();
if (!is_array($gms)) { $gms = array(); }

$gcache = is_file($gcachefile)
    ? json_decode((string) @file_get_contents($gcachefile), true) : null;
if (!is_array($gcache)) { $gcache = array(); }

$gloglines = array();
if (is_file($glogfile)) {
    $gloglines = array_slice(array_reverse(file($glogfile,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array()), 0, 300);
}

$ghatcurl = gardena::hasCurl();
// Dieselbe Frage, dieselbe Antwort: gardena_udp_moeglich() prueft drei
// Funktionen, function_exists('socket_create') nur eine. Bis 1.2.5 blieb bei
// einer verstuemmelten Installation die rote Kopfmeldung aus, waehrend der
// Reiter Test ein Kreuz setzte - zwei Antworten auf derselben Seite.
$ghatsockets = gardena_udp_moeglich();
$gpl = gardena_e($gordner);


/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das. */
if ($gpost && isset($_POST['gardena_sichern'])) {
    /*
      * Ausgegeben werden GENAU die Schluessel, die das Zurueckspielen auch
      * wieder annimmt. Bis 1.2.5 ging alles hinaus, was in der gardena.cfg
      * stand; eine gewachsene Datei mit einem Rest aus einer alten Fassung
      * (postupgrade.sh nennt LOCALTIME) erzeugte damit eine Sicherung, die
      * das Plugin beim Zurueckspielen als "Unbekannte Einstellung" ABLEHNTE.
      * Die eigene Sicherung war unbrauchbar - und genau fuer den Umzug auf
      * einen zweiten LoxBerry ist sie gedacht. Gemessen am 05.09.2026.
      */
    $gardena_stand = gardena_sicherung_stand();
    /*
     * Vor dem Ausliefern die eigene Leseseite fragen. Sonst entsteht eine
     * Datei, die das Plugin beim Zurueckspielen ablehnt - und das merkt man
     * genau dann, wenn man sie braucht.
     */
    $gardena_unrueck = array();
    foreach ($gardena_stand as $gk => $gv) {
        if (!gardena_wert_taugt($gv) || gardena_wert_pruefen($gk, (string) $gv) !== '') {
            $gardena_unrueck[] = $gk;
        }
    }
    if ($gardena_unrueck) {
        $gfehler[] = sprintf(gardena_t('EINST.SICH_NICHT_RUECK'),
                             implode(', ', $gardena_unrueck));
        gardena_log('ERR', 'Sicherung nicht erzeugt: diese Werte liessen sich nicht '
            . 'zurueckspielen - ' . implode(', ', $gardena_unrueck));
        $gardena_js = false;
    } else {
        $gardena_js = json_encode($gardena_stand,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($gardena_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="gardenasmartsystem_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $gardena_js;
        exit;
    }
    $gfehler[] = gardena_t('EINST.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei
 * des Servers unterschieben. Dann die Groessengrenze - eine Sicherung
 * dieses Plugins ist wenige Kilobyte gross; alles darueber wird gar
 * nicht erst gelesen. */
if ($gpost && isset($_POST['gardena_zurueck'])) {
    if (!isset($_FILES['gardena_sicherung']) || !is_array($_FILES['gardena_sicherung'])
        || !isset($_FILES['gardena_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['gardena_sicherung']['tmp_name'])) {
        $gfehler[] = gardena_t('EINST.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['gardena_sicherung']['size'] > 262144) {
        $gfehler[] = gardena_t('EINST.SICH_ZU_GROSS');
    } else {
        list($gardena_neu, $gardena_mangel, $gardena_n) = gardena_sicherung_lesen(
            (string) @file_get_contents($_FILES['gardena_sicherung']['tmp_name']));
        if ($gardena_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert
             * wird nichts. */
            $gfehler[] = gardena_t('EINST.SICH_ABGELEHNT') . ' ' . implode(' ', $gardena_mangel);
        } elseif (gardena_config_speichern($gardena_neu)) {
            /*
             * $gsaved traegt hier einen TEXT. Bis 1.2.5 wurde er nur als
             * Wahrheitswert ausgewertet und die Zahl der uebernommenen Werte
             * nie angezeigt - EINST.SICH_UEBERNOMMEN war toter Text in beiden
             * Sprachdateien. Die Ausgabe unten kennt jetzt beide Faelle.
             */
            $gsaved = sprintf(gardena_t('EINST.SICH_UEBERNOMMEN'), $gardena_n);
            gardena_log('INF', 'Einstellungen aus einer Sicherungsdatei zurueckgespielt ('
                . $gardena_n . ' Werte).');
            /*
             * Den Stand NEU lesen.
             *
             * Der Ladeblock steht weiter oben; nach dem Zurueckspielen zeigte
             * die Seite bis 1.2.5 weiter den alten Stand - altes Token, alte
             * Adressen, und vor allem gardena_formtoken($gc) aus dem ALTEN
             * Token in allen zehn Formularen. Der naechste Klick lief damit in
             * "Das Formular kam ohne gueltiges Merkmal an": eine
             * Fremdabsender-Warnung nach einer GEGLUECKTEN Wiederherstellung.
             */
            $gc = gardena_cfg_read($gconfigfile);
            $gtokenurl = ((string) $gc['TOKEN'] !== '')
                ? '&amp;token=' . rawurlencode($gc['TOKEN']) : '&amp;token=TOKEN';
        } else {
            $gfehler[] = gardena_t('EINST.SICH_SCHREIBFEHLER');
        }
    }
}


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
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
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
/* --- Selbstpruefung: Haekchen, Hinweis, Kreuz --- */
.sm-ja, .sm-hm, .sm-nein { text-align: center; font-weight: 700; font-size: 1.05em; }
.sm-ja   { color: #3d7a10; }
.sm-hm   { color: #b26a00; }
.sm-nein { color: #b3261e; }
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
<?php if ($gsaved) { ?><div class="sm-alert sm-ok"><b><?=
    is_string($gsaved) ? gardena_e($gsaved) : gardena_t('ALLG.GESPEICHERT') ?></b></div><?php } ?>
<?php if ($gfehler) { ?>
<div class="sm-alert sm-err"><b><?= gardena_t('ALLG.FEHLER') ?></b><br><?= gardena_e(implode(' | ', $gfehler)) ?></div>
<?php } ?>
<?php if ($gtokenmsg !== '') { ?>
<div class="sm-alert <?= stripos($gtokenmsg, 'FEHLER') !== false || stripos($gtokenmsg, 'ERROR') !== false ? 'sm-err' : 'sm-ok' ?>"><?= gardena_e($gtokenmsg) ?></div>
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
<div class="sm-seite<?= gaktiv('tab-settings') ?>" id="tab-settings">

<div class="sm-alert sm-info"><b><?= gardena_t('EINST.ZUGANG_TITEL') ?></b> <?= gardena_t('EINST.ZUGANG_TEXT') ?></div>

<form action="index.php" method="post" autocomplete="off">
<input data-role="none" type="hidden" name="formtoken" value="<?= gardena_e(gardena_formtoken($gc)) ?>">
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
        <?php /* Das Secret steht NICHT mehr im Seitenquelltext. type="password"
                  verdeckt nur die Anzeige; der Wert selbst stand bis 1.2.5 im
                  Klartext im HTML, das der Browser puffert. Ein leeres Feld
                  heisst jetzt "unveraendert lassen", geloescht wird ueber den
                  Haken daneben - ein leeres Kennwortfeld darf nichts loeschen,
                  weil der Browser es nicht vorfuellt. */ ?>
        <input data-role="none" type="password" name="client_secret" value=""
               placeholder="<?= gardena_e((string) $gc['CLIENT_SECRET'] !== ''
                   ? gardena_t('EINST.SECRET_GESETZT') : gardena_t('EINST.SECRET_LEER')) ?>">
        <label class="sm-small"><input data-role="none" type="checkbox" name="secret_loeschen" value="1">
            <?= gardena_t('EINST.SECRET_LOESCHEN') ?></label>
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

<h2><?= gardena_t('EINST.H_TAKT') ?></h2>
<div class="sm-row">
    <div>
        <label><?= gardena_t('EINST.INTERVALL') ?></label>
        <input data-role="none" type="number" name="intervall" value="<?= (int) gardena_intervall($gc) ?>" min="5" max="1440" step="5">
    </div>
</div>
<div class="sm-small"><?= gardena_t('EINST.INTERVALL_HINWEIS') ?></div>

<h2><?= gardena_t('EINST.H_AUSWAHL') ?></h2>
<?php
/* Die Liste entsteht aus dem Geraete-Abbild. Sie wird NUR angezeigt, wenn
 * es ein Abbild gibt - und nur dann traegt das Formular den Merker
 * ausgenommen_da. Ohne ihn fasst der Speichern-Handler die Auswahl nicht
 * an: ein Speichern bei leerem Abbild wuerde sie sonst wortlos loeschen. */
$gnamen_alle = array();
if (!empty($gcache['locations']) && is_array($gcache['locations'])) {
    foreach ($gcache['locations'] as $gl) {
        if (empty($gl['devices']) || !is_array($gl['devices'])) { continue; }
        foreach ($gl['devices'] as $gid => $gd) {
            $gnamen_alle[] = (is_array($gd) && isset($gd['name']) && $gd['name'] !== '')
                ? (string) $gd['name'] : (string) $gid;
        }
    }
}
$gaus_jetzt = gardena_ausgenommen($gc);
if (!$gnamen_alle) { ?>
<div class="sm-alert sm-info"><?= gardena_t('EINST.AUSWAHL_KEIN_ABBILD') ?></div>
<?php } else { ?>
<input data-role="none" type="hidden" name="ausgenommen_da" value="1">
<div class="sm-small"><?= gardena_t('EINST.AUSWAHL_HINWEIS') ?></div>
<?php foreach ($gnamen_alle as $gn) { ?>
<label style="font-weight:400;">
  <input data-role="none" type="checkbox" name="ausgenommen[]" value="<?= gardena_e($gn) ?>"<?= in_array($gn, $gaus_jetzt, true) ? ' checked' : '' ?>>
  <?= gardena_e($gn) ?>
</label>
<?php } ?>
<?php } ?>

<h2><?= gardena_t('WARTUNG.H') ?></h2>
<div class="sm-row">
    <div>
        <label><?= gardena_t('EINST.MESSER_INTERVALL') ?></label>
        <input data-role="none" type="number" name="messer_intervall" value="<?= (int) $gc['MESSER_INTERVALL'] ?>" min="0" max="100000">
    </div>
</div>
<div class="sm-small"><?= gardena_t('WARTUNG.HINWEIS') ?></div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= gardena_t('LEGENDE.LESEN') ?></span>
</div>
<button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= gardena_t('ALLG.SPEICHERN') ?></button>
</form>

<?php
/* Der Stand der Betriebsstunden kommt aus dem Abbild, nicht aus der
 * Konfiguration - geraten wird nichts. Ohne Abbild bleibt der Zaehler
 * stumm, statt eine Restzeit zu erfinden. */
$gstd_jetzt = gardena_betriebsstunden($gcache);
$gmesser_int = (int) $gc['MESSER_INTERVALL'];
if ($gmesser_int > 0) {
    if ($gstd_jetzt === null) { ?>
<div class="sm-alert sm-info"><?= gardena_t('WARTUNG.KEIN_STAND') ?></div>
<?php } else {
        $grest = $gmesser_int - ((int) $gstd_jetzt - (int) $gc['MESSER_BASIS']);
    ?>
<div class="sm-alert <?= $grest <= 0 ? 'sm-warn' : 'sm-info' ?>">
<?= gardena_e(sprintf(gardena_t($grest <= 0 ? 'WARTUNG.FAELLIG' : 'WARTUNG.REST'),
                      (int) $grest, (int) $gstd_jetzt)) ?>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= gardena_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="formtoken" value="<?= gardena_e(gardena_formtoken($gc)) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="messer_quittieren" value="1"><?= gardena_t('WARTUNG.QUITTIEREN') ?></button>
</form>
</div>
<?php }
} ?>

<h2><?= gardena_t('TOKEN.H') ?></h2>
<div class="sm-alert sm-info"><?= gardena_t('TOKEN.TEXT') ?></div>
<label><?= gardena_t('TOKEN.LABEL') ?></label>
<input data-role="none" type="text" value="<?= gardena_e($gc['TOKEN']) ?>" readonly onclick="this.select();">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= gardena_t('LEGENDE.AKTION') ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="formtoken" value="<?= gardena_e(gardena_formtoken($gc)) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="newtoken" value="1"
            onclick="return confirm(<?= json_encode(html_entity_decode(gardena_t('TOKEN.NEU_FRAGE'), ENT_QUOTES, 'UTF-8')) ?>);"><?= gardena_t('TOKEN.NEU') ?></button>
</form>
</div>

<h2><?= gardena_t('EINST.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= gardena_t('EINST.SICH_ERKLAERUNG') ?></div>
<div class="sm-alert sm-warn"><?= gardena_t('EINST.SICH_WARNUNG') ?></div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="formtoken" value="<?= gardena_e(gardena_formtoken($gc)) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="gardena_sichern" value="1"><?= gardena_t('EINST.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="formtoken" value="<?= gardena_e(gardena_formtoken($gc)) ?>">
    <input data-role="none" type="file" name="gardena_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="gardena_zurueck" value="1"><?= gardena_t('EINST.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: MQTT (eigener Reiter seit 1.1.6, Hausstandard) ================= -->
<div class="sm-seite<?= gaktiv('tab-mqtt') ?>" id="tab-mqtt">
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="formtoken" value="<?= gardena_e(gardena_formtoken($gc)) ?>">
<input data-role="none" type="hidden" name="mqtt_save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<h2><?= gardena_t('EINST.H_MQTT') ?></h2>
<?php if (gard_hs_autostart() === false) { ?><div class="sm-alert sm-warn"><b>MQTT:</b> <?php echo gardena_t('EINST.W_AUTOSTART'); ?></div><?php } ?>
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

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= gardena_t('LEGENDE.LESEN') ?></span>
</div>
<button data-role="none" class="sm-btn sm-b-lesen" type="submit"><?= gardena_t('ALLG.SPEICHERN') ?></button>
</form>

<h2><?= gardena_t('MQTT.H_ABO') ?></h2>
<div class="sm-alert sm-warn"><?= gardena_abo_text() ?></div>
<div class="sm-mono"><?= gardena_e(gardena_mqtt_thema((string) $gc['MQTT_TOPIC'])) ?>/#</div>

<h2><?= gardena_t('MQTT.H_THEMEN') ?></h2>
<?php
/* Die Tabelle entsteht aus dem Geraete-Abbild, also aus der letzten echten
 * Antwort - nichts ist geraten. Die Themen werden mit derselben Funktion
 * gebaut, die der Dienst benutzt. */
$gthemen_liste = array();
if (!empty($gcache['locations']) && is_array($gcache['locations'])) {
    foreach ($gcache['locations'] as $gl) {
        if (empty($gl['devices']) || !is_array($gl['devices'])) { continue; }
        foreach ($gl['devices'] as $gid => $gd) {
            if (!is_array($gd) || empty($gd['services']) || !is_array($gd['services'])) { continue; }
            $gn = (isset($gd['name']) && $gd['name'] !== '') ? (string) $gd['name'] : (string) $gid;
            foreach ($gd['services'] as $gt => $gattrs) {
                if (!is_array($gattrs)) { continue; }
                foreach ($gattrs as $ga => $gv) {
                    if ($ga === '_service_id' || !is_array($gv) || !array_key_exists('value', $gv)) { continue; }
                    /* Was der Dienst nicht sendet, wird hier auch nicht
                     * versprochen: gardena_wert_fehlt() ueberspringt Werte,
                     * die die Wolke ohne Inhalt liefert. Bis 1.2.5 standen sie
                     * in der Tabelle, und der Anwender suchte im Broker nach
                     * einem Thema, das es nie gab. */
                    if (gardena_wert_fehlt($gv['value'])) { continue; }
                    $gthemen_liste[] = array(
                        gardena_wert_thema((string) $gc['MQTT_TOPIC'], $gn, $gt, $ga),
                        $gn, $gt, $ga,
                        is_array($gv['value']) ? '(Feld)' : (string) $gv['value']);
                }
            }
        }
    }
}
if (!$gthemen_liste) { ?>
<div class="sm-alert sm-info"><?= gardena_t('MQTT.THEMEN_KEIN_ABBILD') ?></div>
<?php } else { ?>
<div class="sm-small"><?= sprintf(gardena_t('MQTT.THEMEN_ANZAHL'), count($gthemen_liste) + 4) ?></div>
<table class="sm-tbl">
<tr><th><?= gardena_t('MQTT.SP_THEMA') ?></th><th><?= gardena_t('MQTT.SP_BEDEUTUNG') ?></th><th><?= gardena_t('MQTT.SP_LETZTER') ?></th></tr>
<?php foreach ($gthemen_liste as $gz) { ?>
<tr><td><span class="sm-mono"><?= gardena_e($gz[0]) ?></span></td><td><?= gardena_e($gz[3] . ' — ' . $gz[1] . ' (' . $gz[2] . ')') ?></td><td><?= gardena_e($gz[4]) ?></td></tr>
<?php } ?>
<tr><td><span class="sm-mono"><?= gardena_e(gardena_wert_thema((string) $gc['MQTT_TOPIC'], 'Plugin', 'STATUS', 'ok')) ?></span></td><td colspan="2"><?= gardena_t('MQTT.Z_OK') ?></td></tr>
<tr><td><span class="sm-mono"><?= gardena_e(gardena_wert_thema((string) $gc['MQTT_TOPIC'], 'Plugin', 'STATUS', 'zeitstempel')) ?></span></td><td colspan="2"><?= gardena_t('MQTT.Z_ZEIT') ?></td></tr>
<tr><td><span class="sm-mono"><?= gardena_e(gardena_wert_thema((string) $gc['MQTT_TOPIC'], 'Plugin', 'STATUS', 'werte')) ?></span></td><td colspan="2"><?= gardena_t('MQTT.Z_WERTE') ?></td></tr>
<tr><td><span class="sm-mono"><?= gardena_e(gardena_wert_thema((string) $gc['MQTT_TOPIC'], 'Plugin', 'STATUS', 'fehler')) ?></span></td><td colspan="2"><?= gardena_t('MQTT.Z_FEHLER') ?></td></tr>
</table>
<?php } ?>
</div>

<!-- ================= Reiter: Geraete ================= -->
<div class="sm-seite<?= gaktiv('tab-devices') ?>" id="tab-devices">
<h2><?= gardena_t('GERAETE.H') ?><?= !empty($gcache['updated']) ? ' (' . gardena_t('GERAETE.LETZTER_ABRUF') . ' ' . gardena_e($gcache['updated']) . ')' : '' ?></h2>
<?php if (empty($gcache['locations']) || !is_array($gcache['locations'])) { ?>
<div class="sm-alert sm-info"><?= gardena_t('GERAETE.KEINE_DATEN') ?></div>
<?php } else { foreach ($gcache['locations'] as $gloc) {
    if (!is_array($gloc) || empty($gloc['devices']) || !is_array($gloc['devices'])) { continue; } ?>
<p><b><?= gardena_e(isset($gloc['name']) ? $gloc['name'] : '?') ?></b></p>
<table class="sm-tbl">
<tr><th><?= gardena_t('GERAETE.SP_GERAET') ?></th><th><?= gardena_t('GERAETE.SP_THEMA') ?></th><th><?= gardena_t('GERAETE.SP_SERVICES') ?></th><th><?= gardena_t('GERAETE.SP_STATUS') ?></th><th><?= gardena_t('GERAETE.SP_AKKU') ?></th><th><?= gardena_t('GERAETE.SP_STAND') ?></th></tr>
<?php foreach ($gloc['devices'] as $gdevid => $gdev) {
    if (!is_array($gdev)) { continue; }
    $gname = isset($gdev['name']) && $gdev['name'] !== '' ? (string) $gdev['name'] : (string) $gdevid;
    $gsvcs = (isset($gdev['services']) && is_array($gdev['services'])) ? implode(', ', array_keys($gdev['services'])) : '';
    $gstate = isset($gdev['services']['COMMON']['rfLinkState']['value']) ? $gdev['services']['COMMON']['rfLinkState']['value'] : '';
    $gbatt = isset($gdev['services']['COMMON']['batteryLevel']['value']) ? $gdev['services']['COMMON']['batteryLevel']['value'] . ' %' : '';
    /* Unter welchem Namen kommt das Geraet beim Miniserver an? Der Anwender
     * vergibt den Namen in der Gardena-App frei; im MQTT-Thema sind nur
     * Buchstaben, Ziffern, _ und - zulaessig, alles andere wird
     * umgeschrieben. Wer das nicht sieht, sucht seinen "Mähroboter" im
     * Broker vergeblich. */
    $gthema = gardena_mqtt_thema((string) $gc['MQTT_TOPIC'] . '/' . $gname);
?>
<?php
    /* Der Zeitstempel der Wolke, nicht der des Abrufs: er sagt, wie alt die
     * Angabe des Geraetes selbst ist. Steht keiner da, bleibt die Zelle leer
     * - eine erfundene Zeit waere schlimmer als keine. */
    $gstamp = '';
    foreach (array('COMMON', 'MOWER', 'VALVE', 'SENSOR') as $gsv) {
        foreach (array('rfLinkState', 'batteryLevel', 'activity', 'state') as $ga2) {
            if (!empty($gdev['services'][$gsv][$ga2]['timestamp'])) {
                $gstamp = (string) $gdev['services'][$gsv][$ga2]['timestamp'];
                break 2;
            }
        }
    }
    if ($gstamp !== '') {
        $gt2 = strtotime($gstamp);
        $gstamp = ($gt2 !== false) ? date('d.m.Y H:i', $gt2) : $gstamp;
    }
?>
<tr><td><?= gardena_e($gname) ?></td><td><span class="sm-mono"><?= gardena_e($gthema) ?></span><?= gardena_name_umgeschrieben($gname) ? '<div class="sm-small">' . gardena_e(gardena_t('GERAETE.UMGESCHRIEBEN')) . '</div>' : '' ?></td><td><?= gardena_e($gsvcs) ?></td><td><?= gardena_e($gstate) ?></td><td><?= gardena_e($gbatt) ?></td><td><?= gardena_e($gstamp) ?></td></tr>
<?php } ?>
</table>
<?php } } ?>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= gaktiv('tab-loxone') ?>" id="tab-loxone">
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
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= gardena_t('LEGENDE.TECHNIK') ?></span>
</div>
<div class="sm-knopfreihe" style="margin-bottom:14px;">
<form action="index.php" method="post" style="margin:0;">
<input data-role="none" type="hidden" name="formtoken" value="<?= gardena_e(gardena_formtoken($gc)) ?>">
  <input data-role="none" type="hidden" name="vorlage" value="1">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= gardena_t('LOX.K_VORLAGE') ?></button>
</form>
<form action="index.php" method="post" style="margin:0;">
<input data-role="none" type="hidden" name="formtoken" value="<?= gardena_e(gardena_formtoken($gc)) ?>">
  <input data-role="none" type="hidden" name="vorlage" value="udp">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= gardena_t('LOX.K_VORLAGE_UDP') ?></button>
</form>
<form action="index.php" method="post" style="margin:0;">
<input data-role="none" type="hidden" name="formtoken" value="<?= gardena_e(gardena_formtoken($gc)) ?>">
  <input data-role="none" type="hidden" name="vorlage" value="vo">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?= gardena_t('LOX.K_VORLAGE_VO') ?></button>
</form>
</div>
<div class="sm-small"><?= gardena_t('LOX.VORLAGE_ADRESSE') ?></div>
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

<div class="sm-step"><b><?= gardena_t('LOX.H_BAUSTEINE') ?></b>
<div class="sm-small"><?= gardena_t('LOX.BAUSTEINE_TEXT') ?></div>
<table class="sm-tbl">
<tr><th>#</th><th><?= gardena_t('LOX.S_BAUSTEIN') ?></th><th><?= gardena_t('LOX.S_NAME') ?></th><th><?= gardena_t('LOX.S_PARAMETER') ?></th><th><?= gardena_t('LOX.S_EINGAENGE') ?></th></tr>
<tr><td>1</td><td><?= gardena_t('LOX.B_VI') ?></td><td>Gardena_Akku</td><td><?= gardena_t('LOX.S_EINHEIT') ?> <span class="sm-mono">&lt;v.0&gt; %</span></td><td>MQTT <span class="sm-mono">…/COMMON/batteryLevel</span></td></tr>
<tr><td>2</td><td><?= gardena_t('LOX.B_VI') ?></td><td>Gardena_Funk</td><td><?= gardena_t('LOX.S_EINHEIT') ?> <span class="sm-mono">&lt;v.0&gt; %</span></td><td>MQTT <span class="sm-mono">…/COMMON/rfLinkLevel</span></td></tr>
<tr><td>3</td><td><?= gardena_t('LOX.B_VI') ?></td><td>Gardena_Betriebsstunden</td><td><?= gardena_t('LOX.S_EINHEIT') ?> <span class="sm-mono">&lt;v.0&gt; h</span></td><td>MQTT <span class="sm-mono">…/MOWER/operatingHours</span></td></tr>
<tr><td>4</td><td><?= gardena_t('LOX.B_VI') ?></td><td>Gardena_Abruf_ok</td><td><?= gardena_t('LOX.S_EINHEIT') ?> <span class="sm-mono">&lt;v.0&gt;</span></td><td>MQTT <span class="sm-mono">…/Plugin/STATUS/ok</span></td></tr>
<tr><td>5</td><td><?= gardena_t('LOX.B_VI') ?></td><td>Gardena_Abrufzeit</td><td><?= gardena_t('LOX.S_EINHEIT') ?> <span class="sm-mono">&lt;v.0&gt;</span></td><td>MQTT <span class="sm-mono">…/Plugin/STATUS/zeitstempel</span></td></tr>
<tr><td>6</td><td><?= gardena_t('LOX.B_VERGLEICHER') ?></td><td>Gardena_Akku_niedrig</td><td><?= gardena_t('LOX.P_SCHWELLE') ?></td><td><?= gardena_t('LOX.S_EINGANG') ?> &larr; #1</td></tr>
<tr><td>7</td><td><?= gardena_t('LOX.B_TREPPENLICHT') ?></td><td>Gardena_Abruf_haengt</td><td><?= gardena_t('LOX.P_HALTEZEIT') ?></td><td><?= gardena_t('LOX.P_FLANKE') ?> #5</td></tr>
<tr><td>8</td><td><?= gardena_t('LOX.B_ODER') ?></td><td>Gardena_Meldungen</td><td>–</td><td><?= gardena_t('LOX.S_EINGAENGE') ?> #6, #7</td></tr>
<tr><td>9</td><td><?= gardena_t('LOX.B_BENACHRICHTIGUNG') ?></td><td>Gardena_Melder</td><td><?= gardena_t('LOX.P_TEXT_FREI') ?></td><td><?= gardena_t('LOX.S_EINGANG') ?> &larr; #8</td></tr>
<tr><td>10</td><td><?= gardena_t('LOX.B_VQ') ?></td><td>Gardena_maehen</td><td><span class="sm-mono">…&amp;type=MOWER_CONTROL&amp;cmd=START_SECONDS_TO_OVERRIDE&amp;seconds=3600</span></td><td><?= gardena_t('LOX.S_VON_VISU') ?></td></tr>
<tr><td>11</td><td><?= gardena_t('LOX.B_VQ') ?></td><td>Gardena_parken</td><td><span class="sm-mono">…&amp;type=MOWER_CONTROL&amp;cmd=PARK_UNTIL_NEXT_TASK</span></td><td><?= gardena_t('LOX.S_VON_VISU') ?></td></tr>
<tr><td>12</td><td><?= gardena_t('LOX.B_VQ') ?></td><td>Gardena_bewaessern</td><td><span class="sm-mono">…&amp;type=VALVE_CONTROL&amp;cmd=START_SECONDS_TO_OVERRIDE&amp;seconds=1800</span></td><td><?= gardena_t('LOX.S_VON_VISU') ?></td></tr>
<tr><td>13 <i><?= gardena_t('LOX.OPTIONAL') ?></i></td><td><?= gardena_t('LOX.B_VQ') ?></td><td>Gardena_bewaessern_stopp</td><td><span class="sm-mono">…&amp;type=VALVE_CONTROL&amp;cmd=STOP_UNTIL_NEXT_TASK</span></td><td><?= gardena_t('LOX.S_VON_VISU') ?></td></tr>
<tr><td>14 <i><?= gardena_t('LOX.OPTIONAL') ?></i></td><td><?= gardena_t('LOX.B_VI') ?></td><td>Gardena_Werte</td><td><?= gardena_t('LOX.S_EINHEIT') ?> <span class="sm-mono">&lt;v.0&gt;</span></td><td>MQTT <span class="sm-mono">…/Plugin/STATUS/werte</span></td></tr>
</table>
<div class="sm-small"><b><?= gardena_t('LOX.ZU_7') ?></b> <?= gardena_t('LOX.ZU_7_TEXT') ?></div>
<div class="sm-small"><b><?= gardena_t('LOX.ZU_9') ?></b> <?= gardena_t('LOX.ZU_9_TEXT') ?></div>
</div>

<div class="sm-step"><b><?= gardena_t('LOX.H_LEERE_WERTE') ?></b><br>
<?= gardena_t('LOX.LEERE_WERTE') ?>
</div>

<div class="sm-step"><b><?= gardena_t('LOX.H_AUSFALL') ?></b><br>
<?= gardena_t('LOX.AUSFALL') ?>
</div>

<div class="sm-step"><b><?= gardena_t('LOX.H_SICHER') ?></b><br>
<?= gardena_t('LOX.SICHER') ?>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= gaktiv('tab-test') ?>" id="tab-test">
<h2><?= gardena_t('REITER.TEST') ?></h2>

<?php
/* Die Selbstpruefung laeuft bei jedem Aufruf der Seite. Sie liest nur -
 * kein API-Aufruf, kein Versand, kein Schreiben. */
$gpruef = gardena_selbstpruefung($gc, $gconfigfile, $gcachefile, $gcache, $lbpbindir, $lbpconfigdir, $gordner);
?>
<h3 class="sm-h3"><?= gardena_t('TEST.H_PRUEFUNG') ?></h3>
<div class="sm-alert <?= $gpruef['kreuze'] ? 'sm-err' : 'sm-ok' ?>"><b><?=
    gardena_e(sprintf(gardena_t($gpruef['kreuze'] ? 'TEST.ZUSAMMEN_FEHL' : 'TEST.ZUSAMMEN_OK'),
                      $gpruef['gut'], $gpruef['gesamt'], $gpruef['kreuze'])) ?></b></div>
<table class="sm-tbl">
<tr><th style="width:2.2em;"></th><th><?= gardena_t('TEST.SP_FRAGE') ?></th><th><?= gardena_t('TEST.SP_ANTWORT') ?></th></tr>
<?php foreach ($gpruef['zeilen'] as $gz) {
    $gk = ($gz['stand'] === 1) ? 'sm-ja' : (($gz['stand'] === 0) ? 'sm-hm' : 'sm-nein');
    $gzeichen = ($gz['stand'] === 1) ? '&#10003;' : (($gz['stand'] === 0) ? '&#8226;' : '&#10007;');
?>
<tr><td class="<?= $gk ?>"><?= $gzeichen ?></td><td><?= gardena_e($gz['frage']) ?></td><td><?= gardena_e($gz['antwort']) ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?= gardena_t('TEST.PRUEFUNG_HINWEIS') ?></div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= gardena_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= gardena_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= gardena_t('LEGENDE.AKTION') ?></span>
</div>

<h3 class="sm-h3"><?= gardena_t('TEST.H_ANSEHEN') ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen" href="/plugins/<?= $gpl ?>/index.php?selftest=1<?= $gtokenurl ?>" target="_blank"><?= gardena_t('TEST.K_SELFTEST') ?></a>
<a class="sm-btn sm-b-lesen" href="/plugins/<?= $gpl ?>/index.php?action=list<?= $gtokenurl ?>" target="_blank"><?= gardena_t('TEST.K_LISTE') ?></a>
<a class="sm-btn sm-b-lesen" href="/plugins/<?= $gpl ?>/index.php" target="_blank"><?= gardena_t('TEST.K_UEBERSICHT') ?></a>
</div>
<div class="sm-small"><?= gardena_t('TEST.SELFTEST_HINWEIS') ?></div>

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
<div class="sm-seite<?= gaktiv('tab-log') ?>" id="tab-log">
<h2><?= gardena_t('LOG.H') ?></h2>
<?php
/*
 * Der Reiter zeigt jetzt die Protokolle des PLUGINS, nicht mehr eine eigene
 * Datei.
 *
 * Bis 1.2.0 stand hier ausschliesslich gardena_ui.log - und die wurde von
 * gardena_log() nie beschrieben (siehe das Protokollobjekt weiter oben).
 * Der Reiter meldete deshalb dauerhaft "Noch keine Eintraege vorhanden",
 * waehrend im Protokoll des Dienstes alles stand, wonach der Anwender
 * suchte. LBWeb::loglist_html() ist der Hausstandard fuer diesen Reiter.
 *
 * Zwei Verfahren waeren eines zu viel: die Ersatzansicht darunter kommt nur
 * zum Zug, wenn es loglist_html() nicht gibt.
 */
$ghat_loglist = class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html');
?>
<div class="sm-small"><?= gardena_t('LOG.TEXT') ?></div>
<?php if ($ghat_loglist) { ?>
<?= LBWeb::loglist_html() ?>
<div class="sm-small"><?= gardena_t('LOG.MANAGER') ?></div>
<?php } else { ?>
<div class="sm-small"><?= gardena_t('LOG.DATEI') ?> <span class="sm-mono"><?= gardena_e($glogfile) ?></span></div>
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
<input data-role="none" type="hidden" name="formtoken" value="<?= gardena_e(gardena_formtoken($gc)) ?>">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <?php /* Orange, nicht rot: Rot ist im Hausstandard nicht vorgesehen -
       es liest sich als Warnung vor einer Gefahr, und ein geleertes
       Protokoll ist keine. Die Farbe sagt nur: dieser Knopf veraendert etwas. */ ?>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?= gardena_t('LOG.LEEREN') ?></button>
</form>
</div>
<?php } ?>
</div>

</div>
<script>
(function () {
    var tabs = document.querySelectorAll('.sm-tab');
    function activate(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-seite').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
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
