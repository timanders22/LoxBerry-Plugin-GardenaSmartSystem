# LoxBerry-Plugin: GARDENA smart system

## Neu in 1.2.10

Diese Fassung behebt Befunde einer Durchsicht vom 25.09.2026. Gemessen in WSL
(Ubuntu, PHP 8.3.6) mit Attrappen für Wolke, LoxBerry-SDK, MQTT-Gateway und
Broker, **nicht am Gerät** (55 Fälle, vorher 31 rot, nachher 0).

**Behoben:**

- **Alte zurückbehaltene MQTT-Werte gelten erst als abgeräumt, wenn der Broker
  es bestätigt.** 1.2.8 und 1.2.9 räumten die Altwerte aus 1.2.7 und früher
  einmal ab und merkten sich das, sobald die Datagramme den LoxBerry verlassen
  hatten. Der UDP-Eingang des MQTT-Gateways verwirft unter Last Datagramme, ohne
  dass der Absender es merkt – der Altwert konnte stehen bleiben, während das
  Plugin ihn für erledigt hielt. Jetzt fragt das Plugin den Broker (kurze eigene
  MQTT-Anmeldung mit den Zugangsdaten aus der LoxBerry-Konfiguration), räumt nur
  ab, was dort noch steht, und vermerkt nur, was der Broker leer meldet. Die
  leere Nachricht geht unmittelbar vor dem gültigen Wert desselben Themas
  hinaus. **Grenze:** ist der Broker nicht zu fragen (keine Verbindung,
  Anmeldung oder Abonnement abgewiesen), wird bei jeder vollständigen Meldung
  erneut abgeräumt, bis er wieder antwortet.
- **Weggefallene Themen** (Gerät umbenannt, entfernt oder ausgenommen) werden
  gelöscht, bis der Broker es bestätigt; bisher ging die Löschung genau einmal
  hinaus. Ist der Broker nicht zu fragen, geht sie in drei Läufen hinaus, danach
  wird das Thema nicht weiter verfolgt.
- **Die Deinstallation räumt den Broker auf.** Bisher blieben die
  zurückbehaltenen Themen des Plugins nach dem Entfernen stehen, und Loxone
  bekam sie nach jedem Neustart von Broker oder Gateway wieder. Jetzt fragt die
  Deinstallation den Broker, leert höchstens dreimal, was dort noch steht, und
  sagt im Installationsprotokoll, was geschah. Einen Abruf, der beim
  Deinstallieren noch läuft, wartet sie bis 30 s ab, damit er die geleerten
  Werte nicht wieder hineinschreibt (den Cron-Eintrag entfernt LoxBerry schon
  vorher). Nach 90 s (hart nach 95 s) bricht dieser Schritt ab und meldet es;
  die übrigen Aufräumschritte laufen trotzdem. **Grenze:** ein Sofortabruf über
  `?action=refresh`, den Loxone genau in den Sekunden zwischen diesem Schritt
  und dem Entfernen der Plugin-Dateien auslöst, schreibt wieder zurück.
- **Nach einem Update wird nur die Sicherung aus diesem Update eingespielt.**
  Scheiterte das Sichern in `preupgrade.sh`, blieb die Sicherung eines
  früheren Updates liegen, und `postupgrade.sh` spielte sie über die jetzige
  Konfiguration. Die Sicherung trägt jetzt ihren Zeitpunkt; eine ältere als
  eine Stunde, eine ohne Zeitpunkt oder eine bei nicht lesbarer Uhr wird nicht
  eingespielt, bleibt liegen und wird mit Ablageort gemeldet.
- **Ein ausgepacktes Archiv wirkt nicht mehr auf die Anlage.** Aus einem
  entpackten Archiv heraus aufgerufen, schrieb `bin/gardenaMain.php` mit den
  Pfaden der Anlage: den Zustand nach `config/plugins/`, Protokoll und
  Sperrdatei nach `log/plugins/`, jeweils ohne Ordnernamen. Jetzt läuft der
  Abruf nur installiert (oder wenn `LBHOMEDIR` und `LBPPLUGINDIR` ausdrücklich
  gesetzt sind) und sagt sonst, warum nicht. Oberfläche und Loxone-Endpunkt
  suchen ihre Bibliothek am eigenen Ablageort und schreiben aus einem Archiv
  heraus nichts.
