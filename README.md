# LoxBerry-Plugin: GARDENA smart system

Holt zyklisch (alle 5 Minuten) die Daten aller GARDENA-smart-system-Geräte
(Mähroboter, Bewässerungscomputer/Ventile, Sensoren, Steckdosen, Gateway) und
sendet sie an den Loxone Miniserver – per **UDP** und/oder **MQTT**
(LoxBerry MQTT Gateway, retained). Kommandos (Mähen starten, Parken,
Bewässerung starten/stoppen …) können über einen Virtuellen Ausgang gesendet werden.

## Neu in 1.2.0

Vier Befunde behoben, alle vier still — sie meldeten sich nicht, sondern
sahen im Betrieb aus wie „geht halt nicht".

**Die Loxone-Vorlage passte nicht zu den Themen, die das Plugin sendet.**
Der Titel der virtuellen Eingänge entstand aus dem **rohen** Gerätenamen,
gesendet wurde aber ein Thema, in dem jedes Zeichen außerhalb
`A-Za-z0-9_/-` durch `_` ersetzt ist. Gemessen unter PHP 7.4.33 und 8.4.24:
nur ein Name aus reinen ASCII-Buchstaben, Ziffern und Bindestrich traf. Bei
jedem anderen — „Mähroboter Vorgarten" ist der Normalfall — legte die
Vorlage Eingänge an, die **nie einen Wert bekamen** und auf `DefVal="0"`
stehenblieben; in Loxone sieht das aus wie „Akku 0 %". Vorlage und Sender
bauen jetzt über dieselben Funktionen `gardena_wert_thema()` und
`gardena_wert_eingang()`.

Die Umschreibung selbst wurde **bewusst nicht** angefasst: ein Umlaut ergibt
weiterhin zwei Unterstriche. Jede Änderung daran benennt auf einer
bestehenden Anlage sämtliche Themen um — die vorhandenen virtuellen Eingänge
und die retained-Werte im Broker hängen daran. Das wäre ein bewusster
Schnitt mit Umstiegshinweis, keine Fehlerbehebung.

Neu im Reiter *Geräte*: eine Spalte **MQTT-Thema**, damit sichtbar ist, unter
welchem Namen ein Gerät beim Miniserver ankommt.

**Nach einem Update war MQTT aus, während die Oberfläche „Aktiv
(empfohlen)" anzeigte.** Die Vorgabewerte standen zweimal da und
widersprachen sich: die Oberfläche ergänzte `MQTT_ENABLED` mit `1`, der
Dienst las die Datei ohne Vorgaben und wertete einen fehlenden Schlüssel als
*aus* (bei `UDP_ENABLED` war es umgekehrt gebaut). Betroffen war jede
Anlage, deren `gardena.cfg` aus einer Fassung ohne diesen Schlüssel stammt.
Die Vorgaben stehen jetzt in `gardena_vorgaben()`, und der Dienst liest über
dieselbe Funktion wie die Oberfläche.

**Fehlte `php-sockets`, starb der Abruf beim ersten Wert.** In `sendUDP()`
stand nur ein `@` vor `socket_create()`; eine „Call to undefined function"
lässt sich damit nicht unterdrücken. Der Geräte-Zwischenspeicher wurde nie
geschrieben, der Reiter *Geräte* blieb dauerhaft leer, die Vorlagenknöpfe
ausgeblendet, `?action=command` fand nie eine Dienstkennung, und das
Protokoll brach ohne `LOGEND` mitten ab. Der Fall ist vorgesehen —
`dpkg/apt` installiert die Erweiterung nicht, `postinstall.sh` warnt vor
ihrem Fehlen — und wird jetzt getragen: Der Dienst stellt es vor dem ersten
Wert fest, sagt es und bricht geordnet ab. Betroffen sind **beide** Wege,
denn das MQTT-Gateway wird ebenfalls über UDP beschickt.

**„240 Werte versendet" stand auch dann im Protokoll, wenn kein einziger
ankam.** Die Rückgaben von `sendUDP()` und `mqttPublish()` wurden verworfen
und der Zähler unbedingt hochgezählt. War das MQTT-Gateway nicht
eingerichtet, stieg `mqttPublish()` bei jedem Wert sofort aus — und der Lauf
meldete Erfolg. Gezählt werden jetzt Zustellungen; die Schlussmeldung nennt
Zugestelltes und Gescheitertes getrennt.

### Neu: Ausfallerkennung

