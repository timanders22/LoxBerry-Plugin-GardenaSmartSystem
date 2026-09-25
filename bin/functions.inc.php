<?php
/**
 * Gardena Smart System - gemeinsame Hilfsfunktionen
 *
 * Liegt seit 1.1.0 in bin/, also ausserhalb des Apache-Wurzelverzeichnisses.
 */

/* ==================================================================
 * Protokoll
 * ================================================================== */

/**
 * Eine Meldung ins Protokoll.
 *
 * Bis 1.0.2 stand hier nur der Weg ueber das LoxBerry-SDK, abgesichert mit
 * function_exists(). Ein toedlicher Fehler drohte dadurch NICHT - die
 * Behauptung, hier gaebe es "Call to undefined function", trifft nicht zu,
 * die Pruefung faengt genau das ab.
 *
 * Der wirkliche Schaden war ein anderer und stiller: die Admin-Oberflaeche
 * bindet loxberry_log.php gar nicht ein. Dort gab es also weder LOGERR noch
 * LOGINF, jede Pruefung schlug fehl - und die Funktion kehrte wortlos
 * zurueck. Wer sich fragte, warum das Speichern des Tokens nicht klappte,
 * fand im Protokoll nichts, weil nie etwas hineingeschrieben wurde.
 *
 * Bis 1.2.7 deshalb zweistufig: bevorzugt das SDK, ersatzweise eine eigene
 * Datei. Seit 1.2.8 nur noch die eigene Datei - siehe gardena_log().
 */

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins, data/plugins UND config/system/general.json traegt
 * (Regeln/06). Das trifft die uebliche Installation genauso wie eine an einem
 * anderen Ort. Findet sich keines - ein entpacktes Archiv, ein fremder Baum -,
 * ist die Antwort ein Leerstring, und kein Aufrufer baut daraus einen Pfad.
 *
 * Bis 1.2.9 genuegten config/plugins und webfrontend: ein ausgepacktes Archiv
 * in einem fremden Baum, der beides traegt (ein Pruefstandsrest), hielt
 * diesen Baum fuer die Wurzel (in WSL gemessen,
 * Pruefung-GardenaSmartSystem-1.2.10, Fall T3). Ein LoxBerry hat die
 * general.json immer, ein solcher Rest nie.
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/**
 * Die LoxBerry-Wurzel fuer Dateien des SYSTEMS (general.json, Sprachdateien).
 *
 * Der Reihe nach $lbhomedir und die Konstante LBHOMEDIR des SDK, $LBHOMEDIR,
 * zuletzt die Suche vom eigenen Ablageort aus. Es gilt nur ein Verzeichnis,
 * das config/system/general.json traegt. Sonst: Leerstring - und kein
 * Aufrufer setzt daraus einen Pfad ab der Laufwerkswurzel zusammen.
 */
function gardena_lbhome()
{
    $kandidaten = array(
        isset($GLOBALS['lbhomedir']) ? (string) $GLOBALS['lbhomedir'] : '',
        defined('LBHOMEDIR') ? (string) LBHOMEDIR : '',
        (string) getenv('LBHOMEDIR'),
    );
    foreach ($kandidaten as $k) {
        $k = rtrim($k, '/');
        if ($k !== '' && is_file($k . '/config/system/general.json')) { return $k; }
    }
    return (string) lb_wurzel_ermitteln();
}

/**
 * Wo laeuft dieses Plugin - und darf es auf die Anlage wirken?
 *
 *   'installiert'    diese Bibliothek liegt unter <Wurzel>/bin/plugins/<ordner>
 *                    (physisch verglichen), und die Wurzel traegt general.json
 *   'ausdruecklich'  der Aufrufer nennt Wurzel UND Ordner: $LBHOMEDIR und
 *                    $LBPPLUGINDIR sind gesetzt (so arbeiten die
 *                    Pruefwerkzeuge mit ihrer Attrappe)
 *   ''               ein ausgepacktes Archiv oder ein fremder Baum - dann wird
 *                    nichts geschrieben, nichts gesendet, nichts geschaltet
 *
 * Die Pfade kommen aus dem LoxBerry-SDK, und das erkennt den Pluginordner am
 * Pfad des aufgerufenen Skripts (Regeln/03, "LBPPLUGINDIR ist am Geraet keine
 * Umgebungsvariable"). Aus einem Archiv heraus erkennt es keinen: bis 1.2.9
 * schrieb gardenaMain.php dann seinen Zustand nach config/plugins/ und sein
 * Protokoll samt Sperrdatei nach log/plugins/ der Anlage, ohne Ordnernamen
 * (in WSL gemessen, Pruefung-GardenaSmartSystem-1.2.10, Fall L1; Bauart
 * tb_paths() von Spotpreis-Tibber 0.9.19).
 */
function gardena_lage()
{
    static $lage = null;
    if ($lage !== null) { return $lage; }
    $lage = '';
    $home = isset($GLOBALS['lbhomedir']) ? (string) $GLOBALS['lbhomedir'] : '';
    if ($home === '' && defined('LBHOMEDIR')) { $home = (string) LBHOMEDIR; }
    $home = rtrim($home, '/');
    $ordner = isset($GLOBALS['lbpplugindir']) ? (string) $GLOBALS['lbpplugindir'] : '';
    if ($ordner === '' && defined('LBPPLUGINDIR')) { $ordner = (string) LBPPLUGINDIR; }
    $konf = isset($GLOBALS['lbpconfigdir']) ? (string) $GLOBALS['lbpconfigdir'] : '';
    if ($home === '' || $ordner === '' || $konf === '') { return $lage; }
    $soll = @realpath($home . '/bin/plugins/' . $ordner);
    $ist = @realpath(__DIR__);
    if ($soll !== false && $ist !== false && $soll === $ist
        && is_file($home . '/config/system/general.json')) {
        $lage = 'installiert';
    } elseif ((string) getenv('LBPPLUGINDIR') !== ''
              && rtrim((string) getenv('LBHOMEDIR'), '/') === $home) {
        $lage = 'ausdruecklich';
    }
    return $lage;
}

/**
 * Das Protokoll des Plugins - EINE Datei, gardena.log.
 *
 * Bis 1.2.7 schrieben Dienst und Oberflaeche ueber das LoxBerry-SDK
 * (LBLog::newLog mit 'name' und 'logdir'). Am Geraet gemessen (LoxBerry 4.0,
 * 17.09.2026) hatte das zwei Folgen:
 *
 *   1. Jeder Cron-Lauf legte eine NEUE Datei mit Zeitstempel im Namen an -
 *      zwoelf je Stunde, auch bei ausgeschaltetem Plugin. log/plugins liegt
 *      auf der RAM-Scheibe, und log_maint.pl kuerzt dort stuendlich ueber
 *      ALLE Plugins hinweg auf die 24 juengsten Dateien. Gardena half damit,
 *      die Protokolle anderer Plugins wegzuraeumen.
 *   2. Die Plugin-Datenbank fuehrt fuer dieses Plugin loglevel -1 (plugin.cfg
 *      setzt CUSTOM_LOGLEVELS nicht). Das SDK schreibt dann nur die Kopf- und
 *      Schlusszeilen; jedes LOGINF, LOGERR und LOGCRIT fiel weg. Belegt an
 *      der Datei vom 17.09.2026 01:15: "Plugin ist deaktiviert" stand nicht
 *      darin. Ein Fehler des Dienstes war damit nirgends zu lesen.
 *
 * Jetzt schreibt JEDER Teil - Dienst, Oberflaeche, Endpunkt - in dieselbe
 * Datei, gekappt auf 256 kB. Der Loglevel-Waehler der Plugin-Verwaltung
 * bleibt aus: dieses Plugin wertet ihn nicht aus, also bietet es ihn nicht an.
 * 'DEB' wird nicht geschrieben (je Wert eine Zeile waere zu viel).
 */
function gardena_log_datei($name = 'gardena.log')
{
    $dir = isset($GLOBALS['lbplogdir']) ? (string) $GLOBALS['lbplogdir'] : '';
    if ($dir === '' || !is_dir($dir)) { return ''; }
    return rtrim($dir, '/') . '/' . $name;
}

function gardena_log($level, $msg)
{
    $level = strtoupper((string) $level);
    if ($level === 'DEB') { return; }
    $f = gardena_log_datei();
    if ($f === '') { return; }
    // clearstatcache VOR dem Tor: ein anhaengendes file_put_contents macht
    // den stat-Zwischenspeicher nicht ungueltig (unter 7.4 gemessen).
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 262144) {
        // Gekuerzt wird unter Sperre, sonst schreibt ein zweiter Prozess
        // waehrenddessen ans alte Ende und verliert seine Zeile.
        $fh = @fopen($f, 'c+');
        if ($fh && flock($fh, LOCK_EX)) {
            $rest = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -400);
            ftruncate($fh, 0); rewind($fh);
            fwrite($fh, implode("\n", $rest) . "\n");
            flock($fh, LOCK_UN);
        }
        if ($fh) { fclose($fh); }
    }
    // Eine Meldung ist eine Zeile: Texte der Wolke koennen Umbrueche tragen.
    $msg = str_replace(array("\r\n", "\r", "\n"), ' ', (string) $msg);
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $level . ' ' . $msg . "\n",
                       FILE_APPEND | LOCK_EX);
}

/**
 * Eine Zustandsmeldung, die sich bei jedem Lauf wiederholt, hoechstens
 * einmal je Stunde - oder sofort, wenn sich ihr Wortlaut geaendert hat.
 *
 * "Plugin ist deaktiviert" oder "nichts geaendert" stuenden sonst
 * 288-mal am Tag im Protokoll, und die eine Zeile, die zaehlt, ginge darin
 * unter. Der Merker liegt im Protokollordner (RAM-Scheibe, kein Schreiben
 * auf die Speicherkarte) und endet nicht auf .log, damit die Logwartung
 * ihn nicht mitzaehlt.
 */
function gardena_log_gebremst($merker, $level, $msg, $sekunden = 3600)
{
    $s = gardena_log_datei('gardena_' . preg_replace('/[^a-z0-9_]/', '', (string) $merker) . '.merker');
    if ($s === '') { return; }
    clearstatcache(true, $s);
    if (is_file($s) && (time() - (int) filemtime($s)) < $sekunden
        && (string) @file_get_contents($s) === (string) $msg) {
        return;
    }
    gardena_log($level, $msg);
    @file_put_contents($s, (string) $msg);
}

/**
 * Protokollzeile des Loxone-Endpunkts.
 *
 * Bewusst OHNE das SDK: der Endpunkt laeuft unter dem Webserver, legt kein
 * Protokollobjekt an (LBLog::newLog), und gardena_log() nimmt trotzdem den
 * SDK-Weg, sobald loxberry_log.php eingebunden ist - die Zeile verschwindet
 * dann spurlos. Genau diese Bauart machte in 1.1.9 den Reiter Logdateien
 * strukturell leer.
 *
 * Warum es die Zeile ueberhaupt braucht: der Miniserver kann sich nicht
 * beschweren. Ohne Protokoll ist "der Miniserver ruft nicht an" nicht von
 * "er ruft an und wird abgewiesen" zu unterscheiden.
 *
 * Die Zugangsmarke steht NIE darin - ein Protokoll, das Geheimnisse
 * mitschreibt, verlagert das Problem nur in eine Datei, die laenger lebt.
 * Von aussen kommende Werte, die gerade abgewiesen wurden, stehen mit ihrer
 * LAENGE darin, nicht im Wortlaut.
 */
function gardena_endpunkt_log($msg, $gebremst = false)
{
    $dir = isset($GLOBALS['lbplogdir']) ? (string) $GLOBALS['lbplogdir'] : '';
    if ($dir === '' || !is_dir($dir)) { return false; }
    $f = rtrim($dir, '/') . '/gardena_endpunkt.log';

    /*
     * Lesende Erfolge werden GEBREMST.
     *
     * Der Miniserver fragt den Endpunkt im Sechzigsekundentakt ab. Jede
     * geglueckte Abfrage zu protokollieren ergaebe 1440 gleichlautende Zeilen
     * am Tag - ein Dauerzustand gehoert in die gebremste Meldung, sonst sieht
     * beim dritten Mal niemand mehr hin. Abweisungen und schaltende Aufrufe
     * sind selten und gehen IMMER hinaus; sie sind der Grund, warum es das
     * Protokoll gibt.
     *
     * Der Merker liegt in log/ - auf dem LoxBerry eine Ramdisk, also kein
     * Schreibvorgang je Minute auf der Speicherkarte.
     */
    if ($gebremst) {
        $stempel = rtrim($dir, '/') . '/gardena_endpunkt.stempel';
        clearstatcache(true, $stempel);
        if (is_file($stempel) && (time() - (int) filemtime($stempel)) < 3600) {
            return true;
        }
        @touch($stempel);
        $msg .= ' (gebremst: die naechste Zeile dieser Art fruehestens in einer Stunde)';
    }

    // clearstatcache VOR dem aeusseren Tor: PHP haelt die stat()-Antwort im
    // Zwischenspeicher, und ein anhaengendes file_put_contents macht den
    // Eintrag nicht ungueltig. Unter 7.4 oeffnete die Kappung sonst nie.
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 262144) {
        $fh = @fopen($f, 'c+');
        if ($fh && flock($fh, LOCK_EX)) {
            $rest = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200);
            ftruncate($fh, 0); rewind($fh);
            fwrite($fh, implode("\n", $rest) . "\n");
            flock($fh, LOCK_UN);
        }
        if ($fh) { fclose($fh); }
    }
    $wer = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '-';
    $wer = preg_replace('/[^0-9a-fA-F.:]/', '', $wer);
    if ($wer === '') { $wer = '-'; }
    return @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $wer . ' ' . $msg . "\n",
                              FILE_APPEND | LOCK_EX) !== false;
}

