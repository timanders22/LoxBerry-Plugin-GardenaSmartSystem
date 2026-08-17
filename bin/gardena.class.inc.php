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

    /* Husqvarna begrenzt die Zahl der Abrufe. Wird die Grenze ueberschritten,
     * antwortet die Gegenstelle mit HTTP 429 - und wer dann einfach im
     * Fuenf-Minuten-Takt weiter anklopft, verlaengert die Sperre, statt sie
     * abzuwarten. Bis 1.2.0 wurde 429 wie jeder andere Fehler behandelt:
     * protokollieren, null zurueckgeben, fuenf Minuten spaeter wieder
     * anklopfen. Diese beiden Felder machen den Fall unterscheidbar. */
    public  $last_http = 0;
    /** Sekunden aus der Kopfzeile Retry-After; 0, wenn sie fehlt. */
    public  $retry_after = 0;

    public function __construct($client_id, $client_secret, $tokendir = '/tmp')
    {
        $this->client_id = trim((string) $client_id);
        $this->client_secret = trim((string) $client_secret);
        $this->tokenfile = rtrim($tokendir, '/') . '/gardena_token.json';
    }

    private function log($level, $msg)
    {
        // Ueber die gemeinsame Funktion, damit Meldungen auch dann irgendwo
        // landen, wenn das LoxBerry-SDK nicht geladen ist (Admin-Oberflaeche).
        if (function_exists('gardena_log')) { gardena_log($level, $msg); return; }
        if ($level === 'ERR' && function_exists('LOGERR')) { LOGERR($msg); return; }
        if ($level === 'DEB' && function_exists('LOGDEB')) { LOGDEB($msg); return; }
        if (function_exists('LOGINF')) { LOGINF($msg); }
    }

    /** Steht die cURL-Erweiterung zur Verfuegung? */
    public static function hasCurl()
    {
        return function_exists('curl_init') && function_exists('curl_exec');
    }

    /**
     * Retry-After aus den Antwortkopfzeilen lesen.
     *
     * Der Wert kann eine Sekundenzahl oder ein Zeitpunkt sein (RFC 7231).
     * Beide Formen werden gelesen; was sich nicht deuten laesst, ergibt 0 -
     * dann entscheidet der Aufrufer selbst ueber die Wartezeit. Geraten wird
     * nichts.
     */
    private function retryAfterLesen($zeilen)
    {
        foreach ((array) $zeilen as $z) {
            if (!preg_match('/^\s*Retry-After\s*:\s*(.+?)\s*$/i', (string) $z, $m)) { continue; }
            $w = $m[1];
            if (preg_match('/^[0-9]+$/', $w)) { return (int) $w; }
            $t = strtotime($w);
            if ($t !== false && $t > time()) { return $t - time(); }
            return 0;
        }
        return 0;
    }

    /**
     * Eine HTTP-Anfrage ausfuehren - bevorzugt per cURL, sonst per PHP-Stream.
     * Rueckgabe: array(Inhalt|false, HTTP-Code, Fehlertext)
     */
    private function http($method, $url, $headers = array(), $body = null, $timeout = 30)
    {
        $this->retry_after = 0;
        if (self::hasCurl()) {
            $kopf = array();
            $ch = curl_init($url);
            // Die Kopfzeilen mitlesen, ohne sie in den Rumpf zu mischen:
            // CURLOPT_HEADER wuerde sie vor den Inhalt setzen, und alles
            // dahinter muesste wieder auseinandergeschnitten werden.
            curl_setopt($ch, CURLOPT_HEADERFUNCTION,
                function ($ch, $zeile) use (&$kopf) { $kopf[] = $zeile; return strlen($zeile); });
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            // Eigene Grenze fuers Verbinden: ohne sie zaehlt nur die
            // Gesamtzeit, und ein nicht erreichbarer Namensdienst blockiert
            // den Cron-Lauf die vollen 30 Sekunden.
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            if ($headers) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
            if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
            $result = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = ($result === false) ? ('cURL-Fehler: ' . curl_error($ch)) : '';
            curl_close($ch);
            $this->last_http = $http;
            if ($http === 429) { $this->retry_after = $this->retryAfterLesen($kopf); }
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
        if ($body !== null) {
            $opt['content'] = $body;
            // Content-Length wird hier BEWUSST nicht von Hand gesetzt.
            //
            // Der HTTP-Datenstrom von PHP ergaenzt die Kopfzeile selbst,
            // sobald 'content' eine nicht leere Zeichenkette ist und die
            // Kopfzeile nicht schon vorhanden ist (ext/standard/
            // http_fopen_wrapper.c). Sie zusaetzlich einzutragen ist im
            // guenstigen Fall wirkungslos und im unguenstigen schaedlich:
            // steht sie doppelt oder mit falscher Laenge in der Anfrage,
            // antworten Gegenstellen mit 400. Die Vermutung, ohne diese
            // Zeile scheitere die Token-Anfrage mit "411 Length Required",
            // trifft deshalb nicht zu.
        }
        $ctx = stream_context_create(array('http' => $opt, 'ssl' => array('verify_peer' => true, 'verify_peer_name' => true)));
        $result = @file_get_contents($url, false, $ctx);
        $http = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $http = (int) $m[1]; }
            }
        }
        $err = ($result === false) ? 'HTTP-Fehler (PHP-Streams): Verbindung fehlgeschlagen' : '';
        $this->last_http = $http;
        if ($http === 429 && isset($http_response_header) && is_array($http_response_header)) {
            $this->retry_after = $this->retryAfterLesen($http_response_header);
        }
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
        // Unteilbar schreiben und die Rechte VOR dem Umbenennen setzen:
        // in der Datei steht ein gueltiges Zugriffstoken der Husqvarna-Wolke.
        // Bis 1.0.2 wurde erst geschrieben und dann chmod aufgerufen - dazwischen
        // lag die Datei mit 0644 auf der Platte.
        $daten = array(
            'access_token' => $this->token,
            'valid_until' => time() + max(60, $expires - 300),
        );
        if (function_exists('gardena_json_write')) {
            gardena_json_write($this->tokenfile, $daten, 0600);
        } else {
            $tmp = $this->tokenfile . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode($daten)) !== false) {
                @chmod($tmp, 0600);
                if (!@rename($tmp, $this->tokenfile)) { @unlink($tmp); }
            }
        }
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
            if ($http === 429) {
                // Das Abrufkontingent ist erschoepft. Wer jetzt im gleichen
                // Takt weiter anklopft, verlaengert die Sperre.
                $this->last_error = 'GET ' . $path . ': HTTP 429 - das Abrufkontingent der '
                    . 'Husqvarna-API ist erschoepft'
                    . ($this->retry_after > 0 ? ' (Retry-After: ' . $this->retry_after . ' s)' : '')
                    . '.';
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
            // Die Antwort kommt aus einer fremden Wolke - jeder Zugriff wird
            // abgesichert. Ohne isset() waere ein Eintrag ohne 'id' unter
            // PHP 8 eine Warnung und der Geraeteschluessel eine leere
            // Zeichenkette; alle Dienste landeten dann unter einem Geraet.
            if (!is_array($svc) || !isset($svc['id']) || (string) $svc['id'] === '') {
                $this->log('DEB', 'Gardena: Eintrag ohne id uebersprungen.');
                continue;
            }
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
