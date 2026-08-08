#!/bin/sh
# Gardena Smart System - postupgrade (laeuft als Benutzer loxberry)

ARGV1=$1
ARGV3=$3
ARGV5=$5

BASE="${ARGV5:-$LBHOMEDIR}"
PFOLDER="${ARGV3:-gardenasmartsystem}"
SICHER="$BASE/data/plugins/$PFOLDER/upgrade_sicherung"

mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" \
         "$BASE/data/plugins/$PFOLDER" 2>/dev/null

# Wer von 1.0.2 oder frueher kommt, hat die Sicherung noch am alten Ort -
# damit dieses eine Update nichts verliert, wird auch dort nachgesehen.
if [ ! -d "$SICHER/config" ]; then
    for k in "/tmp/uploads/${ARGV1}_upgrade" "/tmp/${ARGV1}_upgrade"; do
        if [ -d "$k/config" ]; then
            SICHER="$k"
            echo "<INFO> Sicherung am alten Ort gefunden ($k)."
            break
        fi
    done
fi

# Geschweifte Klammern statt Rueckstrich: ${ARGV1}_upgrade ist eindeutig,
# $ARGV1\_upgrade verlaesst sich darauf, dass die Shell den Rueckstrich als
# Ende des Variablennamens deutet.

echo "<INFO> Stelle Konfiguration zurueck"
# Die Existenz PRUEFEN, bevor kopiert wird.
#
# Bis 1.0.2 lief hier ein cp auf einen Pfad mit *, ohne jede Pruefung. Gab
# es das Quellverzeichnis nicht, brach cp mit "No such file or directory"
# ab - und die Zeile darunter legte per >> eine gardena.cfg an, in der dann
# NUR "LOCALTIME=0" stand. Das ist keine leere Konfiguration, sondern eine
# kaputte: sie hat keinen Abschnitt [GARDENA], und alles, was danach
# parse_ini_file benutzte, fand nichts mehr.
if [ -d "$SICHER/config" ] && [ -n "$(ls -A "$SICHER/config" 2>/dev/null)" ]; then
    cp -a "$SICHER/config/." "$BASE/config/plugins/$PFOLDER/" 2>/dev/null
    chmod 0640 "$BASE/config/plugins/$PFOLDER/gardena.cfg" 2>/dev/null
    chmod 0600 "$BASE/config/plugins/$PFOLDER/gardena_token.json" 2>/dev/null
    echo "<OK> Konfiguration zurueckgestellt."
else
    echo "<WARNING> Keine gesicherte Konfiguration gefunden."
    echo "<WARNING> Application Key, Secret und Zugriffstoken muessen in der"
    echo "<WARNING> Plugin-Oberflaeche neu eingetragen werden."
fi

# Die frueher hier angehaengte Zeile LOCALTIME=0 ist ersatzlos entfallen.
# Der Wert wurde im gesamten Plugin nirgends gelesen - er stammt aus der
# alten sg-1-Fassung. Eine Konfigurationszeile, die nichts bewirkt, aber bei
# jedem Update angehaengt wird, stiftet nur Verwirrung.

if [ -d "$SICHER/log" ] && [ -n "$(ls -A "$SICHER/log" 2>/dev/null)" ]; then
    cp -a "$SICHER/log/." "$BASE/log/plugins/$PFOLDER/" 2>/dev/null
    echo "<OK> Protokolle zurueckgestellt."
fi

# Das zwischengespeicherte OAuth2-Token verwerfen: nach einem Update kann
# sich die Struktur geaendert haben, und ein neues ist in einer Sekunde da.
rm -f "$BASE/config/plugins/$PFOLDER/gardena_token.json" 2>/dev/null

rm -rf "$BASE/data/plugins/$PFOLDER/upgrade_sicherung" 2>/dev/null
rm -rf "/tmp/uploads/${ARGV1}_upgrade" "/tmp/${ARGV1}_upgrade" 2>/dev/null

echo "<OK> Update abgeschlossen."
exit 0
