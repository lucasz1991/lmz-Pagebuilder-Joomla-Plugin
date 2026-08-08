# LMZ Builder Suite — Joomla-Paket & Update-Quelle

Ein installierbares Joomla-Paket, das die komplette LMZ-Builder-Suite bündelt und
über einen eigenen Update-Server auf dem eigenen Host update-fähig macht — für
mehrere Projekte, ohne Lizenzkey.

## Inhalt des Bundles (`pkg_lmzbuilder`)

| Extension | Typ | Element |
|---|---|---|
| Page Builder | Komponente | `com_lmzpagebuilder` |
| Studio Modules (Deutschlandkarten) | Komponente | `com_lmzstudiomodules` |
| Content Studio API | Komponente | `com_lmzcontentapi` |
| Frontend-Template | Template (site) | `lmz_builder` |
| Admin-Template | Template (admin) | `lmz_builder_admin` |
| QuickIcon-Plugin | Plugin | `quickicon/lmzpagebuilder` |

Ein Klick installiert alles; ein Update aktualisiert die ganze Suite.

## Verzeichnisse

- `build.php` — baut das Paket aus dem laufenden Joomla-Install neu (wiederholbar).
- `dist/` — Ergebnis: `pkg_lmzbuilder-<version>.zip` (das installierbare Paket) und
  die einzelnen Extension-ZIPs.
- `updates/` — **das wird auf den Update-Server geladen** (siehe unten).

## Neu bauen

```bash
cd Website/lmz-Pagebuilder-Joomla-Plugin
php build.php            # baut Version 1.0.0
php build.php 1.0.1      # baut eine neue Version
```

`build.php` liest die Extensions aus dem Live-Install
(`Website/railtime-joomla-website`), rekonstruiert die installierbaren ZIPs,
schnürt das `pkg`-Bundle und erzeugt die Update-Server-Dateien. Ausgeschlossen
wird bewusst der Fremdkörper `media/com_lmzpagebuilder/updates/` (ein 54-MB-ZIP
eines früheren Package-Versuchs — kann im Live-Install gelöscht werden).

## Installieren (in jedem Projekt)

Joomla-Admin → **System → Installieren → Erweiterungen → Paketdatei hochladen** →
`dist/pkg_lmzbuilder-<version>.zip` hochladen. Beim Installieren registriert
Joomla automatisch die Update-Quelle (siehe `<updateservers>` im Paketmanifest),
sodass künftige Updates gefunden werden.

> Hinweis: Über einen bestehenden Install (wie dieses RailTime-Projekt, in dem die
> Extensions bereits einzeln vorhanden sind) einmal das Paket installieren —
> dadurch werden alle Teile als verwaltete Suite zusammengefasst und der
> Update-Check aktiviert.

## Update-Server hosten

Den **Inhalt von `updates/`** auf den Server unter dieser Basis-URL ablegen:

```
https://src.follow-flow.de/lmzbuilder/
├── pkg_lmzbuilder.xml              (Update-XML, die Joomla pollt)
└── packages/
    └── pkg_lmzbuilder-<version>.zip (das herunterladbare Paket)
```

Kein Lizenzkey, keine Zugangssperre — frei ladbar. Die URL ist bereits in den
Paketmanifesten hinterlegt.

## Auto-Updates

- Joomla prüft die Update-Quelle regelmäßig und **meldet** verfügbare Updates unter
  *System → Aktualisieren → Erweiterungen*. Ein Klick installiert die neue Suite.
- Für **automatische Prüfung/Benachrichtigung** in *System → Planer (Scheduled Tasks)*
  die Aufgabe **„Update: Aktualisierungsinformationen abrufen"** (bzw.
  „Aktualisierungsbenachrichtigung") aktivieren. Joomla installiert Updates aus
  Sicherheitsgründen nicht vollständig unbeaufsichtigt; die Quelle + Benachrichtigung
  sind eingerichtet, der finale Klick bleibt bewusst manuell.

## Neue Version veröffentlichen

1. `php build.php 1.0.1`
2. `updates/pkg_lmzbuilder.xml` **und** `updates/packages/pkg_lmzbuilder-1.0.1.zip`
   auf den Update-Server laden (die XML immer überschreiben — sie nennt die
   aktuellste Version).
3. In den Projekten meldet Joomla das Update automatisch.

## Lizenz-Hinweis (wichtig)

Dieses Bundle enthält **FontAwesome Pro** (im Frontend-Template und in der
Page-Builder-Media). Das ist ausschließlich für die **eigenen Projekte** unter
einer gültigen FA-Pro-Lizenz zulässig. Der Update-Server darf **nicht öffentlich**
verlinkt/frei zugänglich gemacht werden — sonst wäre die Weitergabe der Pro-Fonts
ein Lizenzverstoß. Für eine wirklich öffentliche Verteilung müssten die Pro-Fonts
durch FA Free ersetzt werden (`build.php` könnte das per Schalter erledigen).
