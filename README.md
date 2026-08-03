# LoxBerry-Plugin: GARDENA smart system

Holt zyklisch (alle 5 Minuten) die Daten aller GARDENA-smart-system-Geräte
(Mähroboter, Bewässerungscomputer/Ventile, Sensoren, Steckdosen, Gateway) und
sendet sie an den Loxone Miniserver – per **UDP** und/oder **MQTT**
(LoxBerry MQTT Gateway, retained). Kommandos (Mähen starten, Parken,
Bewässerung starten/stoppen …) können über einen Virtuellen Ausgang gesendet werden.

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
