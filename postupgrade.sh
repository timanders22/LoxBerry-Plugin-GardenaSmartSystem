#!/bin/sh
# Gardena Smart System - postupgrade (laeuft als Benutzer loxberry)

ARGV1=$1
ARGV3=$3
ARGV5=$5
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
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"

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
    # Kein blinder Alarm. BERICHTIGT in 1.2.6: der Kommentar behauptete
    # hier, der Installer loesche data/plugins/<ordner> und damit die
    # Sicherung aus preupgrade.sh - "diese Kette kann hier gar nichts
    # finden". Das ist falsch und widersprach preupgrade.sh, das im selben
    # Paket richtig erklaert, warum die Sicherung ueberlebt: sie liegt als
    # NACHBAR (<ordner>.upgrade_sicherung, mit Punkt), nicht IM Ordner.
    # rm -rf .../<ordner>/ trifft den Nachbarn nicht. Wer den alten
    # Kommentar las, hielt den Hauptrettungsweg fuer tot.
    # Gerettet wird ausserdem aus der Zweitschrift neben dem Konfigordner,
    # und das tut postinstall.sh, das VOR postupgrade laeuft. Also erst
    # nachsehen, wie es wirklich steht; eine Warnung bei heiler
    # Konfiguration erschreckt ohne Grund und entwertet die echte.
    NETZ_PRUEF="${5:-$LBHOMEDIR}/config/plugins/${3:-gardenasmartsystem}/gardena.cfg"
    if [ -s "$NETZ_PRUEF" ]; then
        echo "<OK> Die Einstellungen sind vorhanden (aus der Zweitschrift)."
    else
    echo "<WARNING> Keine gesicherte Konfiguration gefunden."
    echo "<WARNING> Application Key, Secret und Zugriffstoken muessen in der"
    echo "<WARNING> Plugin-Oberflaeche neu eingetragen werden."
    fi
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
# ... UND die Zweitschrift dazu. Bis 1.2.5 wurde nur das Original
# geloescht; die Kopie .backup.gardena_token.json blieb liegen - also genau
# das Geheimnis, das hier bewusst verworfen werden soll, ueberlebte
# unbegrenzt. postinstall.sh haette es beim naechsten Update sogar wieder
# zurueckgespielt.
rm -f "$BASE/config/plugins/$PFOLDER/gardena_token.json" 2>/dev/null
rm -f "${5:-$LBHOMEDIR}/config/plugins/${3:-gardenasmartsystem}.backup.gardena_token.json" 2>/dev/null

rm -rf "$BASE/data/plugins/$PFOLDER.upgrade_sicherung" 2>/dev/null
rm -rf "/tmp/uploads/${ARGV1}_upgrade" "/tmp/${ARGV1}_upgrade" 2>/dev/null

echo "<OK> Update abgeschlossen."
exit 0
