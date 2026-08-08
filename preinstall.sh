#!/bin/sh
# Gardena Smart System - preinstall (laeuft als Benutzer loxberry)
#
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER> [<TEMPPATH>]

ARGV1=$1   # Name bzw. Pfad des temporaeren Ordners
ARGV6=$6   # ab LoxBerry 2: der vollstaendige Pfad des entpackten Archivs

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
