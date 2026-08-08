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

function sendUDP($data, $destIP, $destPort)
{
    $destPort = (int) $destPort;
    if ($destIP === '' || $destPort < 1 || $destPort > 65535) {
        gardena_log('ERR', 'UDP: unbrauchbares Ziel (' . $destIP . ':' . $destPort . ')');
        return false;
    }
    if (!($socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP))) {
        $errorcode = socket_last_error();
        gardena_log('ERR', 'UDP-Socket konnte nicht erstellt werden: ['
            . $errorcode . '] ' . socket_strerror($errorcode));
        return false;
    }
    $dataEnc = mb_convert_encoding((string) $data, 'UTF-8', 'UTF-8');
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
        if ($home === '') { $home = getenv('LBHOMEDIR') ?: '/opt/loxberry'; }
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
function gardena_cfg_read($cfgfile)
{
    $ini = @parse_ini_file($cfgfile, true, INI_SCANNER_RAW);
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
    $g += array(
        'ENABLED' => '0', 'CLIENT_ID' => '', 'CLIENT_SECRET' => '', 'TOKEN' => '',
        'MINISERVER' => '1', 'UDP_ENABLED' => '1', 'UDPPORT' => '5005',
        'MQTT_ENABLED' => '1', 'MQTT_TOPIC' => 'gardena',
    );
    return $g;
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
            foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
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