- **Keine Pfade mehr ab der Laufwerkswurzel.** Ohne gefundene LoxBerry-Wurzel
  las das Plugin Sprachtexte aus einem festen Installationspfad oder aus
  `/templates/…` und den UDP-Port des Gateways aus `/config/system/general.json`.
  Als Wurzel gilt jetzt nur ein Verzeichnis mit `config/plugins`, `data/plugins`
  und `config/system/general.json`.
- **`postinstall.sh` entscheidet nach Inhalt.** Eine nicht leere, aber
  unlesbare `gardena_status.json`, `devices_cache.json` oder
  `gardena_token.json` wird aus der Zweitschrift ersetzt (der alte Stand bleibt
  als `.kaputt` daneben); eine unlesbare Zweitschrift wird nicht mehr über eine
  fehlende Datei gelegt.

**Unverändert:** alle MQTT-Themen, UDP-Zeilen und die Retain-Einstellung je
Thema – Zustände wie `activity`, `state`, `rfLinkState` retained, Messwerte und
`Plugin/STATUS/*` flüchtig.

## Neu in 1.2.9

Diese Fassung behebt Befunde einer Nachstellung des Updates in WSL (Ubuntu,
17.09.2026), nicht am Gerät. Sie betreffen die knappe Minute, in der LoxBerry
bei einem Update die neuen Dateien schon kopiert, `postinstall.sh` aber noch
nicht aufgerufen hat. Läuft in dieser Zeit der Fünf-Minuten-Takt oder öffnet
jemand die Plugin-Seite, arbeitet das Plugin mit der mitgelieferten Vorgabe.

**Behoben:**

- **Die Zweitschrift der Einstellungen wurde durch die Vorgabe ersetzt.** Nach
  einem Update mit Takt in dieser Minute stand
  `config/plugins/<Ordner>.backup.gardena.cfg` ohne Application Key, Secret und
  Aktionstoken da, bis zum nächsten Speichern. Die Einstellungen selbst holte
  `postupgrade.sh` zurück; der Rettungsweg über die Zweitschrift war aber
  wertlos. Ursache: die Prüfung „steht ein Token darin?“ zählte ein leeres
  `TOKEN=` als gesetzt, sobald die nächste Zeile einen Wert trug. Das Token wird
  jetzt so gelesen wie beim Laden der Konfiguration, und nur im Abschnitt
  `[GARDENA]`. Zusätzlich ersetzt ein Stand ohne Zugangsdaten mit einem
  anderen Token keine Zweitschrift mit Zugangsdaten mehr — so sah es aus, wenn
  die Seite während des Updates geöffnet wurde. Wer die Zugangsdaten bewusst
  löscht, behält sein Token; dann zieht die Zweitschrift wie bisher mit.
- **`postinstall.sh` spielte die Zweitschrift nicht zurück**, wenn die Vorgabe
  in dieser Minute schon vervollständigt oder mit einem neuen Token versehen
  worden war: erkannt wurde nur die unveränderte Vorgabe an ihrer Prüfsumme.
  Fiel `postupgrade.sh` dann aus, fehlten die Einstellungen. Als verloren gilt
  jetzt auch eine Datei ohne Token, wenn die Zweitschrift eines trägt, und eine
  Datei ohne Zugangsdaten mit einem anderen Token als die Zweitschrift mit
  Zugangsdaten.
- **Protokollzeilen aus dem Update gingen verloren.** `preupgrade.sh` sicherte
  `log/`, `postupgrade.sh` kopierte die Sicherung zurück und überschrieb damit
  alles, was dazwischen geschrieben worden war. LoxBerry löscht `log/` beim
  Update nicht; gesichert und zurückkopiert wird es nicht mehr.
