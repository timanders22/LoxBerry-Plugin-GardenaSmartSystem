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

# ---------------------------------------------------------------------------
# ZUERST die Marke "Aktualisierung laeuft" (1.2.11, I1; Entscheidung 1).
# preinstall.sh legt ohne sie liegengebliebene Zweitschriften beiseite, und
# postinstall.sh spielt nur mit ihr ein - ohne Altersgrenze. Sie liegt NEBEN
# dem Datenordner; purge_installation ("rm -rf .../<ordner>/") trifft den
# Nachbarn mit dem Punkt nicht. postinstall.sh raeumt sie per trap ab, die
# Deinstallation ebenfalls.
# ---------------------------------------------------------------------------
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if date +%s > "$MARKE" 2>/dev/null; then
    chmod 0600 "$MARKE" 2>/dev/null
else
    echo "<WARNING> Die Marke $MARKE liess sich nicht anlegen - dieses Update wird dann wie eine"
    echo "<WARNING> Neuinstallation behandelt (Zweitschriften werden beiseitegelegt, nicht eingespielt)."
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

# Erst die NEUE Sicherung bauen, dann die alte ablegen - nicht umgekehrt.
#
# Bis 1.2.8 stand hier "rm -rf $SICHER" VOR dem Sichern, und die
# Erfolgsmeldung stand unbedingt hinter dem cp. Ging das Neusichern schief,
# war beides weg: die alte Sicherung geloescht, die neue leer oder
# unvollstaendig - und das Protokoll meldete trotzdem
# "<OK> Konfiguration gesichert." Gemessen am 17.09.2026 in WSL
# (Pruefung-GardenaSmartSystem-1.2.9, messe_runde2.sh): ein cp mit
# Rueckgabewert 1 (Q4c), ein cp ohne Wirkung (Q4d) und "gar keine
# Konfiguration vorhanden" (Q4e) liessen je eine leere oder fremde Sicherung
# zurueck, wo die alte gestanden hatte.
#
# Reihenfolge jetzt: in $SICHER.neu bauen -> Rueckgabewert UND Inhalt pruefen
# -> die alte nach $SICHER.alt schieben -> die neue an ihren Platz -> die
# alte wegwerfen. In keinem Augenblick gibt es keine Sicherung.
NEU="$SICHER.neu"

# Ein liegengebliebener Bestand stammt IMMER aus einem frueheren Vorgang
# (1.2.11, I2; Entscheidung 1): postupgrade.sh loescht ihn, sobald er
# eingespielt ist. Er wird weggeraeumt, BEVOR neu gesichert wird. Bis 1.2.10
# blieb er liegen, wenn das Neusichern scheiterte, und postupgrade.sh spielte
# den fremden Stand ueber die richtige Konfiguration (in WSL gemessen,
# installer Befund 2, Faelle U5/U6). Er geht nach .alt (0600); die
# Deinstallation raeumt ihn ab.
if [ -e "$SICHER" ] || [ -L "$SICHER" ]; then
    rm -rf "${SICHER:?}.alt" 2>/dev/null
    if mv "$SICHER" "$SICHER.alt" 2>/dev/null; then
        chmod 0700 "$SICHER.alt" 2>/dev/null
        find "$SICHER.alt" -type f -exec chmod 0600 {} + 2>/dev/null
        echo "<INFO> Eine Upgrade-Sicherung aus einem frueheren Vorgang lag noch da - beiseitegelegt: $SICHER.alt"
    elif rm -rf "${SICHER:?}" 2>/dev/null && [ ! -e "$SICHER" ]; then
        echo "<INFO> Eine Upgrade-Sicherung aus einem frueheren Vorgang lag noch da - entfernt."
    else
        echo "<WARNING> Eine Upgrade-Sicherung aus einem frueheren Vorgang liess sich weder beiseitelegen"
        echo "<WARNING> noch entfernen: $SICHER - bitte von Hand entfernen, sonst spielt postupgrade.sh sie ein."
    fi
fi

echo "<INFO> Sichere Konfiguration nach $SICHER"
rm -rf "$NEU" 2>/dev/null
mkdir -p "$NEU/config" 2>/dev/null
chmod 0700 "$NEU" 2>/dev/null

