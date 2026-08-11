#!/bin/sh
# Gardena Smart System - postinstall (laeuft als Benutzer loxberry)

ARGV3=$3
ARGV5=$5
BASE="${ARGV5:-$LBHOMEDIR}"
PFOLDER="${ARGV3:-gardenasmartsystem}"
CFG="$BASE/config/plugins/$PFOLDER/gardena.cfg"

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

# bin/ ausfuehrbar machen - ohne das startet der Cron-Lauf nicht.
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
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
netz_zurueck "gardena.cfg" "ba8589cf2ef0c5d8ed0fc1135a0463178b477d00400053ac8a5c7f991dbc0b7e"

exit 0