- **Die Sicherung wurde ungeprüft gelöscht.** `postupgrade.sh` meldete
  „Konfiguration zurueckgestellt.“ und löschte
  `data/plugins/<Ordner>.upgrade_sicherung` auch dann, wenn das Kopieren
  scheiterte. Jetzt wird nachgesehen: Rückgabewert des Kopierens und jede Datei
  der Sicherung byteweise am Ziel. Fehlt etwas, bleibt die Sicherung liegen,
  und das Installationsprotokoll nennt sie mit `<WARNING>`. Die Deinstallation
  räumt sie wie bisher ab.

**Ebenfalls in 1.2.9 behoben — zweiter Durchgang am 17.09.2026.** Beim
Durchsehen der Hakenskripte fiel eine gemeinsame Bauart auf: an sechs Stellen
entschied die *Form* einer Datei („ist sie leer?“, „steht die Zeichenfolge
irgendwo?“) über etwas, das vom *Inhalt* abhängt. Eine `gardena.cfg`, die nur
die mitgelieferten Vorgaben trägt, ist nicht leer — und genau so sieht sie
während eines Updates aus.

- **Ein Leerzeichen galt als eingetragenes Zugangsdatum.** Am Ende der
  Installation entschied `grep "^CLIENT_ID=..*"` darüber, ob
  „Zugangsdaten sind eingetragen - es ist nichts weiter zu tun.“ erscheint
  oder der Hinweis auf die Ersteinrichtung. `CLIENT_ID=` mit einem Leerzeichen
  dahinter erfüllte das Muster; `parse_ini_file`, mit dem das Plugin liest,
  wirft diesen Leerraum weg und sieht einen leeren Wert. Umgekehrt fand das
  Muster einen Wert nicht, wenn vor dem Namen ein Leerzeichen stand, und es
  zählte auch einen `CLIENT_ID` aus einem anderen Abschnitt mit. Gelesen wird
  jetzt mit demselben Zerleger wie an den übrigen Stellen, und verlangt werden
  **beide** Werte: ohne Application Secret kommt keine Anmeldung zustande.
- **Die Zweitschrift entstand nach `[ -s ]`.** `preupgrade.sh` legte
  `config/plugins/<Ordner>.backup.gardena.cfg` an, sobald die Konfiguration
  nicht leer war — auch aus einer Datei ohne Zugangsdaten und ohne Token.
  Damit konnte dieselbe Minute, gegen die diese Fassung sonst schützt, die
  gute Zweitschrift über den Umweg des Hakenskripts doch noch überschreiben.
  Jetzt gilt dieselbe Regel wie beim Speichern aus der Oberfläche: geschrieben
  wird nur ein Stand mit Zugangsdaten oder Token, und ein Stand ohne
  Zugangsdaten mit einem anderen Token ersetzt keine Zweitschrift mit
  Zugangsdaten.
- **Dasselbe für die drei JSON-Zweitschriften** (`gardena_token.json`,
  `devices_cache.json`, `gardena_status.json`): eine halb geschriebene Datei
  ist nicht leer und hätte die heile Kopie ersetzt. Eine vorhandene
  Zweitschrift wird jetzt nur von einer Datei abgelöst, die sich als JSON
  lesen lässt; gibt es noch keine, wird auch eine beschädigte Datei gesichert
  — etwas ist besser als nichts.
- **Die Meldung am Ende des Updates war zu freundlich.** `postupgrade.sh`
  meldete „Die Einstellungen sind vorhanden“, sobald eine `gardena.cfg`
  dalag — auch wenn darin nur die Vorgaben standen und Application Key und
  Secret fehlten. Gefragt wird jetzt nach denselben zwei Werten wie am Ende
  der Installation; fehlt einer, steht die Aufforderung da, sie neu
  einzutragen.
