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
exit 0
