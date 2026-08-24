# LMZ Builder Suite — Produktaufbau

Dieses Repository entwickelt die LMZ-Builder-Suite zu einem eigenständigen
Joomla-Produkt. Der Zielzustand ist ein installierbares Paket mit geprüftem
Aktualisierungsdienst. Aktuell freigegeben ist nur die lokale, hash-geprüfte
Shared-Source für den generischen J-Suite-Menü-Plugin; ein öffentliches Paket
und automatische Installationen sind noch nicht freigegeben.

Ziel ist ein **eigenständiges Produkt**: installierbar auf beliebigen
Joomla-5.4-Seiten, nicht nur auf der Seite, aus der es entstanden ist.

---

## Aufbau des Repos

| Ordner | Zweck |
|---|---|
| `server/` | Vorbereitete Dateien für den vorgesehenen Aktualisierungsdienst; ein aktuelles Live-Deployment ist hier nicht belegt. Siehe [server/README.md](server/README.md). |
| `shared-suite/` | Versionierte, installationsneutrale Shared-Source mit SHA-256-Inventar. Siehe [shared-suite/README.md](shared-suite/README.md). |
| `tools/` | Sicheres Check-/Sync-Werkzeug; standardmäßig nur lesend, Schreiben ausschließlich mit `--apply`. |
| `build.php` | Baut die installierbaren Archive und das Paket. |
| `dist/` | Bauergebnis. Nicht versioniert — entsteht bei jedem Lauf neu. |

---

## Stand der Arbeit

Die serverseitige Dienst-Implementierung ist vorbereitet; ihr aktueller
Live-Deploymentstatus ist in diesem Repository nicht belegt. Das
Produktpaket ist **noch nicht freigegeben** — eine Prüfung hat Stellen
gefunden, an denen die
Suite auf ihre Ursprungsseite festgelegt ist und auf einer fremden Installation
Schaden anrichten würde:

| Befund | Wirkung auf einer fremden Seite |
|---|---|
| Der Installer der Kartenkomponente legt 83 fremde Standorte samt Anschrift an | Fremddaten in der Kundendatenbank |
| Das Paket bündelt ein Bewerbungsformular mit fester Empfängeradresse | Bewerbungen gehen an das falsche Unternehmen |
| Das Paket bündelt 66 MB ortsgebundenes Bildmaterial | Unnötige Last, fremde Inhalte |
| Der SEO-Crawler ist fest auf eine Domain verriegelt | Wirft dort eine Ausnahme, Auswertung unbenutzbar |
| Die Editor-Oberfläche lädt ihr Aussehen aus einem bestimmten Template | Ohne dieses Template bleibt der Editor ungestaltet |
| `postflight()` löscht ungefragt Joomla-Standarddaten | Eingriff in eine fremde Installation |

Deshalb liegt unter `server/lmzbuilder/pkg_lmzbuilder.xml` bewusst ein
**leeres** Versionsverzeichnis: Joomla liest daraus „nichts Neues". Ein Eintrag,
der auf ein noch nicht ausgeliefertes Paket zeigt, würde auf jeder
angeschlossenen Seite eine fehlschlagende Aktualisierung melden.

Für RailTime und NorthPaxx gibt es zusätzlich eine sichere lokale
Shared-Source-Grundlage. Sie verwaltet zunächst ausschließlich den generischen
J-Suite-Menü-Plugin und verändert weder Paket-IDs noch Update-Feeds. Details und
Rolloutbefehle stehen unter [shared-suite/README.md](shared-suite/README.md).

---

## Lizenzlage — bitte lesen

Die Suite verwendet **FontAwesome Pro**. Das ist kommerziell lizenziert und
darf nicht weitergegeben werden.

> **Diese Dateien lagen bereits offen.** Bis zur Bereinigung enthielt die
> versionierte `dist/com_lmzpagebuilder.zip` 15 MB FontAwesome Pro, dazu 0,9 MB
> in `tpl_lmz_builder.zip` — in einem öffentlichen Repo, frei über
> `raw.githubusercontent.com` abrufbar.
>
> Die Dateien sind aus der Verfolgung genommen und die `.gitignore` hält sie
> künftig draußen. **Sie stecken aber weiterhin in der Git-Historie** und sind
> über ältere Commits weiter erreichbar. Um sie wirklich zu entfernen, muss die
> Historie umgeschrieben werden (`git filter-repo`) — das erzwingt einen
> `push --force`, und wer das Repo geklont hat, muss neu klonen.

Für das Produktpaket sind zwei Wege möglich:

1. **FontAwesome Free ausliefern**, Pro bleibt der eigenen Seite vorbehalten.
   Dann darf alles offen liegen.
2. **Pro ausliefern, aber hinter dem Schlüssel** des Torwächters. Das Repo
   bleibt frei von den Schriften; nur der Server kennt sie.

Der Torwächter unter `server/lmzbuilder/get.php` beherrscht beides.

---

## Aktualisierungsdienst in Kürze

```
https://src.follow-flow.de/lmzbuilder/
├── pkg_lmzbuilder.xml   ← frei abrufbar, nennt nur die Fassung
├── get.php              ← prüft den Schlüssel, liefert das Paket
└── packages/            ← gesperrt, nur über get.php erreichbar
```

Joomla holt die XML **ohne Anmeldung** — sie muss offen sein, sonst erfährt
keine Seite je von einer neuen Fassung. Das Paket dagegen darf geschützt sein:
Joomla hängt den unter *Extra Query* hinterlegten Schlüssel ausschließlich an
die Download-Adresse an, nie an den XML-Abruf.

Einrichtung Schritt für Schritt: [server/README.md](server/README.md)

---

## Was noch fehlt

1. Produktschnitt: die sechs oben genannten Befunde auflösen
2. Quellen der Erweiterungen in dieses Repo überführen
3. Bau-Skript auf das Repo umstellen statt auf eine laufende Installation
4. Automatische Installation von Aktualisierungen — Joomla meldet sie von Haus
   aus nur, es spielt sie nicht ein
5. Anleitung für die Installation auf fremden Seiten
