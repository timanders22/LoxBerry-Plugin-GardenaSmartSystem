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

# Ist eine gardena.cfg fuer das PLUGIN lesbar? (1.2.11, I4)
# Dieselbe Pruefung wie gardena_cfg_zustand() in bin/functions.inc.php:
# '#'-Zeilen entfernen ([[:blank:]] ist Leerzeichen oder Tabulator, wie dort),
# parse_ini_string(..., true, INI_SCANNER_RAW), und im
# Abschnitt [GARDENA] (oder ohne Abschnitt) mindestens ein einfacher Wert.
# Rueckgabe 0 lesbar, 1 unlesbar oder leer. Ist php nicht aufrufbar, gilt die
# Datei als lesbar - dann verhaelt sich das Skript wie bis 1.2.10.
# Wortgleich in preupgrade.sh, postinstall.sh und postupgrade.sh.
cfg_lesbar() {
    [ -s "$1" ] || return 1
    php -r '$r = @parse_ini_string(preg_replace("/^[[:blank:]]*#.*$/m", "", (string) @file_get_contents($argv[1])), true, INI_SCANNER_RAW); if (!is_array($r)) { exit(1); } $g = (isset($r["GARDENA"]) && is_array($r["GARDENA"])) ? $r["GARDENA"] : $r; foreach ($g as $v) { if (!is_array($v)) { exit(0); } } exit(1);' -- "$1" 2>/dev/null
    rc=$?
    case $rc in
        0) return 0 ;;
        1) return 1 ;;
        *) return 0 ;;
    esac
}