Bis 1.1.9 veröffentlichte das Plugin ausschließlich Gerätewerte. Scheiterte
die Anmeldung oder antwortete die Wolke nicht, endete der Lauf und schickte
**gar nichts**; die virtuellen Eingänge behielten ihren letzten Wert, und in
der App sah alles normal aus. Jetzt geht am Ende **jedes** Laufs ein
Lebenszeichen hinaus, auch nach einem Abbruch:

| UDP | MQTT | Bedeutung |
|---|---|---|
| `STATUS.Plugin.ok` | `<Basis>/Plugin/STATUS/ok` | 1 = Lauf vollständig und alle Werte zugestellt |
| `STATUS.Plugin.zeitstempel` | `…/zeitstempel` | Loxone-Zeit des letzten **erfolgreichen** Laufs, 0 = noch nie |
| `STATUS.Plugin.werte` | `…/werte` | Zahl der zugestellten Werte des letzten Laufs |
| `STATUS.Plugin.fehler` | `…/fehler` | Klartext der letzten Fehlermeldung, `-` = kein Fehler |

In Loxone genügen ein Status-Baustein auf `ok` und ein Vergleich auf das
Alter des Zeitstempels. Die Schwelle deutlich über den Abholtakt legen, damit
ein einzelner verpasster Durchlauf keine Meldung auslöst.

### Neu: Selbstprüfung und `?selftest=1`

Der Reiter *Test* beantwortet jetzt ohne Loxone, ob die Einrichtung trägt —
dreizehn Zeilen mit Häkchen, Hinweis oder Kreuz: Zugangsdaten, Token,
Erweiterungen, eingeschaltete Wege, Zustand des MQTT-Gateways, Alter des
letzten erfolgreichen Abrufs, Zustellungen des letzten Laufs, erkannte
Geräte, umgeschriebene Gerätenamen, und ob die erzeugten Loxone-Vorlagen
wohlgeformt sind. Die Prüfung **liest nur**: kein Abruf bei Husqvarna, kein
Versand, keine Änderung.

Dazu beantwortet der Endpunkt `?selftest=1&token=…`, **ohne etwas
auszulösen**. Ohne ihn ließ sich nicht feststellen, ob das in Loxone
eingetragene Token noch stimmt, ohne wirklich zu schalten — also den Mäher
loszuschicken oder ein Ventil aufzudrehen.

### Vier weitere Befunde

**Ein fehlender Wert sah in Loxone aus wie 0.** Liefert die Wolke ein
Attribut mit `null`, ging es als leere Zeichenkette hinaus:

```
MOWER.Rasen.batteryLevel:
```

Die Zeile endet auf den Doppelpunkt, und ein virtueller Eingang mit
Befehlserkennung liest daraus 0 — ein fehlender Ladestand war von einem
gemessenen von 0 % nicht zu unterscheiden. Über MQTT ging zusätzlich eine
leere retained-Nutzlast hinaus. Ein Wert, den es nicht gibt, wird jetzt
**nicht gesendet**; der Eingang behält seinen letzten Wert, und dass er alt
ist, beantwortet das Lebenszeichen. Gezählt werden solche Attribute
trotzdem — die Zahl steht in der Selbstprüfung, damit ein dauerhaft leeres
Attribut auffällt.

`0` und die leere Zeichenkette sind **keine** fehlenden Werte: die hat die
Wolke so geschickt. Eine leere Zeichenkette erzeugt deshalb weiterhin eine
Zeile, die auf den Doppelpunkt endet. Das ist Absicht — würde sie
unterdrückt, bliebe ein einmal gemeldeter Fehlertext in Loxone für immer
stehen.

**Der Reiter *Logdateien* konnte strukturell nichts anzeigen.** Er las
ausschließlich `gardena_ui.log` — und die wurde nie beschrieben: die
Oberfläche bindet `loxberry_log.php` ein, also nimmt `gardena_log()` immer
den Weg über das SDK und erreicht seinen Ersatzschreiber nie; ein
Protokollobjekt hat die Oberfläche aber nie angelegt. Der Reiter meldete
dauerhaft „Noch keine Einträge vorhanden", während die Hilfe versprach, dort
stehe die Antwort der Wolke im Wortlaut. Die Oberfläche legt jetzt ihr
eigenes Protokollobjekt an, und der Reiter zeigt über
`LBWeb::loglist_html()` die Protokolle des Plugins — die des Abrufdienstes
und die der Oberfläche.