- **Die vorhandene Sicherung wurde weggeworfen, bevor die neue stand.**
  `preupgrade.sh` begann mit `rm -rf` auf
  `data/plugins/<Ordner>.upgrade_sicherung` und meldete danach unbedingt
  „Konfiguration gesichert.“ Ging das Kopieren schief oder gab es gar keine
  Konfiguration, war beides weg. Jetzt wird die neue Sicherung daneben
  aufgebaut, Rückgabewert und Inhalt werden geprüft, und erst dann tritt sie
  an die Stelle der alten. Schlägt etwas fehl, bleibt die alte unangetastet,
  und das Installationsprotokoll sagt es mit `<WARNING>`.
- **Die Deinstallation ließ die Upgrade-Sicherung liegen**, wenn LoxBerry sie
  ohne fünftes Argument und ohne `LBHOMEDIR` aufrief: für die Zweitschriften
  neben dem Konfigordner war der Rückfall über den eigenen Ablageort
  eingebaut, für die Sicherung nicht. In ihr stehen Application Key, Secret
  und ein gültiges OAuth2-Token. Außerdem stand der Satz „Zugangsdaten und
  Zugriffstoken des Plugins sind geloescht“ **vor** dem Abräumen der
  Sicherung und sah sie gar nicht an. Beides berichtigt: es wird mit derselben
  Wurzel gerechnet, die Nachschau steht hinter allem, was das Skript entfernt,
  und nennt jede Stelle einzeln, die liegen geblieben ist.

**Nicht gemessen:** am Gerät; als Benutzer `loxberry` (die Nachstellung lief
als abgebildeter root eines Namensraums); mit der wirklichen Lücke von rund
50 Sekunden (nachgestellt mit einem einzelnen Takt oder Seitenaufruf); an einer
GARDENA-Anlage weiterhin nichts. Die Stände des zweiten Durchgangs liefen in
WSL unter PHP 8.3.6, die Oberfläche zusätzlich unter PHP 7.4.33 und 8.4.24.

## Neu in 1.2.8

Diese Fassung behebt Befunde einer Messung am installierten Plugin auf einem
LoxBerry 4.0.0.15 (17.09.2026) und einer Durchsicht gegen die Hausregeln. An
einer GARDENA-Anlage ist weiterhin **nichts** gemessen.

**Umstieg — was Sie bemerken können:**

- **Messwerte gehen nicht mehr retained hinaus.** Zustände (`activity`,
  `state`, `lastErrorCode`, `batteryState`, `rfLinkState`, `operatingHours`,
  `name`, `serial`, `modelType`) bleiben retained; Messwerte (`batteryLevel`,
  `rfLinkLevel`, Bodenfeuchte, Boden- und Lufttemperatur, Helligkeit) nicht,
  damit nach einem Ausfall kein alter Wert als frisch erscheint. Der volle
  Satz geht wie bisher spätestens alle 30 Minuten hinaus. Beim ersten
  vollständigen Lauf nach dem Update räumt das Plugin die früher
  zurückbehaltenen Werte einmal im Broker ab und schickt die aktuellen
  unmittelbar hinterher. Die Spalte *retained* im Reiter MQTT zeigt, was was ist.
- **Zwei neue Themen im Lebenszeichen**, neben den vier bisherigen:
  `…/Plugin/STATUS/ts` (Unix-Zeit des Durchgangs) und `…/Plugin/STATUS/zaehler`
  (0 bis 999). Beide wandern bei **jedem** Cron-Lauf — auch während einer
  Abrufsperre nach HTTP 429 und bei gestrecktem Abstand, wo bis 1.2.7 gar
  nichts hinausging. Kein bestehendes Thema ändert Namen oder Bedeutung.
- **Bremsen am Endpunkt:** `?action=refresh` frühestens 60 Sekunden nach dem
  letzten Lauf, `?action=command` höchstens 30-mal je Stunde (sonst HTTP 429);
  während einer Abrufsperre weisen beide mit HTTP 503 ab. `?action=list` ohne
  Daten antwortet mit 503 statt 200.
- **Das Protokoll ist eine Datei:** `log/plugins/<Ordner>/gardena.log`.

**Behoben:**