/* ==================================================================
 * UDP und MQTT
 * ================================================================== */

/**
 * Kann dieses PHP ueberhaupt UDP?
 *
 * Bis 1.1.9 stand in sendUDP() nur ein @ vor socket_create(). Das
 * unterdrueckt Warnungen - eine "Call to undefined function" ist aber ein
 * toedlicher Fehler und laesst sich nicht unterdruecken. Fehlte die
 * Erweiterung php-sockets, starb der Cron-Lauf beim ERSTEN Wert: der
 * Geraete-Zwischenspeicher wurde nie geschrieben, die Oberflaeche zeigte
 * dauerhaft "Noch keine Daten", die Vorlagenknoepfe blieben ausgeblendet,
 * ?action=command fand nie eine Dienstkennung, und das Protokoll brach ohne
 * LOGEND mitten ab. Die Oberflaeche warnte korrekt vor der fehlenden
 * Erweiterung (function_exists), der Dienst nicht.
 *
 * dpkg/apt installiert php-sockets nicht - postinstall.sh rechnet ausdruecklich
 * mit ihrem Fehlen. Der Fall ist also vorgesehen und muss getragen werden.
 *
 * Betrifft BEIDE Wege: das MQTT-Gateway wird ebenfalls ueber UDP beschickt.
 */
function gardena_udp_moeglich()
{
    return function_exists('socket_create') && function_exists('socket_sendto')
        && function_exists('socket_close');
}

function sendUDP($data, $destIP, $destPort)
{
    $destPort = (int) $destPort;
    if ($destIP === '' || $destPort < 1 || $destPort > 65535) {
        gardena_log('ERR', 'UDP: unbrauchbares Ziel (' . $destIP . ':' . $destPort . ')');
        return false;
    }
    if (!gardena_udp_moeglich()) {
        gardena_log('ERR', 'Die PHP-Erweiterung sockets fehlt - es kann WEDER ueber UDP '
            . 'NOCH ueber MQTT gesendet werden. Nachinstallieren: apt-get install -y php-sockets');
        return false;
    }
    if (!($socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP))) {
        $errorcode = socket_last_error();
        gardena_log('ERR', 'UDP-Socket konnte nicht erstellt werden: ['
            . $errorcode . '] ' . socket_strerror($errorcode));
        return false;
    }
    // mbstring steht nicht in dpkg/apt und ist damit nicht zugesichert. Der
    // Aufruf soll ungueltiges UTF-8 aus der fremden Wolke wegraeumen; fehlt
    // die Erweiterung, geht die Zeichenkette unveraendert hinaus. Das ist
    // schlechter als die Bereinigung, aber unendlich viel besser als ein
    // toedlicher Fehler mitten im Sendelauf.
    $dataEnc = function_exists('mb_convert_encoding')
        ? mb_convert_encoding((string) $data, 'UTF-8', 'UTF-8')
        : (string) $data;
    $numBytesSent = @socket_sendto($socket, $dataEnc, strlen($dataEnc), 0, $destIP, $destPort);
    if ($numBytesSent === false) {
        $errorcode = socket_last_error($socket);
        gardena_log('ERR', 'UDP-Daten konnten nicht gesendet werden: ['
            . $errorcode . '] ' . socket_strerror($errorcode));
        socket_close($socket);
        return false;
    }
    socket_close($socket);
    return true;
}

/**
 * Den UDP-Eingangsport des LoxBerry-MQTT-Gateways ermitteln.
 *
 * Zwei Wege, in dieser Reihenfolge:
 *
 *   1. mqtt_connectiondetails() aus dem SDK. Die gibt es nur, wenn
 *      loxberry_io.php geladen wurde - in der Admin-Oberflaeche ist das
 *      nicht der Fall, und bis 1.0.2 war damit ueberhaupt kein Port zu
 *      bekommen.
 *   2. Unmittelbar aus config/system/general.json. Das MQTT-Gateway ist
 *      seit LoxBerry 3 Bestandteil des Systems, die Datei ist immer da.
 *
 * Beide Schluesselschreibweisen werden gelesen: die Datei fuehrt je nach
 * LoxBerry-Fassung "Mqtt"/"Udpinport" oder "mqtt"/"udpinport".
 */
function gardena_mqtt_udpport()
{
    static $port = null;
    if ($port !== null) { return $port; }
    $port = 0;

    if (function_exists('mqtt_connectiondetails')) {
        $creds = mqtt_connectiondetails();
        if (is_array($creds) && !empty($creds['udpinport'])) {
            $port = (int) $creds['udpinport'];
        }
    }
    if (!$port) {
        // Ohne Wurzel wird nichts gelesen. Bis 1.2.9 hiess eine leere Wurzel
        // '' . '/config/system/general.json', also eine Datei ab der
        // Laufwerkswurzel, und deren Port galt (in WSL gemessen,
        // Pruefung-GardenaSmartSystem-1.2.10, Fall T2).
        $home = gardena_lbhome();
        $gen = ($home !== '')
            ? @json_decode((string) @file_get_contents($home . '/config/system/general.json'), true)
            : null;
        // is_array() vor dem verschachtelten Zugriff: waere der Wert eine
        // Zeichenkette mit Inhalt, verrechnete PHP den Schluessel zu
        // Position 0, isset() waere wahr, und der Port ergaebe sich aus dem
        // ersten Buchstaben. Die Meldungen gingen dann an einen
        // ausgewuerfelten Port.
        foreach (array(array('Mqtt', 'Udpinport'), array('mqtt', 'udpinport')) as $paar) {
            list($a, $b) = $paar;
            if (isset($gen[$a]) && is_array($gen[$a]) && isset($gen[$a][$b])) {
                $port = (int) $gen[$a][$b];
                if ($port) { break; }
            }
        }
    }
    if ($port < 1 || $port > 65535) {
        $port = 0;
        gardena_log('ERR', 'MQTT-Gateway: UDP-Eingangsport nicht ermittelbar - '
            . 'ist das MQTT-Gateway im LoxBerry eingerichtet?');
    } else {
        gardena_log('DEB', 'MQTT-Gateway, UDP-Eingangsport: ' . $port);
    }
    return $port;
}

/**
 * Ein Thema fuer das MQTT-Gateway.
 *
 * Das Gateway liest die UDP-Zeile als drei Teile: Verb, Thema, Rest. Getrennt
 * wird an Leerzeichen - ein Leerzeichen IM Thema verschiebt alles dahinter.
 *
 * Bis 1.0.2 wurden nur Leerzeichen ersetzt. Das genuegt nicht: die Themen
 * werden aus GERAETENAMEN gebaut, und die vergibt der Anwender in der
 * Gardena-App frei. Ein Umbruch, ein Doppelkreuz (# ist im MQTT-Thema ein
 * Platzhalter!), ein Pluszeichen (ebenso) oder ein Umlaut haben dort nichts
 * verloren.
 */
function gardena_mqtt_thema($thema)
{
    $t = preg_replace('#[^A-Za-z0-9_/\-]#', '_', (string) $thema);
    return trim(preg_replace('#/+#', '/', $t), '/');
}

/**
 * Eine Nutzlast fuer das MQTT-Gateway.
 *
 * Zeilenumbrueche muessen weg: das Gateway liest zeilenweise. Ein Umbruch
 * mitten in der Nutzlast macht aus einer Nachricht zwei - die zweite beginnt
 * nicht mit dem Verb und wird verworfen. Leerzeichen sind dagegen in Ordnung,
 * das Gateway nimmt den ganzen Rest der Zeile.
 */
function gardena_mqtt_nutzlast($wert)
{
    $w = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $wert);
    return trim(preg_replace('/ {2,}/', ' ', $w));
}

/**
 * MQTT-Publish ueber das LoxBerry-MQTT-Gateway (UDP-Schnittstelle).
 * Kein eigener MQTT-Client noetig - das Gateway nimmt Nachrichten der Form
 * "publish <Thema> <Wert>" bzw. "retain <Thema> <Wert>" entgegen.
 */
function mqttPublish($topic, $value, $retain = false)
{
    $udpport = gardena_mqtt_udpport();
    if (!$udpport) { return false; }
    $nutzlast = gardena_mqtt_nutzlast($value);
    /*
     * Ein LEERER Wert geht nie retained hinaus.
     *
     * Eine leere Nutzlast mit Retain LOESCHT das zurueckbehaltene Thema
     * (mqttgateway.pl, sub udpin). Gesaeubert wird vorher: ein Wert aus
     * blossem Leerraum ist erst NACH gardena_mqtt_nutzlast() leer - geprueft
     * wird deshalb hier, nicht beim Aufrufer. Loeschen ist eine eigene
     * Absicht und hat eine eigene Funktion: gardena_mqtt_loeschen().
     *
     * Die Vorgabe ist seit 1.2.8 FLUECHTIG: ein Thema, fuer das niemand
     * entschieden hat, soll nicht auf Dauer im Broker stehen.
     */
    if ($nutzlast === '') { $retain = false; }
    $msg = ($retain ? 'retain ' : 'publish ') . gardena_mqtt_thema($topic) . ' ' . $nutzlast;
    return sendUDP($msg, '127.0.0.1', $udpport);
}

/**
 * Ein zurueckbehaltenes Thema im Broker loeschen (leere Nutzlast mit Retain).
 *
 * Gemessen am Gateway (LoxBerry 4.0, mqttgateway.pl v1, 06.09.2026):
 * ist das Thema im Gateway abonniert, bekommt es seine eigene leere
 * Veroeffentlichung zurueck und reicht sie als LEEREN Wert an den Miniserver
 * weiter ("HTTP: Preparing input ... : " - 74 Faelle im Protokoll). Geloescht
 * wird also nur im Broker; der virtuelle Eingang bekommt einen leeren Wert.
 * Wer danach einen gueltigen Wert hat, schickt ihn unmittelbar hinterher.
 */
function gardena_mqtt_loeschen($topic)
{
    $udpport = gardena_mqtt_udpport();
    if (!$udpport) { return false; }
    return sendUDP('retain ' . gardena_mqtt_thema($topic) . ' ', '127.0.0.1', $udpport);
}

/**
 * Die Themen unter Plugin/STATUS, die eine Vorfassung zurueckbehalten hat:
 * bis 1.2.5 ging das Lebenszeichen retained hinaus (Kopfkommentar der
 * Altwert-Abraeumung in gardenaMain.php). 'ts' und 'zaehler' kamen mit 1.2.8
 * und waren nie retained - eine leere Nachricht auf ihnen loeschte nichts.
 */
function gardena_altlast_status()
{
    return array('ok', 'zeitstempel', 'werte', 'fehler');
}

/**
 * Den Broker fragen, welche der Themen $themen er zurueckbehaelt - in EINER
 * Verbindung, ein SUBSCRIBE mit allen Filtern.
 *
 * Rueckgabe array('lage' => 'ok'|'unbekannt', 'belegt' => array(thema => true)).
 * 'ok': der Broker hat die Anmeldung (CONNACK 0) und JEDEN Filter
 * (SUBACK-Rueckgabe unter 0x80) bestaetigt; was dann nicht unter 'belegt'
 * steht, ist leer. 'unbekannt': er war nicht zu fragen - keine Wurzel, keine
 * general.json, keine Verbindung, Anmeldung abgewiesen, Filter abgelehnt,
 * keine Antwort. "Nicht zu fragen" heisst nie "nichts belegt".
 *
 * Warum ueberhaupt fragen: gesendet wird ueber den UDP-Eingang des Gateways,
 * und dort meldet socket_sendto() auch fuer ein verworfenes Datagramm Erfolg
 * (Regeln/07, "Ein Absender merkt nichts davon", am Geraet belegt). Belegt
 * ist ein Abraeumen erst, wenn der Broker selbst sagt, dass nichts mehr
 * dasteht - und belegt ist ein Thema nur am EMPFANGENEN Paket mit
 * Retain-Merkmal und nicht leerer Nutzlast.
 *
 * MQTT 3.1.1 von Hand, nur CONNECT, SUBSCRIBE (QoS 0) und DISCONNECT, ohne
 * fremde Bibliothek; Bauart bw_mqtt_behalten_liste() (Beschattungswaechter
 * 0.9.21). Die Anmeldung nimmt Brokeruser/Brokerpass aus der general.json
 * (Regeln/07, Abschnitt 2); das Kennwort steht nur im CONNECT-Paket, nie in
 * einem Protokoll und nie auf einer Kommandozeile.
 */
