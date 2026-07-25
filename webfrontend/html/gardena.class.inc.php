<?php
/**
 * GARDENA smart system API v2 (Husqvarna Group Cloud)
 *
 * Komplett neu geschrieben (v1.0.0): Die alte sg-1-API (smart.gardena.com,
 * Login mit Benutzername/Passwort) wurde von Gardena abgeschaltet.
 * Heute: OAuth2 client_credentials mit Application Key + Secret vom
 * Husqvarna Developer Portal (https://developer.husqvarnagroup.cloud).
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x.
 *
 * HTTP laeuft ueber cURL, faellt aber automatisch auf PHP-Streams zurueck,
 * falls die Erweiterung php-curl auf dem System fehlt (z. B. weil das Paket
 * bei der Plugin-Installation nicht geladen werden konnte). Das Plugin
 * funktioniert dadurch auch ohne php-curl.
 */

class gardena
{
    const AUTH_URL   = 'https://api.authentication.husqvarnagroup.dev/v1/oauth2/token';
    const SMART_HOST = 'https://api.smart.gardena.dev';

    private $client_id;
    private $client_secret;
    private $token = '';
    private $tokenfile;
    private $logfunc;
    public  $last_error = '';

    public function __construct($client_id, $client_secret, $tokendir = '/tmp')
    {
        $this->client_id = trim((string) $client_id);
        $this->client_secret = trim((string) $client_secret);
        $this->tokenfile = rtrim($tokendir, '/') . '/gardena_token.json';
    }

    private function log($level, $msg)
    {
        if (function_exists('LOGINF')) {
            if ($level === 'ERR' && function_exists('LOGERR')) { LOGERR($msg); return; }
            if ($level === 'DEB' && function_exists('LOGDEB')) { LOGDEB($msg); return; }
            LOGINF($msg);
        }
    }

    /** Steht die cURL-Erweiterung zur Verfuegung? */
    public static function hasCurl()
    {
        return function_exists('curl_init') && function_exists('curl_exec');
    }

