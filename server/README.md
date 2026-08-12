# Auslieferungsserver `src.follow-flow.de`

Der Inhalt dieses Ordners wird auf den Server gespielt und beantwortet dort die
Aktualisierungsanfragen aller Joomla-Seiten, auf denen die Suite installiert
ist.

```
server/lmzbuilder/          →  https://src.follow-flow.de/lmzbuilder/
├── pkg_lmzbuilder.xml      ← frei abrufbar: welche Fassung es gibt
├── get.php                 ← Torwächter: prüft den Schlüssel, liefert das Paket
├── keys.php                ← die gültigen Schlüssel (NICHT im Repo)
├── keys.php.beispiel       ← Vorlage dafür
└── packages/
    ├── .htaccess           ← sperrt den Direktzugriff
    └── pkg_lmzbuilder-<Fassung>.zip
```

## Warum die XML offen ist und das Paket nicht

Joomla holt die Versions-XML **ohne jede Anmeldung**. Wer sie schützt, bekommt
auf keiner Seite je eine Aktualisierungsmeldung. Sie enthält aber auch nichts
Schützenswertes — nur die Nummer der neuesten Fassung und die Downloadadresse.

Das **Paket** kann lizenzpflichtiges Material enthalten. Joomla trennt genau
hier: das Feld `extra_query` eines Aktualisierungsdienstes wird
ausschließlich an die Download-Adresse angehängt, nie an den Abruf der XML.
Metadaten offen, Paket hinter einem Schlüssel — und Joomla muss dafür nichts
Besonderes können.

---

## Einrichtung in Plesk

### 1. Subdomain anlegen

*Websites & Domains → Subdomain hinzufügen*

| Feld | Wert |
|---|---|
| Subdomain | `src` unter `follow-flow.de` |
| Dokumentenstamm | Vorgabe belassen (eigenes Verzeichnis) |

### 2. SSL einschalten — zwingend

*SSL/TLS-Zertifikate → Kostenloses Zertifikat von Let's Encrypt installieren*

Joomla prüft das Zertifikat und bricht sonst ab. Ohne gültiges Zertifikat
findet **keine** Seite eine Aktualisierung.

Danach *Hosting-Einstellungen → Permanente SEO-sichere 301-Weiterleitung von
HTTP zu HTTPS* aktivieren.

### 3. PHP sicherstellen

*Hosting-Einstellungen → PHP-Unterstützung*: PHP 8.1 oder neuer. Der
Torwächter braucht es; die XML allein käme ohne aus.

### 4. Auslieferung per Git

*Git → Repository hinzufügen*

| Feld | Wert |
|---|---|
| Fernrepository | `https://github.com/lucasz1991/lmz-Pagebuilder-Joomla-Plugin.git` |
| Zweig | `master` |
| Auslieferungsmodus | Automatisch bei Push |
| **Auslieferungspfad** | `/httpdocs` |

Wichtig ist die letzte Zeile: Plesk spielt standardmäßig das ganze Repo aus.
Damit unter `/lmzbuilder/` genau dieser Ordner landet, den Auslieferungspfad
auf `/httpdocs` setzen und in Plesk unter *Zusätzliche Auslieferungsaktionen*
eintragen:

```bash
rsync -a --delete /var/www/vhosts/follow-flow.de/src.follow-flow.de/git-repo/server/lmzbuilder/ /var/www/vhosts/follow-flow.de/src.follow-flow.de/httpdocs/lmzbuilder/
```

Den genauen Repo-Pfad zeigt Plesk auf der Git-Seite an — bitte von dort
übernehmen.

> Wer es ohne Zusatzaktion mag: den Auslieferungspfad direkt auf
> `/httpdocs/lmzbuilder` setzen und in Kauf nehmen, dass dort auch `README.md`
> und `build.php` liegen. Sie schaden nicht, sind aber unnötig sichtbar.

### 5. Schlüssel anlegen

Einmalig auf dem Server, **nicht** im Repo:

```bash
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

Die Ausgabe in `httpdocs/lmzbuilder/keys.php` eintragen — als Vorlage dient
`keys.php.beispiel`:

```php
return [
    'schluessel' => ['der-erzeugte-schluessel'],
    'ohne_schluessel' => false,
];
```

Mehrere Schlüssel sind erlaubt: einer je Kundenseite, dann lässt sich einer
einzeln zurücknehmen, ohne die anderen zu stören.

> Enthält das Paket kein lizenzpflichtiges Material, kann `ohne_schluessel` auf
> `true` stehen. Dann entfällt die Prüfung und Joomla braucht keinen Eintrag.

### 6. Verzeichnisschutz prüfen

Die mitgelieferten `.htaccess`-Dateien erledigen das. Falls Plesk mit **nginx
ohne Apache** läuft, greifen sie nicht — dann in
*Apache & nginx Einstellungen → Zusätzliche nginx-Anweisungen* eintragen:

```nginx
location ~ ^/lmzbuilder/packages/ { deny all; }
location ~ ^/lmzbuilder/keys\.php  { deny all; }
```

---

## Prüfen, ob es läuft

```bash
curl -sI https://src.follow-flow.de/lmzbuilder/pkg_lmzbuilder.xml
```
→ `HTTP/2 200`, Inhaltstyp `text/xml`

```bash
curl -sI "https://src.follow-flow.de/lmzbuilder/packages/pkg_lmzbuilder-1.0.0.zip"
```
→ `403` — die Sperre greift

```bash
curl -sI "https://src.follow-flow.de/lmzbuilder/get.php?f=pkg_lmzbuilder-1.0.0.zip&key=DEIN_SCHLUESSEL"
```
→ `200`, Inhaltstyp `application/zip`

Ohne Schlüssel muss dieselbe Adresse mit `403` antworten.

---

## In der Ziel-Joomla-Installation

Nach dem Installieren des Pakets trägt Joomla den Aktualisierungsdienst selbst
ein. Einmalig ist nur der Schlüssel nachzureichen:

*System → Aktualisierungs-Server → LMZ Builder Suite → Feld „Extra Query"*

```
key=DEIN_SCHLUESSEL
```

Danach findet und lädt die Seite Aktualisierungen selbstständig.
