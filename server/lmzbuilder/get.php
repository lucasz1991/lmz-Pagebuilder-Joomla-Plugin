<?php

/**
 * Torwächter für die Paketauslieferung der LMZ Builder Suite.
 *
 * WOZU ÜBERHAUPT EIN TORWÄCHTER: die Versions-XML muss frei abrufbar sein —
 * Joomla holt sie ohne jede Anmeldung, und wer sie sperrt, bekommt nie eine
 * Aktualisierungsmeldung. Das Paket dagegen kann lizenzpflichtiges Material
 * enthalten (etwa FontAwesome Pro) und darf dann nicht offen im Netz liegen.
 *
 * Joomla trennt genau an dieser Stelle: das Feld `extra_query` eines
 * Aktualisierungsdienstes wird AUSSCHLIESSLICH an die Download-Adresse
 * angehängt, niemals an den Abruf der XML
 * (administrator/components/com_installer/src/Model/UpdateModel.php, Zeile 423).
 * Metadaten offen, Paket hinter einem Schlüssel — ohne dass Joomla etwas
 * Besonderes können müsste.
 *
 * AUFRUF
 *   /lmzbuilder/get.php?f=pkg_lmzbuilder-1.2.3.zip&key=<schluessel>
 *
 * Der Schlüssel wird in der Zielinstallation einmalig unter
 * System -> Aktualisierungs-Server beim Eintrag der Suite als
 * `key=<schluessel>` hinterlegt. Joomla hängt ihn danach bei jedem Download an.
 */

declare(strict_types=1);

/* Keine Ausgabe vor dem Kopfbereich - sonst ist das Archiv beschädigt. */
ini_set('display_errors', '0');
error_reporting(E_ALL);

const PAKETORDNER = __DIR__ . '/packages';

/**
 * Bricht mit einem knappen Klartexthinweis ab.
 *
 * Bewusst ohne Einzelheiten: ein Torwächter, der erklärt, WARUM er ablehnt,
 * hilft beim Durchprobieren.
 */
function abweisen(int $status, string $hinweis): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo $hinweis, "\n";
    exit;
}

/* ------------------------------------------------------------------ *
 * 1. Zugelassene Schlüssel laden
 * ------------------------------------------------------------------ */

$schluesseldatei = __DIR__ . '/keys.php';

if (!is_file($schluesseldatei)) {
    /* Fehlt die Datei, wird NICHT ausgeliefert. Ein Torwächter, der bei
       fehlender Einstellung öffnet, ist keiner. */
    abweisen(503, 'Die Auslieferung ist noch nicht eingerichtet.');
}

$einstellungen = require $schluesseldatei;

if (!is_array($einstellungen)) {
    abweisen(503, 'Die Auslieferung ist nicht richtig eingerichtet.');
}

/** @var array<int, string> $schluessel */
$schluessel = array_values(array_filter(
    array_map('strval', (array) ($einstellungen['schluessel'] ?? [])),
    static fn (string $s): bool => $s !== ''
));

$offen = ($einstellungen['ohne_schluessel'] ?? false) === true;

/* ------------------------------------------------------------------ *
 * 2. Schlüssel prüfen
 * ------------------------------------------------------------------ */

if (!$offen) {
    $eingang = (string) ($_GET['key'] ?? '');
    $passt = false;

    foreach ($schluessel as $erlaubt) {
        /* Zeitkonstanter Vergleich: ein gewöhnliches === verrät über die
           Laufzeit, wie viele Zeichen stimmen. */
        if (hash_equals($erlaubt, $eingang)) {
            $passt = true;

            break;
        }
    }

    if (!$passt) {
        abweisen(403, 'Kein Zugriff.');
    }
}

/* ------------------------------------------------------------------ *
 * 3. Dateinamen prüfen
 * ------------------------------------------------------------------ */

$angefragt = (string) ($_GET['f'] ?? '');

/* Weissliste statt Sperrliste: nur genau diese Form ist zulässig. Damit sind
   Pfadtrenner, Punkt-Punkt, Nullbytes und alles Weitere ausgeschlossen, ohne
   dass ich sie einzeln aufzählen müsste. */
if (preg_match('/^[A-Za-z0-9._-]{1,120}\.zip$/', $angefragt) !== 1) {
    abweisen(400, 'Ungueltiger Dateiname.');
}

if (str_contains($angefragt, '..')) {
    abweisen(400, 'Ungueltiger Dateiname.');
}

$pfad = PAKETORDNER . '/' . $angefragt;
$echt = realpath($pfad);
$wurzel = realpath(PAKETORDNER);

/* Zweiter Riegel: der aufgelöste Pfad muss wirklich unterhalb des
   Paketordners liegen. Fängt auch symbolische Verknüpfungen ab. */
if ($echt === false || $wurzel === false || !str_starts_with($echt, $wurzel . DIRECTORY_SEPARATOR)) {
    abweisen(404, 'Nicht gefunden.');
}

if (!is_file($echt) || !is_readable($echt)) {
    abweisen(404, 'Nicht gefunden.');
}

/* ------------------------------------------------------------------ *
 * 4. Ausliefern
 * ------------------------------------------------------------------ */

$groesse = filesize($echt);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $angefragt . '"');
header('Content-Length: ' . $groesse);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');

/* readfile() streamt und legt die Datei nicht komplett in den Speicher -
   bei Paketen jenseits des PHP-Speicherlimits ist das der Unterschied
   zwischen Auslieferung und Abbruch. */
readfile($echt);
