<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie GARDENA smart system.
 *
 * Gruppe 5 / MQTT (1.2.21; Bauvorschrift wz_bild/GRUPPE5_BAUVORSCHRIFT.md, Entwurf Musterprojekt/entwurf_gruppe5_ga.txt
 * Abschnitt 3, ENTSCHEIDUNGEN_GRUPPE5_MQTT.md; Muster vb_g1_bruecke_bau/beispiel/bin/befehle_zuordnung.php und
 * vb_g3_gv_bau/bin/befehle_zuordnung.php fuer Befehle je Geraet). Die Bruecke selbst (bin/befehlsbruecke.php und .sh)
 * ist byte-gleich die Stammfassung Werkzeuge/gemeinsam/ - linieneigen ist nur diese Datei.
 *
 * Regeln (Entwurf gemeinsam 2.5): die Datei gibt ein Feld zurueck, setzt keine Konfiguration und schreibt nichts.
 * 'tun' loest den Teil <geraet> im Abbild auf (gardena_befehl_geraet(): Name oder Kennung klein, "_" statt "-" und
 * "/"; mehrdeutig oder unbekannt wird abgelehnt) und ruft DIESELBE Funktion wie der HTTP-Endpunkt
 * webfrontend/html/index.php - gardena_befehl_ausfuehren() bzw. gardena_abruf_starten() - mit Quelle 'mqtt' (type gegen
 * cmd, Grenzen, Abbild, Mehrventil, Gleichwert-Unterdrueckung X-7, Abrufsperre, 30 Befehle je Stunde, Anmeldung,
 * sendCommand). Die Token-Pruefung des Endpunkts ersetzen die Anmeldung am Broker und die beiden Haken (Entwurf
 * gemeinsam E13).
 *
 * Themen je Geraet <praefix>/befehl/<geraet>/<aktion> (Entwurf ga E1): maehen (Minuten 1-1440), betrieb (zeitplan,
 * parken, parken_dauerhaft), bewaessern (Minuten 1-180), stoppen (Puls), zeitplan (aussetzen, fortsetzen), einschalten
 * (Minuten 1-1440), schalten (dauerhaft, aus); dazu abruf (Puls). Minuten statt Sekunden: der Endpunkt verlangt ein
 * Vielfaches von 60, Minuten machen den Fehler unmoeglich; die Obergrenzen sind die festen Grenzen des Plugins
 * (gardena_sekunden_grenzen(), das Plugin prueft sie weiter selbst). Auswahl, wo die Werte einander ausschliessen: die
 * Bruecke legt je Name zusammen, der neueste Wunsch gilt. Alle Geraetebefehle greifen ein (Maeher, Wasser, Strom):
 * zweiter Haken "Geraete ueber MQTT schalten" (BEFEHLE_MQTT_STEUERN, ab Werk aus, E3); abruf braucht ihn nicht.
 * Sperrzeiten (E2): Maeher 10 s, Ventil und Steckdose 0 s, abruf 60 s (Vorgabe).
 *
 * Die SDK-Variablen ($lbpconfigdir usw.) setzt loxberry_system.php beim Einbinden. Die Bruecke bindet diese Datei
 * aber INNERHALB einer Funktion ein (bb_einbinden()) - dort waeren sie lokal. Sie werden deshalb hier ausdruecklich in
 * $GLOBALS gelegt (aus den Variablen dieses Gueltigkeitsbereichs bzw. den Konstanten des SDK).
 */
if (!function_exists('gardena_befehl_ausfuehren')) {
    require_once __DIR__ . '/header.inc.php';
}
foreach (array('lbhomedir' => 'LBHOMEDIR', 'lbpplugindir' => 'LBPPLUGINDIR', 'lbpconfigdir' => 'LBPCONFIGDIR',
               'lbplogdir' => 'LBPLOGDIR', 'lbpdatadir' => 'LBPDATADIR', 'lbpbindir' => 'LBPBINDIR') as $ga_v => $ga_k) {
    if (isset($GLOBALS[$ga_v]) && (string) $GLOBALS[$ga_v] !== '') { continue; }
    if (isset($$ga_v) && is_string($$ga_v) && $$ga_v !== '') {
        $GLOBALS[$ga_v] = $$ga_v;
    } elseif (defined($ga_k) && (string) constant($ga_k) !== '') {
        $GLOBALS[$ga_v] = (string) constant($ga_k);
    }
}
$ga_maeher = 'Das Plugin bremst Maeherbefehle nicht (nur 30 je Stunde fuer alle Geraete); 10 s fangen ein Prellen ab. '
    . 'Die Sperre gilt je Name und Wert - parken, zeitplan, parken binnen einer Minute bliebe mit 60 s haengen (Bauart '
    . 'Robonect 10 s, Entwurf ga E2).';
