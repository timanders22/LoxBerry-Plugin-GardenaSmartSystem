<?php
/**
 * Gemeinsame Einbindungen.
 *
 * Diese Datei liegt seit 1.1.0 in bin/, NICHT mehr in webfrontend/html/.
 * Alles unter webfrontend/html/ ist ueber den Apache von aussen erreichbar -
 * ohne Anmeldung. Fuer gardenaMain.php war das eine offene Tuer: jeder Aufruf
 * loest einen vollstaendigen API-Durchlauf samt UDP-Schwall an den Miniserver
 * aus, und die Husqvarna-API hat ein Abrufkontingent. bin/ liegt ausserhalb
 * des Apache-Wurzelverzeichnisses.
 *
 * Eingebunden wird ueber __DIR__, damit es gleichgueltig ist, aus welchem
 * Arbeitsverzeichnis der Cron startet.
 */
require_once 'loxberry_system.php';
require_once 'loxberry_log.php';
require_once 'loxberry_io.php';
require_once __DIR__ . '/gardena.class.inc.php';
require_once __DIR__ . '/functions.inc.php';
