#!/bin/sh
# Gardena Smart System - preupgrade (laeuft als Benutzer loxberry)
#
# Laeuft als allererster Schritt eines Updates, noch vor preinstall.sh.

ARGV3=$3   # Installationsordner des Plugins
ARGV5=$5   # Wurzelverzeichnis des LoxBerry

BASE="${ARGV5:-$LBHOMEDIR}"
PFOLDER="${ARGV3:-gardenasmartsystem}"

# Die Sicherung liegt BEWUSST NICHT unter /tmp/uploads/.
#
# Diesen Ordner gibt es nur beim Hochladen ueber die Oberflaeche. Beim
# Auto-Update war er nicht da, mkdir scheiterte, das anschliessende cp lief
# ins Leere - und postupgrade.sh fand nichts zum Zurueckstellen. Ergebnis:
# gardena.cfg mit Application Key, Secret und Zugriffstoken war nach dem
# Update weg.
#
# Ausserdem ist /tmp auf dem LoxBerry eine Ramdisk. Zwischen preupgrade und
# postupgrade liegt eine Paketinstallation; braucht die einen Neustart oder
# bricht das Update dazwischen ab, ist die Ramdisk leer.
#
# Deshalb: data/plugins/<Ordner>/upgrade_sicherung - auf der Karte.
SICHER="$BASE/data/plugins/$PFOLDER/upgrade_sicherung"

echo "<INFO> Sichere Konfiguration und Protokolle nach $SICHER"
rm -rf "$SICHER" 2>/dev/null
mkdir -p "$SICHER/config" "$SICHER/log" 2>/dev/null
chmod 0700 "$SICHER" 2>/dev/null

if [ -d "$BASE/config/plugins/$PFOLDER" ]; then
    cp -a "$BASE/config/plugins/$PFOLDER/." "$SICHER/config/" 2>/dev/null
    # In der Datei stehen Application Secret und Zugriffstoken - die Kopie
    # bekommt dieselben engen Rechte.
    chmod 0640 "$SICHER/config/gardena.cfg" 2>/dev/null
    chmod 0600 "$SICHER/config/gardena_token.json" 2>/dev/null
    echo "<OK> Konfiguration gesichert."
else
    echo "<INFO> Keine Konfiguration vorhanden."
fi

if [ -d "$BASE/log/plugins/$PFOLDER" ]; then
    cp -a "$BASE/log/plugins/$PFOLDER/." "$SICHER/log/" 2>/dev/null
    echo "<OK> Protokolle gesichert."
fi

exit 0
