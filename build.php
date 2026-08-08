<?php
/**
 * LMZ Builder Suite - Paket-Builder.
 *
 * Baut aus dem laufenden Joomla-Install die installierbaren Extension-ZIPs,
 * schnuert daraus ein pkg_lmzbuilder-Bundle (ein Klick installiert die ganze
 * Suite) und erzeugt die Update-Server-Dateien (Update-XML + Paket-Kopie).
 *
 * Ergebnis:
 *   dist/pkg_lmzbuilder-<version>.zip        - das installierbare Paket
 *   updates/pkg_lmzbuilder.xml               - die Update-XML (Joomla pollt sie)
 *   updates/packages/pkg_lmzbuilder-<version>.zip - das herunterladbare Paket
 * Den Ordner updates/ auf den Update-Server hochladen (siehe README.md):
 *   https://src.follow-flow.de/lmzbuilder/
 *
 * Aufruf:  php build.php            (baut Version aus VERSION unten)
 *          php build.php 1.0.1      (baut die angegebene Version)
 */

declare(strict_types=1);

$VERSION = $argv[1] ?? '1.0.0';
$UPDATE_BASE = 'https://src.follow-flow.de/lmzbuilder/';

$PKG_DIR = __DIR__;                                   // Website/lmz-Pagebuilder-Joomla-Plugin
$JROOT = dirname(__DIR__) . '/railtime-joomla-website'; // Live-Joomla
$WORK = $PKG_DIR . '/.work';
$DIST = $PKG_DIR . '/dist';
$UPDATES = $PKG_DIR . '/updates';
$UPD_PKGS = $UPDATES . '/packages';

if (!is_dir($JROOT)) {
    fwrite(STDERR, "Joomla-Root nicht gefunden: $JROOT\n");
    exit(1);
}

/* Extensions der Suite. layout = [ZielordnerImZip => QuellordnerImLiveInstall].
   'manifest' wird zusaetzlich in die Zip-Wurzel gelegt. */
$EXTENSIONS = [
    'com_lmzpagebuilder' => [
        'type' => 'component',
        'zip' => 'com_lmzpagebuilder.zip',
        'manifest' => 'administrator/components/com_lmzpagebuilder/lmzpagebuilder.xml',
        'pkgfile' => ['type' => 'component', 'id' => 'com_lmzpagebuilder'],
        'layout' => [
            'admin' => 'administrator/components/com_lmzpagebuilder',
            'site' => 'components/com_lmzpagebuilder',
            'media' => 'media/com_lmzpagebuilder',
        ],
        // Fremdkoerper: 54-MB-Zip eines frueheren Package-Versuchs, liegt im
        // Media-Ordner, ist kein Komponenten-Asset -> nicht mitpaketieren.
        'exclude' => ['media/updates'],
    ],
    'com_lmzstudiomodules' => [
        'type' => 'component',
        'zip' => 'com_lmzstudiomodules.zip',
        'manifest' => 'administrator/components/com_lmzstudiomodules/lmzstudiomodules.xml',
        'pkgfile' => ['type' => 'component', 'id' => 'com_lmzstudiomodules'],
        'layout' => [
            'admin' => 'administrator/components/com_lmzstudiomodules',
            'site' => 'components/com_lmzstudiomodules',
            'media' => 'media/com_lmzstudiomodules',
        ],
    ],
    'com_lmzcontentapi' => [
        'type' => 'component',
        'zip' => 'com_lmzcontentapi.zip',
        'manifest' => 'administrator/components/com_lmzcontentapi/lmzcontentapi.xml',
        'pkgfile' => ['type' => 'component', 'id' => 'com_lmzcontentapi'],
        'layout' => [
            'admin' => 'administrator/components/com_lmzcontentapi',
            'site' => 'components/com_lmzcontentapi',
        ],
    ],
    'tpl_lmz_builder' => [
        'type' => 'template',
        'zip' => 'tpl_lmz_builder.zip',
        'manifest' => 'templates/lmz_builder/templateDetails.xml',
        'pkgfile' => ['type' => 'template', 'id' => 'lmz_builder', 'client' => 'site'],
        'layout' => ['' => 'templates/lmz_builder'],   // ganzer Templateordner in die Zip-Wurzel
    ],
    'tpl_lmz_builder_admin' => [
        'type' => 'template',
        'zip' => 'tpl_lmz_builder_admin.zip',
        'manifest' => 'administrator/templates/lmz_builder_admin/templateDetails.xml',
        'pkgfile' => ['type' => 'template', 'id' => 'lmz_builder_admin', 'client' => 'administrator'],
        'layout' => [
            '' => 'administrator/templates/lmz_builder_admin',
            'media' => 'media/templates/administrator/lmz_builder_admin',
        ],
    ],
    'plg_quickicon_lmzpagebuilder' => [
        'type' => 'plugin',
        'zip' => 'plg_quickicon_lmzpagebuilder.zip',
        'manifest' => 'plugins/quickicon/lmzpagebuilder/lmzpagebuilder.xml',
        'pkgfile' => ['type' => 'plugin', 'id' => 'lmzpagebuilder', 'group' => 'quickicon'],
        'layout' => ['' => 'plugins/quickicon/lmzpagebuilder'],
    ],
    'plg_system_lmzbuildermenu' => [
        'type' => 'plugin',
        'zip' => 'plg_system_lmzbuildermenu.zip',
        'manifest' => 'plugins/system/lmzbuildermenu/lmzbuildermenu.xml',
        'pkgfile' => ['type' => 'plugin', 'id' => 'lmzbuildermenu', 'group' => 'system'],
        'layout' => ['' => 'plugins/system/lmzbuildermenu'],
    ],
    'mod_lmzpagebuilder_dashboard' => [
        'type' => 'module',
        'zip' => 'mod_lmzpagebuilder_dashboard.zip',
        'manifest' => 'administrator/modules/mod_lmzpagebuilder_dashboard/mod_lmzpagebuilder_dashboard.xml',
        'pkgfile' => ['type' => 'module', 'id' => 'mod_lmzpagebuilder_dashboard', 'client' => 'administrator'],
        'layout' => ['' => 'administrator/modules/mod_lmzpagebuilder_dashboard'],
    ],
];

