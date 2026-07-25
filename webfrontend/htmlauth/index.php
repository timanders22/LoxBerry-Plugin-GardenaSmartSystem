<?php
/**
 * GARDENA smart system - Admin-Oberflaeche (LoxBerry 3/4, PHP 7.4+/8.x)
 * Ersetzt das alte Perl-CGI (LoxBerry-1.x-Templatesystem, nicht mehr vorhanden).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

require_once 'loxberry_system.php';
require_once 'loxberry_web.php';

global $lbpconfigdir, $lbpplugindir;
$gconfigfile = $lbpconfigdir . '/gardena.cfg';

function gsel($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

// ---------- Speichern ----------
$gsaved = false;
$gtest = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $gnew = array(
        'ENABLED' => (isset($_POST['enabled']) && $_POST['enabled'] == '1') ? '1' : '0',
        'CLIENT_ID' => trim((string) ($_POST['client_id'] ?? '')),
        'CLIENT_SECRET' => trim((string) ($_POST['client_secret'] ?? '')),
        'MINISERVER' => max(1, (int) ($_POST['miniserver'] ?? 1)),
        'UDP_ENABLED' => (isset($_POST['udp_enabled']) && $_POST['udp_enabled'] == '1') ? '1' : '0',
        'UDPPORT' => max(1, min(65535, (int) ($_POST['udpport'] ?? 5005))),
        'MQTT_ENABLED' => (isset($_POST['mqtt_enabled']) && $_POST['mqtt_enabled'] == '1') ? '1' : '0',
        'MQTT_TOPIC' => trim((string) ($_POST['mqtt_topic'] ?? 'gardena')) ?: 'gardena',
    );
    $ini = "[GARDENA]\n";
    foreach ($gnew as $k => $v) { $ini .= $k . '=' . $v . "\n"; }
    if (@file_put_contents($gconfigfile, $ini) !== false) {
        $gsaved = true;
        @chmod($gconfigfile, 0640);
        @unlink($lbpconfigdir . '/gardena_token.json'); // Token-Cache verwerfen (neue Zugangsdaten)
    }

    // Verbindungstest bei aktiviertem Plugin
    if ($gsaved && $gnew['ENABLED'] == '1' && $gnew['CLIENT_ID'] !== '' && $gnew['CLIENT_SECRET'] !== '') {
        require_once __DIR__ . '/../html/gardena.class.inc.php';
        $gapi = new gardena($gnew['CLIENT_ID'], $gnew['CLIENT_SECRET'], $lbpconfigdir);
        if ($gapi->authenticate()) {
            $glocs = $gapi->getLocations();
            if (!empty($glocs)) {
                $names = array();
                foreach ($glocs as $l) { $names[] = isset($l['attributes']['name']) ? $l['attributes']['name'] : $l['id']; }
                $gtest = 'OK: Verbindung erfolgreich. Location(s): ' . implode(', ', $names);
            } else {
                $gtest = 'WARNUNG: Anmeldung ok, aber keine Location gefunden. ' . $gapi->last_error;
            }
        } else {
            $gtest = 'FEHLER: ' . $gapi->last_error;
        }
    }
}

// ---------- Laden ----------
$gini = @parse_ini_file($gconfigfile, true, INI_SCANNER_RAW);
$gc = (is_array($gini) && !empty($gini['GARDENA'])) ? $gini['GARDENA'] : array();
$gc += array('ENABLED' => '0', 'CLIENT_ID' => '', 'CLIENT_SECRET' => '', 'MINISERVER' => '1',
    'UDP_ENABLED' => '1', 'UDPPORT' => '5005', 'MQTT_ENABLED' => '1', 'MQTT_TOPIC' => 'gardena');

// Miniserver-Liste
$gms = LBSystem::get_miniservers();
if (!is_array($gms)) { $gms = array(); }

// Geraete-Cache
$gcache = json_decode((string) @file_get_contents($lbpconfigdir . '/devices_cache.json'), true) ?: array();

LBWeb::lbheader('Gardena Smart System', 'https://developer.husqvarnagroup.cloud/', '');
?>
<style>
.gsw { max-width: 900px; margin: 0 auto; }
.gsw h2 { color: #2e7d32; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.gsw label { display: block; font-weight: 600; font-size: 0.88em; color: #555; margin: 10px 0 4px; }
.gsw input[type=text], .gsw input[type=password], .gsw input[type=number], .gsw select {
  width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; }
.gsw .row { display: flex; gap: 12px; } .gsw .row > div { flex: 1; }
.gsw .btn { background: #2e7d32; color: #fff; border: 0; border-radius: 6px; padding: 10px 22px; cursor: pointer; margin-top: 18px; }
.gsw .alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.gsw .ok { background: #e8f5e9; border: 1px solid #a5d6a7; }
.gsw .err { background: #ffebee; border: 1px solid #ef9a9a; }
.gsw .info { background: #e3f2fd; border: 1px solid #90caf9; font-size: 0.88em; }
.gsw .mono { font-family: ui-monospace, monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
.gsw table { border-collapse: collapse; width: 100%; font-size: 0.9em; }
.gsw td, .gsw th { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
</style>
<div class="gsw">

<?php if (!class_exists('gardena')) { require_once __DIR__ . '/../html/gardena.class.inc.php'; }
if (!gardena::hasCurl()) { ?>
<div class="alert err"><b>Hinweis: Die PHP-Erweiterung <span style="font-family:monospace;">php-curl</span> fehlt.</b><br>
Das Plugin arbeitet trotzdem &mdash; es nutzt dann PHP-Streams statt cURL. Etwas robuster wird es mit dem Paket.
Nachinstallieren am LoxBerry (SSH):<br>
<span style="font-family:monospace;">sudo apt-get update &amp;&amp; sudo apt-get install -y php-curl</span><br>
<span style="font-size:0.9em;">Das <span style="font-family:monospace;">apt-get update</span> ist wichtig: Schl&auml;gt die Installation mit
&bdquo;404 Not Found&ldquo; auf packages.sury.org fehl, ist nur der Paketindex veraltet.</span></div>
<?php } ?>
<?php if ($gsaved) { ?><div class="alert ok"><b>Konfiguration gespeichert.</b></div><?php } ?>
<?php if ($gtest !== '') { ?><div class="alert <?= strpos($gtest, 'OK:') === 0 ? 'ok' : 'err' ?>"><?= gsel($gtest) ?></div><?php } ?>

<div class="alert info">
<b>Zugangsdaten (einmalig):</b> Auf <a href="https://developer.husqvarnagroup.cloud/" target="_blank">developer.husqvarnagroup.cloud</a>
mit dem GARDENA/Husqvarna-Konto anmelden &rarr; <b>Create application</b> &rarr; unter <b>Connect an API</b> die
&bdquo;GARDENA smart system API&ldquo; verbinden &rarr; <b>Application Key</b> und <b>Application Secret</b> unten eintragen.
Die fr&uuml;here Anmeldung mit Benutzername/Passwort funktioniert nicht mehr (alte API abgeschaltet).
</div>

<form method="post" autocomplete="off">
<input type="hidden" name="save" value="1">

<h2>Allgemein</h2>
<div class="row">
    <div>
        <label>Plugin aktiv</label>
        <select name="enabled">
            <option value="0"<?= $gc['ENABLED'] != '1' ? ' selected' : '' ?>>Nein</option>
            <option value="1"<?= $gc['ENABLED'] == '1' ? ' selected' : '' ?>>Ja (Abruf alle 5 Minuten)</option>
        </select>
    </div>
    <div>
        <label>Application Key (Client-ID)</label>
        <input type="text" name="client_id" value="<?= gsel($gc['CLIENT_ID']) ?>">
    </div>
    <div>
        <label>Application Secret</label>
        <input type="password" name="client_secret" value="<?= gsel($gc['CLIENT_SECRET']) ?>">
    </div>
</div>

<h2>UDP an Miniserver</h2>
<div class="row">
    <div>
        <label>UDP-Versand</label>
        <select name="udp_enabled">
            <option value="1"<?= $gc['UDP_ENABLED'] != '0' ? ' selected' : '' ?>>Aktiv</option>
            <option value="0"<?= $gc['UDP_ENABLED'] == '0' ? ' selected' : '' ?>>Aus</option>
        </select>
    </div>
    <div>
        <label>Miniserver</label>
        <select name="miniserver">
            <?php if (empty($gms)) { ?><option value="1">Kein Miniserver konfiguriert!</option><?php } ?>
            <?php foreach ($gms as $nr => $ms) { ?>
            <option value="<?= (int) $nr ?>"<?= (int) $gc['MINISERVER'] === (int) $nr ? ' selected' : '' ?>><?= gsel($ms['Name'] . ' (' . $ms['IPAddress'] . ')') ?></option>
            <?php } ?>
        </select>
    </div>
    <div>
        <label>UDP-Port</label>
        <input type="number" name="udpport" value="<?= (int) $gc['UDPPORT'] ?>" min="1" max="65535">
    </div>
</div>
<p style="font-size:0.85em;color:#666;">Format wie bisher: <span class="mono">SERVICE.Ger&auml;tename.attribut:wert</span> &ndash; in Loxone per Virtuellem UDP-Eingang mit Befehlserkennung auswerten.</p>

<h2>MQTT (LoxBerry MQTT Gateway)</h2>
<div class="row">
    <div>
        <label>MQTT-Versand</label>
        <select name="mqtt_enabled">
            <option value="1"<?= $gc['MQTT_ENABLED'] == '1' ? ' selected' : '' ?>>Aktiv (empfohlen)</option>
            <option value="0"<?= $gc['MQTT_ENABLED'] != '1' ? ' selected' : '' ?>>Aus</option>
        </select>
    </div>
    <div>
        <label>Basis-Topic</label>
        <input type="text" name="mqtt_topic" value="<?= gsel($gc['MQTT_TOPIC']) ?>" placeholder="gardena">
    </div>
</div>
<p style="font-size:0.85em;color:#666;">Topics: <span class="mono">gardena/&lt;Ger&auml;t&gt;/&lt;SERVICE&gt;/&lt;attribut&gt;</span> (retained) &ndash;
im LoxBerry MQTT Gateway abonnieren mit <span class="mono">gardena/#</span>.</p>

<button class="btn" type="submit">Speichern &amp; Verbindung testen</button>
</form>

<h2>Ger&auml;te (letzter Abruf<?= !empty($gcache['updated']) ? ': ' . gsel($gcache['updated']) : '' ?>)</h2>
<?php if (empty($gcache['locations'])) { ?>
<p>Noch keine Daten. Nach dem Speichern l&auml;uft der Abruf automatisch alle 5 Minuten, oder sofort per
<a href="/plugins/<?= gsel($lbpplugindir) ?>/index.php?action=refresh" target="_blank">Jetzt abrufen</a>.</p>
<?php } else { foreach ($gcache['locations'] as $gloc) { ?>
<p><b><?= gsel($gloc['name']) ?></b></p>
<table>
<tr><th>Ger&auml;t</th><th>Services</th><th>Status</th><th>Akku</th></tr>
<?php foreach ($gloc['devices'] as $gdevid => $gdev) {
    $svcs = implode(', ', array_keys($gdev['services']));
    $state = isset($gdev['services']['COMMON']['rfLinkState']['value']) ? $gdev['services']['COMMON']['rfLinkState']['value'] : '';
    $batt = isset($gdev['services']['COMMON']['batteryLevel']['value']) ? $gdev['services']['COMMON']['batteryLevel']['value'] . ' %' : '';
?>
<tr><td><?= gsel($gdev['name']) ?></td><td><?= gsel($svcs) ?></td><td><?= gsel($state) ?></td><td><?= gsel($batt) ?></td></tr>
<?php } ?>
</table>
<?php } } ?>

<h2>Einbindung in Loxone</h2>
<div class="alert info">
<b>Werte empfangen:</b> Virtueller UDP-Eingang auf Port <?= (int) $gc['UDPPORT'] ?> bzw. MQTT Gateway.<br>
<b>Kommandos senden</b> (Virtueller Ausgang, Befehl bei EIN):<br>
<span class="mono">/plugins/<?= gsel($lbpplugindir) ?>/index.php?action=command&amp;device=NAME&amp;type=MOWER_CONTROL&amp;cmd=START_SECONDS_TO_OVERRIDE&amp;seconds=3600</span><br>
<span class="mono">/plugins/<?= gsel($lbpplugindir) ?>/index.php?action=command&amp;device=NAME&amp;type=MOWER_CONTROL&amp;cmd=PARK_UNTIL_NEXT_TASK</span><br>
<span class="mono">/plugins/<?= gsel($lbpplugindir) ?>/index.php?action=command&amp;device=NAME&amp;type=VALVE_CONTROL&amp;cmd=START_SECONDS_TO_OVERRIDE&amp;seconds=1800</span><br>
Test/Doku: <a href="/plugins/<?= gsel($lbpplugindir) ?>/index.php" target="_blank">Endpunkt-&Uuml;bersicht</a> |
<a href="/plugins/<?= gsel($lbpplugindir) ?>/index.php?action=list" target="_blank">Ger&auml;teliste (JSON)</a> |
<a href="/admin/system/logmanager.cgi?package=<?= gsel($lbpplugindir) ?>" target="_blank">Logs</a>
</div>

</div>
<?php
LBWeb::lbfooter();
