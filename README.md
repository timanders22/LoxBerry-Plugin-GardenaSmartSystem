# LoxBerry-Plugin: GARDENA smart system

Holt zyklisch (alle 5 Minuten) die Daten aller GARDENA-smart-system-Geräte
(Mähroboter, Bewässerungscomputer/Ventile, Sensoren, Steckdosen, Gateway) und
sendet sie an den Loxone Miniserver – per **UDP** und/oder **MQTT**
(LoxBerry MQTT Gateway, retained). Kommandos (Mähen starten, Parken,
Bewässerung starten/stoppen …) können über einen Virtuellen Ausgang gesendet werden.

## Neu in 1.1.8

**Das Plugin konnte seine eigene Konfiguration nicht lesen.** Die
`gardena.cfg` kommentiert mit `#`. PHPs INI-Zerleger kennt als
Kommentarzeichen seit PHP 7 aber nur noch `;` — er liest die Kommentarzeilen
als Zuweisungen und bricht an der ersten mit einem Sonderzeichen ab.
`parse_ini_file()` gab daraufhin `false` zurück, gemessen gegen die
mitgelieferte `config/gardena.cfg` unter PHP 7.4.33 und 8.4.24, beide gleich.

Die Folge war an zwei Stellen unterschiedlich schwer:

* **Der Dienst startete nicht.** `gardenaMain.php` bricht bei nicht lesbarer
  Konfiguration mit `LOGCRIT` und `exit(1)` ab — genau das trat ein.
* **Die Oberfläche las nur die Vorgaben.** `gardena_cfg_read()` lieferte
  `ENABLED=0`, leere Zugangsdaten und kein Token, egal was eingetragen war.

Betroffen ist jede Installation, deren `gardena.cfg` diese Kommentarzeilen
enthält — also jede **Neuinstallation**. Bei einer aktualisierten Anlage
hängt es davon ab, ob die Zeilen jemals hineingekommen sind:
`gardena_cfg_write()` arbeitet zeilenweise und lässt vorhandene Kommentare
stehen, fügt aber keine hinzu.

Behoben mit einer gemeinsamen Funktion `gardena_ini_lesen()`, die beide
Stellen benutzen — damit sie nicht wieder auseinanderlaufen. Sie entfernt vor
dem Zerlegen nur ganze Zeilen, deren erstes sichtbares Zeichen `#` ist; ein
`#` **innerhalb** eines Wertes bleibt erhalten.

## Version 1.0.0 – was ist neu

- **Neue API**: Husqvarna/GARDENA **smart system API v2** mit OAuth2
  (Application Key + Secret). Die alte sg-1-API mit Benutzername/Passwort wurde
  von Gardena abgeschaltet – deshalb funktionierten ältere Versionen nicht mehr.
- **Neue Admin-Oberfläche** für LoxBerry 3/4 (die alte Perl-Oberfläche nutzte das
  LoxBerry-1.x-Templatesystem und war auf aktuellen Systemen kaputt).
- **MQTT-Unterstützung** über das LoxBerry MQTT Gateway (keine Zusatzsoftware nötig).
- PHP 7.4 und PHP 8.x kompatibel; SVG-Icon (PNG als Fallback).

## Einrichtung

1. Auf https://developer.husqvarnagroup.cloud mit dem GARDENA-Konto anmelden.
2. **Create application** → Name egal, Redirect-URL z. B. `http://localhost`.
3. In der Application unter **Connect an API** die **GARDENA smart system API** verbinden.
4. **Application Key** und **Application Secret** in der Plugin-Oberfläche eintragen,
   Plugin aktivieren, speichern – der Verbindungstest zeigt sofort, ob es klappt.

## Loxone

- **Werte**: Virtueller UDP-Eingang (Standard-Port 5005), Format
  `SERVICE.Gerätename.attribut:wert` – oder via MQTT Gateway
  (Topics `gardena/<Gerät>/<SERVICE>/<attribut>`).
- **Kommandos** (Virtueller Ausgang, Befehl bei EIN), Beispiele:
  - `/plugins/gardenasmartsystem/index.php?action=command&token=TOKEN&device=NAME&type=MOWER_CONTROL&cmd=START_SECONDS_TO_OVERRIDE&seconds=3600`
  - `...&type=MOWER_CONTROL&cmd=PARK_UNTIL_NEXT_TASK`
  - `...&type=VALVE_CONTROL&cmd=START_SECONDS_TO_OVERRIDE&seconds=1800`
- Geräteliste/Diagnose: `/plugins/gardenasmartsystem/index.php?action=list` (ohne Token, rein lesend)

### Zugriffstoken