**`?…&device[]=x` beendete den Endpunkt unter PHP 8 mit HTTP 500 und leerem
Rumpf.** `trim()` auf ein Feld ist dort ein `TypeError`; der Miniserver bekam
statt einer Fehlermeldung gar nichts. Unter 7.4 lief dieselbe Anfrage mit
einer Warnung weiter. Die Parameter werden jetzt einmal zentral eingesammelt
und abgewiesen, wenn sie kein Skalar sind oder 200 Zeichen überschreiten.
Ein enges Zeichenmuster für `device` wäre falsch — Gerätenamen vergibt der
Anwender in der Gardena-App frei, mit Leerzeichen und Umlauten. Bei dieser
Gelegenheit ist `ctype_digit()` durch `preg_match()` ersetzt: `ctype` ist
nicht in jeder PHP-Zusammenstellung geladen und steht nicht in `dpkg/apt`.

**Die Formulare trugen kein Merkmal gegen fremde Absender.** Der angemeldete
Bereich ist durch die Anmeldung des LoxBerry geschützt — gegen eine fremde
Seite schützt das nicht, der Browser schickt die hinterlegten Zugangsdaten
mit. Ein untergeschobenes Formular hätte „Neues Token erzeugen" auslösen
können; danach beantwortet der Endpunkt jeden Virtuellen Ausgang in Loxone
mit 403, und ein Virtueller Ausgang wertet die Antwort nicht aus — der
Ausfall wäre still. Jedes Formular trägt jetzt ein aus dem Zugriffstoken
abgeleitetes Merkmal, geprüft **einmal zentral**, bevor irgendein Handler
läuft. Muster übernommen aus dem Abfahrts-Assistenten 1.6.1.

### Drei Aufräumarbeiten

**Der Ordnername des Plugins kam aus zwei Quellen.** Die angezeigten
Adressen benutzten `$lbpplugindir`, die Vorlage der Steuerbefehle dagegen
`getenv('LBPPLUGINDIR') ?: 'gardena'` — und dieser Rückfallwert konnte nie
stimmen, denn `plugin.cfg` legt `FOLDER=gardenasmartsystem` fest. Hätte er
gegriffen, wären alle Befehle der heruntergeladenen Vorlage auf
`/plugins/gardena/…` gelaufen und mit 404 geendet, ohne dass es jemandem
aufgefallen wäre: ein Virtueller Ausgang wertet die Antwort nicht aus. Jetzt
gibt es genau eine Quelle, und wo sie fehlt, wird der Name aus dem
Konfigurationspfad abgeleitet statt geraten — LoxBerry hängt bei einer
Namenskollision eine Nummer an.

**Die Reiter-Bereiche heißen jetzt `sm-seite`, nicht `sm-pane`.** Rein
äußerlich ändert das nichts; die Prüfkette des Hauses sucht am gerenderten
HTML aber wörtlich nach `sm-seite sm-active" id="tab-` und lief bis dahin ins
Leere. Gegengeprüft: das Muster findet in 1.2.0 den aktiven Reiter, in 1.1.9
nichts.

**Leere Textwerte sind jetzt erklärt.** Im Reiter *Einbindung in Loxone*
steht, dass ein Attribut *ohne* Wert gar nicht gesendet wird, eine *leere
Zeichenkette* dagegen schon — und dass ein Virtueller Eingang mit
Befehlserkennung daraus 0 liest. Wer ein Attribut auswertet, das leer werden
kann, nimmt dafür einen Texteingang oder MQTT.

### Neue Funktionen

**Abrufabstand einstellbar.** Der Cron läuft weiter alle fünf Minuten; ein
größerer Abstand wird eingehalten, indem Durchläufe übersprungen werden. Ein
Durchlauf verbraucht zwei Abrufe des Husqvarna-Kontingents.

**HTTP 429 wird abgewartet statt ignoriert.** Antwortet die Wolke mit 429,
hält das Plugin die Rücknahme fest und ruft bis dahin nicht mehr ab —
`Retry-After` wird gelesen, wenn die Gegenstelle sie mitschickt, sonst wird
eine Stunde gewartet. Bis 1.1.9 wurde 429 wie jeder andere Fehler behandelt
und fünf Minuten später wieder angeklopft, was die Sperre verlängert.

**Die Standortliste wird einen Tag lang wiederverwendet.** Sie ändert sich
praktisch nie und kostete bisher die Hälfte aller Abrufe.

**Es wird nur noch bei Änderung gesendet**, sonst höchstens alle 30 Minuten
als Lebenszeichen. Eine Signatur über den gesamten Bestand entscheidet.
Bisher ging bei jedem Lauf jeder Wert hinaus, mit 100 ms Pause je UDP-Wert.

**Weggefallene MQTT-Themen werden geleert.** Wird ein Gerät umbenannt oder
entfernt, bliebe sein retained-Wert sonst für immer im Broker stehen.

