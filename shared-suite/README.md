# LMZ J-Suite: lokale Shared-Source

Dieser Ordner ist die versionierte, installationsneutrale Quelle für Dateien,
die RailTime und NorthStaff wirklich gemeinsam verwenden. Der Umfang ist
absichtlich eine Positivliste: Nur die Dateien aus `inventory.json` werden
geprüft oder synchronisiert.

Aktuell verwaltet die Shared-Source den generischen System-Plugin
`lmzsuitemenu`. Kundenspezifische Templates, Inhalte, Datenbankwerte,
Konfigurationen und die übrigen J-Suite-Erweiterungen sind noch nicht Teil
dieses gemeinsamen Kerns.

## Sicherheitsmodell

- Ohne Option läuft das Werkzeug immer im Nur-Lese-Modus.
- Erst `--apply` erlaubt Schreibzugriffe.
- Jede Quelldatei hat einen festgehaltenen SHA-256 im Inventar.
- `sourceRoot` muss kanonisch auf `shared-suite/source` in diesem
  Produktrepository zeigen; eine Live-Installation kann nicht als Quelle
  eingeschleust werden.
- Eine Zieldatei wird nur geschrieben, wenn sie fehlt oder ihr SHA-256 als
  freigegebene Vorversion in `allowedPreviousSha256` eingetragen ist.
- Ein unbekannter Ziel-Hash gilt als lokale Änderung und blockiert den
  vollständigen Apply-Lauf.
- Vor dem atomaren Austausch werden Zielzustand, SHA-256 und der kanonische
  Elternpfad erneut geprüft. Junctions und Symlinks dürfen nicht aus dem
  bestätigten Joomla-Root hinausführen.
- Das Werkzeug löscht keine Dateien.
- Joomla-Paketmanifeste, Paket-IDs, Update-Feeds und `configuration.php` sind
  technisch vom Sync ausgeschlossen.
- RailTime-, NorthStaff- und NorthPaxx-Inhalte sowie Feed-Metadaten sind in den
  verwalteten Quelldateien verboten.

## Prüfen

Von der Wurzel dieses Repositories:

```powershell
.\tools\sync-shared-suite.ps1
```

Oder direkt mit PHP:

```powershell
C:\xampp\php\php.exe .\tools\sync-shared-suite.php --check
```

Ein einzelnes Ziel und eine maschinenlesbare Ausgabe:

```powershell
.\tools\sync-shared-suite.ps1 -Target northstaff -Json
```

Exitcode `0` bedeutet aktuell, `2` bedeutet fehlende, veraltete oder
konfliktbehaftete Zieldateien. Weitere Exitcodes zeigt `--help`.

## Bewusst synchronisieren

Der normale Rollout sollte zuerst NorthStaff als Testziel und danach RailTime
verwenden:

```powershell
.\tools\sync-shared-suite.ps1 -Apply -Target northstaff
.\tools\sync-shared-suite.ps1 -Apply -Target railtime
```

Wer beide Ziele in einem vorab vollständig geprüften Lauf aktualisieren will,
ruft `-Apply` ohne `-Target` auf. Der Schreibvorgang startet nur, wenn kein
unbekannter Ziel-Hash und kein Quellfehler vorliegt.

## Eine gemeinsame Datei ändern

1. Datei ausschließlich unter `shared-suite/source/` ändern.
2. Den bisherigen `sha256` der Datei nach `allowedPreviousSha256` übernehmen.
3. Den neuen Hash mit `Get-FileHash -Algorithm SHA256` berechnen und als
   `sha256` eintragen.
4. `suite.version` im Inventar erhöhen.
5. PHP-Syntax und den Dry-Run für beide Ziele prüfen.
6. NorthStaff anwenden und funktional testen, danach RailTime anwenden.

Ein Ziel mit einem nicht freigegebenen Hash wird nicht überschrieben. Die
Abweichung muss zuerst geprüft und entweder in die Shared-Source übernommen
oder bewusst außerhalb des gemeinsamen Umfangs gehalten werden.

## Ehrliche Grenze

Dies ist eine lokale gemeinsame Quelle mit reproduzierbarer Hash-Prüfung. Sie
ist noch kein vollständiges J-Suite-Release, kein bestätigter öffentlicher
Joomla-Updatefeed und keine automatische Installation. Das vorhandene
Paketprojekt bleibt gesperrt, solange die noch kundenspezifischen Erweiterungen
nicht produktneutral in diese Quelle überführt und separat paketiert wurden.