function gardena_mqtt_behalten_liste(array $themen)
{
    $aus = array('lage' => 'unbekannt', 'belegt' => array());
    $soll = array();
    foreach ($themen as $t) {
        if ((string) $t !== '') { $soll[(string) $t] = true; }
    }
    if (!$soll) {
        $aus['lage'] = 'ok';
        return $aus;
    }
    $home = gardena_lbhome();
    if ($home === '') { return $aus; }
    $d = @json_decode((string) @file_get_contents($home . '/config/system/general.json'), true);
    if (!is_array($d) || !isset($d['Mqtt']) || !is_array($d['Mqtt'])) { return $aus; }
    $m = $d['Mqtt'];
    $hol = function ($k) use ($m) {
        return (isset($m[$k]) && is_scalar($m[$k])) ? (string) $m[$k] : '';
    };
    $host = trim($hol('Brokerhost'));
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    $port = (int) $hol('Brokerport');
    if ($port <= 0 || $port > 65535) { $port = 1883; }
    $benutzer = $hol('Brokeruser');
    $kennwort = $hol('Brokerpass');

    $errno = 0;
    $errstr = '';
    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 2);
    if (!$s) { return $aus; }
    stream_set_timeout($s, 1);

    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) { $b |= 128; }
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    };
    /* Genau $n Bytes lesen oder null - bei Zeitablauf und Verbindungsende. */
    $lies = function ($n) use ($s) {
        $d = '';
        while (strlen($d) < $n) {
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    /* Ein Paket: array(kopfbyte, rumpf) oder null. */
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) { return null; }
        $n = 0;
        $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = $lies(1);
            if ($b === null) { return null; }
            $n += (ord($b) & 127) * $mult;
            $mult *= 128;
            if (!(ord($b) & 128)) { break; }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };

    $flags = 0x02;                                  // saubere Sitzung
    $nutz = $zk('garueck' . getmypid());
    if ($benutzer !== '') {
        $flags |= 0x80;
        // Ein Kennwort ohne Benutzer laesst MQTT 3.1.1 nicht zu.
        if ($kennwort !== '') { $flags |= 0x40; }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 10);
    if ($benutzer !== '') {
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') { $nutz .= $zk($kennwort); }
    }
    if (@fwrite($s, chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz) !== false) {
        $ack = $paket();
        // CONNACK: Art 2, zweites Byte 0 = angenommen. Jede andere Antwort
        // (5 = nicht berechtigt) heisst "nicht zu fragen".
        if ($ack !== null && ($ack[0] >> 4) === 2 && strlen($ack[1]) >= 2 && ord($ack[1][1]) === 0) {
            $sub = pack('n', 1);
            foreach (array_keys($soll) as $t) { $sub .= $zk($t) . chr(0); }
            @fwrite($s, chr(0x82) . $laenge(strlen($sub)) . $sub);
            $bestaetigt = false;
            $abgelehnt = false;
            $ende = microtime(true) + 3.0;
            while (microtime(true) < $ende) {
                $pk = $paket();
                if ($pk === null) { break; }           // Zeitablauf: nichts mehr gekommen
                $art = $pk[0] >> 4;
                if ($art === 9) {
                    /* Je Filter ein Rueckgabebyte hinter der Paketkennung;
                       0x80 heisst abgelehnt - danach schickt der Broker
                       nichts, und das waere sonst "nichts belegt". */
                    $rc = (string) substr($pk[1], 2);
                    if (strlen($rc) !== count($soll)) { $abgelehnt = true; }
                    for ($i = 0; $i < strlen($rc); $i++) {
                        if (ord($rc[$i]) >= 0x80) { $abgelehnt = true; }
                    }
                    if ($abgelehnt) { break; }
                    $bestaetigt = true;
                    // Zurueckbehaltenes kommt unmittelbar nach dem SUBACK.
                    $ende = min($ende, microtime(true) + 1.0);
                } elseif ($art === 3 && strlen($pk[1]) >= 2) {
                    $tl = unpack('n', substr($pk[1], 0, 2));
                    $t = substr($pk[1], 2, $tl[1]);
                    $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
                    $wert = (string) substr($pk[1], $versatz);
                    // Am empfangenen Paket: nur mit gesetztem Retain-Merkmal.
                    if (isset($soll[$t]) && ($pk[0] & 1) && $wert !== '') {
                        $aus['belegt'][$t] = true;
                        if (count($aus['belegt']) === count($soll)) { break; }
                    }
                }
            }
            if ($bestaetigt && !$abgelehnt) {
                $aus['lage'] = 'ok';
            } else {
                $aus['belegt'] = array();
            }
        }
        @fwrite($s, chr(0xE0) . chr(0));
    }
    fclose($s);
    return $aus;
}

/**
 * Welche der Themen $kandidaten tragen noch einen zurueckbehaltenen Altwert?
 * Rueckgabe: die Themen, die in diesem Vollversand UNMITTELBAR vor ihrem
 * gueltigen Wert mit leerer retain-Nutzlast abgeraeumt werden
 * (gardena_wert_senden(), gardena_lebenszeichen()).
 *
 * Gefragt wird nur nach Themen, die der Broker nicht schon leer gemeldet hat
 * (Merker im Datenordner). Seine Antwort entscheidet:
 *   leer gemeldet    -> in den Merker, nichts abraeumen
 *   belegt gemeldet  -> abraeumen, NICHT in den Merker; der naechste
 *                       Vollversand fragt wieder
 *   nicht zu fragen  -> alle offenen abraeumen, KEIN Merker - dann raeumt
 *                       jeder Vollversand ab (README, Grenze)
 * Der Merker entsteht nur aus der Antwort des Brokers, nie aus dem Senden
 * (Regeln/07, Nachtrag 19.09.2026). Er traegt die Kennung
 * "gardena leer-bestaetigt v1" und die vollen Themen samt Basisthema;
 * retain_stand aus gardena_status.json (1.2.8, 1.2.9) gilt nicht.
 * purge_installation leert den Datenordner bei jedem Update, danach wird
 * einmal nachgefragt.
 */
function gardena_altlast(array $kandidaten)
{
    $kennung = 'gardena leer-bestaetigt v1';
    $datadir = isset($GLOBALS['lbpdatadir']) ? (string) $GLOBALS['lbpdatadir'] : '';
    if ($datadir === '' && defined('LBPDATADIR')) { $datadir = (string) LBPDATADIR; }
    $merker = ($datadir !== '') ? rtrim($datadir, '/') . '/retain_altlast_bestaetigt' : '';
    $bestaetigt = array();
    if ($merker !== '' && is_file($merker)) {
        $zeilen = explode("\n", (string) @file_get_contents($merker));
        if (trim((string) array_shift($zeilen)) === $kennung) {
            foreach ($zeilen as $t) {
                $t = trim($t);
                if ($t !== '') { $bestaetigt[$t] = true; }
            }
        }
    }
    $offen = array();
    foreach ($kandidaten as $t) {
        $t = (string) $t;
        if ($t !== '' && !isset($bestaetigt[$t])) { $offen[$t] = true; }
    }
    if (!$offen) { return array(); }
    $f = gardena_mqtt_behalten_liste(array_keys($offen));
    if ($f['lage'] !== 'ok') {
        gardena_log_gebremst('altlast_unbekannt', 'INF', 'MQTT: der Broker liess sich nicht befragen '
            . '(Brokerhost, Brokerport und Zugangsdaten in general.json) - fruehere Altwerte gehen '
            . 'in jedem Vollversand mit leerer Nutzlast unmittelbar vor dem gueltigen Wert hinaus. '
            . 'Siehe README.', 86400);
        return array_keys($offen);
    }
    $neu = $bestaetigt;
    foreach (array_keys($offen) as $t) {
        if (!isset($f['belegt'][$t])) { $neu[$t] = true; }
    }
    if ($merker !== '' && count($neu) > count($bestaetigt)) {
        ksort($neu);
        if (!is_dir($datadir)) { @mkdir($datadir, 0775, true); }
        @file_put_contents($merker, $kennung . "\n" . implode("\n", array_keys($neu)) . "\n");
    }
    if ($f['belegt']) {
        $b = array_keys($f['belegt']);
        gardena_log('INF', 'MQTT: ' . count($b) . ' zurueckbehaltene Altwerte im Broker ('
            . implode(', ', array_slice($b, 0, 5)) . (count($b) > 5 ? ', ...' : '')
            . ') - sie gehen mit leerer Nutzlast unmittelbar vor dem gueltigen Wert hinaus; '
            . 'der naechste Vollversand fragt wieder nach.');
    }
    return array_keys($f['belegt']);
}

/**
 * Die zurueckbehaltenen Themen der Linie leeren - fuer uninstall/uninstall
 * (gardenaMain.php --mqtt-leeren). Ins Protokoll kommen hoechstens
 * Sendefehler; was geschah, steht in der Ausgabe fuer den Installer.
 *
 * Welche Themen: jedes, das eine Fassung retained gesendet haben kann - alle
 * Geraetethemen aus dem Zustand ('themen'; bis 1.2.7 ging jeder Geraetewert
 * retained hinaus), die noch nicht bestaetigt geloeschten weggefallenen
 * ('weg_offen') und die vier alten Lebenszeichen-Themen. VOR der ersten Runde
 * und nach jeder wird der Broker gefragt; hinaus geht nur, was dort noch
 * steht, hoechstens $runden Runden. Ist er nicht zu fragen, gehen alle in
 * jeder Runde hinaus, und die Ausgabe sagt, dass nicht nachgelesen wurde - der
 * Eingang verwirft unter Last Datagramme (Regeln/07). Bauart bw_mqtt_leeren()
 * (Beschattungswaechter 0.9.21).
 *
 * Rueckgabe 0 geleert oder nicht nachpruefbar, 1 es steht noch etwas, 2 nicht
 * moeglich.
 */
function gardena_mqtt_leeren($cfgdir, $runden = 3, $pause_us = 1000000)
{
    $g = gardena_cfg_read(rtrim((string) $cfgdir, '/') . '/gardena.cfg');
    $basis = !empty($g['MQTT_TOPIC']) ? rtrim($g['MQTT_TOPIC'], '/') : 'gardena';
    $port = gardena_mqtt_udpport();
    if (!$port) {
        echo '<INFO> MQTT: kein UDP-Eingangsport des Gateways bekannt - zurueckbehaltene Themen '
           . 'unter ' . gardena_mqtt_thema($basis) . '/ wurden nicht geleert.' . "\n";
        return 2;
    }
    $st = gardena_status_lesen($cfgdir);
    $alle = array();
    foreach ((array) $st['themen'] as $t) {
        $t = (string) $t;
        if (strpos($t, '|') !== false) {          // Stand aus 1.2.5
            $tp = explode('|', $t, 3);
            if (count($tp) !== 3) { continue; }
            $t = gardena_wert_thema($basis, $tp[0], $tp[1], $tp[2]);
        }
        if ($t !== '') { $alle[$t] = true; }
    }
    foreach (array_keys((array) $st['weg_offen']) as $t) {
        if ((string) $t !== '') { $alle[(string) $t] = true; }
    }
    foreach (gardena_altlast_status() as $sn) {
        $alle[gardena_wert_thema($basis, 'Plugin', 'STATUS', $sn)] = true;
    }
    $alle = array_keys($alle);
    $n = count($alle);
    $f = gardena_mqtt_behalten_liste($alle);
    $nachgelesen = ($f['lage'] === 'ok');
    $offen = $nachgelesen ? array_keys($f['belegt']) : $alle;
    if ($nachgelesen && !$offen) {
        echo '<OK> MQTT: der Broker bestaetigt: keines der ' . $n . ' Themen der Linie steht '
           . 'zurueckbehalten - nichts zu leeren.' . "\n";
        return 0;
    }
    $zu_leeren = count($offen);
    $datagramme = 0;
    $gelaufen = 0;
    for ($r = 1; $r <= max(1, (int) $runden) && $offen; $r++) {
        if ($r > 1) { usleep((int) $pause_us); }
        $gelaufen = $r;
        foreach ($offen as $t) {
            if (gardena_mqtt_loeschen($t)) { $datagramme++; }
        }
        usleep(300000);     // dem Gateway Zeit bis zum Broker lassen
        $f = gardena_mqtt_behalten_liste($offen);
        if ($f['lage'] === 'ok') {
            $nachgelesen = true;
            $offen = array_keys($f['belegt']);
        } else {
            $nachgelesen = false;
        }
    }
    echo '<INFO> MQTT: ' . $zu_leeren . ' von ' . $n . ' Themen mit leerer Nutzlast an den '
       . 'UDP-Eingang ' . (int) $port . ' des Gateways gesendet (' . $gelaufen . ' Runde(n), '
       . $datagramme . ' Datagramme).' . "\n";
    if ($nachgelesen && !$offen) {
        echo '<OK> MQTT: der Broker bestaetigt: keines der ' . $n . ' Themen steht mehr '
           . 'zurueckbehalten.' . "\n";
        return 0;
    }
    if ($nachgelesen) {
        echo '<WARNING> MQTT: ' . count($offen) . ' Themen stehen noch zurueckbehalten im Broker ('
           . implode(', ', array_slice($offen, 0, 5)) . (count($offen) > 5 ? ', ...' : '')
           . '). Von Hand: mosquitto_pub -r -n -t <thema> (mit den Broker-Zugangsdaten).' . "\n";
        return 1;
    }
    echo '<INFO> MQTT: der Broker liess sich nicht befragen - nicht nachgelesen. Der UDP-Eingang '
       . 'verwirft unter Last Datagramme; was stehen bleibt, laesst sich mit '
       . 'mosquitto_pub -r -n -t <thema> von Hand loeschen.' . "\n";
    return 0;
}