- **Jeder Cron-Lauf legte eine neue Protokolldatei an** — zwölf je Stunde, auch
  bei ausgeschaltetem Plugin. `log/plugins` liegt auf der RAM-Scheibe, und die
  Logwartung von LoxBerry kürzt dort stündlich über **alle** Plugins hinweg auf
  die 24 jüngsten Dateien; Gardena half damit, die Protokolle anderer Plugins
  wegzuräumen. Zugleich stand in keiner dieser Dateien eine Meldung: die
  Plugin-Datenbank führt für dieses Plugin die Protokollstufe −1, und das SDK
  schrieb nur Kopf- und Schlusszeile. Ein Fehler des Dienstes war nirgends zu
  lesen. Gemessen am Gerät. Dienst, Endpunkt und Oberfläche schreiben jetzt in
  `gardena.log` (gekappt auf 256 kB); Zeilen, die sich bei jedem Lauf
  wiederholen, höchstens einmal je Stunde.
- **Der MQTT-Speicherknopf speicherte trotz Beanstandung.** Ein leeres oder
  ungültiges Thema wurde mit „Der Eintrag wurde nicht gespeichert.“ beanstandet
  — und im selben Aufruf gespeichert. Dasselbe beim Formular der Einstellungen:
  ein Gerätename mit Komma in der Ausnahmeliste wurde beanstandet, der Rest
  trotzdem geschrieben, und ein abgewiesenes Speichern legte schon ein Token an.
  Jetzt wird bei einer Beanstandung nichts geschrieben.
- **Nach dem Zurückspielen einer Sicherung** blieb die zwischengespeicherte
  Anmeldung bei Husqvarna liegen, auch wenn die Datei andere Zugangsdaten trug.
  Sie wird jetzt verworfen, und die Meldung sagt, was mit dem Dienst geschieht.
- **„Alle retained“ stand über der Thementabelle**, obwohl das Lebenszeichen
  seit 1.2.6 ohne Retain hinausgeht. Die Tabelle zeigte außerdem Themen
  ausgenommener Geräte, die nie gesendet werden.
- **Die Baustein-Liste im Reiter „Einbindung in Loxone“** schlug Namen wie
  `Gardena_Akku` für die virtuellen Eingänge vor. Das Gateway legt Eingänge aber
  unter dem Thema an; wer dem Vorschlag folgte, bekam Eingänge, die nie einen
  Wert erhielten. Die Liste nennt jetzt die Gateway-Namen. Die
  Ausfallerkennung darin (ein Treppenlichtschalter am Zeitstempel) konnte nicht
  ansprechen: ein Analogwert, der von einer großen Zahl zur nächsten wechselt,
  erzeugt an einem Digitaleingang keine Flanke, und der Ausgang war im
  Normalbetrieb EIN. Ersetzt durch eine Altersformel auf `ts`.
- **Zwei Prüfzeilen im Reiter Test** setzten ein Häkchen über eine leere Menge
  („jedes Attribut der letzten Antwort trug einen Wert“ — ohne je eine Antwort).
  Sie sagen jetzt „nicht feststellbar“.
- **Die UDP-Vorlage** enthielt die Eingänge des Lebenszeichens nicht.
- **Eine Zweitschrift der Konfiguration** entstand nur in `preupgrade.sh`. Sie
  wird jetzt bei jedem Speichern mitgezogen, mit denselben Rechten wie das
  Original.
- Kleineres: doppelt maskierte Beanstandungen; „Protokoll leeren“ meldete
  „Konfiguration gespeichert.“; der MQTT-Knopf hieß „Speichern & Verbindung
  testen“ und testete nichts; Speicherknöpfe standen grün unter der Legende
  „Ansehen“; `postinstall.sh` riet auch nach einem Update zur Ersteinrichtung
  und meldete Rechte, ohne nachzusehen; `uninstall` rechnete eine Ebene zu weit
  hinauf; ein fester Systempfad als Rückfall; der Ersatzweg ohne cURL folgte
  Umleitungen; „zugestellt“ statt „abgeschickt“ — UDP meldet keine Zustellung.