Alles, was etwas auslöst (`action=command`, `action=refresh`), verlangt ein Token.
Ohne diese Prüfung könnte jedes Gerät im Netz – und über eine unbedacht weitergeleitete
Portfreigabe auch jemand von außen – den Mäher losschicken oder die Bewässerung aufdrehen.

Das Token wird beim ersten Öffnen der Plugin-Oberfläche automatisch erzeugt und dort
angezeigt; die fertigen Loxone-Adressen enthalten es bereits. Ist noch keins hinterlegt,
werden Schaltbefehle abgewiesen (fail closed). Über „Neues Token erzeugen“ lässt es sich
jederzeit wechseln – die Virtuellen Ausgänge in Loxone müssen dann angepasst werden.

## Hinweise

- Husqvarna begrenzt die API-Nutzung (Rate Limit); der 5-Minuten-Zyklus liegt
  weit darunter.
- Logs: LoxBerry Log Manager → Paket `gardenasmartsystem`.

## Herkunft, Lizenz und Änderungen

Dieses Plugin ist eine **abgeleitete Arbeit** des ursprünglichen Plugins von
**Michael Jani** (Repository: https://github.com/DiabloVmax1200/LoxBerry-Plugin-GardenaSmartSystem),
veröffentlicht unter der **Apache-Lizenz 2.0**. Lizenztext (`LICENSE`) und
Copyright-Hinweise bleiben unverändert erhalten; die Plugin-Kennung
(`NAME`/`EMAIL` in `plugin.cfg`) wurde bewusst **nicht** geändert.

Gemäß Apache-2.0 (Abschnitt 4b) hier die Liste der geänderten Bestandteile:

- `webfrontend/html/gardena.class.inc.php` — vollständig neu: GARDENA smart
  system **API v2** (OAuth2, Application Key/Secret) statt der abgeschalteten
  sg-1-API; ab v1.0.1 HTTP wahlweise über cURL oder PHP-Streams
- `webfrontend/htmlauth/index.php`, `webfrontend/html/*` — neue Oberfläche für
  LoxBerry 3/4 anstelle des LoxBerry-1.x-Templatesystems (Perl entfallen)
- `cron/cron.05min` — neuer Abrufzyklus, UDP- und MQTT-Ausgabe
- `dpkg/apt` — `libstring-escape-perl` entfernt (Perl-Teile entfallen),
  `php-curl` nur noch als Empfehlung
- `plugin.cfg` — Versionsstand 1.0.1

Forum-Thread des ursprünglichen Plugins:
https://www.loxforum.com/forum/projektforen/loxberry/plugins/160685-plugin-gardena-smart-system

## Fehlerbehebung: „404 Not Found" bei php7.4-curl während der Installation

Meldung im Installationsprotokoll (Beispiel):

```
Err:2 https://packages.sury.org/php bookworm/main amd64 php7.4-curl ... 404 Not Found
E: Unable to fetch some archives, maybe run apt-get update ...
CRITICAL: Error installing php7.4-curl libstring-escape-perl - Error 100
```

**Ursache:** Das Paket `php-curl` ist ein Sammelpaket, das auf die PHP-Version des
Systems zeigt. Auf LoxBerrys mit dem Fremd-Repository *packages.sury.org* landet man
damit bei `php7.4-curl`. Sury hält von jeder PHP-Version aber immer nur den neuesten
Build vor — ist der lokale Paketindex ein paar Wochen alt, zeigt er auf ein Paket,
das dort nicht mehr liegt: 404. Mit dem Plugin selbst hat das nichts zu tun.

**Lösung am LoxBerry (SSH):**

```
sudo apt-get update
sudo apt-get install -y php-curl
```

**Ab v1.0.1 ist das kein Beinbruch mehr:** Das Plugin nutzt cURL nur noch, wenn die
Erweiterung vorhanden ist, und weicht sonst automatisch auf PHP-Streams aus. Es
läuft also auch dann, wenn die Paketinstallation fehlgeschlagen ist; die Oberfläche
weist dann auf die fehlende Erweiterung hin.

**`libstring-escape-perl`** aus der Meldung wird seit v1.0.0 nicht mehr benötigt —
die Perl-Bestandteile der abgeschalteten sg-1-API sind entfallen. Wer die Meldung
sieht, installiert noch die ältere Fassung (v0.0.7) des Plugins.

## Änderungen in 1.1.0

**Zwei offene Türen geschlossen.**

- `gardenaMain.php` lag unter `webfrontend/html/` und war damit von jedem Gerät
  im Netz **ohne Anmeldung** aufrufbar. Jeder Aufruf löste einen vollständigen
  API-Durchlauf aus — die Husqvarna-API hat ein Abrufkontingent — und schickte
  anschließend den gesamten Datenbestand als UDP-Schwall an den Miniserver.
  Die Datei liegt jetzt in `bin/`, der Cron ruft sie über `REPLACELBPBINDIR` auf.
- `?action=list` lieferte die Geräteliste ohne Token: Klarnamen, Ladezustände,
  Verbindungsgüte und vor allem die Service-Kennungen, die ein Schaltbefehl
  braucht. Alle Endpunkte verlangen jetzt das Token.

**Datenverlust bei Updates.**

- `preinstall.sh`, `preupgrade.sh` und `postupgrade.sh` benutzten den festen
  Pfad `/tmp/uploads/…`. Den gibt es nur beim Hochladen über die Oberfläche;
  beim Auto-Update lief das Sichern ins Leere, und `gardena.cfg` mit Application
  Key, Secret und Zugriffstoken war nach dem Update weg. Gesichert wird jetzt
  nach `data/plugins/<Ordner>/upgrade_sicherung` — auf der Karte, nicht in der
  Ramdisk. Der alte Ort wird beim Update von 1.0.2 noch mitgelesen.
- Das `cp` in `postupgrade.sh` lief ohne Existenzprüfung. Schlug es fehl, legte
  die Zeile darunter per `>>` eine `gardena.cfg` an, in der **nur**
  `LOCALTIME=0` stand — eine Datei ohne Abschnitt `[GARDENA]`, mit der danach
  nichts mehr funktionierte. `LOCALTIME` ist ersatzlos entfallen: der Wert wurde
  im ganzen Plugin nirgends gelesen, er stammt aus der alten sg-1-Fassung.
- Verkettung jetzt als `${ARGV1}_upgrade` statt `$ARGV1\_upgrade`.

**Konfiguration.**

- Die Oberfläche baute die Datei aus ihren acht bekannten Feldern neu zusammen
  und schrieb sie stumpf zurück. Jeder Schlüssel, den sie nicht kannte, war
  danach weg — samt aller erklärenden Kommentare. Jetzt wird
  zusammengeführt: nur die übergebenen Schlüssel werden ersetzt.
- Der neue Schreiber ist abschnittsbewusst, arbeitet unter `flock` und schreibt
  unteilbar (Zwischendatei, dann `rename`). Die Rechte 0640 werden **vor** dem
  Umbenennen gesetzt — in der Datei stehen Secret und Token. Windows-Zeilenenden
  werden dabei entfernt, denn `dos2unix` lief beim Auto-Update nie.

**Weiteres.**

- Sperre gegen gleichzeitige Abrufe. Cron und `?action=refresh` konnten sich
  überholen, die API-Abrufe verdoppeln und dem Miniserver alles doppelt schicken.
- `random_bytes()` kann eine Ausnahme werfen; abgefangen wurde sie nicht, und
  weil `function_exists('random_bytes')` auf jedem PHP 7 wahr ist, war der
  openssl-Weg dahinter unerreichbar. Der letzte Rückfall auf
  `md5(uniqid(mt_rand()))` ist entfallen — das ist kein Zufall für
  Sicherheitszwecke, und dieses Token schützt den einzigen schaltenden Endpunkt.
- MQTT-Themen werden vollständig gesäubert. Bisher wurden nur Leerzeichen
  ersetzt; ein `#` oder `+` im Gerätenamen wäre als MQTT-Platzhalter gelesen
  worden. Der UDP-Port des Gateways wird ersatzweise aus der `general.json`
  gelesen, wenn `mqtt_connectiondetails()` nicht verfügbar ist — in der
  Oberfläche war das immer der Fall.
- Meldungen aus der Oberfläche landen jetzt wirklich im Protokoll. `gardena_log()`
  prüfte korrekt mit `function_exists()` — es gab also **keine** „Call to
  undefined function"-Fehler —, aber weil die Oberfläche `loxberry_log.php` gar
  nicht einband, verschwand jede Meldung wortlos.
- Der `[AUTOUPDATE]`-Block fehlte ganz; `release.cfg` und `prerelease.cfg` gab es
  nicht. Beides ist ergänzt und auf dieses Repository gerichtet. Die
  `RELEASECFG` liest aus dem **Zweig**, das `ARCHIVEURL` zieht aus dem **Tag** —
  bei jedem Release müssen beide mitwandern, sonst bekommen Neuinstallationen
  weiter den alten Stand.

**Oberfläche.** Fünf Reiter nach Hausstandard (Einstellungen, Geräte, Einbindung
in Loxone, Test, Protokoll), als echte Verweise mit serverseitig gesetztem
`sm-active` — die Seite funktioniert damit auch ohne JavaScript. Zweisprachig
Deutsch/Englisch mit Englisch als Rückfallebene (91 Schlüssel je Datei); bis
1.0.2 gab es überhaupt keine Sprachdateien.

### Nicht bestätigt

- **`Content-Length` im Stream-Rückfall.** Der HTTP-Datenstrom von PHP ergänzt
  die Kopfzeile selbst, sobald `content` gesetzt und die Kopfzeile nicht schon
  vorhanden ist (`ext/standard/http_fopen_wrapper.c`). Sie zusätzlich von Hand
  einzutragen wäre im günstigen Fall wirkungslos und im ungünstigen schädlich.
  Ein „411 Length Required" tritt hier nicht auf.
- **Sections in `gardena_cfg_set`.** Solange es nur den einen Abschnitt
  `[GARDENA]` gibt, landete ein angehängter Wert sehr wohl darin. Fragil war es
  trotzdem — bei einem zweiten Abschnitt wäre der neue Wert dort gelandet.
  Deshalb ist die Funktion jetzt abschnittsbewusst, aber nicht wegen eines
  gegenwärtigen Fehlers.

### Zur Frage einer Abspaltung

`NAME`, `FOLDER` und die Angaben unter `[AUTHOR]` sind **bewusst unverändert**
geblieben. LoxBerry erkennt ein Plugin genau an diesen drei Angaben. Wer sie für
eine Veröffentlichung unter neuem Namen ändert, bekommt bei allen bestehenden
Installationen eine zweite, parallel installierte Fassung statt eines Updates —
und beide würden dann alle fünf Minuten dieselben Daten abrufen und an denselben
UDP-Port des Miniservers senden. Eine Abspaltung ist deshalb ein bewusster
Schnitt, kein Nebeneffekt einer Umbenennung.

## Aufgeräumt

Wenig — die Struktur wurde beim Umbau auf 1.1.0 schon geradegezogen (Ordner
`…-master` umbenannt, `gardenaMain.php` und die Bibliotheken nach `bin/`
verlegt). Übrig waren:

- **Zwei Lizenzdateien.** `LICENSE` und `LICENSE.md` trugen denselben
  Apache-2.0-Text; der Unterschied bestand ausschließlich aus Leerzeichen am
  Zeilenanfang und einem fehlenden Zeilenumbruch am Dateiende. Die README
  verweist auf `LICENSE` — `LICENSE.md` ist entfallen, der fehlende
  Zeilenumbruch ergänzt.
- **`icons/README.txt`** — wortwörtlicher Vorlagentext von LoxBerry („Please
  copy your plugin icons here", mit der Aufzählung der benötigten Größen).
  Alle fünf Icons liegen längst daneben.
- **Drei tote Argumentzuweisungen** (`ARGV3`, `ARGV5` in `preinstall.sh`,
  `ARGV1` in `preupgrade.sh`) — zugewiesen und nie gelesen.
- **`.gitignore`** ergänzt. Sie schließt ausdrücklich
  `config/gardena_token.json` und `config/devices_cache.json` aus: beide legt
  das Plugin zur Laufzeit an, und in der ersten steht ein gültiges
  Zugriffstoken der Husqvarna-Wolke.

### Geprüft und in Ordnung

Sprachdateien deckungsgleich (91 Schlüssel je Seite, keiner fehlt, kein
deutscher Text mehr fest in der Oberfläche), keine `__pycache__`-Reste, keine
leeren Ordner, keine doppelten Icons.

### Nachgetragen: `uninstall/uninstall`

Am System gibt es nichts rückgängig zu machen — das Plugin legt weder einen
Systemdienst noch eine sudo-Regel oder einen Benutzer an. Was es hinterlässt,
sind Dateien mit Zugangsdaten: `gardena.cfg` mit Application Key und Secret im
**Klartext** samt Zugriffstoken, `gardena_token.json` mit einem gültigen
OAuth2-Token der Husqvarna-Wolke und `devices_cache.json` mit den Klarnamen und
Service-Kennungen der Geräte.

LoxBerry räumt `config/` und `data/` beim Entfernen normalerweise selbst auf.
Darauf verlässt sich das Skript nicht: der Aufwand ist ein `rm`, der Schaden im
Fehlerfall ein Anmeldegeheimnis, das auf der Karte liegenbleibt. Die Anwendung
im Husqvarna Developer Portal bleibt bestehen — darauf weist die Deinstallation
hin, entfernen muss man sie dort von Hand.