/**
 * Retain je Thema - die EINE Tabelle, aus der Dienst und Oberflaeche lesen.
 *
 * Hausstandard (03.09.2026): Zustaende retained, Messwerte mit Zeitbezug
 * nicht, das Lebenszeichen nie. Bis 1.2.7 ging JEDER Geraetewert retained
 * hinaus - auch Bodentemperatur und Funkpegel. Nach einem Ausfall des
 * Plugins lieferte der Broker einem neu verbindenden Miniserver diese Werte
 * als frisch.
 *
 * Entschieden wird je ATTRIBUT (der Name ist ueber alle Dienste eindeutig in
 * seiner Bedeutung). Was nicht in der Liste steht, geht fluechtig hinaus.
 *
 *   retained      activity, state, lastErrorCode, batteryState, rfLinkState,
 *                 operatingHours (ein Zaehlerstand ist ein Zustand), name,
 *                 serial, modelType
 *   nicht         batteryLevel, rfLinkLevel, soilHumidity, soilTemperature,
 *                 ambientTemperature, lightIntensity - Messwerte, die die
 *                 Wolke laufend nachliefert; der volle Satz geht ohnehin
 *                 spaetestens alle 30 Minuten hinaus
 *   nie           alles unter Plugin/STATUS (das Lebenszeichen)
 */
function gardena_retain_zustaende()
{
    return array('activity', 'state', 'lastErrorCode', 'batteryState', 'rfLinkState',
                 'operatingHours', 'name', 'serial', 'modelType');
}

function gardena_retain($geraet, $dienst, $attribut)
{
    if ((string) $geraet === 'Plugin' && (string) $dienst === 'STATUS') { return false; }
    return in_array((string) $attribut, gardena_retain_zustaende(), true);
}

/* ==================================================================
 * Thema und Eingangsname - EINE Quelle fuer Sender und Vorlage
 *
 * Bis 1.1.9 wurden beide getrennt gebaut, und sie liefen auseinander:
 *
 *   gesendet  (mqttPublish -> gardena_mqtt_thema)
 *       jedes Zeichen ausserhalb A-Za-z0-9_/- wird zu '_'
 *   Vorlage   (webfrontend/htmlauth/index.php)
 *       nur str_replace('/', '_', ...) auf den ROHEN Geraetenamen
 *
 * Gemessen unter PHP 7.4.33 und 8.4.24:
 *
 *   Geraet "Maehroboter"           -> Thema  gardena/Maehroboter/...
 *                                     Vorlage gardena_Maehroboter_...   gleich
 *   Geraet "Maehroboter Vorgarten" -> Thema  gardena/Maehroboter_Vorgarten/...
 *                                     Vorlage gardena_Maehroboter Vorgarten_...
 *   Geraet mit Umlaut              -> im Thema ZWEI Unterstriche (das Muster
 *                                     arbeitet byteweise), in der Vorlage der
 *                                     Umlaut selbst
 *
 * Nur ein Name aus reinen ASCII-Buchstaben, Ziffern und Bindestrich traf.
 * Bei jedem anderen legte die Vorlage Eingaenge an, die NIE einen Wert
 * bekommen und auf DefVal="0" stehenbleiben - in Loxone sieht das aus wie
 * "Akku 0 %". Das Gateway legte daneben einen zweiten Satz unter den
 * wirklichen Namen an. Der Anwender sucht den Fehler dann in Loxone Config.
 *
 * Seit 1.2.0 bauen beide Seiten ueber diese zwei Funktionen. Der Aufruf von
 * gardena_mqtt_thema() in mqttPublish() bleibt stehen und schadet nicht: das
 * Ergebnis besteht nur noch aus erlaubten Zeichen, ein zweiter Durchlauf
 * aendert daran nichts.
 *
 * Die Umschreibung SELBST wurde bewusst NICHT angefasst. Ein Umlaut ergibt
 * weiterhin zwei Unterstriche. Das ist unschoen, aber jede Aenderung daran
 * benennt auf einer bestehenden Anlage saemtliche Themen um - die vorhandenen
 * virtuellen Eingaenge in Loxone und die retained-Werte im Broker haengen
 * daran. Das waere ein bewusster Schnitt mit Umstiegshinweis, keine
 * Fehlerbehebung.
 * ================================================================== */

/**
 * Die UDP-Zeile eines einzelnen Wertes - an genau EINER Stelle.
 *
 * Bis 1.2.5 baute der Sender sie hier und die Loxone-Vorlage ein zweites Mal
 * selbst zusammen. Der Sender liess dabei Umbrueche, Tabulatoren und
 * Mehrfachleerzeichen zusammenfallen, die Vorlage nicht: bei einem
 * Geraetenamen mit zwei Leerzeichen schrieb die Vorlage einen Suchtext, den
 * die gesendete Zeile nie enthielt. Der virtuelle Eingang blieb dann auf
 * DefVal="0" stehen - in Loxone sieht das aus wie ein gemessener Wert.
 * Das ist derselbe Fehler, der auf dem MQTT-Weg in 1.2.0 behoben wurde.
 *
 * $wert === null liefert die Zeile fuer die VORLAGE: dort steht statt des
 * Wertes der Loxone-Platzhalter.
 */
function gardena_udp_zeile($dienst, $geraet, $attribut, $wert = null)
{
    $ende = ($wert === null) ? '\\v' : (string) $wert;
    return gardena_mqtt_nutzlast($dienst . '.' . $geraet . '.' . $attribut . ':' . $ende);
}

/**
 * Maskieren fuer die Ausgabe. Gehoert in die Bibliothek, nicht in die
 * index.php: sonst kann keine Bibliotheksfunktion sie benutzen, und ein
 * Pruefstand, der nur die Bibliothek laedt, stirbt an "undefined function".
 * function_exists davor, damit eine aeltere index.php mit eigener Fassung
 * weiterlaeuft.
 */
if (!function_exists('gardena_e')) {
    function gardena_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

/** Das MQTT-Thema eines einzelnen Wertes. */
function gardena_wert_thema($basis, $geraet, $dienst, $attribut)
{
    return gardena_mqtt_thema($basis . '/' . $geraet . '/' . $dienst . '/' . $attribut);
}

/**
 * Der Name, unter dem das MQTT-Gateway den virtuellen Eingang anlegt:
 * das Thema mit '/' als '_'. Genau dieser Name gehoert in die Loxone-Vorlage.
 */
function gardena_wert_eingang($basis, $geraet, $dienst, $attribut)
{
    return str_replace('/', '_', gardena_wert_thema($basis, $geraet, $dienst, $attribut));
}

/**
 * Wird der Name eines Geraetes fuer das Thema umgeschrieben?
 * Fuer die Anzeige in der Oberflaeche - der Anwender soll sehen, unter
 * welchem Namen sein Geraet beim Miniserver ankommt.
 */
function gardena_name_umgeschrieben($geraet)
{
    return gardena_mqtt_thema($geraet) !== (string) $geraet;
}

/* ==================================================================
 * Senden und zaehlen
 * ================================================================== */

/**
 * Fehlt der Wert - im Unterschied zu "ist 0"?
 *
 * Die Antwort der Wolke enthaelt Attribute, deren 'value' null ist. Bis
 * 1.2.0 gingen die als leere Zeichenkette hinaus:
 *
 *     MOWER.Rasen.batteryLevel:
 *
 * Die Zeile endet auf den Doppelpunkt, und ein virtueller Eingang mit
 * Befehlserkennung liest daraus 0. In Loxone sieht ein fehlender Wert damit
 * aus wie ein gemessener Ladestand von 0 % - die stille Falschaussage, die
 * unter allen Fehlerarten am teuersten ist. Ueber MQTT ging zusaetzlich eine
 * leere retained-Nutzlast hinaus.
 *
 * Ein Wert, den es nicht gibt, wird deshalb NICHT gesendet. Der virtuelle
 * Eingang behaelt dann seinen letzten Wert - dass er alt ist, beantwortet
 * das Lebenszeichen (STATUS.Plugin.zeitstempel), nicht eine erfundene Null.
 * Gezaehlt werden die uebersprungenen Werte trotzdem: sie stehen im Zustand
 * und in der Selbstpruefung, damit ein dauerhaft leeres Attribut auffaellt,
 * statt sich zu verstecken.
 *
 * '' und 0 sind KEINE fehlenden Werte - die hat die Wolke so geschickt.
 */
function gardena_wert_fehlt($wert)
{
    if ($wert === null) { return true; }
    // Ein Feld, das sich nicht in JSON fassen laesst (ungueltiges UTF-8 aus
    // einer fremden Wolke ist keine ferne Moeglichkeit), ergaebe ebenfalls
    // eine leere Nutzlast.
    if (is_array($wert) && json_encode($wert) === false) { return true; }
    return false;
}

/** Einen Wert der fremden Wolke in etwas Sendbares verwandeln. */
function gardena_wert_flach($wert)
{
    if (is_bool($wert)) { return $wert ? 1 : 0; }
    if (is_array($wert)) {
        $js = json_encode($wert);
        return ($js === false) ? '' : $js;
    }
    return $wert;
}

/**
 * Einen Wert auf beiden Wegen hinausgeben - und den Erfolg ZAEHLEN.
 *
 * Bis 1.1.9 wurden die Rueckgaben von sendUDP() und mqttPublish() verworfen
 * und ein Zaehler unbedingt hochgezaehlt; der Lauf endete danach mit
 * LOGOK('<n> Werte versendet.'). War das MQTT-Gateway im LoxBerry nicht
 * eingerichtet, stieg mqttPublish() sofort aus - und das Protokoll meldete
 * trotzdem Erfolg. Eine Meldung, die den Anwender beruhigt, waehrend nichts
 * ankommt, ist schlimmer als gar keine.
 *
 * Rueckgabe: array(versucht, gescheitert)
 */
function gardena_wert_senden($basis, $geraet, $dienst, $attribut, $wert,
                             $udp_ziel, $udp_port, $mqtt_ein, $retain = null,
                             $vorher_leeren = false)
{
    // Keine Angabe: die Tabelle entscheidet (gardena_retain), nicht der Aufruf.
    if ($retain === null) { $retain = gardena_retain($geraet, $dienst, $attribut); }
    $wert = gardena_wert_flach($wert);
    $versucht = 0;
    $fehl = 0;
    if ((string) $udp_ziel !== '') {
        $versucht++;
        // Umbrueche raus: Loxone wertet den UDP-Eingang zeilenweise aus. Der
        // Geraetename kommt aus der Gardena-App, der Wert aus einer fremden
        // Wolke - beides ist ungeprueft.
        $zeile = gardena_udp_zeile($dienst, $geraet, $attribut, $wert);
        gardena_log('DEB', 'UDP: ' . $zeile);
        if (!sendUDP($zeile, $udp_ziel, $udp_port)) { $fehl++; }
    }
    if ($mqtt_ein) {
        $versucht++;
        // Ein Altwert, den der Broker noch haelt, geht UNMITTELBAR vor dem
        // gueltigen Wert mit leerer retain-Nutzlast hinaus. Das Gateway reicht
        // die leere Nachricht als leeren Wert an den Miniserver weiter
        // (gardena_mqtt_loeschen()); der Wert dahinter ersetzt ihn. Welche
        // Themen: gardena_altlast().
        if ($vorher_leeren) {
            gardena_mqtt_loeschen(gardena_wert_thema($basis, $geraet, $dienst, $attribut));
        }
        if (!mqttPublish(gardena_wert_thema($basis, $geraet, $dienst, $attribut), $wert, $retain)) {
            $fehl++;
        }
    }
    return array($versucht, $fehl);
}

/* ==================================================================
 * Lebenszeichen und Zustand
 *
 * Bis 1.1.9 veroeffentlichte das Plugin ausschliesslich Geraetewerte.
 * Scheiterte die Anmeldung oder antwortete die Wolke nicht, endete der Lauf
 * mit exit(1) und schickte GAR NICHTS. Die virtuellen Eingaenge behielten
 * ihren letzten Wert, in der App sah alles normal aus - der Maeher konnte
 * tagelang stehen, ohne dass es jemandem auffiel.
 *
 * Seit 1.2.0 geht am Ende JEDES Laufs ein Lebenszeichen hinaus, auch nach
 * einem Abbruch. Vier Werte, auf demselben Weg wie die Geraetewerte:
 *
 *   UDP   STATUS.Plugin.ok:1
 *   MQTT  <Basis>/Plugin/STATUS/ok
 *
 *   ok           1 = der Lauf ist vollstaendig durchgelaufen und alle Werte
 *                    sind abgeschickt; 0 = Abbruch oder gescheiterte Sendeversuche
 *   zeitstempel  Loxone-Zeit des letzten ERFOLGREICHEN Laufs (Sekunden seit
 *                dem 01.01.2009; 0 = noch nie erfolgreich)
 *   werte        Zahl der abgeschickten Werte des letzten Laufs
 *   fehler       Klartext der letzten Fehlermeldung, sonst '-'
 *
 * Seit 1.2.8 zwei Themen DANEBEN (die vier bestehenden behalten ihre
 * Bedeutung, an ihnen haengen eingerichtete Anlagen):
 *
 *   ts           Unix-Zeit DIESES Durchgangs - wandert bei jedem Cron-Lauf,
 *                auch wenn der Abruf wegen des Takts oder einer
 *                Abrufsperre entfaellt
 *   zaehler      laeuft 0 ... 999 um (Unix-Minuten modulo 1000; aendert
 *                sich bei jedem Cron-Lauf, braucht keinen Merker)
 *
 * Warum: 'zeitstempel' aendert sich nur bei ERFOLG. Ein Dienst, der laeuft,
 * aber nicht abrufen darf (HTTP 429), war von einem toten nicht zu
 * unterscheiden. Hausstandard seit 26.08.2026 (Regeln/07): ts geht bei jedem
 * Cron-Durchgang hinaus.
 *
 * In Loxone genuegt damit ein Vergleich auf 'ok' und das Alter des
 * Zeitstempels, um Stille von Normalbetrieb zu unterscheiden. Die Schwelle
 * gehoert deutlich ueber den Abholtakt gelegt, damit ein einzelner
 * verpasster Durchlauf keine Meldung ausloest.
 * ================================================================== */

/** Unix-Zeit in Loxone-Zeit (Sekunden seit 01.01.2009). */
function gardena_loxzeit($unix)
{
    $unix = (int) $unix;
    return ($unix > 1230768000) ? ($unix - 1230768000) : 0;
}

function gardena_status_datei($cfgdir)
{
    return rtrim((string) $cfgdir, '/') . '/gardena_status.json';
}

function gardena_status_lesen($cfgdir)
{
    // is_file() davor: ohne den Test meldet ein gesetzter Fehlerbehandler
    // (Pruefstand, LoxBerry mit eigenem Handler) eine Warnung fuer einen
    // Pfad, der beim ersten Lauf zu Recht fehlt. Das '@' haelt ihn nicht auf.
    $sd = gardena_status_datei($cfgdir);
    $d = is_file($sd) ? json_decode((string) @file_get_contents($sd), true) : null;
    if (!is_array($d)) { $d = array(); }
    $d += array('ok' => 0, 'letzter_erfolg' => 0, 'letzter_lauf' => 0,
                'werte' => 0, 'verloren' => 0, 'ohne_inhalt' => 0, 'fehler' => '',
                // ab 1.2.0
                'signatur' => '', 'letzte_volle_meldung' => 0, 'sperre_bis' => 0,
                'themen' => array(), 'locations' => array(), 'locations_stand' => 0,
                // 1.2.8 und 1.2.9: 2 = das Abraeumen war GESENDET. Wird nicht
                // mehr ausgewertet - ein Merker auf den Sendeerfolg ueber UDP
                // beweist nichts (Regeln/07); siehe gardena_altlast().
                'retain_stand' => 0,
                // Thema => Laeufe ohne Rueckfrage: weggefallene Themen, deren
                // Loeschung der Broker noch nicht bestaetigt hat (gardenaMain.php).
                'weg_offen' => array());
    return $d;
}

/**
 * Den Zustand fortschreiben. $neu ersetzt nur die uebergebenen Schluessel -
 * 'letzter_erfolg' ueberlebt damit einen gescheiterten Lauf.
 */
function gardena_status_schreiben($cfgdir, $neu)
{
    $d = gardena_status_lesen($cfgdir);
    foreach ($neu as $k => $v) { $d[$k] = $v; }
    return gardena_json_write(gardena_status_datei($cfgdir), $d, 0640);
}

/**
 * Das Lebenszeichen hinausgeben.
 * $altlast: Thema => true fuer die Themen, deren Altwert im Broker unmittelbar
 * vor dem gueltigen Wert abgeraeumt wird (gardena_altlast()).
 * Rueckgabe: array(versucht, gescheitert) - wie gardena_wert_senden().
 */
function gardena_lebenszeichen($basis, $status, $udp_ziel, $udp_port, $mqtt_ein, $altlast = array())
{
    $werte = array(
        'ok' => !empty($status['ok']) ? 1 : 0,
        'zeitstempel' => gardena_loxzeit(isset($status['letzter_erfolg']) ? $status['letzter_erfolg'] : 0),
        'werte' => isset($status['werte']) ? (int) $status['werte'] : 0,
        // Kein Fehler ergibt '-', nicht die leere Zeichenkette: eine UDP-Zeile,
        // die auf den Doppelpunkt endet, liest ein virtueller Eingang mit
        // Befehlserkennung als 0 - und 0 ist hier schon der Wert von 'ok'.
        // Ein sichtbares Zeichen laesst sich von beidem unterscheiden.
        // Der Strich gehoert NACH das Saeubern (Regeln/07, Sprachsteuerung
        // 0.11.5): ein Fehlertext aus blossem Leerraum waere sonst erst im
        // Sender leer geworden und als leere Nutzlast hinausgegangen.
        'fehler' => gardena_mqtt_nutzlast(isset($status['fehler']) ? $status['fehler'] : '') !== ''
            ? gardena_mqtt_nutzlast($status['fehler']) : '-',
        'ts' => time(),
        'zaehler' => (int) floor(time() / 60) % 1000,
    );
    $versucht = 0;
    $fehl = 0;
    foreach ($werte as $name => $wert) {
        // Das Lebenszeichen geht NICHT retained hinaus (Hausstandard) - die
        // Tabelle gardena_retain() sagt es fuer Plugin/STATUS, nicht der
        // Aufruf. Retained zeigte es nach dem Abschalten des LoxBerry weiter
        // "lebt"; ein neu verbindender Abonnent bekam sofort ok=1.
        list($v, $f) = gardena_wert_senden($basis, 'Plugin', 'STATUS', $name, $wert,
                                           $udp_ziel, $udp_port, $mqtt_ein, null,
                                           isset($altlast[gardena_wert_thema($basis, 'Plugin', 'STATUS', $name)]));
        $versucht += $v;
        $fehl += $f;
    }
    return array($versucht, $fehl);
}

/* ==================================================================
 * Zugriffstoken
 * ================================================================== */

/**
 * Ein neues Zugriffstoken (32 Hex-Zeichen).
 *
 * Bis 1.0.2 stand hier eine Kette aus drei Versuchen, und sie hatte zwei
 * Fehler:
 *
 *   1. random_bytes() kann eine Ausnahme werfen, wenn das Betriebssystem
 *      keine sichere Zufallsquelle anbietet. Abgefangen wurde sie nicht -
 *      und weil function_exists('random_bytes') auf jedem PHP 7 wahr ist,
 *      wurde der openssl-Weg dahinter nie erreicht. Die Oberflaeche brach
 *      beim Speichern mit einem toedlichen Fehler ab.
 *   2. Der letzte Rueckfall war md5(uniqid(mt_rand())). Das ist kein
 *      Zufall fuer Sicherheitszwecke: mt_rand ist ein Mersenne-Twister,
 *      uniqid ist im Wesentlichen die Uhrzeit. Dieses Token ist das
 *      EINZIGE, was den schaltenden Endpunkt schuetzt - ein erratbares
 *      waere schlimmer als gar keines.
 *
 * Jetzt: random_bytes in try/catch, ersatzweise openssl mit geprueftem
 * Guetesiegel, sonst kontrollierter Abbruch mit Begruendung.
 */
function gardena_token_new()
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Exception $e) {
        gardena_log('ERR', 'random_bytes lieferte keinen sicheren Zufall: ' . $e->getMessage());
    } catch (Error $e) {
        gardena_log('ERR', 'random_bytes nicht verfuegbar: ' . $e->getMessage());
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        $stark = false;
        $roh = openssl_random_pseudo_bytes(16, $stark);
        // Nur nehmen, wenn OpenSSL selbst sagt, dass es stark ist.
        if ($roh !== false && $stark) { return bin2hex($roh); }
    }
    gardena_log('ERR', 'Es liess sich KEIN sicheres Token erzeugen. Lieber keines als '
        . 'ein erratbares - der Endpunkt weist Schaltbefehle bis auf Weiteres ab.');
    throw new RuntimeException(
        'Auf diesem System ist keine sichere Zufallsquelle verfuegbar - es wurde kein Token erzeugt.');
}