**Geräte lassen sich vom Versand ausnehmen.** Sie bleiben im Abbild
sichtbar; es geht nur nichts von ihnen an den Miniserver.

**Wartungszähler für den Messerwechsel**, gerechnet aus `operatingHours`,
mit Quittierknopf. Ab Werk aus. Steht im Abbild keine Stundenzahl, wird
nichts gerechnet und nichts quittiert — statt auf 0 zu raten.

**Der MQTT-Reiter zeigt jetzt das einzutragende Abo** (aus dem eingestellten
Basis-Thema gebildet, nicht mehr fest `gardena/#`) **und eine Tabelle aller
veröffentlichten Themen** mit Bedeutung und letztem Wert.

**Der Reiter „Einbindung in Loxone" hat eine Baustein-Liste**: vierzehn
Zeilen mit Typ, Namensvorschlag, Parametern und Eingängen, dazu die
Erläuterungen zu Ausfallerkennung und Benachrichtigung.

**Vorlage auch für den UDP-Weg.** Aufbau und Attributreihenfolge sind gegen
die maßgebliche Ausfuhr aus Loxone Config vom 12.08.2026 gemessen
(`VirtualInUdp`, `templateType="1"`).

**Der Reiter Geräte zeigt den Zeitstempel der Wolke** je Gerät — also wie
alt die Angabe des Gerätes selbst ist, nicht wann zuletzt abgerufen wurde.

### Was bewusst NICHT umgesetzt ist

Drei Vorschläge stehen weiter offen, weil ihre **Voraussetzung nicht
gemessen** ist. Sie umzusetzen hieße, auf eine Vermutung zu bauen:

* **Mehrventil-Geräte.** Dienste eines Gerätes werden nach Diensttyp
  abgelegt; melden sich mehrere gleichartige (eine *smart Irrigation
  Control* mit sechs Ventilen), überschreiben sie einander. Belegt ist das
  im Code — **ungeprüft ist, ob die Wolke das wirklich so liefert**.
  Aufgelöst wird es durch ein `?action=list` an einer Anlage mit einem
  solchen Gerät.
* **Zustände als Zahl.** `activity`, `state` und `rfLinkState` kommen als
  Text; eine Zuordnung Text→Zahl bräuchte die vollständige Werteliste.
  Sie ist hier nicht gemessen, und eine erfundene Tabelle wäre schlimmer als
  keine.
* **Echtzeit über WebSocket** (`POST /v2/websocket`). Das ist der richtige
  Weg, wenn das Abrufkontingent wirklich so eng ist, wie Fremdquellen
  berichten — aber es braucht einen Dauerläufer statt eines Cron-Skripts.
  Solange die Grenze nicht belegt ist, wäre der Umbau eine Wette.

### Was geprüft wurde — und was nicht

Geprüft: Syntax gegen PHP 7.4.33 **und** 8.4.24; die Oberfläche gerendert bei
Neuinstallation, im Aktualisierungsfall (Konfiguration ohne die neuen
Schlüssel), im gesunden und im gestörten Zustand; die Vorlagen durch
`simplexml_load_string()`; das Lebenszeichen mit einem UDP-Horcher auf
127.0.0.1 **auf dem Draht** gemessen, mit und ohne die Erweiterung `sockets`;
die Titel der Vorlage gegen die wirklich gesendeten Themen, mit einem
Kontrollgerät ohne Sonderzeichen und einem mit Umlaut; `?device[]=x` gegen
beide PHP-Fassungen, mit gültigem Gerätenamen als Kontrollfall; ein Attribut
mit `null` gegen eines mit `0` und eines mit leerer Zeichenkette, auf dem
Draht mitgelesen; der Formularschutz ohne, mit falschem und mit richtigem
Merkmal; das Muster, mit dem die hauseigene Prüfkette den aktiven Reiter am
gerenderten HTML sucht, mit 1.1.9 als Kontrollfall; der Ordnername in den
erzeugten Steuerbefehlen; Sprachdateien deckungsgleich (167 Schlüssel je
Seite). Das Archiv ist gepackt und byteweise gegen den Ordner geprüft.

**Nicht geprüft:** der Betrieb an einer echten GARDENA-Anlage. Alle Aussagen
über die Antwort der Husqvarna-Wolke stützen sich auf den vorhandenen Code
und die Schnittstellenbeschreibung, nicht auf eine Messung am Gerät.

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
Deutsch/Englisch mit Englisch als Rückfallebene (damals 91 Schlüssel je Datei,
inzwischen 222); bis
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

Sprachdateien deckungsgleich (damals 91 Schlüssel je Seite, keiner fehlt, kein
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