SICHER_OK=0
if [ -d "$BASE/config/plugins/$PFOLDER" ]; then
    if cp -a "$BASE/config/plugins/$PFOLDER/." "$NEU/config/" 2>/dev/null; then
        CP_RC=0
    else
        CP_RC=$?
    fi
    # In der Datei stehen Application Secret und Zugriffstoken - die Kopie
    # bekommt dieselben engen Rechte: 0600 (1.2.11, I5/C8).
    chmod 0600 "$NEU/config/gardena.cfg" "$NEU/config/gardena_token.json" \
               "$NEU/config/gardena_status.json" "$NEU/config/devices_cache.json" 2>/dev/null
    # Die Wirkung pruefen, nicht den Rueckgabewert allein (CLAUDE.md, 2):
    # jede Datei der Konfiguration byteweise in der neuen Sicherung.
    ABWEICHEND=$( { cd "$BASE/config/plugins/$PFOLDER" && find . -type f | while IFS= read -r f; do
                      cmp -s "$f" "$NEU/config/$f" || printf '%s ' "${f#./}"
                  done; } 2>/dev/null || echo "(Konfiguration nicht lesbar)" )
    if [ "$CP_RC" -eq 0 ] && [ -z "$ABWEICHEND" ]; then
        SICHER_OK=1
    else
        echo "<WARNING> Die Konfiguration liess sich NICHT vollstaendig sichern"
        echo "<WARNING> (cp Rueckgabewert $CP_RC; nicht in der Sicherung: ${ABWEICHEND:-keine})."
    fi
else
    echo "<INFO> Keine Konfiguration vorhanden."
fi

if [ "$SICHER_OK" = "1" ]; then
    # Der Zeitpunkt dieses Vorgangs steht zur Auskunft in der Sicherung. Seit
    # 1.2.11 gilt er nicht mehr als Altersgrenze (I3, Entscheidung 1): ob eine
    # Sicherung zu diesem Update gehoert, entscheidet allein, dass ein
    # frueherer Bestand oben weggeraeumt wurde.
    JETZT=$(date +%s 2>/dev/null)
    case "$JETZT" in
        ''|*[!0-9]*) ;;
        *) echo "$JETZT" > "$NEU/zeitpunkt" ;;
    esac
    if mv "$NEU" "$SICHER" 2>/dev/null; then
        echo "<OK> Konfiguration gesichert."
    else
        rm -rf "$NEU" 2>/dev/null
        echo "<WARNING> Die neue Sicherung liess sich nicht an ihren Platz bringen (Platz und Rechte in"
        echo "<WARNING> $BASE/data/plugins pruefen). Aus einer Upgrade-Sicherung wird bei diesem Update nichts"
        echo "<WARNING> eingespielt; den Rueckweg bildet die Zweitschrift neben dem Konfigordner."
    fi
else
    rm -rf "$NEU" 2>/dev/null
    if [ -d "$BASE/config/plugins/$PFOLDER" ]; then
        echo "<WARNING> Aus einer Upgrade-Sicherung wird bei diesem Update nichts eingespielt - auch keine"
        echo "<WARNING> aeltere (I2). Den Rueckweg bildet die Zweitschrift neben dem Konfigordner."
    fi
fi

