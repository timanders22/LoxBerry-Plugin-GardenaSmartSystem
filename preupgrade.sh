#!/bin/sh
# Gardena Smart System - preupgrade (laeuft als Benutzer loxberry)
#
# Laeuft als allererster Schritt eines Updates, noch vor preinstall.sh.

ARGV3=$3   # Installationsordner des Plugins
ARGV5=$5   # Wurzelverzeichnis des LoxBerry
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"

BASE="${ARGV5:-$LBHOMEDIR}"
PFOLDER="${ARGV3:-gardenasmartsystem}"
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
# Deshalb: data/plugins/<Ordner>.upgrade_sicherung - auf der Karte.
# Die Sicherung liegt NEBEN dem Ordner, nicht darin. Gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026): der Installer ruft
# &purge_installation nicht nur beim Deinstallieren, sondern auch im
# Upgrade-Zweig (:886), und deren Rumpf loescht ohne jede Bedingung
# (:1629 ff.) config/plugins/<x>/, bin/plugins/<x>/, data/plugins/<x>/,
# templates/plugins/<x>/ und beide webfrontend/-Ordner. Eine Sicherung IN
# data/plugins/<x>/ wird also von genau dem Schritt vernichtet, den sie
# ueberdauern soll. Der Punkt im Namen ist der ganze Unterschied:
# "rm -rf .../<x>/" trifft den Nachbarn "<x>.upgrade_sicherung" nicht.
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"

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


# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> (plugininstall.pl Zeile 899, cp -r ohne -n) und
# ueberschreibt dabei die Datei des Nutzers. Bisher haing die Rettung allein
# an postupgrade.sh. Laeuft das aus irgendeinem Grund nicht durch, greift
# jetzt postinstall.sh auf diese Zweitschrift zu - sie liegt ausserhalb des
# ueberschriebenen Ordners und wird vom Installer nicht angefasst.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-gardenasmartsystem}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
# Rueckgabe pruefen und nur melden, was wirklich geschah. Bis 1.2.5 stand
# die Erfolgsmeldung UNBEDINGT hinter dem cp - sie erschien auch dann, wenn
# das if daneben gar nicht zugetroffen hatte oder das cp an Platz oder
# Rechten scheiterte. Der Verlust faellt dann erst auf, wenn Key, Secret und
# Token weg sind.
if [ -s "$NETZ_CFG/gardena.cfg" ]; then
    if cp -p "$NETZ_CFG/gardena.cfg" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.gardena.cfg" 2>/dev/null; then
        chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.gardena.cfg" 2>/dev/null
        echo "<INFO> Zweitschrift der Einstellungen angelegt."
    else
        echo "<WARNING> Die Zweitschrift der Einstellungen liess sich NICHT anlegen."
        echo "<WARNING> Platz und Rechte in $NETZ_BASE/config/plugins pruefen."
    fi
fi


# NICHT MITGELIEFERTE Dateien - und gerade deshalb die wichtigen.
# Das Archiv liefert sie nie, also standen sie bis jetzt auf keiner Liste;
# geloescht werden sie vom Installer trotzdem, samt Token und Zugangsdaten.
if [ -s "$NETZ_CFG/gardena_token.json" ]; then
    cp -p "$NETZ_CFG/gardena_token.json" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.gardena_token.json" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.gardena_token.json" 2>/dev/null
fi
if [ -s "$NETZ_CFG/devices_cache.json" ]; then
    cp -p "$NETZ_CFG/devices_cache.json" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.devices_cache.json" 2>/dev/null \
        && chmod 0640 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.devices_cache.json" 2>/dev/null
fi
# gardena_status.json gehoert MIT in die Zweitschrift.
# Bis 1.2.5 fehlte sie: der Sammelweg (cp -a nach $SICHER) deckt sie ab, die
# Zweitschrift kannte nur drei Dateien. Faellt der Sammelweg aus - genau der
# Fall, fuer den es die Zweitschrift gibt -, gehen 'letzter_erfolg' und
# 'sperre_bis' (die HTTP-429-Wartezeit) verloren; das Lebenszeichen meldet
# danach zeitstempel=0, in Loxone also "noch nie erfolgreich".
if [ -s "$NETZ_CFG/gardena_status.json" ]; then
    cp -p "$NETZ_CFG/gardena_status.json" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.gardena_status.json" 2>/dev/null \
        && chmod 0640 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.gardena_status.json" 2>/dev/null
fi

exit 0