    /**
     * Eine HTTP-Anfrage ausfuehren - bevorzugt per cURL, sonst per PHP-Stream.
     * Rueckgabe: array(Inhalt|false, HTTP-Code, Fehlertext)
     */
    private function http($method, $url, $headers = array(), $body = null, $timeout = 30)
    {
        if (self::hasCurl()) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            if ($headers) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
            if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
            $result = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = ($result === false) ? ('cURL-Fehler: ' . curl_error($ch)) : '';
            curl_close($ch);
            return array($result, $http, $err);
        }
        // Ersatzweg ohne php-curl
        $opt = array(
            'method' => $method,
            'timeout' => $timeout,
            'ignore_errors' => true,   // damit auch 4xx/5xx den Inhalt liefern
            'user_agent' => 'LoxBerry Gardena-Plugin',
        );
        if ($headers) { $opt['header'] = implode("\r\n", $headers); }
        if ($body !== null) { $opt['content'] = $body; }
        $ctx = stream_context_create(array('http' => $opt, 'ssl' => array('verify_peer' => true, 'verify_peer_name' => true)));
        $result = @file_get_contents($url, false, $ctx);
        $http = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $http = (int) $m[1]; }
            }
        }
        $err = ($result === false) ? 'HTTP-Fehler (PHP-Streams): Verbindung fehlgeschlagen' : '';
        return array($result, $http, $err);
    }

    /** OAuth2-Token holen (mit Datei-Cache, Token gilt ~24h) */
    public function authenticate()
    {
        // Cache pruefen
        if (is_file($this->tokenfile)) {
            $t = json_decode((string) file_get_contents($this->tokenfile), true);
            if (is_array($t) && !empty($t['access_token']) && !empty($t['valid_until']) && time() < $t['valid_until']) {
                $this->token = $t['access_token'];
                $this->log('DEB', 'Gardena: Token aus Cache verwendet.');
                return true;
            }
        }

        $post = http_build_query(array(
            'grant_type' => 'client_credentials',
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
        ));
        list($result, $http, $err) = $this->http('POST', self::AUTH_URL,
            array('Content-Type: application/x-www-form-urlencoded'), $post, 20);
        if ($result === false) {
            $this->last_error = 'OAuth2: ' . $err;
            $this->log('ERR', $this->last_error);
            return false;
        }
        $data = json_decode($result, true);
        if ($http !== 200 || empty($data['access_token'])) {
            $this->last_error = 'OAuth2: HTTP ' . $http . ' - ' . substr((string) $result, 0, 300)
                . ' (Application Key/Secret pruefen; im Developer Portal muss die "GARDENA smart system API" mit der Application verbunden sein!)';
            $this->log('ERR', $this->last_error);
            return false;
        }
        $this->token = $data['access_token'];
        $expires = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        @file_put_contents($this->tokenfile, json_encode(array(
            'access_token' => $this->token,
            'valid_until' => time() + max(60, $expires - 300),
        )));
        @chmod($this->tokenfile, 0600);
        $this->log('INF', 'Gardena: neues OAuth2-Token erhalten (gueltig ' . $expires . ' s).');
        return true;
    }

    /** Token-Cache verwerfen (z.B. nach 401) */
    public function clearToken()
    {
        $this->token = '';
        @unlink($this->tokenfile);
    }

    private function request($method, $path, $body = null)
    {
        $headers = array(
            'Authorization: Bearer ' . $this->token,
            'Authorization-Provider: husqvarna',
            'X-Api-Key: ' . $this->client_id,
        );
        if ($body !== null) {
            $headers[] = 'Content-Type: application/vnd.api+json';
        }
        list($result, $http, $err) = $this->http($method, self::SMART_HOST . $path, $headers, $body, 30);
        if ($result === false) {
            $this->last_error = $method . ' ' . $path . ': ' . $err;
            $this->log('ERR', $this->last_error);
            return array(false, 0);
        }
        return array($result, $http);
    }

    /** GET mit automatischem Re-Login bei 401 */
    private function apiGet($path)
    {
        list($result, $http) = $this->request('GET', $path);
        if ($http === 401) {
            $this->log('INF', 'Gardena: Token abgelaufen, hole neues Token.');
            $this->clearToken();
            if (!$this->authenticate()) { return null; }
            list($result, $http) = $this->request('GET', $path);
        }
        if ($result === false) { return null; }
        if ($http < 200 || $http >= 300) {
            $this->last_error = 'GET ' . $path . ': HTTP ' . $http . ' - ' . substr((string) $result, 0, 300);
            if ($http === 403) {
                $this->last_error .= ' (Hinweis: Ist im Developer Portal die GARDENA smart system API mit der Application verbunden?)';
            }
            $this->log('ERR', $this->last_error);
            return null;
        }
        return json_decode($result, true);
    }

    /** Liste aller Locations (Gaerten) */
    public function getLocations()
    {
        $data = $this->apiGet('/v2/locations');
        if (!is_array($data) || empty($data['data'])) { return array(); }
        return $data['data'];
    }

    /**
     * Location-Detail: liefert Geraete mit allen Services/Attributen.
     * Rueckgabe: Array deviceId => [ 'name' => ..., 'services' => [type => [attribute => ['value'=>..,'timestamp'=>..]]] ]
     */
    public function getDevices($locationId)
    {
        $data = $this->apiGet('/v2/locations/' . rawurlencode($locationId));
        if (!is_array($data) || empty($data['included'])) { return array(); }

        $devices = array();
        // 1. Durchlauf: Namen aus COMMON-Services
        foreach ($data['included'] as $svc) {
            $realId = explode(':', (string) $svc['id']);
            $realId = $realId[0];
            if (!isset($devices[$realId])) {
                $devices[$realId] = array('name' => $realId, 'services' => array());
            }
            $type = isset($svc['type']) ? $svc['type'] : 'UNKNOWN';
            $attrs = isset($svc['attributes']) && is_array($svc['attributes']) ? $svc['attributes'] : array();
            if ($type === 'COMMON' && isset($attrs['name']['value'])) {
                $devices[$realId]['name'] = $attrs['name']['value'];
            }
            $flat = array();
            foreach ($attrs as $attrName => $attr) {
                if (is_array($attr) && array_key_exists('value', $attr)) {
                    $flat[$attrName] = array(
                        'value' => $attr['value'],
                        'timestamp' => isset($attr['timestamp']) ? $attr['timestamp'] : '',
                    );
                } else {
                    $flat[$attrName] = array('value' => $attr, 'timestamp' => '');
                }
            }
            // Service-ID fuer Kommandos merken (z.B. "abc123:mower")
            $flat['_service_id'] = array('value' => (string) $svc['id'], 'timestamp' => '');
            $devices[$realId]['services'][$type] = $flat;
        }
        return $devices;
    }

    /**
     * Kommando an einen Service senden (API v2, erwartet HTTP 202).
     * $serviceId z.B. "deviceid" oder "deviceid:mower"; $type z.B. MOWER_CONTROL;
     * $command z.B. START_SECONDS_TO_OVERRIDE; $seconds optional.
     */
    public function sendCommand($serviceId, $type, $command, $seconds = null)
    {
        $attributes = array('command' => $command);
        if ($seconds !== null) { $attributes['seconds'] = (int) $seconds; }
        $body = json_encode(array('data' => array(
            'id' => 'cmd-' . time(),
            'type' => $type,
            'attributes' => $attributes,
        )));
        list($result, $http) = $this->request('PUT', '/v2/command/' . rawurlencode($serviceId), $body);
        if ($http === 401) {
            $this->clearToken();
            if (!$this->authenticate()) { return false; }
            list($result, $http) = $this->request('PUT', '/v2/command/' . rawurlencode($serviceId), $body);
        }
        if ($http === 202) {
            $this->log('INF', 'Gardena: Kommando ' . $command . ' an ' . $serviceId . ' gesendet (202 Accepted).');
            return true;
        }
        $this->last_error = 'Kommando ' . $command . ': HTTP ' . $http . ' - ' . substr((string) $result, 0, 300);
        $this->log('ERR', $this->last_error);
        return false;
    }
}