/**
 * Vergleicht das mitgeschickte Token zeitkonstant mit dem hinterlegten.
 * Ist kein Token hinterlegt, wird der Zugriff verweigert (fail closed) -
 * sonst waere die Absicherung durch eine leere Konfiguration aushebelbar.
 */
function gardena_token_ok($expected, $given)
{
    $expected = (string) $expected;
    $given = (string) $given;
    if ($expected === '' || $given === '') { return false; }
    if (function_exists('hash_equals')) { return hash_equals($expected, $given); }
    return $expected === $given;
}

/* ==================================================================
 * Konfiguration lesen und schreiben
 * ================================================================== */

/**
 * Die gardena.cfg lesen. Rueckgabe: das Array des Abschnitts [GARDENA],
 * ergaenzt um die Vorgaben - nie null, nie false.
 */
/**
 * Eine INI-Datei lesen, die mit '#' kommentiert ist.
 *
 * WARUM DAS NOETIG IST
 * Die gardena.cfg kommentiert mit '#'. PHPs INI-Zerleger kennt als
 * Kommentarzeichen aber nur ';' - '#' wurde mit PHP 7 entfernt. Er versucht
 * die Kommentarzeilen als Zuweisungen zu lesen und bricht an der ersten mit
 * einem Sonderzeichen ab. parse_ini_file() gibt dann false zurueck.
 *
 * Gemessen am 15.08.2026 gegen die mitgelieferte config/gardena.cfg, PHP
 * 7.4.33 und 8.4.24 - beide false. Die Folge war schwerwiegend: der Dienst
 * bricht in gardenaMain.php mit LOGCRIT und exit(1) ab, und die Oberflaeche
 * las nur noch die Vorgaben (ENABLED=0, keine Zugangsdaten). Betroffen ist
 * jede Installation, deren gardena.cfg diese Kommentarzeilen enthaelt - also
 * jede Neuinstallation, denn gardena_cfg_write() arbeitet zeilenweise und
 * laesst vorhandene Kommentare stehen.
 *
 * Entfernt werden nur Zeilen, deren erstes sichtbares Zeichen '#' ist. Ein
 * '#' INNERHALB eines Wertes bleibt erhalten.
 *
 * Rueckgabe wie parse_ini_file(): das Array oder false.
 */
function gardena_ini_lesen($datei)
{
    // is_file() davor - siehe gardena_status_lesen().
    if (!is_file($datei)) { return false; }
    $roh = @file_get_contents($datei);
    if ($roh === false) { return false; }
    return gardena_ini_text($roh);
}

/**
 * Denselben Text lesen, ohne Datei - fuer einen Stand, der gerade erst
 * geschrieben wird (gardena_cfg_write, Zweitschrift). Eine Stelle fuer das
 * Entfernen der '#'-Zeilen, damit Datei und Text gleich gelesen werden.
 */
function gardena_ini_text($roh)
{
    return @parse_ini_string(preg_replace('/^[ \t]*#.*$/m', '', (string) $roh),
                             true, INI_SCANNER_RAW);
}

/** Ein Wert aus dem Abschnitt [GARDENA] eines gelesenen Stands, getrimmt; fehlt er: ''. */
function gardena_ini_feld($ini, $k)
{
    return (is_array($ini) && isset($ini['GARDENA']) && is_array($ini['GARDENA'])
            && isset($ini['GARDENA'][$k]) && !is_array($ini['GARDENA'][$k]))
        ? trim((string) $ini['GARDENA'][$k]) : '';
}

/**
 * Die Vorgabewerte - an genau EINER Stelle.
 *
 * Bis 1.1.9 standen sie zweimal da, und die beiden Stellen waren sich uneinig:
 * gardena_cfg_read() ergaenzte MQTT_ENABLED mit '1', der Dienst las die Datei
 * ohne Vorgaben und pruefte
 *
 *     isset($g['MQTT_ENABLED']) && $g['MQTT_ENABLED'] == '1'
 *
 * Ein fehlender Schluessel bedeutete dort also AUS, waehrend die Oberflaeche
 * "Aktiv (empfohlen)" ausgewaehlt anzeigte; bei UDP war es andersherum gebaut
 * (fehlend = AN). Betroffen war jede Anlage, deren gardena.cfg aus einer
 * Fassung ohne diesen Schluessel stammt: MQTT sendete nichts, die Oberflaeche
 * sagte, es sei eingeschaltet, und im Protokoll stand kein Wort davon.
 *
 * Seit 1.2.0 liest auch gardenaMain.php ueber gardena_cfg_read(). Damit gibt
 * es nur noch diese eine Liste.
 */
function gardena_vorgaben()
{
    return array(
        'ENABLED' => '0', 'CLIENT_ID' => '', 'CLIENT_SECRET' => '', 'TOKEN' => '',
        'MINISERVER' => '1', 'UDP_ENABLED' => '1', 'UDPPORT' => '5005',
        'MQTT_ENABLED' => '1', 'MQTT_TOPIC' => 'gardena',
        // Ab 1.2.0. Alle drei so vorbelegt, dass sich fuer eine bestehende
        // Anlage NICHTS aendert: derselbe Takt wie bisher, kein Geraet
        // ausgenommen, der Wartungszaehler aus.
        'INTERVALL' => '5', 'AUSGENOMMEN' => '', 'MESSER_INTERVALL' => '0',
        'MESSER_BASIS' => '0',
    );
}

/**
 * Der Abrufabstand in Minuten.
 *
 * Der Cron laeuft alle fuenf Minuten; ein kuerzerer Abstand ist damit nicht
 * erreichbar, und ein laengerer wird eingehalten, indem Laeufe uebersprungen
 * werden. Warum das ueberhaupt einstellbar ist: Husqvarna begrenzt die Zahl
 * der Abrufe, und ein Durchlauf verbraucht zwei davon. Wie hoch die Grenze
 * wirklich liegt, ist in diesem Plugin NICHT gemessen - die Anleitung sagt
 * das inzwischen auch so. Wer sie kennt oder in HTTP 429 laeuft, kann den
 * Takt hier strecken.
 */
