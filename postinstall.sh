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
    # Nur melden, was nachgelesen ist (Regeln/06): bis 1.2.7 stand die
    # Erfolgsmeldung unbedingt hinter dem chmod.
    if [ "$(stat -c %a "$CFG" 2>/dev/null)" = "640" ]; then
        echo "<OK> Rechte der Konfiguration gesetzt (0640)."
    else
        echo "<WARNING> Die Rechte der Konfiguration liessen sich nicht auf 0640 setzen ($(stat -c %a "$CFG" 2>/dev/null))."
    fi
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
# Einen Wert aus dem Abschnitt [GARDENA] lesen, wie gardena_cfg_read() es tut:
# '#'- und ';'-Zeilen zaehlen nicht, Leerraum um Name und Wert faellt weg,
# umschliessende Anfuehrungszeichen fallen weg, ein spaeterer Eintrag gilt.
# Wortgleich in preupgrade.sh, postinstall.sh und postupgrade.sh: ein
# /bin/sh-Hakenskript kann keine gemeinsame Datei einbinden (der Installer
# ruft es aus dem Auspackordner heraus), deshalb dreimal DERSELBE Text und
# nicht drei Fassungen. Wer eine anfasst, fasst alle drei an.
# Der zweite Leerraum-Schnitt steht hinter den Anfuehrungszeichen: parse_ini
# liefert fuer CLIENT_ID="  " zwei Leerzeichen, und gardena_ini_feld() gibt
# sie durch trim() weiter als leeren Wert aus (gemessen 17.09.2026 unter
# PHP 7.4.33, 8.3.6 und 8.4.24 - alle drei gleich).
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
    # Zusaetzlich nach INHALT (Regeln/05: Merkwort, nicht Form). Die
    # Pruefsumme erkennt nur die unveraenderte Vorgabe. Laeuft zwischen
    # Installer-Kopie und diesem Skript der Cron-Takt, ist die Vorgabe schon
    # vervollstaendigt; oeffnet jemand die Oberflaeche, traegt sie ein neues
    # Token. Beides gemessen am 17.09.2026 in WSL
    # (Pruefung-GardenaSmartSystem-1.2.9, postupgrade.sh fiel aus): die
    # Zweitschrift war heil, zurueckgespielt wurde sie nicht - 2 von 5
    # Merkinhalten. Als verloren gilt deshalb auch:
    #  - kein Token in der Datei, aber eines in der Zweitschrift;
    #  - keine Zugangsdaten und ein anderes Token als die Zweitschrift, die
    #    Zugangsdaten traegt (dieselbe Regel wie gardena_cfg_write).
    # Wer Zugangsdaten bewusst geloescht hat, behielt sein Token - diese
    # Datei bleibt.
    if [ "$verloren" = "0" ] && [ "$datei" = "gardena.cfg" ]; then
        z_tok=$(ini_feld "$zweit" TOKEN); i_tok=$(ini_feld "$ziel" TOKEN)
        z_zug="$(ini_feld "$zweit" CLIENT_ID)$(ini_feld "$zweit" CLIENT_SECRET)"
        i_zug="$(ini_feld "$ziel" CLIENT_ID)$(ini_feld "$ziel" CLIENT_SECRET)"
        if [ -n "$z_tok" ] && [ -z "$i_tok" ]; then
            verloren=1
        elif [ -n "$z_zug" ] && [ -z "$i_zug" ] && [ "$z_tok" != "$i_tok" ]; then
            verloren=1
        fi
        z_tok=; i_tok=; z_zug=; i_zug=
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
# womit man vergleichen koennte. Entschieden wird nach INHALT (Regeln/05):
# eine Datei, die sich zu einem JSON-Feld lesen laesst, wird nie
# ueberschrieben; eine fehlende, leere oder unlesbare wird ersetzt - aber nur
# aus einer Zweitschrift, die selbst lesbar ist. Der verdraengte Stand bleibt
# als <datei>.kaputt (0600) liegen.
# Bis 1.2.9 entschied "[ ! -s ]", also die Groesse: eine nicht leere, aber
# unlesbare Datei blieb stehen und die heile Zweitschrift daneben ungenutzt;
# eine unlesbare Zweitschrift wurde ueber eine fehlende Datei gelegt (in WSL
# gemessen, Pruefung-GardenaSmartSystem-1.2.10, Faelle N1, N3, N5).
# json_heil() wie in preupgrade.sh: ist php nicht aufrufbar, gilt die Datei
# als heil - dann verhaelt sich das Skript wie bis 1.2.9.
json_heil() {
    [ -s "$1" ] || return 1
    php -r 'exit(is_array(json_decode((string) @file_get_contents($argv[1]), true)) ? 0 : 1);' -- "$1" 2>/dev/null
    rc=$?
    case $rc in
        0) return 0 ;;
        1) return 1 ;;
        *) return 0 ;;
    esac
}
netz_ohne_vorgabe() {
    ziel="$NETZ_CFG/$1"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$1"
    modus="${2:-0600}"
    [ -f "$zweit" ] || return 0
    json_heil "$ziel" && return 0
    if ! json_heil "$zweit"; then
        echo "<WARNING> $1 fehlt oder ist unlesbar, die Zweitschrift $zweit ist aber selbst kein lesbares JSON - nicht zurueckgespielt."
        return 0
    fi
    if [ -s "$ziel" ]; then
        if mv "$ziel" "$ziel.kaputt" 2>/dev/null; then
            chmod 0600 "$ziel.kaputt" 2>/dev/null
            echo "<INFO> $1 war kein lesbares JSON - der alte Stand liegt als $1.kaputt daneben."
        fi
    fi
    if cp -p "$zweit" "$ziel" 2>/dev/null; then
        # Die Rechte kommen je Datei mit. Bis 1.2.5 setzte dieser Weg alles
        # auf 0600 - auch devices_cache.json, die der Code bewusst mit 0640
        # schreibt und die der OEFFENTLICHE Endpunkt liest. Laeuft der Cron
        # als root und Apache als loxberry, antwortete ?action=list danach
        # dauerhaft "Noch keine Daten" und ?action=command fand nie eine
        # Dienstkennung.
        chmod "$modus" "$ziel" 2>/dev/null
        echo "<OK> $1 aus der Zweitschrift wiederhergestellt."
    else
        echo "<WARNING> $1 liess sich nicht zurueckspielen ($zweit)."
    fi
}
netz_ohne_vorgabe "gardena_token.json" 0600
netz_ohne_vorgabe "devices_cache.json" 0640
netz_ohne_vorgabe "gardena_status.json" 0640