# Die Protokolle werden NICHT gesichert. purge_installation loescht log/
# nicht (sbin/plugininstall.pl, Regeln/06), es gibt also nichts zu retten.
# Bis 1.2.8 kopierte postupgrade.sh die Sicherung von hier zurueck und
# ueberschrieb damit jede Zeile, die zwischen preupgrade und postupgrade
# geschrieben wurde - gemessen am 17.09.2026 in WSL
# (Pruefung-Upgradeluecke-2026-09-17, N1): die Zeilen des Cron-Laufs aus
# der Luecke waren nach dem Update weg.


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
# Nach INHALT entscheiden, nicht nach Form (Regeln/05: "Merkwort vorhanden?",
# nicht "ist die Datei leer?"). Bis 1.2.8 stand hier "[ -s ]": eine
# gardena.cfg, die nur die mitgelieferten Vorgaben traegt, ist nicht leer -
# der Cron-Takt in der Upgrade-Luecke vervollstaendigt sie, und die
# Zweitschrift mit Key, Secret und Token wurde damit ueberschrieben.
# Dieselbe Wache wie gardena_cfg_write() in bin/functions.inc.php: die
# Zweitschrift entsteht nur aus einem Stand, der ein Token ODER Zugangsdaten
# traegt, und nie ersetzt ein Stand OHNE Zugangsdaten mit einem ANDEREN Token
# eine Zweitschrift MIT Zugangsdaten. Wer die Zugangsdaten bewusst loescht,
# behaelt sein Token - dann zieht die Zweitschrift mit.
# Gemessen am 17.09.2026 in WSL (messe_runde2.sh Q2a, Q2b, Q2c, Q2g).
NETZ_ZWEIT="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.gardena.cfg"
N_TOK=$(ini_feld "$NETZ_CFG/gardena.cfg" TOKEN)
N_CID=$(ini_feld "$NETZ_CFG/gardena.cfg" CLIENT_ID)
N_SEC=$(ini_feld "$NETZ_CFG/gardena.cfg" CLIENT_SECRET)
ZWEIT_SCHREIBEN=1
ZWEIT_GRUND=
if [ ! -f "$NETZ_CFG/gardena.cfg" ]; then
    ZWEIT_SCHREIBEN=0
elif ! cfg_lesbar "$NETZ_CFG/gardena.cfg"; then
    # Eine Datei, die das Plugin nicht lesen kann, ersetzt nie die Zweitschrift
    # (1.2.11, I4). Bis 1.2.10 fand ini_feld() in einer abgeschnittenen Datei
    # noch CLIENT_ID, und die heile Zweitschrift wurde ueberschrieben -
    # danach war das Aktionstoken nirgends mehr (installer Befund 4, Fall U3).
    ZWEIT_SCHREIBEN=0
    ZWEIT_GRUND=unlesbar
elif [ -z "$N_TOK" ] && [ -z "$N_CID" ] && [ -z "$N_SEC" ]; then
    ZWEIT_SCHREIBEN=0
    ZWEIT_GRUND=nichts_wertvolles
elif [ -f "$NETZ_ZWEIT" ]; then
    A_TOK=$(ini_feld "$NETZ_ZWEIT" TOKEN)
    A_CID=$(ini_feld "$NETZ_ZWEIT" CLIENT_ID)
    A_SEC=$(ini_feld "$NETZ_ZWEIT" CLIENT_SECRET)
    if { [ -n "$A_CID" ] || [ -n "$A_SEC" ]; } && [ -z "$N_CID" ] && [ -z "$N_SEC" ] \
       && [ "$A_TOK" != "$N_TOK" ]; then
        ZWEIT_SCHREIBEN=0
        ZWEIT_GRUND=fremdes_token
    fi
    A_TOK=; A_CID=; A_SEC=
fi

# Rueckgabe pruefen und nur melden, was wirklich geschah. Bis 1.2.5 stand
# die Erfolgsmeldung UNBEDINGT hinter dem cp - sie erschien auch dann, wenn
# das if daneben gar nicht zugetroffen hatte oder das cp an Platz oder
# Rechten scheiterte. Der Verlust faellt dann erst auf, wenn Key, Secret und
# Token weg sind.
if [ "$ZWEIT_SCHREIBEN" = "1" ]; then
    if cp -p "$NETZ_CFG/gardena.cfg" "$NETZ_ZWEIT" 2>/dev/null; then
        # 0600 wie das Original (1.2.11, I5/C8; Regeln/05: dieselben Rechte an
        # der Zweitschrift). Die 0640 bis 1.2.10 hatte keine gemessene
        # Begruendung - der Webserver laeuft als loxberry (mod_php).
        chmod 0600 "$NETZ_ZWEIT" 2>/dev/null
        echo "<INFO> Zweitschrift der Einstellungen angelegt."
    else
        echo "<WARNING> Die Zweitschrift der Einstellungen liess sich NICHT anlegen."
        echo "<WARNING> Platz und Rechte in $NETZ_BASE/config/plugins pruefen."
    fi