**Nicht gemessen:** nichts davon an einer GARDENA-Anlage; ob der Miniserver aus
einer leeren Nutzlast 0 macht; die Altersformel an einem Miniserver.

## Neu in 1.2.7

- **Das Auswahlfeld zeichnet seinen Pfeil selbst.** Bis 1.2.6 kam er von der
  Oberfläche des LoxBerry. Am 05.09.2026 am Gerät gemessen (LoxBerry 4.0.0.15,
  `system/css/components.css`): deren Regel `.lb-content select`
  gibt es erst seit der neuen Oberfläche, und jede eigene Feldregel mit der
  Kurzform `background:` löscht sie wieder. Darauf soll sich eine
  Plugin-Oberfläche nicht verlassen (`Regeln/04`). Sonst ist an dieser
  Fassung nichts geändert.

## Neu in 1.2.6

Diese Fassung behebt Befunde einer vollständigen Durchsicht vom 05.09.2026.
Gemessen wurde gegen PHP **7.4.33 und 8.4.24**; an einer GARDENA-Anlage ist
nach wie vor **nichts** gemessen — es steht keine zur Verfügung.

**Was Sie merken werden**

- **Die Sicherungsdatei prüft jetzt auch die Werte, nicht nur die Namen.**
  Bis 1.2.5 wurde jeder Wert zu einem bekannten Schlüssel ungeprüft
  übernommen und roh in die `gardena.cfg` geschrieben. Ein Zeilenumbruch
  darin erzeugte eine zweite Zeile — eine untergeschobene Sicherung konnte
  auf diesem Weg **das Aktionstoken setzen**, also genau das Geheimnis, das
  Mäher und Ventile vor fremdem Zugriff schützt.
- **Ihre eigene Sicherung lässt sich wieder zurückspielen.** Trug Ihre
  `gardena.cfg` einen Rest aus einer alten Fassung (`LOCALTIME`), erzeugte
  das Plugin eine Sicherungsdatei, die es beim Zurückspielen selbst als
  „Unbekannte Einstellung" ablehnte.
- **Unsinnige Angaben am Endpunkt werden abgewiesen statt geraten.**
  `&seconds=abc`, `&seconds=-60` und `&seconds=1800.0` ließen den Mäher bis
  1.2.5 wortlos eine Stunde laufen; ein fehlendes `&type=` startete den
  **Mäher**, auch wenn ein Ventil gemeint war. Beides wird jetzt gemeldet.
- **Ein Netzfehler löscht nicht mehr die Geräteliste.** Scheiterte der Abruf,
  überschrieb 1.2.5 den Geräte-Zwischenspeicher mit einer leeren Liste —
  danach fand `?action=command` keine Dienstkennung mehr, der Reiter *Geräte*
  war leer und die Vorlagenknöpfe verschwanden.
- **Der Endpunkt schreibt ein Protokoll.** `log/plugins/<Ordner>/gardena_endpunkt.log`
  hält jede Abweisung und jeden Schaltbefehl fest, mit der Adresse des
  Anrufers. Geglückte Abfragen werden gebremst (höchstens eine Zeile je
  Stunde), sonst wären es 1440 am Tag. Ohne diese Zeile ließ sich „der
  Miniserver ruft nicht an" nicht von „er ruft an und wird abgewiesen"
  unterscheiden.
- **Die Warnung an der Sicherungsdatei hat wieder einen Rahmen.** Sie stand
  seit jeher als nackter Fließtext da — die CSS-Klasse `sm-warnung` ist in
  diesem Plugin nirgends definiert.
- **Das Application Secret steht nicht mehr im Seitenquelltext.** Ein leeres
  Feld heißt jetzt „unverändert lassen"; gelöscht wird über den Haken darunter.
- **Das Lebenszeichen geht nicht mehr `retained` hinaus** und die drei
  Zahlenwerte stehen jetzt **in der Importvorlage** — bisher mussten Sie
  ausgerechnet die Eingänge der Ausfallerkennung von Hand anlegen.