function gardena_intervall($cfg)
{
    $m = isset($cfg['INTERVALL']) ? (int) $cfg['INTERVALL'] : 5;
    if ($m < 5) { $m = 5; }
    if ($m > 1440) { $m = 1440; }
    return $m;
}

/** Die Namen der Geraete, die nicht gesendet werden sollen. */
function gardena_ausgenommen($cfg)
{
    $roh = isset($cfg['AUSGENOMMEN']) ? (string) $cfg['AUSGENOMMEN'] : '';
    if (trim($roh) === '') { return array(); }
    $aus = array();
    foreach (explode(',', $roh) as $n) {
        $n = trim($n);
        if ($n !== '') { $aus[] = $n; }
    }
    return $aus;
}

/**
 * Der hoechste Betriebsstundenstand aus dem Geraete-Abbild.
 *
 * Grundlage des Wartungszaehlers. Der Dienst MOWER meldet 'operatingHours';
 * gibt es mehrere Maeher, wird der hoechste Stand genommen - der
 * Wartungszaehler ist eine Anzeige, keine Buchhaltung je Geraet.
 *
 * Rueckgabe: die Stundenzahl oder null, wenn im Abbild keine steht. NICHT 0:
 * "keine Angabe" und "null Stunden" sind zweierlei, und wer daraus 0 macht,
 * quittiert einen Wechsel auf einen erfundenen Stand.
 */
function gardena_betriebsstunden($cache)
{
    if (!is_array($cache) || empty($cache['locations']) || !is_array($cache['locations'])) {
        return null;
    }
    $hoechster = null;
    foreach ($cache['locations'] as $loc) {
        if (empty($loc['devices']) || !is_array($loc['devices'])) { continue; }
        foreach ($loc['devices'] as $dev) {
            if (!is_array($dev) || empty($dev['services']) || !is_array($dev['services'])) { continue; }
            foreach ($dev['services'] as $attrs) {
                if (!is_array($attrs) || !isset($attrs['operatingHours']['value'])) { continue; }
                $w = $attrs['operatingHours']['value'];
                if (!is_numeric($w)) { continue; }
                if ($hoechster === null || $w > $hoechster) { $hoechster = 0 + $w; }
            }
        }
    }
    return $hoechster;
}

/**
 * Die Signatur eines Datenbestandes.
 *
 * Aus ihr entscheidet der Dienst, ob sich seit dem letzten Lauf ueberhaupt
 * etwas geaendert hat. Was gemeldet werden soll, gehoert IN die Signatur -
 * ein Wert, der in der Meldung steht, aber nicht in der Signatur, wird nie
 * ausgeloest und liegt bis zum naechsten Zustandswechsel.
 */
function gardena_signatur($paare)
{
    ksort($paare);
    $s = '';
    foreach ($paare as $k => $v) { $s .= $k . '=' . $v . "\n"; }
    return sha1($s);
}

function gardena_cfg_read($cfgfile)
{
    $ini = gardena_ini_lesen($cfgfile);
    $g = (is_array($ini) && isset($ini['GARDENA']) && is_array($ini['GARDENA']))
        ? $ini['GARDENA'] : array();
    // Eine Datei OHNE Abschnitt kann entstehen, wenn etwas schiefgegangen
    // ist (bis 1.0.2 legte postupgrade.sh im Fehlerfall eine Datei an, in
    // der nur LOCALTIME=0 stand). Dann sind die Werte auf der obersten
    // Ebene - besser die lesen als gar nichts.
    if (!$g && is_array($ini)) {
        foreach ($ini as $k => $v) {
            if (!is_array($v)) { $g[$k] = $v; }
        }
    }
    $g += gardena_vorgaben();
    return $g;
}

/**
 * Steht in der Datei ein eigener Wert, oder greift die Vorgabe?
 *
 * Die Selbstpruefung zeigt das an: ein Schluessel, der nur aus der Vorgabe
 * kommt, verhaelt sich zwar seit 1.2.0 ueberall gleich - der Anwender soll
 * aber sehen koennen, woher der Wert stammt, den sein Miniserver zu spueren
 * bekommt.
 *
 * Rueckgabe: Feld der Schluessel, die in der Datei WIRKLICH stehen.
 */
function gardena_cfg_eigene_schluessel($cfgfile)
{
    $ini = gardena_ini_lesen($cfgfile);
    if (!is_array($ini)) { return array(); }
    $g = (isset($ini['GARDENA']) && is_array($ini['GARDENA'])) ? $ini['GARDENA'] : $ini;
    $da = array();
    foreach ($g as $k => $v) {
        if (!is_array($v)) { $da[] = (string) $k; }
    }
    return $da;
}

/**
 * Werte in die gardena.cfg schreiben, ohne die uebrigen zu verlieren.
 *
 * $werte ist ein Array Schluessel => Wert. Alles, was bereits in der Datei
 * steht und hier nicht vorkommt, BLEIBT ERHALTEN.
 *
 * Diese Funktion loest zwei Verfahren ab, die beide fehlerhaft waren:
 *
 *   1. Die Oberflaeche baute die Datei aus ihren acht bekannten Feldern neu
 *      zusammen und schrieb sie stumpf zurueck. Jeder Schluessel, den sie
 *      nicht kannte, war danach weg - samt der erklaerenden Kommentare.
 *      Genau so ging LOCALTIME verloren, das postupgrade.sh vorher angelegt
 *      hatte.
 *   2. gardena_cfg_set() ersetzte eine Zeile per regulaerem Ausdruck und
 *      haengte sonst ans Dateiende an. Solange es nur den einen Abschnitt
 *      [GARDENA] gibt, geht das gut - die Behauptung, es lande "unterhalb
 *      der Section", trifft auf die heutige Datei also nicht zu. Fragil ist
 *      es trotzdem: ein zweiter Abschnitt, und der neue Wert landet darin.
 *
 * Geschrieben wird deshalb abschnittsbewusst, unter Sperre und unteilbar:
 * erst eine Zwischendatei, dann rename(). Sonst kann ein gleichzeitiger
 * Aufruf (Oberflaeche speichert, waehrend der Endpunkt ein Token anlegt)
 * die Datei halb gefuellt erwischen oder die Aenderung des anderen
 * ueberschreiben.
 *
 * Kommentare und Leerzeilen bleiben stehen.
 */
/**
 * Einen Wert so zurichten, dass er EINE Zeile dieser Datei bleibt.
 *
 * Bis 1.2.5 ging der Wert roh in "SCHLUESSEL=<wert>". Ein Zeilenumbruch darin
 * erzeugte damit eine zweite Zeile - und eine zurueckgespielte Sicherung mit
 * "MQTT_TOPIC": "garten\nTOKEN=0000..." setzte auf diesem Weg das
 * AKTIONSTOKEN auf einen Wert, den der Absender der Datei kennt. Genau das
 * Geheimnis, das den Endpunkt schuetzt. Gemessen am 05.09.2026 unter 7.4.33
 * und 8.4.24.
 *
 * Ein '[' am Zeilenanfang wuerde ausserdem einen Abschnitt eroeffnen und alle
 * folgenden Schluessel aus [GARDENA] hinaustragen.
 */
function gardena_ini_wert($v)
{
    $s = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    $s = preg_replace('/[\x00-\x1F\x7F]/', '', $s);
    return ltrim($s, "[ ");
}

function gardena_cfg_write($cfgfile, $werte, $abschnitt = 'GARDENA')
{
    if (!is_array($werte) || !$werte) { return true; }

    $dir = dirname($cfgfile);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    // Sperre ueber eine eigene Datei - nicht ueber die Konfiguration selbst,
    // die wird ja gleich durch rename() ersetzt, und eine Sperre auf einer
    // ersetzten Datei schuetzt niemanden mehr.
    $sperre = @fopen($cfgfile . '.lock', 'c');
    if ($sperre === false) {
        gardena_log('ERR', 'Sperrdatei ' . $cfgfile . '.lock nicht zu oeffnen - Rechte pruefen.');
        return false;
    }
    if (!flock($sperre, LOCK_EX)) {
        fclose($sperre);
        gardena_log('ERR', 'Sperre auf ' . $cfgfile . ' nicht zu bekommen.');
        return false;
    }

    $zeilen = is_file($cfgfile) ? @file($cfgfile, FILE_IGNORE_NEW_LINES) : array();
    if (!is_array($zeilen)) { $zeilen = array(); }

    $offen = '';                 // aktueller Abschnitt beim Durchlaufen
    $erledigt = array();         // welche Schluessel schon ersetzt wurden
    $letzte_im_abschnitt = -1;   // hinter welcher Zeile Neues einzufuegen ist
    $abschnitt_da = false;

    foreach ($zeilen as $i => $zeile) {
        // Zeilenende einer Windows-Datei entfernen. FILE_IGNORE_NEW_LINES
        // nimmt nur das \n weg, das \r bliebe sonst am Wert kleben - und
        // parse_ini_file liest es mit. dos2unix laeuft nur bei der
        // Installation ueber die Oberflaeche, nicht beim Auto-Update.
        $zeile = rtrim($zeile, "\r");
        $zeilen[$i] = $zeile;

        $roh = trim($zeile);
        if (preg_match('/^\[([^\]]+)\]$/', $roh, $m)) {
            $offen = $m[1];
            if ($offen === $abschnitt) { $abschnitt_da = true; $letzte_im_abschnitt = $i; }
            continue;
        }
        if ($offen !== $abschnitt) { continue; }
        if ($roh !== '' && $roh[0] !== ';' && $roh[0] !== '#') {
            $letzte_im_abschnitt = $i;
        }
        foreach ($werte as $k => $v) {
            if (isset($erledigt[$k])) { continue; }
            if (preg_match('/^\s*' . preg_quote($k, '/') . '\s*=/', $zeile)) {
                $zeilen[$i] = $k . '=' . gardena_ini_wert($v);
                $erledigt[$k] = true;
                $letzte_im_abschnitt = $i;
            }
        }
    }

    $neu = array();
    foreach ($werte as $k => $v) {
        if (!isset($erledigt[$k])) { $neu[] = $k . '=' . gardena_ini_wert($v); }
    }
    if ($neu) {
        if (!$abschnitt_da) {
            // Der Abschnitt fehlt ganz. Er kommt an den Anfang, damit alles
            // Vorhandene (das dann ausserhalb stuende) nicht ploetzlich
            // hineinrutscht.
            array_unshift($zeilen, '[' . $abschnitt . ']');
            $letzte_im_abschnitt = 0;
        }
        array_splice($zeilen, $letzte_im_abschnitt + 1, 0, $neu);
    }

    $inhalt = implode("\n", $zeilen);
    if (substr($inhalt, -1) !== "\n") { $inhalt .= "\n"; }

    $tmp = $cfgfile . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    $ok = false;
    if (@file_put_contents($tmp, $inhalt) !== false) {
        // Rechte VOR dem Umbenennen setzen: in der Datei stehen das
        // Application Secret und das Zugriffstoken. Nach dem rename() gaebe
        // es sonst einen Augenblick, in dem sie mit 0644 dalaege.
        @chmod($tmp, 0640);
        $ok = @rename($tmp, $cfgfile);
        if (!$ok) { @unlink($tmp); }
    }
    if (!$ok) {
        gardena_log('ERR', 'gardena.cfg liess sich nicht schreiben (' . $cfgfile . ') - Platz? Rechte?');
    }

    /*
     * Die Zweitschrift MITZIEHEN (Hausstandard, Regeln/05).
     *
     * Bis 1.2.7 entstand <ordner>.backup.gardena.cfg nur in preupgrade.sh.
     * Gemessen am Geraet am 17.09.2026: nach dem ersten Oeffnen der
     * Oberflaeche stand das neue Aktionstoken in der Konfiguration, eine
     * Zweitschrift gab es nicht - die Meldung CFG_UNLESBAR verwies auf eine
     * Datei, die nie angelegt worden war.
     *
     * Nur wenn der geschriebene Stand das Merkwort traegt (ein nicht leeres
     * TOKEN im Abschnitt [GARDENA]): eine Datei ohne Token ist kein Stand, den
     * man zurueckholen will, und sie darf eine gute Zweitschrift nicht
     * ueberschreiben. Gleiche Rechte wie das Original, unteilbar geschrieben.
     *
     * Gelesen wird das Merkwort mit demselben Zerleger wie gardena_cfg_read(),
     * nicht mehr mit einem Suchmuster ueber den ganzen Text. Dessen
     * Leerraum-Klasse nahm den Zeilenumbruch mit: "TOKEN=" gefolgt von
     * "MINISERVER=1" galt als gesetztes Token, und ein Token in einem anderen
     * Abschnitt zaehlte mit. Gemessen am 17.09.2026 in WSL
     * (Pruefung-Upgradeluecke-2026-09-17, B3): der Cron-Lauf zwischen neuer
     * Cron-Datei und postinstall.sh vervollstaendigt die mitgelieferte
     * Vorgabe und ersetzte dabei die Zweitschrift durch einen Stand ohne
     * CLIENT_ID, Secret und Token.
     *
     * Und nie ein Stand OHNE Zugangsdaten mit einem ANDEREN Token ueber eine
     * Zweitschrift MIT Zugangsdaten. So sieht es aus, wenn die Oberflaeche in
     * derselben Luecke geoeffnet wird: die Vorgabe bekommt ein frisches Token,
     * die Einstellungen des Anwenders liegen nur noch in der Sicherung
     * (gemessen am 17.09.2026, Pruefung-GardenaSmartSystem-1.2.9). Wer die
     * Zugangsdaten bewusst loescht, behaelt sein Token - dann zieht die
     * Zweitschrift mit.
     */
    $zweit = dirname($dir) . '/' . basename($dir) . '.backup.gardena.cfg';
    $zneu = ($ok && basename($cfgfile) === 'gardena.cfg') ? gardena_ini_text($inhalt) : false;
    $zmerkwort = gardena_ini_feld($zneu, 'TOKEN');
    if ($zmerkwort !== '' && is_file($zweit)) {
        $zalt = gardena_ini_lesen($zweit);
        $zalt_zugang = (gardena_ini_feld($zalt, 'CLIENT_ID') !== '' || gardena_ini_feld($zalt, 'CLIENT_SECRET') !== '');
        $zneu_zugang = (gardena_ini_feld($zneu, 'CLIENT_ID') !== '' || gardena_ini_feld($zneu, 'CLIENT_SECRET') !== '');
        if ($zalt_zugang && !$zneu_zugang && gardena_ini_feld($zalt, 'TOKEN') !== $zmerkwort) {
            $zmerkwort = '';
            gardena_log_gebremst('zweitschrift_bleibt', 'INF', 'Zweitschrift ' . $zweit
                . ' bleibt unveraendert: sie traegt Zugangsdaten und ein anderes Token, '
                . 'der neue Stand keine Zugangsdaten.');
        }
    }
    if ($zmerkwort !== '') {
        $ztmp = $zweit . '.' . getmypid() . '.tmp';
        $zok = false;
        if (@file_put_contents($ztmp, $inhalt) !== false) {
            @chmod($ztmp, 0640);
            $zok = @rename($ztmp, $zweit);
            if (!$zok) { @unlink($ztmp); }
        }
        if (!$zok) {
            gardena_log('ERR', 'Die Zweitschrift ' . $zweit . ' liess sich nicht schreiben - '
                . 'ein Update koennte die Einstellungen dann nicht wiederherstellen.');
        }
    }

    flock($sperre, LOCK_UN);
    fclose($sperre);
    return $ok;
}

