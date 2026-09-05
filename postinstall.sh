#!/bin/sh
# Gardena Smart System - postinstall (laeuft als Benutzer loxberry)

ARGV3=$3
ARGV5=$5
BASE="${ARGV5:-$LBHOMEDIR}"
PFOLDER="${ARGV3:-gardenasmartsystem}"
CFG="$BASE/config/plugins/$PFOLDER/gardena.cfg"

# Ohne eine brauchbare Wurzel wird NICHTS angefasst.
# Sind $5 und LBHOMEDIR beide leer, entstuenden sonst absolute Pfade ab der
# Wurzel: mkdir -p /config/plugins/... und rm -rf /data/plugins/... . Der
# feste Namensteil verhindert den grossen Schaden, und als loxberry scheitert
# es voraussichtlich an den Rechten - sauber ist es nicht. uninstall/uninstall
# macht diese Pruefung im selben Paket seit jeher richtig.
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    echo "<WARNING> LoxBerry-Wurzel nicht bestimmbar (weder Argument 5 noch LBHOMEDIR)."
    echo "<WARNING> Es wurde nichts geaendert."
    exit 0
fi

mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" \
         "$BASE/data/plugins/$PFOLDER" 2>/dev/null

# In der Konfiguration stehen Application Secret und Zugriffstoken im
# Klartext - sie darf nicht fuer alle lesbar sein. Bis 1.0.2 wurden die
# Rechte nur beim Speichern aus der Oberflaeche gesetzt; bis dahin lag die
# Datei mit den Vorgaberechten da.
if [ -f "$CFG" ]; then
    chmod 0640 "$CFG" 2>/dev/null
    echo "<OK> Rechte der Konfiguration gesetzt (0640)."
fi

# bin/ ausfuehrbar machen. BERICHTIGT in 1.2.6: die Begruendung stimmte
# nicht - cron.05min ruft "php <datei>", und dabei braucht die Datei nur
# Leserecht. Das Ausfuehrungsrecht schadet nicht (gardenaMain.php traegt eine
# Shebang-Zeile und laesst sich damit auch direkt starten), aber ein
# Kommentar, den der Code nicht einloest, fuehrt die naechste Aenderung in
# die Irre.
chmod 755 "$BASE/bin/plugins/$PFOLDER/gardenaMain.php" 2>/dev/null
chmod 644 "$BASE/bin/plugins/$PFOLDER"/*.inc.php 2>/dev/null

if php -r 'exit(function_exists("curl_init") ? 0 : 1);' 2>/dev/null; then
    echo "<OK> PHP-Erweiterung curl vorhanden."
else
    echo "<INFO> PHP-Erweiterung curl fehlt - das Plugin nutzt dann PHP-Datenstroeme."
    echo "<INFO> Nachinstallieren: sudo apt-get update && sudo apt-get install -y php-curl"
fi
if php -r 'exit(function_exists("socket_create") ? 0 : 1);' 2>/dev/null; then
    echo "<OK> PHP-Erweiterung sockets vorhanden."
else
    echo "<WARNING> PHP-Erweiterung sockets fehlt - ohne sie ist WEDER UDP NOCH MQTT moeglich."
    echo "<WARNING> Nachinstallieren: sudo apt-get install -y php-sockets"
fi

echo "<INFO> Naechster Schritt: Plugin-Oberflaeche oeffnen, Application Key und"
echo "<INFO> Secret von developer.husqvarnagroup.cloud eintragen und speichern."

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-gardenasmartsystem}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
netz_zurueck() {
    datei=$1; soll=$2
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    verloren=0
    if [ ! -f "$ziel" ] || [ ! -s "$ziel" ]; then
        verloren=1
    else
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
    fi
    if [ "$verloren" = "1" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            # cp -p uebernimmt die 0600 der Zweitschrift. Ueberall sonst im
            # Paket traegt die gardena.cfg 0640 (postinstall oben,
            # preupgrade, postupgrade). Nach einer Rettung ueber diesen Weg
            # lag sie bis 1.2.5 mit 0600 da - laeuft der Webserver unter
            # einem anderen Benutzer, der nur ueber die Gruppe herankommt,
            # liest die Oberflaeche danach ihre eigene Konfiguration nicht.
            chmod 0640 "$ziel" 2>/dev/null
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt (Rechte 0640)."
        else
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
# Die Pruefsumme der MITGELIEFERTEN Vorgabe. Sie ist eine Momentaufnahme:
# jede kuenftige Aenderung an config/gardena.cfg - auch nur ein Kommentar -
# macht sie ungueltig, und dann faellt dieser Rettungsweg STILL aus. Sie
# gehoert deshalb in die Freigabe-Pruefliste jeder Fassung, die
# config/gardena.cfg anfasst. Nachrechnen:
#     sha256sum config/gardena.cfg
netz_zurueck "gardena.cfg" "ba8589cf2ef0c5d8ed0fc1135a0463178b477d00400053ac8a5c7f991dbc0b7e"


# Zurueckspielen fuer Dateien OHNE mitgelieferte Vorgabe: es gibt nichts,
# womit man vergleichen koennte, also ist das Kriterium "fehlt oder leer".
# Eine vorhandene Datei wird nie ueberschrieben.
netz_ohne_vorgabe() {
    ziel="$NETZ_CFG/$1"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$1"
    modus="${2:-0600}"
    [ -f "$zweit" ] || return 0
    if [ ! -s "$ziel" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            # Die Rechte kommen jetzt je Datei mit. Bis 1.2.5 setzte dieser
            # Weg alles auf 0600 - auch devices_cache.json, die der Code
            # bewusst mit 0640 schreibt und die der OEFFENTLICHE Endpunkt
            # liest. Laeuft der Cron als root und Apache als loxberry,
            # antwortete ?action=list danach dauerhaft "Noch keine Daten"
            # und ?action=command fand nie eine Dienstkennung.
            chmod "$modus" "$ziel" 2>/dev/null
            echo "<OK> $1 aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $1 liess sich nicht zurueckspielen ($zweit)."
        fi
    fi
}
netz_ohne_vorgabe "gardena_token.json" 0600
netz_ohne_vorgabe "devices_cache.json" 0640
netz_ohne_vorgabe "gardena_status.json" 0640

exit 0
