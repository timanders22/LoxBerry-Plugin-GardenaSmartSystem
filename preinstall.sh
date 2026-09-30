#!/bin/sh
# Gardena Smart System - preinstall (laeuft als Benutzer loxberry)
#
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER> [<TEMPPATH>]

ARGV1=$1   # Name bzw. Pfad des temporaeren Ordners
ARGV3=$3   # Installationsordner des Plugins
ARGV5=$5   # Wurzelverzeichnis des LoxBerry
ARGV6=$6   # ab LoxBerry 2: der vollstaendige Pfad des entpackten Archivs

# ---------------------------------------------------------------------------
# NEUINSTALLATION: liegengebliebene Zweitschriften beiseitelegen (1.2.11, I1;
# Entscheidung 1 vom 29.09.2026).
#
# Der Installer ruft dieses Skript bei JEDEM Einbau auf, nach dem Aufraeumen
# der alten Fassung und VOR dem Kopieren von Konfiguration und Cron-Datei
# (Geraet/2026-09-05/08_plugininstall.pl: preupgrade :846, purge :874,
# preinstall :877). Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt -
# ohne Altersgrenze. Dann tut es hier nichts.
#
# Ohne Marke ist es eine Neuinstallation. Die vier Zweitschriften
# (config/plugins/<ordner>.backup.*) und eine Upgrade-Sicherung
# (data/plugins/<ordner>.upgrade_sicherung) einer frueheren Installation gehen
# nach <name>.alt (0600), gemeldet mit genau einer <WARNING>. Bis 1.2.10
# spielte postinstall.sh sie ungefragt ein - Application Key/Secret,
# Aktionstoken und OAuth-Token des frueheren Kontos, und der Dienst meldete
# sich damit an (in WSL gemessen, installer Befund 1, Fall N2). Die
# Bibliothek liest .alt nie; die Deinstallation raeumt es ab. Bauform
# audi_bau/preinstall.sh.
# ---------------------------------------------------------------------------
GA_BASE="${ARGV5:-$LBHOMEDIR}"
GA_ORDNER="${ARGV3:-gardenasmartsystem}"
beiseitelegen() {
    if [ -z "$GA_BASE" ] || [ ! -d "$GA_BASE/config/plugins" ] || [ ! -d "$GA_BASE/data/plugins" ] \
       || [ ! -f "$GA_BASE/config/system/general.json" ]; then
        echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$GA_BASE') - nichts beiseitegelegt."
        return 0
    fi
    case "$GA_ORDNER" in
        ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$GA_ORDNER' - nichts beiseitegelegt."; return 0 ;;
    esac
    [ -f "$GA_BASE/data/plugins/$GA_ORDNER.upgrade_laeuft" ] && return 0
    BEISEITE=""
    FEST=""
    for ZIEL in "$GA_BASE/config/plugins/$GA_ORDNER.backup.gardena.cfg" \
                "$GA_BASE/config/plugins/$GA_ORDNER.backup.gardena_token.json" \
                "$GA_BASE/config/plugins/$GA_ORDNER.backup.devices_cache.json" \
                "$GA_BASE/config/plugins/$GA_ORDNER.backup.gardena_status.json" \
                "$GA_BASE/data/plugins/$GA_ORDNER.upgrade_sicherung"; do
        if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
            rm -rf "${ZIEL:?}.alt" 2>/dev/null
            if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
                if [ -L "$ZIEL.alt" ]; then
                    :
                elif [ -d "$ZIEL.alt" ]; then
                    chmod 0700 "$ZIEL.alt" 2>/dev/null
                    find "$ZIEL.alt" -type f -exec chmod 0600 {} + 2>/dev/null
                else
                    chmod 0600 "$ZIEL.alt" 2>/dev/null
                fi
                BEISEITE="$BEISEITE $ZIEL.alt"
            else
                FEST="$FEST $ZIEL"
            fi
        fi
    done
    if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
        T="<WARNING> Neuinstallation: Einstellungen und Zugangsdaten einer frueheren Installation werden NICHT eingespielt."
        [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
        [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
        echo "$T"
    fi
    return 0
}
beiseitelegen

# Den entpackten Ordner FINDEN, nicht raten.
#
# Bis 1.0.2 stand hier fest /tmp/uploads/$ARGV1. Diesen Pfad gibt es nur,
# wenn das Plugin von Hand ueber die LoxBerry-Oberflaeche hochgeladen wird.
# Beim Auto-Update und bei der Installation von der Kommandozeile liegt das
# Archiv woanders - dann lief find ins Leere, dos2unix bekam keine Dateien,
# und der Fehler fiel niemandem auf, weil find still bleibt.
#
# Deshalb der Reihe nach: erst der ausdrueckliche Pfad aus dem sechsten
# Argument, dann das erste Argument selbst (falls es schon ein Pfad ist),
# zuletzt der alte Ort.
PSRC=""
for k in "$ARGV6" "$ARGV1" "/tmp/uploads/$ARGV1" "/tmp/$ARGV1"; do
    if [ -n "$k" ] && [ -d "$k" ]; then PSRC="$k"; break; fi
done

if [ -z "$PSRC" ]; then
    echo "<WARNING> Entpackter Plugin-Ordner nicht gefunden - Zeilenenden werden nicht umgestellt."
    echo "<INFO> Das ist nur dann ein Problem, wenn die Dateien unter Windows bearbeitet wurden."
    exit 0
fi

echo "<INFO> Quellordner: $PSRC"
if command -v dos2unix >/dev/null 2>&1; then
    find "$PSRC" -type f \( -name '*.php' -o -name '*.sh' -o -name '*.cfg' -o -name '*.ini' \
         -o -name 'cron.*' -o -name 'apt' \) -print0 | xargs -0 -r dos2unix -q
    echo "<OK> Zeilenenden umgestellt."
else
    echo "<INFO> dos2unix ist nicht vorhanden - uebersprungen."
fi

exit 0