- **Ausgenommene Geräte stehen nicht mehr in den Eingangsvorlagen.** Sie
  erzeugten virtuelle Eingänge, die nie einen Wert bekamen und in Loxone auf
  `0` stehenblieben.
- **Eine beschädigte Konfiguration wird nicht mehr stillschweigend ersetzt.**
  War die `gardena.cfg` leer oder unlesbar, erzeugte das Plugin beim Öffnen
  der Oberfläche ein **neues Zugriffstoken** und schrieb es weg — jede im
  Miniserver eingetragene Adresse war danach ungültig, ohne ein Wort. Jetzt
  wird nichts geschrieben, der Zustand steht im Reiter *Test* und die
  Meldung nennt die Datei und die Zweitschrift daneben. (Gemessen am
  05.09.2026 an 1.2.5 und 1.2.6 nebeneinander.)
- **Beim Deinstallieren werden auch die Zweitschriften entfernt.** Bis 1.2.5
  blieben `…​.backup.gardena.cfg`, `….backup.gardena_token.json` und
  `….backup.devices_cache.json` liegen — mit Application Secret,
  Endpunkt-Token und einem gültigen OAuth2-Token der Husqvarna-Wolke. Die
  Deinstallation meldete trotzdem, die Zugangsdaten seien gelöscht.

**Kleiner, aber gemeldet statt still**

Ein ungültiges MQTT-Basisthema und ein Gerätename mit Komma in der
Ausnahmeliste werden jetzt beanstandet statt wortlos zurechtgebogen; eine
Miniserver-Nummer wird gegen die wirklich eingerichteten gehalten; der
Cron-Lauf sucht seinen PHP-Interpreter und meldet einen Fehlschlag, statt
lautlos nichts zu tun; `type` und `cmd` werden gegeneinander geprüft, statt
einen Abruf des Husqvarna-Kontingents an einen Befehl zu verschwenden, den es
für dieses Gerät nicht gibt; die Konfiguration wird beim Öffnen und beim
Dienststart **vervollständigt**; und `SELFTEST` liefert `TOKEN=1` statt
`TOKEN=OK`, damit die Zeile durchgehend aus Zahlen besteht.

**Umstiegshinweis.** Es ändert sich kein MQTT-Thema und keine Adresse. Zwei
Dinge sind neu: `&type=` ist am Endpunkt jetzt **Pflicht** (bisher galt
stillschweigend `MOWER_CONTROL`) — steht es in einem Ihrer Virtuellen
Ausgänge nicht, tragen Sie es nach. Und der Suchtext `;TOKEN=` am
`?selftest=1` liest jetzt eine 1 statt des Textes `OK`.

## Was das Plugin tut