/* ---------------------------------------------------------------- Helfer */

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

const AUSSCHLUSS = ['.git', 'node_modules', '.lmzdev', '.DS_Store', 'Thumbs.db'];

function copyTree(string $src, string $dst): int
{
    if (!is_dir($src)) {
        return 0;
    }
    @mkdir($dst, 0775, true);
    $n = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($src) + 1);
        $relFirst = explode(DIRECTORY_SEPARATOR, str_replace('/', DIRECTORY_SEPARATOR, $rel))[0];
        if (in_array($relFirst, AUSSCHLUSS, true) || in_array($f->getFilename(), AUSSCHLUSS, true)) {
            continue;
        }
        $target = $dst . '/' . $rel;
        if ($f->isDir()) {
            @mkdir($target, 0775, true);
        } else {
            @mkdir(dirname($target), 0775, true);
            copy($f->getPathname(), $target);
            $n++;
        }
    }
    return $n;
}

function zipDir(string $dir, string $zipPath): int
{
    @unlink($zipPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        throw new RuntimeException("Zip nicht erstellbar: $zipPath");
    }
    $n = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        if ($f->isDir()) {
            $zip->addEmptyDir($rel);
        } else {
            $zip->addFile($f->getPathname(), $rel);
            $n++;
        }
    }
    $zip->close();
    return $n;
}

/* ---------------------------------------------------------------- Build */

echo "LMZ Builder Suite - Build v{$VERSION}\n";
rrmdir($WORK);
@mkdir($WORK, 0775, true);
@mkdir($DIST, 0775, true);
@mkdir($UPD_PKGS, 0775, true);

/* Version in Manifesten (nur im BUILD-Abzug, nicht im Live-Install) auf die
   Paketversion setzen und <updateservers> injizieren - so tragen kuenftige
   Installationen die Update-Quelle. */
$updateXmlUrl = $UPDATE_BASE . 'pkg_lmzbuilder.xml';

$pkgFiles = [];
foreach ($EXTENSIONS as $key => $ext) {
    echo "  Baue {$key} ...\n";
    $extWork = $WORK . '/' . $key;
    @mkdir($extWork, 0775, true);

    $dateien = 0;
    foreach ($ext['layout'] as $zipSub => $srcRel) {
        $src = $JROOT . '/' . $srcRel;
        $dst = $zipSub === '' ? $extWork : $extWork . '/' . $zipSub;
        $dateien += copyTree($src, $dst);
    }

    /* Ausgeschlossene Unterpfade (Fremdkoerper) nach dem Kopieren entfernen. */
    foreach ($ext['exclude'] ?? [] as $rel) {
        rrmdir($extWork . '/' . $rel);
    }

    /* Manifest in die Zip-Wurzel (bei Komponenten/Plugins liegt es sonst im
       admin-/Quellordner; Joomla erwartet es in der Paketwurzel). */
    $manifestSrc = $JROOT . '/' . $ext['manifest'];
    $manifestName = basename($ext['manifest']);
    if (is_file($manifestSrc)) {
        copy($manifestSrc, $extWork . '/' . $manifestName);
    }

    /* Version im Abzug auf die Paketversion setzen (Live-Manifest bleibt
       unberuehrt). KEIN Per-Extension-updateservers: aktualisiert wird ueber
       das Paket (pkg_lmzbuilder), das die Update-Quelle traegt. */
    $mf = $extWork . '/' . $manifestName;
    if (is_file($mf)) {
        $xml = file_get_contents($mf);
        $xml = preg_replace('~<version>[^<]*</version>~', "<version>{$VERSION}</version>", $xml, 1);
        file_put_contents($mf, $xml);
    }

    $zipPath = $DIST . '/' . $ext['zip'];
    $count = zipDir($extWork, $zipPath);
    printf("     %-34s %5d Dateien, %6.0f KB\n", $ext['zip'], $count, filesize($zipPath) / 1024);
    $pkgFiles[] = ['zip' => $ext['zip']] + $ext['pkgfile'];
}