elif [ "$ZWEIT_GRUND" = "unlesbar" ]; then
    echo "<WARNING> Die gardena.cfg laesst sich nicht lesen (Zerleger des Plugins) - die Zweitschrift"
    echo "<WARNING> der Einstellungen bleibt unveraendert und ist nach dem Update der Rueckweg."
elif [ "$ZWEIT_GRUND" = "fremdes_token" ]; then
    echo "<INFO> Die Zweitschrift der Einstellungen bleibt unveraendert: sie traegt"
    echo "<INFO> Zugangsdaten und ein anderes Zugriffstoken, die jetzige Datei keine."
elif [ "$ZWEIT_GRUND" = "nichts_wertvolles" ]; then
    if [ -f "$NETZ_ZWEIT" ]; then
        echo "<INFO> Die Zweitschrift der Einstellungen bleibt unveraendert: die jetzige"
        echo "<INFO> Datei traegt weder Zugangsdaten noch Zugriffstoken."
    else
        echo "<INFO> Die Einstellungen tragen weder Zugangsdaten noch Zugriffstoken -"
        echo "<INFO> es gibt nichts, wofuer sich eine Zweitschrift lohnte."
    fi
fi
N_TOK=; N_CID=; N_SEC=


# NICHT MITGELIEFERTE Dateien - und gerade deshalb die wichtigen.
# Das Archiv liefert sie nie, also standen sie bis jetzt auf keiner Liste;
# geloescht werden sie vom Installer trotzdem, samt Token und Zugangsdaten.
#
# Auch hier nach INHALT, nicht nach Form: bis 1.2.8 stand vor jedem cp ein
# "[ -s ]". Eine halb geschriebene Datei (Stromausfall, volles Dateisystem)
# ist nicht leer und haette die heile Zweitschrift ersetzt - dieselbe Klasse
# wie bei der gardena.cfg (Regeln/05, "Selbstheilung entscheidet nach
# Inhalt"). Fuer JSON heisst "Inhalt": es laesst sich zu einem Feld lesen.
#
# Gibt es noch GAR KEINE Zweitschrift, wird auch eine beschaedigte Datei
# kopiert - etwas ist besser als nichts, und es geht nichts verloren.
# Ist php nicht aufrufbar, gilt die Datei als heil: dann verhaelt sich das
# Skript wie bis 1.2.8, statt eine Sicherung stillschweigend zu verweigern.
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
netz_json_zweitschrift() {   # $1 Dateiname, $2 Rechte
    quelle="$NETZ_CFG/$1"
    ziel="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$1"
    [ -s "$quelle" ] || return 0
    if [ -f "$ziel" ] && ! json_heil "$quelle"; then
        echo "<WARNING> $1 ist kein lesbares JSON - die vorhandene Zweitschrift"
        echo "<WARNING> bleibt unveraendert."
        return 0
    fi
    if cp -p "$quelle" "$ziel" 2>/dev/null; then
        chmod "$2" "$ziel" 2>/dev/null
    else
        echo "<WARNING> Die Zweitschrift von $1 liess sich NICHT anlegen."
    fi
}
# Alle Zweitschriften 0600 (1.2.11, I5/C8).
netz_json_zweitschrift gardena_token.json  0600
netz_json_zweitschrift devices_cache.json  0600
# gardena_status.json gehoert MIT in die Zweitschrift.
# Bis 1.2.5 fehlte sie: der Sammelweg (cp -a nach $SICHER) deckt sie ab, die
# Zweitschrift kannte nur drei Dateien. Faellt der Sammelweg aus - genau der
# Fall, fuer den es die Zweitschrift gibt -, gehen 'letzter_erfolg' und
# 'sperre_bis' (die HTTP-429-Wartezeit) verloren; das Lebenszeichen meldet
# danach zeitstempel=0, in Loxone also "noch nie erfolgreich".
netz_json_zweitschrift gardena_status.json 0600

exit 0