Holt zyklisch (alle 5 Minuten) die Daten aller GARDENA-smart-system-Geräte
(Mähroboter, Bewässerungscomputer/Ventile, Sensoren, Steckdosen, Gateway) und
sendet sie an den Loxone Miniserver – per **UDP** und/oder **MQTT**
(LoxBerry MQTT Gateway; Zustände retained, Messwerte und Lebenszeichen nicht). Kommandos (Mähen starten, Parken,
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
- Geräteliste/Diagnose: `/plugins/gardenasmartsystem/index.php?action=list&token=…`
  (rein lesend, aber **mit** Token — seit 1.1.0 verlangen es alle Endpunkte)

### Zugriffstoken

Alles, was etwas auslöst (`action=command`, `action=refresh`), verlangt ein Token.
Ohne diese Prüfung könnte jedes Gerät im Netz – und über eine unbedacht weitergeleitete
Portfreigabe auch jemand von außen – den Mäher losschicken oder die Bewässerung aufdrehen.

Das Token wird beim ersten Öffnen der Plugin-Oberfläche automatisch erzeugt und dort
angezeigt; die fertigen Loxone-Adressen enthalten es bereits. Ist noch keins hinterlegt,
werden Schaltbefehle abgewiesen (fail closed). Über „Neues Token erzeugen“ lässt es sich
jederzeit wechseln – die Virtuellen Ausgänge in Loxone müssen dann angepasst werden.

## Hinweise

- Husqvarna begrenzt die API-Nutzung (Rate Limit); wie hoch die Grenze liegt,
  ist in diesem Plugin nicht gemessen. Nach HTTP 429 wartet das Plugin die
  Sperre ab.
- Protokoll: Reiter *Logdateien*, Datei `log/plugins/gardenasmartsystem/gardena.log`
  (RAM-Scheibe — ein Neustart löscht sie).

## Herkunft, Lizenz und Änderungen

Dieses Plugin ist eine **abgeleitete Arbeit** des ursprünglichen Plugins von
**Michael Jani** (Repository: https://github.com/DiabloVmax1200/LoxBerry-Plugin-GardenaSmartSystem),
veröffentlicht unter der **Apache-Lizenz 2.0**. Lizenztext (`LICENSE`) und
Copyright-Hinweise bleiben unverändert erhalten; die Plugin-Kennung
(`NAME`/`EMAIL` in `plugin.cfg`) wurde bewusst **nicht** geändert.

Gemäß Apache-2.0 (Abschnitt 4b) hier die Liste der geänderten Bestandteile:

- `bin/gardena.class.inc.php` (bis 1.0.2 unter `webfrontend/html/`, seit
  1.1.0 ausserhalb des Apache-Wurzelverzeichnisses) — vollständig neu: GARDENA smart
  system **API v2** (OAuth2, Application Key/Secret) statt der abgeschalteten
  sg-1-API; ab v1.0.1 HTTP wahlweise über cURL oder PHP-Streams
- `webfrontend/htmlauth/index.php`, `webfrontend/html/*` — neue Oberfläche für
  LoxBerry 3/4 anstelle des LoxBerry-1.x-Templatesystems (Perl entfallen)
- `cron/cron.05min` — neuer Abrufzyklus, UDP- und MQTT-Ausgabe
- `dpkg/apt` — `libstring-escape-perl` entfernt (Perl-Teile entfallen),
  `php-curl` nur noch als Empfehlung
- `plugin.cfg` — Fassungsstand, Abhängigkeiten und Selbstaktualisierung
  (die Nummer steht dort, nicht hier: eine zweite Stelle läuft weg)

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

## Änderungen

Die Freigabenotiz zu jeder Fassung steht bei den Releases:
<https://github.com/timanders22/LoxBerry-Plugin-GardenaSmartSystem/releases>

Der folgende Abschnitt hieß bis 1.2.4 „Änderungen in 1.1.0“ — eine Fassungsnummer
in einer Überschrift wird mit jedem Release falscher. Der Inhalt bleibt, weil
er beschreibt, *warum* das Plugin so gebaut ist; er betrifft die Fassung 1.1.0.

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
  nach `data/plugins/<Ordner>.upgrade_sicherung` — mit **Punkt**, also als
  Nachbar des Plugin-Ordners und nicht darin: `rm -rf …/<Ordner>/` trifft den
  Nachbarn nicht, und genau deshalb übersteht die Sicherung das Update. Sie
  liegt auf der Karte, nicht in der Ramdisk. Der alte Ort wird beim Update von 1.0.2 noch mitgelesen.
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


## Fassung 1.2.4 — der Stat-Zwischenspeicher
Die Protokollkappung (262 144 Byte) stand in `bin/functions.inc.php:72`. PHP
merkt sich aber die Antworten von `stat()`: innerhalb **eines** Prozesses
sieht `filesize()` die erste Größe und danach nie wieder eine neue —
`file_put_contents(…, FILE_APPEND)` macht den Eintrag nicht ungültig. Die
Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. Folgen hatte das
hier nicht: die Aufrufer sind kurzlebig, und ein **frischer** Prozess kappt
richtig. Eine Funktion darf aber nicht davon abhängen, wer sie wie oft ruft.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

