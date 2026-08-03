<?php

function gardena_log($level, $msg)
{
    if ($level === 'ERR' && function_exists('LOGERR')) { LOGERR($msg); return; }
    if ($level === 'DEB' && function_exists('LOGDEB')) { LOGDEB($msg); return; }
    if (function_exists('LOGINF')) { LOGINF($msg); }
}

function sendUDP($data, $destIP, $destPort)
{
    if (!($socket = socket_create(AF_INET, SOCK_DGRAM, 0))) {
        $errorcode = socket_last_error();
        $errormsg = socket_strerror($errorcode);
        gardena_log('ERR', "UDP-Socket konnte nicht erstellt werden: [$errorcode] $errormsg");
        return false;
    }
    $dataEnc = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
    $numBytesSent = socket_sendto($socket, $dataEnc, strlen($dataEnc), 0, $destIP, (int) $destPort);
    if ($numBytesSent === false || $numBytesSent == -1) {
        $errorcode = socket_last_error();
        $errormsg = socket_strerror($errorcode);
        gardena_log('ERR', "UDP-Daten konnten nicht gesendet werden: [$errorcode] $errormsg");
        socket_close($socket);
        return false;
    }
    socket_close($socket);
    return true;
}

/**
 * MQTT-Publish ueber das LoxBerry MQTT Gateway (UDP-Interface).
 * Kein eigener MQTT-Client noetig - das Gateway nimmt auf seinem UDP-Port
 * Nachrichten der Form "publish <topic> <wert>" entgegen (auch retain).
 * Voraussetzung: MQTT Gateway im LoxBerry aktiviert (LB 2.x: Plugin, ab LB 3 Bestandteil des Systems).
 */
function mqttPublish($topic, $value, $retain = true)
{
    static $udpport = null;
    if ($udpport === null) {
        $udpport = 0;
        if (function_exists('mqtt_connectiondetails')) {
            $creds = mqtt_connectiondetails();
            if (is_array($creds) && !empty($creds['udpinport'])) {
                $udpport = (int) $creds['udpinport'];
            }
        }
        if (!$udpport) {
            gardena_log('ERR', 'MQTT Gateway: UDP-Port nicht ermittelbar - ist das MQTT Gateway aktiviert?');
        } else {
            gardena_log('DEB', 'MQTT Gateway UDP-Port: ' . $udpport);
        }
    }
    if (!$udpport) { return false; }

    // Topic darf keine Leerzeichen enthalten; Werte mit Leerzeichen sind ok
    $topic = str_replace(' ', '_', (string) $topic);
    $msg = ($retain ? 'retain ' : 'publish ') . $topic . ' ' . $value;
    return sendUDP($msg, '127.0.0.1', $udpport);
}

/**
 * Erzeugt ein neues Zugriffstoken (32 Hex-Zeichen).
 * Nutzt random_bytes(), faellt bei aelteren Systemen auf openssl zurueck.
 */
function gardena_token_new()
{
    if (function_exists('random_bytes')) { return bin2hex(random_bytes(16)); }
    if (function_exists('openssl_random_pseudo_bytes')) { return bin2hex(openssl_random_pseudo_bytes(16)); }
    return md5(uniqid((string) mt_rand(), true));
}

/**
 * Schreibt einen einzelnen Wert in die gardena.cfg zurueck, ohne die
 * uebrigen Zeilen anzutasten. Gibt true bei Erfolg zurueck.
 */
function gardena_cfg_set($cfgfile, $key, $value)
{
    $lines = @file($cfgfile, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) { return false; }
    $done = false;
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/', $line)) {
            $lines[$i] = $key . '=' . $value;
            $done = true;
            break;
        }
    }
    if (!$done) { $lines[] = $key . '=' . $value; }
    if (@file_put_contents($cfgfile, implode("\n", $lines) . "\n") === false) { return false; }
    @chmod($cfgfile, 0640);
    return true;
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