/* pkg-Manifest erzeugen */
$fileLines = '';
foreach ($pkgFiles as $pf) {
    $attr = 'type="' . $pf['type'] . '" id="' . $pf['id'] . '"';
    if (isset($pf['client'])) {
        $attr .= ' client="' . $pf['client'] . '"';
    }
    if (isset($pf['group'])) {
        $attr .= ' group="' . $pf['group'] . '"';
    }
    $fileLines .= "        <file {$attr}>{$pf['zip']}</file>\n";
}

$pkgManifest = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<extension type="package" method="upgrade">
    <name>LMZ Builder Suite</name>
    <packagename>lmzbuilder</packagename>
    <version>{$VERSION}</version>
    <creationDate>2026-08</creationDate>
    <author>LMZ Media</author>
    <authorEmail>info@lmz-media.de</authorEmail>
    <authorUrl>https://lmz-media.de</authorUrl>
    <packager>LMZ Media</packager>
    <packagerurl>https://lmz-media.de</packagerurl>
    <copyright>(C) 2026 LMZ Media</copyright>
    <license>GNU General Public License version 2 or later</license>
    <description>LMZ_BUILDER_SUITE_DESC</description>
    <files>
{$fileLines}    </files>
    <updateservers>
        <server type="extension" name="LMZ Builder Suite">{$updateXmlUrl}</server>
    </updateservers>
</extension>
XML;

$pkgWork = $WORK . '/pkg';
@mkdir($pkgWork, 0775, true);
file_put_contents($pkgWork . '/pkg_lmzbuilder.xml', $pkgManifest);
foreach ($EXTENSIONS as $ext) {
    copy($DIST . '/' . $ext['zip'], $pkgWork . '/' . $ext['zip']);
}

$pkgZipName = "pkg_lmzbuilder-{$VERSION}.zip";
$pkgZip = $DIST . '/' . $pkgZipName;
$cnt = zipDir($pkgWork, $pkgZip);
printf("  Paket %-30s %d Eintraege, %6.0f KB\n", $pkgZipName, $cnt, filesize($pkgZip) / 1024);

/* Update-Server-Dateien */
copy($pkgZip, $UPD_PKGS . '/' . $pkgZipName);
$downloadUrl = $UPDATE_BASE . 'packages/' . $pkgZipName;

/* Extension-Update-XML (Detailformat) - die Update-Quelle des Pakets. */
$detailXml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<updates>
    <update>
        <name>LMZ Builder Suite</name>
        <description>Page Builder, Studio Modules, Content API, Frontend- und Admin-Template sowie QuickIcon-Plugin.</description>
        <element>pkg_lmzbuilder</element>
        <type>package</type>
        <version>{$VERSION}</version>
        <infourl title="LMZ Builder Suite">{$UPDATE_BASE}</infourl>
        <downloads>
            <downloadurl type="full" format="zip">{$downloadUrl}</downloadurl>
        </downloads>
        <tags><tag>stable</tag></tags>
        <maintainer>LMZ Media</maintainer>
        <maintainerurl>https://lmz-media.de</maintainerurl>
        <targetplatform name="joomla" version="5\\.[0-9]+" />
        <php_minimum>8.1</php_minimum>
    </update>
</updates>
XML;
file_put_contents($UPDATES . '/pkg_lmzbuilder.xml', $detailXml);

rrmdir($WORK);

echo "\nFertig.\n";
echo "  Installierbar:  dist/{$pkgZipName}\n";
echo "  Update-Server:  updates/  -> hochladen nach {$UPDATE_BASE}\n";
echo "     updates/pkg_lmzbuilder.xml (Update-XML, wird von Joomla gepollt)\n";
echo "     updates/packages/{$pkgZipName}\n";