# Einen Wert aus dem Abschnitt [GARDENA] lesen. BERICHTIGT in 1.2.11 (K2): hier
# stand "wie gardena_cfg_read() es tut" - das awk nimmt aber Dateien an, die
# PHP ablehnt (etwa "[GARDENA" ohne "]"; in WSL gemessen, Durchgang 29.09.2026,
# installer Befund 4). Ob eine Datei fuer das Plugin LESBAR ist, sagt deshalb
# cfg_lesbar(); ini_feld() fragt nur, welcher Wert dasteht. Gelesen wird so:
# '#'- und ';'-Zeilen zaehlen nicht, Leerraum um Name und Wert faellt weg,
# umschliessende Anfuehrungszeichen fallen weg, ein spaeterer Eintrag gilt.
# Wortgleich in preupgrade.sh, postinstall.sh und postupgrade.sh: ein
# /bin/sh-Hakenskript kann keine gemeinsame Datei einbinden (der Installer
# ruft es aus dem Auspackordner heraus), deshalb dreimal DERSELBE Text und
# nicht drei Fassungen. Wer eine anfasst, fasst alle drei an.
# Ausgabe nur in eine Variable, nie ins Protokoll - in der Datei stehen
# Application Secret und Zugriffstoken.
ini_feld() {
    [ -f "$1" ] || return 0
    awk -v k="$2" '
        { sub(/\r$/, "") }
        /^[ \t]*\[/ { s = $0; gsub(/^[ \t]*\[|\][ \t]*$/, "", s); next }
        s != "GARDENA" { next }
        /^[ \t]*[#;]/ { next }
        {
            i = index($0, "="); if (i == 0) next
            n = substr($0, 1, i - 1); gsub(/^[ \t]+|[ \t]+$/, "", n)
            if (n != k) next
            v = substr($0, i + 1); gsub(/^[ \t]+|[ \t]+$/, "", v)
            if (v ~ /^".*"$/) v = substr(v, 2, length(v) - 2)
            gsub(/^[ \t]+|[ \t]+$/, "", v)
            w = v; da = 1
        }
        END { if (da) print w }' "$1" 2>/dev/null
}

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
#
# Geloescht wird die Sicherung am Ende NUR, wenn die Rueckholung
# nachweislich gelungen ist: cp mit Rueckgabewert 0 UND jede Datei der
# Sicherung byteweise gleich am Ziel. Bis 1.2.8 stand die Erfolgsmeldung
# unbedingt hinter dem cp, und die Sicherung wurde ohne Blick darauf
# geloescht - gemessen am 17.09.2026 in WSL (Pruefung-GardenaSmartSystem-1.2.9,
# gardena.cfg am Ziel nicht ersetzbar): "<OK> Konfiguration zurueckgestellt.",
# Sicherung weg, Zugangsdaten nirgends mehr.
RUECK_OK=1
# Welche Sicherung eingespielt wird (BERICHTIGT in 1.2.11, K3 und I3):
# hier stand "Nur eine Sicherung aus DIESEM Update wird eingespielt", geprueft
# ueber ein Hoechstalter von 3600 s. Beides stimmte nicht: eine Sicherung aus
# einem frueheren Vorgang wurde eingespielt, sobald das Neusichern scheiterte
# (installer Befund 2, U5/U6), und die Sicherung DIESES Updates wurde
# abgelehnt, wenn zwischen preupgrade und postupgrade mehr als eine Stunde lag
# (Befund 3, U4). Seit 1.2.11 raeumt preupgrade.sh jeden frueheren Bestand weg,
# BEVOR es neu sichert (I2); was hier liegt, stammt deshalb aus diesem
# Vorgang - ohne Altersgrenze (Entscheidung 1).
if [ -d "$SICHER/config" ] && [ -n "$(ls -A "$SICHER/config" 2>/dev/null)" ]; then
    ZIEL="$BASE/config/plugins/$PFOLDER"
    # Nie eine unlesbare gardena.cfg ueber eine lesbare legen (1.2.11, I4).
    # Bis 1.2.10 spielte dieser Schritt eine abgeschnittene Datei zurueck und
    # meldete "<OK> Konfiguration zurueckgestellt." (installer Befund 4, U3).
    GA_OHNE_CFG=0
    if [ -f "$SICHER/config/gardena.cfg" ] && ! cfg_lesbar "$SICHER/config/gardena.cfg" \
       && cfg_lesbar "$ZIEL/gardena.cfg"; then
        if mv "$SICHER/config/gardena.cfg" "$SICHER/gardena.cfg.unlesbar" 2>/dev/null; then
            GA_OHNE_CFG=1
            echo "<WARNING> Die gesicherte gardena.cfg laesst sich nicht lesen - die jetzige, lesbare bleibt stehen."
        fi
    fi
    if cp -a "$SICHER/config/." "$ZIEL/" 2>/dev/null; then CP_RC=0; else CP_RC=$?; fi
    # 0600 fuer alles, was Zugangsdaten oder Zustand traegt (1.2.11, I5/C8).
    chmod 0600 "$ZIEL/gardena.cfg" "$ZIEL/gardena_token.json" "$ZIEL/gardena_status.json" \
               "$ZIEL/devices_cache.json" 2>/dev/null
    ABWEICHEND=$( { cd "$SICHER/config" && find . -type f | while IFS= read -r f; do
                      cmp -s "$f" "$ZIEL/$f" || printf '%s ' "${f#./}"
                  done; } 2>/dev/null || echo "(Sicherung nicht lesbar)")
    if [ "$CP_RC" -eq 0 ] && [ -z "$ABWEICHEND" ]; then
        if [ "$GA_OHNE_CFG" = "1" ]; then
            echo "<OK> Konfiguration zurueckgestellt - ohne die unlesbare gardena.cfg; die lesbare bleibt stehen."
        else
            echo "<OK> Konfiguration zurueckgestellt."
        fi
    else
        RUECK_OK=0
        echo "<WARNING> Die Konfiguration liess sich NICHT vollstaendig zurueckstellen"
        echo "<WARNING> (cp Rueckgabewert $CP_RC; nicht am Ziel: ${ABWEICHEND:-keine})."
        echo "<WARNING> Die Sicherung bleibt liegen: $SICHER"
        echo "<WARNING> Von dort von Hand nach $ZIEL kopieren."
    fi
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
    #
    # Geurteilt wird nach INHALT, nicht nach Form: stehen BEIDE Zugangsdaten
    # da? Ohne eines von beiden kommt keine Anmeldung zustande
    # (bin/gardenaMain.php, webfrontend/html/index.php), und genau die
    # verlangt der Text darunter nachzutragen.
    # Gemessen am 17.09.2026 in WSL (messe_runde2.sh Q3c, Q3d, Q3e).
    NETZ_PRUEF="$BASE/config/plugins/$PFOLDER/gardena.cfg"
    if [ -n "$(ini_feld "$NETZ_PRUEF" CLIENT_ID)" ] \
       && [ -n "$(ini_feld "$NETZ_PRUEF" CLIENT_SECRET)" ]; then
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

# Die Protokolle werden nicht mehr zurueckkopiert (preupgrade.sh sichert sie
# nicht mehr, Begruendung dort): purge_installation laesst log/ stehen, und
# die Rueckkopie ueberschrieb die Zeilen, die waehrend des Updates
# geschrieben wurden.

# Das zwischengespeicherte OAuth2-Token verwerfen: nach einem Update kann
# sich die Struktur geaendert haben, und ein neues ist in einer Sekunde da.
# ... UND die Zweitschrift dazu. Bis 1.2.5 wurde nur das Original
# geloescht; die Kopie .backup.gardena_token.json blieb liegen - also genau
# das Geheimnis, das hier bewusst verworfen werden soll, ueberlebte
# unbegrenzt. postinstall.sh haette es beim naechsten Update sogar wieder
# zurueckgespielt.
rm -f "$BASE/config/plugins/$PFOLDER/gardena_token.json" 2>/dev/null
rm -f "${5:-$LBHOMEDIR}/config/plugins/${3:-gardenasmartsystem}.backup.gardena_token.json" 2>/dev/null

if [ "$RUECK_OK" = "1" ]; then
    rm -rf "$BASE/data/plugins/$PFOLDER.upgrade_sicherung" 2>/dev/null
    rm -rf "/tmp/uploads/${ARGV1}_upgrade" "/tmp/${ARGV1}_upgrade" 2>/dev/null
    echo "<OK> Update abgeschlossen."
else
    echo "<WARNING> Update abgeschlossen, die Konfiguration aber nicht vollstaendig zurueckgestellt (siehe oben)."
fi
exit 0