/**
 * Einen einzelnen Wert schreiben. Bleibt als Name erhalten, weil er an
 * mehreren Stellen aufgerufen wird - die Arbeit macht gardena_cfg_write().
 */
function gardena_cfg_set($cfgfile, $key, $value)
{
    return gardena_cfg_write($cfgfile, array($key => $value));
}

/**
 * JSON unteilbar in eine Datei schreiben.
 *
 * json_encode liefert bei ungueltigem UTF-8 false, und
 * file_put_contents($pfad, false) schreibt daraufhin 0 Bytes - und meldet
 * das als Erfolg (Rueckgabe 0, nicht false). Bei den Geraetenamen aus einer
 * fremden Wolke ist ungueltiges UTF-8 keine ferne Moeglichkeit.
 *
 * Ausserdem: erst Zwischendatei, dann rename(). Sonst kann die Oberflaeche
 * den Geraete-Zwischenspeicher halb geschrieben lesen, waehrend der Cron
 * ihn ersetzt.
 */
function gardena_json_write($pfad, $daten, $modus = 0640)
{
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        gardena_log('ERR', basename($pfad) . ' nicht erzeugbar (' . json_last_error_msg()
            . ') - die vorhandene Datei bleibt unveraendert.');
        return false;
    }
    $dir = dirname($pfad);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $tmp = $pfad . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';
    if (@file_put_contents($tmp, $js) === false) {
        gardena_log('ERR', $tmp . ' liess sich nicht schreiben - Platz? Rechte?');
        return false;
    }
    @chmod($tmp, $modus);
    if (!@rename($tmp, $pfad)) {
        @unlink($tmp);
        gardena_log('ERR', basename($pfad) . ' liess sich nicht ersetzen.');
        return false;
    }
    return true;
}

/**
 * Eine Sperre, damit sich zwei Abrufe nicht ueberholen.
 *
 * Ein Durchlauf von gardenaMain dauert bei einem groesseren Garten schnell
 * eine halbe Minute (Netzanfragen plus 100 ms Pause je UDP-Wert). Angestossen
 * wird er vom Cron alle fuenf Minuten UND von ?action=refresh. Ohne Sperre
 * laufen beide gleichzeitig, verdoppeln die Abrufe gegen das Kontingent der
 * Husqvarna-API und schicken dem Miniserver alles doppelt.
 *
 * Rueckgabe: der offene Dateizeiger (offen halten - mit ihm faellt die
 * Sperre) oder false.
 */
