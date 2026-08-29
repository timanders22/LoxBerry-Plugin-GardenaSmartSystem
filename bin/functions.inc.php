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
 * Deshalb jetzt zweistufig: bevorzugt das SDK (dort landet es im
 * Log-Manager), ersatzweise eine eigene Datei im Log-Verzeichnis des
 * Plugins. Verloren geht nichts mehr.
 */

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function gardena_log($level, $msg)
{
    $level = strtoupper((string) $level);
    if ($level === 'ERR' && function_exists('LOGERR')) { LOGERR($msg); return; }
    if ($level === 'DEB' && function_exists('LOGDEB')) { LOGDEB($msg); return; }
    if ($level !== 'DEB' && function_exists('LOGINF')) { LOGINF($msg); return; }
    if ($level === 'DEB') { return; }   // Debug ohne SDK nicht in die Ersatzdatei

    // Ersatzweg ohne SDK.
    $dir = isset($GLOBALS['lbplogdir']) ? (string) $GLOBALS['lbplogdir'] : '';
    if ($dir === '' || !is_dir($dir)) { return; }
    $f = rtrim($dir, '/') . '/gardena_ui.log';
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 262144) {
        // Gekuerzt wird unter Sperre, sonst schreibt ein zweiter Prozess
        // waehrenddessen ans alte Ende und verliert seine Zeile.
        $fh = @fopen($f, 'c+');
        if ($fh && flock($fh, LOCK_EX)) {
            $rest = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200);
            ftruncate($fh, 0); rewind($fh);
            fwrite($fh, implode("\n", $rest) . "\n");
            flock($fh, LOCK_UN);
        }
        if ($fh) { fclose($fh); }
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $level . ' ' . $msg . "\n",
                       FILE_APPEND | LOCK_EX);
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
        $home = isset($GLOBALS['lbhomedir']) ? (string) $GLOBALS['lbhomedir'] : '';
        if ($home === '') { $home = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln(); }
        $gen = @json_decode((string) @file_get_contents($home . '/config/system/general.json'), true);
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
function mqttPublish($topic, $value, $retain = true)
{
    $udpport = gardena_mqtt_udpport();
    if (!$udpport) { return false; }
    $msg = ($retain ? 'retain ' : 'publish ') . gardena_mqtt_thema($topic)
         . ' ' . gardena_mqtt_nutzlast($value);
    return sendUDP($msg, '127.0.0.1', $udpport);
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
                             $udp_ziel, $udp_port, $mqtt_ein)
{
    $wert = gardena_wert_flach($wert);
    $versucht = 0;
    $fehl = 0;
    if ((string) $udp_ziel !== '') {
        $versucht++;
        // Umbrueche raus: Loxone wertet den UDP-Eingang zeilenweise aus. Der
        // Geraetename kommt aus der Gardena-App, der Wert aus einer fremden
        // Wolke - beides ist ungeprueft.
        $zeile = gardena_mqtt_nutzlast($dienst . '.' . $geraet . '.' . $attribut . ':' . $wert);
        gardena_log('DEB', 'UDP: ' . $zeile);
        if (!sendUDP($zeile, $udp_ziel, $udp_port)) { $fehl++; }
    }
    if ($mqtt_ein) {
        $versucht++;
        if (!mqttPublish(gardena_wert_thema($basis, $geraet, $dienst, $attribut), $wert, true)) {
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
 *                    sind zugestellt; 0 = Abbruch oder verlorene Werte
 *   zeitstempel  Loxone-Zeit des letzten ERFOLGREICHEN Laufs (Sekunden seit
 *                dem 01.01.2009; 0 = noch nie erfolgreich)
 *   werte        Zahl der zugestellten Werte des letzten Laufs
 *   fehler       Klartext der letzten Fehlermeldung, sonst leer
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
    $d = json_decode((string) @file_get_contents(gardena_status_datei($cfgdir)), true);
    if (!is_array($d)) { $d = array(); }
    $d += array('ok' => 0, 'letzter_erfolg' => 0, 'letzter_lauf' => 0,
                'werte' => 0, 'verloren' => 0, 'ohne_inhalt' => 0, 'fehler' => '',
                // ab 1.2.0
                'signatur' => '', 'letzte_volle_meldung' => 0, 'sperre_bis' => 0,
                'themen' => array(), 'locations' => array(), 'locations_stand' => 0);
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
 * Rueckgabe: array(versucht, gescheitert) - wie gardena_wert_senden().
 */
function gardena_lebenszeichen($basis, $status, $udp_ziel, $udp_port, $mqtt_ein)
{
    $werte = array(
        'ok' => !empty($status['ok']) ? 1 : 0,
        'zeitstempel' => gardena_loxzeit(isset($status['letzter_erfolg']) ? $status['letzter_erfolg'] : 0),
        'werte' => isset($status['werte']) ? (int) $status['werte'] : 0,
        // Kein Fehler ergibt '-', nicht die leere Zeichenkette: eine UDP-Zeile,
        // die auf den Doppelpunkt endet, liest ein virtueller Eingang mit
        // Befehlserkennung als 0 - und 0 ist hier schon der Wert von 'ok'.
        // Ein sichtbares Zeichen laesst sich von beidem unterscheiden.
        'fehler' => (isset($status['fehler']) && (string) $status['fehler'] !== '')
            ? (string) $status['fehler'] : '-',
    );
    $versucht = 0;
    $fehl = 0;
    foreach ($werte as $name => $wert) {
        list($v, $f) = gardena_wert_senden($basis, 'Plugin', 'STATUS', $name, $wert,
                                           $udp_ziel, $udp_port, $mqtt_ein);
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
    $roh = @file_get_contents($datei);
    if ($roh === false) { return false; }
    return @parse_ini_string(preg_replace('/^[ \t]*#.*$/m', '', $roh),
                             true, INI_SCANNER_RAW);
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
                $zeilen[$i] = $k . '=' . $v;
                $erledigt[$k] = true;
                $letzte_im_abschnitt = $i;
            }
        }
    }

    $neu = array();
    foreach ($werte as $k => $v) {
        if (!isset($erledigt[$k])) { $neu[] = $k . '=' . $v; }
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
        $home = isset($GLOBALS['lbhomedir']) ? (string) $GLOBALS['lbhomedir'] : '';
        if ($home === '' || !is_dir($home)) {
            $home = getenv('LBHOMEDIR') ?: '';
        }
        if ($home === '' || !is_dir($home)) {
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
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
            $mangel[] = sprintf(gardena_t('EINST.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = gardena_t('EINST.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}