# Der Hinweis auf die Ersteinrichtung NACH der Rueckspielung und nur, wenn
# danach wirklich noch keine Zugangsdaten eingetragen sind. Bis 1.2.7 stand er
# unbedingt und VOR der Rueckspielung - auch nach jedem Update einer fertig
# eingerichteten Anlage (Regeln/06: der Schlusstext raet nach einem Update
# nicht zur Erstinstallation).
#
# Geurteilt wird nach INHALT, mit demselben Zerleger wie oben - nicht mit
# einem Suchmuster ueber den Text. Bis 1.2.8 stand hier
# grep -q "^CLIENT_ID=..*": das zaehlt ein einzelnes LEERZEICHEN als Wert,
# findet den Namen nur ohne fuehrenden Leerraum und sieht nicht, in welchem
# Abschnitt er steht. parse_ini_file, mit dem das Plugin liest, wirft
# Leerraum weg - "CLIENT_ID= " ist dort der leere Wert, und die Meldung
# "es ist nichts weiter zu tun" waere falsch gewesen (CLAUDE.md, 6).
# Gebraucht werden BEIDE Werte: ohne Secret kommt keine Anmeldung zustande
# (bin/gardenaMain.php:218, webfrontend/html/index.php:123).
# Gemessen am 17.09.2026 in WSL (messe_runde2.sh Q1c bis Q1f, Q1h).
if [ -n "$(ini_feld "$CFG" CLIENT_ID)" ] && [ -n "$(ini_feld "$CFG" CLIENT_SECRET)" ]; then
    echo "<OK> Zugangsdaten sind eingetragen - es ist nichts weiter zu tun."
else
    echo "<INFO> Naechster Schritt: Plugin-Oberflaeche oeffnen, Application Key und"
    echo "<INFO> Secret von developer.husqvarnagroup.cloud eintragen und speichern."
fi
exit 0