$ga_ventil = 'Das Plugin unterdrueckt denselben Befehl mit derselben Dauer an dasselbe Ventil bzw. dieselbe Steckdose 60 s '
    . 'lang selbst (X-7, gardena_gleichwert_*) und zaehlt ihn nicht gegen die 30 je Stunde (Entwurf ga E2).';
$ga_befehl = function ($art, $extra, $sperre, $grund, $titel, $kachel, $type, $cmd) {
    return array_merge(array('art' => $art, 'eingreifend' => true, 'sperre_s' => $sperre, 'begruendung' => $grund,
        'titel' => $titel, 'kachel' => $kachel, 'quittung' => 'ausgefuehrt',
        'tun' => function ($w, $t) use ($art, $type, $cmd) {
            $c = is_array($cmd) ? $cmd[(string) $w] : $cmd;
            $s = ($art === 'ganz') ? (string) ((int) $w * 60) : '';
            return gardena_befehl_bruecke($t['geraet'], $type, $c, $s);
        }), $extra);
};
return array(
    'fassung' => 1,
    'linie'   => 'gardena',
    'plugin'  => 'GARDENA smart system',
    'geladen' => function_exists('gardena_config') && function_exists('gardena_befehl_ausfuehren')
                 && function_exists('gardena_befehl_bruecke') && function_exists('gardena_abruf_starten'),
    'zweiter_haken' => 'BEFEHLE_MQTT_STEUERN',
    'einstellungen' => function () {
        return gardena_befehle_einstellungen();
    },
    'protokoll' => function ($stufe, $text) {
        gardena_log($stufe === 'INFO' ? 'INF' : 'ERR', $text);
    },
    'teile' => array('geraet' => '[a-z0-9_]{1,40}'),
    'befehle' => array(
        '{geraet}/maehen' => $ga_befehl('ganz', array('min' => 1, 'max' => 1440), 10, $ga_maeher,
            "Gardena M\u{e4}hroboter m\u{e4}hen (MQTT)", 'BEFEHL.K_MAEHEN', 'MOWER_CONTROL', 'START_SECONDS_TO_OVERRIDE'),
        '{geraet}/betrieb' => $ga_befehl('auswahl', array('werte' => array('zeitplan', 'parken', 'parken_dauerhaft')), 10,
            $ga_maeher, "Gardena M\u{e4}hroboter Betrieb (MQTT)", 'BEFEHL.K_BETRIEB', 'MOWER_CONTROL',
            array('zeitplan' => 'START_DONT_OVERRIDE', 'parken' => 'PARK_UNTIL_NEXT_TASK',
                  'parken_dauerhaft' => 'PARK_UNTIL_FURTHER_NOTICE')),
        '{geraet}/bewaessern' => $ga_befehl('ganz', array('min' => 1, 'max' => 180), 0, $ga_ventil,
            "Gardena Ventil bew\u{e4}ssern (MQTT)", 'BEFEHL.K_BEWAESSERN', 'VALVE_CONTROL', 'START_SECONDS_TO_OVERRIDE'),
        '{geraet}/stoppen' => $ga_befehl('puls', array(), 0, $ga_ventil,
            'Gardena Ventil stoppen (MQTT)', 'BEFEHL.K_STOPPEN', 'VALVE_CONTROL', 'STOP_UNTIL_NEXT_TASK'),
        '{geraet}/zeitplan' => $ga_befehl('auswahl', array('werte' => array('aussetzen', 'fortsetzen')), 0, $ga_ventil,
            'Gardena Ventil Zeitplan (MQTT)', 'BEFEHL.K_ZEITPLAN', 'VALVE_CONTROL',
            array('aussetzen' => 'PAUSE', 'fortsetzen' => 'UNPAUSE')),
        '{geraet}/einschalten' => $ga_befehl('ganz', array('min' => 1, 'max' => 1440), 0, $ga_ventil,
            'Gardena Steckdose ein auf Zeit (MQTT)', 'BEFEHL.K_EINSCHALTEN', 'POWER_SOCKET_CONTROL', 'START_SECONDS_TO_OVERRIDE'),
        '{geraet}/schalten' => $ga_befehl('auswahl', array('werte' => array('dauerhaft', 'aus')), 0, $ga_ventil,
            'Gardena Steckdose schalten (MQTT)', 'BEFEHL.K_SCHALTEN', 'POWER_SOCKET_CONTROL',
            array('dauerhaft' => 'START_OVERRIDE', 'aus' => 'STOP_UNTIL_NEXT_TASK')),
        'abruf' => array('art' => 'puls', 'eingreifend' => false,
            'titel' => 'Gardena Sofortabruf (MQTT)', 'kachel' => 'BEFEHL.K_ABRUF', 'quittung' => 'uebergeben',
            'tun' => function ($w, $t) {
                $r = gardena_abruf_starten('mqtt');
                return array((int) $r[0] === 200, (int) $r[0] === 200 ? '' : (string) $r[3]);
            }),
    ),
);