function gardena_sperre($name = 'main')
{
    $dir = isset($GLOBALS['lbplogdir']) && is_dir((string) $GLOBALS['lbplogdir'])
        ? (string) $GLOBALS['lbplogdir'] : sys_get_temp_dir();
    $f = rtrim($dir, '/') . '/gardena_' . preg_replace('/[^a-z0-9_]/', '', $name) . '.lock';
    $fh = @fopen($f, 'c');
    if ($fh === false) {
        gardena_log('ERR', 'Sperrdatei ' . $f . ' nicht zu oeffnen - Platz und Rechte pruefen.');
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return false;
    }
    return $fh;
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
 *
 * Bis 1.0.2 gab es ueberhaupt keine Sprachdateien - die Oberflaeche war
 * fest deutsch.
 * ================================================================== */

function gardena_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
function gardena_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/. Diese Datei liegt in
        // bin/plugins/<ordner>/ - der Ordnername steht also im Ablageort.
        //
        // Die Wurzel nur ueber gardena_lbhome(), ohne festen Systempfad. Bis
        // 1.2.9 stand als letzter Rueckfall der feste Installationspfad eines
        // Standard-LoxBerry da, und eine leere Wurzel ergab
        // /templates/plugins/<ordner>/lang ab der Laufwerkswurzel - aus einem
        // ausgepackten Archiv las gardena_t() dann fremde Texte (in WSL
        // gemessen, Pruefung-GardenaSmartSystem-1.2.10, Fall T1).
        $home = gardena_lbhome();
        $ordner = basename(dirname(__FILE__));
        $pfad = ($home !== '') ? $home . '/templates/plugins/' . $ordner . '/lang' : '';
        if ($pfad === '' || !is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(__FILE__)) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . gardena_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen
        // (sonst beendet jedes Semikolon einer HTML-Entitaet den Wert).
        // Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}


/**
 * Die Fassung des LoxBerry-MQTT-Gateways - 0 heisst "nicht feststellbar".
 *
 * Sie steht als Mqtt.Gatewayversion in config/system/general.json (ab Werk
 * 1) und entscheidet, was der Anwender eintragen muss: unter V1 jedes Thema
 * von Hand auf der Abo-Seite, ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions.
 *
 * Die Datei wird hier eigens gelesen, obwohl andere Stellen sie auch lesen.
 * Das ist Absicht: dieser Baustein passt damit in jedes Plugin, unabhaengig
 * davon, wie es seinen MQTT-Zustand ermittelt - und er geht nicht kaputt,
 * wenn jemand jene Funktion umbaut.
 */
function gardena_gateway_fassung()
{
    $home = getenv('LBHOMEDIR');
    if (!$home && defined('LBHOMEDIR')) {
        $home = LBHOMEDIR;
    }
    if (!$home || !is_dir($home)) {
        return 0;
    }
    $d = @json_decode((string) @file_get_contents(
        $home . '/config/system/general.json'), true);
    if (!is_array($d)) {
        return 0;
    }
    foreach (array('Mqtt', 'mqtt') as $ab) {
        if (!isset($d[$ab]) || !is_array($d[$ab])) {
            continue;
        }
        foreach (array('Gatewayversion', 'gatewayversion') as $sl) {
            if (isset($d[$ab][$sl]) && (string) $d[$ab][$sl] !== '') {
                return (int) $d[$ab][$sl];
            }
        }
    }
    return 0;
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an der Ausgabestelle unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1; ab V2 schickte
 * der Satz jeden Anwender zu einem Eingabeplatz, den es nicht mehr gibt.
 *
 * Drei Ausgaenge: ist die Fassung nicht feststellbar, werden BEIDE Faelle
 * genannt statt einer behauptet.
 */
function gardena_abo_text()
{
    $f = gardena_gateway_fassung();
    if ($f <= 0) {
        return gardena_t('MQTT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(gardena_t('MQTT.ABO_GEMESSEN'), $f) . '</span>';
    return gardena_t($f >= 2 ? 'MQTT.ABO_V2' : 'MQTT.ABO_TEXT') . $gemessen;
}


/**
 * Welche Befehle gibt es zu welchem Dienst?
 *
 * An EINER Stelle, weil es drei Verbraucher gibt: der Endpunkt prueft damit,
 * die Vorlage der Virtuellen Ausgaenge erzeugt daraus, und die Befehlstabelle
 * im Reiter "Einbindung in Loxone" zeigt es an. Bis 1.2.5 pruefte der
 * Endpunkt type und cmd gegen zwei getrennte flache Listen und nie
 * gegeneinander: 'type=MOWER_CONTROL&cmd=PAUSE' kam durch, kostete einen
 * Abruf des Husqvarna-Kontingents und scheiterte erst in der Wolke.
 *
 * HERKUNFT: die Vereinigung der beiden Listen, die dieses Plugin selbst
 * fuehrt - der Kopfkommentar von webfrontend/html/index.php und die
 * Befehlstabelle im Reiter "Einbindung in Loxone". An einer GARDENA-Anlage
 * ist NICHTS davon gemessen; es steht hier keine zur Verfuegung. Die Liste
 * ist deshalb bewusst die weitere der beiden: enger zu fassen hiesse, einer
 * bestehenden Anlage einen Befehl wegzunehmen, den sie vielleicht benutzt.
 */
function gardena_befehle()
{
    return array(
        'MOWER_CONTROL' => array(
            'START_SECONDS_TO_OVERRIDE',
            'START_DONT_OVERRIDE',
            'PARK_UNTIL_NEXT_TASK',
            'PARK_UNTIL_FURTHER_NOTICE',
        ),
        'VALVE_CONTROL' => array(
            'START_SECONDS_TO_OVERRIDE',
            'STOP_UNTIL_NEXT_TASK',
            'PAUSE',
            'UNPAUSE',
        ),
        'POWER_SOCKET_CONTROL' => array(
            'START_SECONDS_TO_OVERRIDE',
            'START_OVERRIDE',
            'STOP_UNTIL_NEXT_TASK',
        ),
    );
}

/* ==================================================================
 * Bremsen fuer Ausloeser aus Loxone (seit 1.2.8)
 *
 * Regeln/03: "Jeder Ausloeser, den eine fremde Anlage bedient, braucht eine
 * Bremse im Plugin - der Takt allein schuetzt nicht." Bis 1.2.7 hatte der
 * Endpunkt keine: ein flatternder Baustein am Virtuellen Ausgang (ein
 * Impulsgeber am falschen Eingang genuegt) loeste mit ?action=refresh JEDE
 * Sekunde einen vollstaendigen Abruf aus, mit ?action=command jede Sekunde
 * einen Befehl - beides zaehlt gegen das Kontingent der Husqvarna-API, und
 * ?action=command las nicht einmal die Abrufsperre nach HTTP 429.
 *
 * Die beiden Grenzen sind FEST, nicht einstellbar: ein neuer Schluessel in
 * gardena_vorgaben() machte jede Sicherungsdatei aus 1.2.7 unvollstaendig,
 * und die wird seit dem 07.09.2026 abgewiesen. Die Werte stehen in der
 * Oberflaeche (Reiter Einbindung in Loxone).
 * ================================================================== */

/** Mindestabstand eines Sofortabrufs zum letzten Lauf, in Sekunden. */
function gardena_bremse_abruf_s() { return 60; }

/** Hoechstzahl schaltender Befehle je Stunde. */
function gardena_bremse_befehle_h() { return 30; }

/**
 * Laeuft eine Abrufsperre nach HTTP 429? Rueckgabe: Unix-Zeit des Endes
 * oder 0.
 */
function gardena_sperre_bis($cfgdir)
{
    $st = gardena_status_lesen($cfgdir);
    $bis = (int) $st['sperre_bis'];
    return ($bis > time()) ? $bis : 0;
}

/**
 * Nach HTTP 429 die Ruecknahme festhalten - fuer Dienst UND Endpunkt.
 *
 * Retry-After wird genommen, wenn die Gegenstelle sie mitschickt. Fehlt sie,
 * wird eine Stunde gewartet - eine Zahl, die NICHT gemessen ist und deshalb
 * bewusst grob gewaehlt ist: lieber eine Stunde zu lange warten als die
 * Sperre zu verlaengern. Bis 1.2.7 stand diese Funktion nur in
 * gardenaMain.php; ein 429 auf einen Befehl blieb unvermerkt.
 */
function gardena_kontingent_vermerken($cfgdir, $retry_after)
{
    $warte = ((int) $retry_after > 0) ? (int) $retry_after : 3600;
    if ($warte > 86400) { $warte = 86400; }
    gardena_status_schreiben($cfgdir, array('sperre_bis' => time() + $warte));
    gardena_log('CRIT', 'Das Abrufkontingent der Husqvarna-API ist erschoepft (HTTP 429). '
        . 'Bis ' . date('H:i', time() + $warte) . ' wird nicht mehr abgerufen. '
        . 'Der Abstand laesst sich in der Plugin-Oberflaeche strecken.');
    return time() + $warte;
}

/**
 * Darf jetzt ein Befehl hinaus? Zaehlt ihn, wenn ja.
 *
 * Der Merker liegt im Protokollordner (RAM-Scheibe): nach einem Neustart
 * beginnt die Stunde von vorn - das ist in Ordnung, die Bremse soll ein
 * Flattern abfangen, keine Buchhaltung sein.
 *
 * Rueckgabe: 0 = erlaubt, sonst die Zahl der Befehle in der letzten Stunde.
 */
function gardena_bremse_befehl()
{
    $f = gardena_log_datei('gardena_befehle.merker');
    if ($f === '') { return 0; }          // ohne Protokollordner keine Bremse, aber kein Absturz
    $fh = @fopen($f, 'c+');
    if (!$fh) { return 0; }
    flock($fh, LOCK_EX);
    $roh = stream_get_contents($fh);
    $liste = json_decode((string) $roh, true);
    if (!is_array($liste)) { $liste = array(); }
    $grenze = time() - 3600;
    $jung = array();
    foreach ($liste as $t) { if ((int) $t > $grenze) { $jung[] = (int) $t; } }
    $zu_viele = count($jung) >= gardena_bremse_befehle_h();
    if (!$zu_viele) { $jung[] = time(); }
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($jung));
    flock($fh, LOCK_UN);
    fclose($fh);
    return $zu_viele ? count($jung) : 0;
}

/**
 * Der Ort der gardena.cfg - an EINER Stelle.
 *
 * Bisher reichte jeder Aufrufer den Pfad selbst durch; das ging gut,
 * solange nur die Oberflaeche ihn kannte. Die Sicherung braucht ihn aber
 * auch, und zwei Stellen, die denselben Pfad bilden, laufen frueher oder
 * spaeter auseinander.
 */
function gardena_konfigdatei()
{
    global $lbpconfigdir;
    return ((string) $lbpconfigdir) . '/gardena.cfg';
}

/** Die volle Konfiguration - samt Vorgaben, wie gardena_cfg_read() sie ergaenzt. */
function gardena_config()
{
    return gardena_cfg_read(gardena_konfigdatei());
}

/**
 * Der Stand, wie er in die Sicherungsdatei gehoert.
 *
 * Genau die Schluessel, die gardena_sicherung_lesen() auch wieder annimmt -
 * nicht mehr. Bis 1.2.5 ging alles hinaus, was in der gardena.cfg stand; eine
 * gewachsene Datei mit einem Rest aus einer alten Fassung (postupgrade.sh
 * nennt LOCALTIME) erzeugte damit eine Sicherung, die das Plugin beim
 * Zurueckspielen als "Unbekannte Einstellung" ABLEHNTE. Die eigene Sicherung
 * war unbrauchbar - und genau fuer den Umzug auf einen zweiten LoxBerry ist
 * sie gedacht.
 */
function gardena_sicherung_stand()
{
    return array_intersect_key(gardena_config(), gardena_vorgaben());
}

/**
 * In welchem Zustand ist die Konfigurationsdatei?
 *
 * Drei Ausgaenge, und der mittlere ist der, auf den es ankommt:
 *   'neu'      - die Datei gibt es nicht. Neuinstallation; ein Token darf
 *                entstehen, und die Vorgaben duerfen geschrieben werden.
 *   'heil'     - lesbar und mit mindestens einem Schluessel.
 *   'unlesbar' - die Datei IST DA, laesst sich aber nicht lesen (leer, halb
 *                geschrieben, Muell). Dann wird NICHTS geschrieben.
 *
 * Bis 1.2.5 gab es diese Unterscheidung nicht: gardena_cfg_read() lieferte
 * in beiden Faellen nur die Vorgaben, das Token war damit leer, und die
 * Oberflaeche wuerfelte beim naechsten Oeffnen ein neues und schrieb es weg.
 * Jede im Miniserver eingetragene Adresse war danach ungueltig - stumm, denn
 * ein Virtueller Ausgang wertet die 403-Antwort nicht aus.
 */
function gardena_cfg_zustand($cfgfile)
{
    if (!is_file($cfgfile)) { return 'neu'; }
    $ini = gardena_ini_lesen($cfgfile);
    if (!is_array($ini)) { return 'unlesbar'; }
    $g = (isset($ini['GARDENA']) && is_array($ini['GARDENA'])) ? $ini['GARDENA'] : $ini;
    foreach ($g as $v) {
        if (!is_array($v)) { return 'heil'; }
    }
    return 'unlesbar';
}

/**
 * Fehlende Schluessel EINMAL mit ihrer Vorgabe in die Datei schreiben.
 *
 * Hausstandard: die Konfiguration wird vervollstaendigt, nicht nur beim Lesen
 * ergaenzt - sonst steht in der Datei etwas anderes als das, womit gearbeitet
 * wird, und der Anwender kann seinen eigenen Stand nicht nachlesen. Bis 1.2.5
 * ergaenzte nur der Speichern-Handler, und auch der nur die Schluessel, die
 * sein Formular gerade mitschickte (gemessen: 2 von 4 fehlenden).
 *
 * Rueckgabe: die Namen der nachgetragenen Schluessel (leer = nichts zu tun).
 */
function gardena_cfg_vervollstaendigen($cfgfile)
{
    // Eine unlesbare Datei wird nicht angefasst - auch nicht "ergaenzt".
    if (gardena_cfg_zustand($cfgfile) === 'unlesbar') { return array(); }
    $da = gardena_cfg_eigene_schluessel($cfgfile);
    if (!$da) { return array(); }          // Datei fehlt - der Dienst legt sie an
    $fehlt = array();
    foreach (gardena_vorgaben() as $k => $v) {
        if (!in_array($k, $da, true)) { $fehlt[$k] = $v; }
    }
    if (!$fehlt) { return array(); }
    if (!gardena_cfg_write($cfgfile, $fehlt)) { return array(); }
    gardena_log('INF', 'Konfiguration vervollstaendigt, nachgetragen: '
        . implode(', ', array_keys($fehlt)));
    return array_keys($fehlt);
}

/** Den ganzen Stand ablegen und sagen, ob es geklappt hat. */
function gardena_config_speichern($cfg)
{
    return (bool) gardena_cfg_write(gardena_konfigdatei(), $cfg);
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Der wichtigste Punkt: eine halb gueltige Datei ueberschreibt GAR NICHTS.
 * Wer eine Sicherung zurueckspielt, will entweder den ganzen Stand oder
 * gar keinen - eine zur Haelfte uebernommene Konfiguration ist schlimmer
 * als die alte, und man sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
/**
 * Taugt der Wert ueberhaupt fuer eine Zeile dieser Datei?
 *
 * Skalar, nicht zu lang, keine Steuerzeichen. Ein Feld oder Objekt wuerde beim
 * Zusammensetzen zu "Array" (gemessen: UDPPORT=Array), ein Zeilenumbruch
 * erzeugt eine zweite Zeile.
 */
function gardena_wert_taugt($v)
{
    if (is_array($v) || is_object($v) || is_bool($v) || is_null($v)) { return false; }
    $s = (string) $v;
    if (strlen($s) > 4096) { return false; }
    return preg_match('/[\x00-\x1F\x7F]/', $s) !== 1;
}

/**
 * Ist der Wert fuer DIESE Einstellung zulaessig?
 *
 * Dieselbe Positivliste, die auch das Formular anlegt - der Endpunkt und die
 * Sicherung duerfen nicht nachsichtiger sein als die Oberflaeche.
 * Rueckgabe: '' = in Ordnung, sonst der Grund als Klartext.
 *
 * Das Muster fuer TOKEN bleibt bewusst WEIT ({0,64}, kein Mindestmass): ein
 * leeres Token in einer Sicherungsdatei heisst "kein Token gesichert" und ist
 * kein unzulaessiger Wert. Wie stark das Token ist, meldet der Reiter Test -
 * melden ist richtig, blockieren nicht.
 */
function gardena_wert_pruefen($k, $v)
{
    $ja_nein = array('ENABLED', 'UDP_ENABLED', 'MQTT_ENABLED');
    if (in_array($k, $ja_nein, true)) {
        return ($v === '0' || $v === '1') ? '' : gardena_t('EINST.PRUEF_NUR01');
    }
    if ($k === 'UDPPORT') {
        return (preg_match('/^[0-9]{1,5}$/', $v) === 1 && (int) $v >= 1 && (int) $v <= 65535)
            ? '' : gardena_t('EINST.PRUEF_PORT');
    }
    if ($k === 'INTERVALL') {
        return (preg_match('/^[0-9]{1,4}$/', $v) === 1 && (int) $v >= 5 && (int) $v <= 1440)
            ? '' : gardena_t('EINST.PRUEF_TAKT');
    }
    if ($k === 'MINISERVER') {
        return (preg_match('/^[0-9]{1,3}$/', $v) === 1 && (int) $v >= 1)
            ? '' : gardena_t('EINST.PRUEF_MS');
    }
    if ($k === 'MESSER_INTERVALL' || $k === 'MESSER_BASIS') {
        return preg_match('/^[0-9]{1,9}$/', $v) === 1 ? '' : gardena_t('EINST.PRUEF_ZAHL');
    }
    if ($k === 'TOKEN') {
        return preg_match('/^[A-Za-z0-9_.\-]{0,64}$/', $v) === 1
            ? '' : gardena_t('EINST.PRUEF_TOKEN');
    }
    if ($k === 'MQTT_TOPIC') {
        return preg_match('#^[A-Za-z0-9_/\-]{1,120}$#', $v) === 1
            ? '' : gardena_t('EINST.PRUEF_THEMA');
    }
    if ($k === 'AUSGENOMMEN') {
        return (strlen($v) <= 2048) ? '' : gardena_t('EINST.PRUEF_LANG');
    }
    // CLIENT_ID, CLIENT_SECRET: Form gibt Husqvarna vor, wir pruefen nur die
    // Laenge und - ueber gardena_wert_taugt() - die Steuerzeichen.
    return (strlen($v) <= 512) ? '' : gardena_t('EINST.PRUEF_LANG');
}

function gardena_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(gardena_t('EINST.SICH_KEIN_JSON')), 0);
    }
    $neu = gardena_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            // NICHT maskieren: die Bibliothek liefert Daten, die Oberflaeche
            // maskiert. Bis 1.2.5 lief der Name durch beide Stellen und der
            // Anwender las "A&amp;B" statt "A&B" - ausgerechnet dort, wo er
            // erkennen soll, WELCHER Schluessel stoert.
            $mangel[] = sprintf(gardena_t('EINST.SICH_FREMD'), (string) $k);
            continue;
        }
        // Der Schluessel allein sagt nichts ueber den Wert. Bis 1.2.5 wurde
        // jeder Wert ungeprueft uebernommen und roh in die INI-Zeile
        // geschrieben - damit liess sich ueber einen Zeilenumbruch das
        // Aktionstoken setzen. Jetzt zwei Tore: taugt der Wert ueberhaupt fuer
        // eine Zeile dieser Datei, und ist er fuer DIESE Einstellung zulaessig.
        if (!gardena_wert_taugt($w)) {
            $mangel[] = sprintf(gardena_t('EINST.SICH_WERT_FORM'), (string) $k);
            continue;
        }
        $grund = gardena_wert_pruefen($k, (string) $w);
        if ($grund !== '') {
            $mangel[] = sprintf(gardena_t('EINST.SICH_WERT_UNZULAESSIG'), (string) $k, $grund);
            continue;
        }
        $neu[$k] = (string) $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = gardena_t('EINST.SICH_LEER');
    }
    /* FEHLENDE Schluessel sind eine Beanstandung, kein stiller Rueckfall.
     *
     * Bis hierher war die Vorgabenliste der Ausgangspunkt, und nur was in
     * der Datei stand wurde darueber geschrieben. Eine Datei mit einem
     * einzigen Schluessel lief damit ohne Beanstandung durch, wurde
     * gespeichert, und alle uebrigen Einstellungen fielen auf Werk
     * zurueck - quittiert mit "1 Wert uebernommen".
     *
     * Gemessen an VolkswagenID 0.9.11 am 03.09.2026 unter PHP 7.4 und 8.4:
     * dort fiel dabei auch das Aktionstoken auf '', und jede im Miniserver
     * eingetragene Adresse war stumm ungueltig. Am 07.09.2026 ueber den
     * Bestand ausgerollt (30 Linien).
     *
     * Der Hausstandard sagt: eine halb gueltige Datei aendert gar nichts.
     * Verglichen wird gegen die VORGABEN, nicht gegen $bekannt: was
     * ausserhalb der Konfigurationsdatei liegt - Zugangsdaten in einer
     * eigenen Datei - faellt nicht auf Werk zurueck und darf hier fehlen. */
    $fehlend = array();
    foreach (array_keys(gardena_vorgaben()) as $fk) {
        if (!array_key_exists($fk, $daten)) {
            $fehlend[] = $fk;
        }
    }
    if ($fehlend) {
        // Roh: die Oberflaeche maskiert die Beanstandungsliste bei der Ausgabe
        // EINMAL. Bis 1.2.7 stand hier htmlspecialchars() - doppelt maskiert.
        $mangel[] = sprintf(gardena_t('EINST.SICH_FEHLEND'), count($fehlend),
            implode(', ', $fehlend));
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}
