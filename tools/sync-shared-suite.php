<?php

declare(strict_types=1);

const LMZ_SYNC_OK = 0;
const LMZ_SYNC_DIFFERENCES = 2;
const LMZ_SYNC_INVALID = 3;
const LMZ_SYNC_CONFLICT = 4;
const LMZ_SYNC_IO_ERROR = 5;

/**
 * Read-only by default. This tool only manages files explicitly listed in
 * shared-suite/inventory.json. It never deletes target files and never writes
 * Joomla package identities or update-feed metadata.
 */

try {
    $options = parseArguments(array_slice($argv, 1));
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, 'Argumentfehler: ' . $exception->getMessage() . PHP_EOL . PHP_EOL);
    printUsage(STDERR);
    exit(LMZ_SYNC_INVALID);
}

if ($options['help']) {
    printUsage(STDOUT);
    exit(LMZ_SYNC_OK);
}

$repositoryRoot = dirname(__DIR__);
$inventoryPath = $repositoryRoot . DIRECTORY_SEPARATOR . 'shared-suite' . DIRECTORY_SEPARATOR . 'inventory.json';

try {
    $inventory = loadInventory($inventoryPath);
    $report = inspectSuite($repositoryRoot, $inventory, $options);
} catch (Throwable $exception) {
    emitFatal($options['json'], $exception->getMessage());
    exit(LMZ_SYNC_INVALID);
}

if ($options['mode'] === 'apply') {
    if ($report['sourceErrors'] !== [] || $report['targetErrors'] !== []) {
        $report['applyBlocked'] = 'Quell- oder Zielpruefung fehlgeschlagen.';
    } elseif ($report['conflicts'] > 0) {
        $report['applyBlocked'] = 'Nicht erkannte lokale Aenderungen vorhanden. Kein Ziel wurde veraendert.';
    } else {
        applyCandidates($report);
    }
}

emitReport($report, $options['json']);

if ($report['sourceErrors'] !== [] || $report['targetErrors'] !== []) {
    exit(LMZ_SYNC_INVALID);
}

if ($report['applyBlocked'] !== null) {
    exit(LMZ_SYNC_CONFLICT);
}

if ($report['applyErrors'] !== []) {
    exit(LMZ_SYNC_IO_ERROR);
}

if ($options['mode'] === 'check' && ($report['missing'] > 0 || $report['outdated'] > 0 || $report['conflicts'] > 0)) {
    exit(LMZ_SYNC_DIFFERENCES);
}

exit(LMZ_SYNC_OK);

/**
 * @param array<int, string> $arguments
 * @return array{mode:string,targets:array<int,string>,roots:array<string,string>,json:bool,help:bool}
 */
function parseArguments(array $arguments): array
{
    $options = [
        'mode' => 'check',
        'targets' => [],
        'roots' => [],
        'json' => false,
        'help' => false,
    ];
    $explicitMode = null;

    foreach ($arguments as $argument) {
        if ($argument === '--check') {
            if ($explicitMode !== null && $explicitMode !== 'check') {
                throw new InvalidArgumentException('--check und --apply duerfen nicht kombiniert werden.');
            }
            $explicitMode = 'check';
            $options['mode'] = 'check';
            continue;
        }

        if ($argument === '--apply') {
            if ($explicitMode !== null && $explicitMode !== 'apply') {
                throw new InvalidArgumentException('--check und --apply duerfen nicht kombiniert werden.');
            }
            $explicitMode = 'apply';
            $options['mode'] = 'apply';
            continue;
        }

        if ($argument === '--json') {
            $options['json'] = true;
            continue;
        }

        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }

        if (str_starts_with($argument, '--target=')) {
            $target = trim(substr($argument, strlen('--target=')));
            if ($target === '') {
                throw new InvalidArgumentException('--target benoetigt eine Ziel-ID.');
            }
            $options['targets'][] = $target;
            continue;
        }

        if (str_starts_with($argument, '--root=')) {
            $definition = substr($argument, strlen('--root='));
            $separator = strpos($definition, '=');
            if ($separator === false) {
                throw new InvalidArgumentException('--root erwartet --root=ziel-id=pfad.');
            }
            $target = trim(substr($definition, 0, $separator));
            $path = trim(substr($definition, $separator + 1));
            if ($target === '' || $path === '') {
                throw new InvalidArgumentException('--root erwartet eine Ziel-ID und einen Pfad.');
            }
            $options['roots'][$target] = $path;
            continue;
        }

        throw new InvalidArgumentException('Unbekannte Option: ' . $argument);
    }

    $options['targets'] = array_values(array_unique($options['targets']));

    return $options;
}

/** @return array<string, mixed> */
function loadInventory(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Inventar fehlt: ' . $path);
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Inventar kann nicht gelesen werden: ' . $path);
    }

    try {
        $inventory = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Inventar ist kein gueltiges JSON: ' . $exception->getMessage(), 0, $exception);
    }

    foreach (['schemaVersion', 'suite', 'sourceRoot', 'targets', 'files', 'guardrails'] as $required) {
        if (!array_key_exists($required, $inventory)) {
            throw new RuntimeException('Inventarfeld fehlt: ' . $required);
        }
    }

    if ($inventory['schemaVersion'] !== 1) {
        throw new RuntimeException('Nicht unterstuetzte schemaVersion. Erwartet: 1.');
    }

    if (!is_array($inventory['targets']) || !is_array($inventory['files']) || !is_array($inventory['guardrails'])) {
        throw new RuntimeException('targets, files und guardrails muessen JSON-Arrays bzw. -Objekte sein.');
    }

    return $inventory;
}

/**
 * @param array<string, mixed> $inventory
 * @param array{mode:string,targets:array<int,string>,roots:array<string,string>,json:bool,help:bool} $options
 * @return array<string, mixed>
 */
function inspectSuite(string $repositoryRoot, array $inventory, array $options): array
{
    $resolvedRepositoryRoot = realpath($repositoryRoot);
    if ($resolvedRepositoryRoot === false || !is_dir($resolvedRepositoryRoot)) {
        throw new RuntimeException('Produktrepository kann nicht kanonisch aufgeloest werden.');
    }

    $managedSourceRoot = realpath(
        $resolvedRepositoryRoot . DIRECTORY_SEPARATOR . 'shared-suite' . DIRECTORY_SEPARATOR . 'source'
    );
    if ($managedSourceRoot === false
        || !is_dir($managedSourceRoot)
        || !isWithin($managedSourceRoot, $resolvedRepositoryRoot)) {
        throw new RuntimeException('Die kanonische Shared-Source muss innerhalb des Produktrepositories liegen.');
    }

    $sourceRootCandidate = makeAbsolute((string) $inventory['sourceRoot'], $repositoryRoot);
    $sourceRoot = realpath($sourceRootCandidate);
    if ($sourceRoot === false || !is_dir($sourceRoot)) {
        throw new RuntimeException('Shared-Source-Verzeichnis fehlt: ' . $sourceRootCandidate);
    }

    if (!samePath($sourceRoot, $managedSourceRoot)) {
        throw new RuntimeException('sourceRoot muss exakt auf shared-suite/source im Produktrepository zeigen.');
    }

    $configuredTargets = [];
    foreach ($inventory['targets'] as $target) {
        if (!is_array($target) || !isset($target['id'], $target['defaultRoot'])) {
            throw new RuntimeException('Jedes Ziel benoetigt id und defaultRoot.');
        }
        $id = (string) $target['id'];
        if ($id === '' || isset($configuredTargets[$id])) {
            throw new RuntimeException('Ziel-ID fehlt oder ist doppelt: ' . $id);
        }
        $configuredTargets[$id] = $target;
    }

    foreach (array_keys($options['roots']) as $id) {
        if (!isset($configuredTargets[$id])) {
            throw new RuntimeException('Root-Override fuer unbekanntes Ziel: ' . $id);
        }
    }

    $selectedTargetIds = $options['targets'] === [] ? array_keys($configuredTargets) : $options['targets'];
    foreach ($selectedTargetIds as $id) {
        if (!isset($configuredTargets[$id])) {
            throw new RuntimeException('Unbekanntes Ziel: ' . $id);
        }
    }

    $sourceErrors = [];
    $sourceFiles = [];
    $seenTargets = [];
    $allowedPrefixes = array_map(
        static fn ($prefix): string => normalizeRelativePath((string) $prefix),
        (array) ($inventory['guardrails']['allowedTargetPrefixes'] ?? [])
    );

    foreach ($inventory['files'] as $index => $file) {
        if (!is_array($file) || !isset($file['source'], $file['target'], $file['sha256'])) {
            $sourceErrors[] = "Dateieintrag {$index} benoetigt source, target und sha256.";
            continue;
        }

        try {
            $sourceRelative = normalizeRelativePath((string) $file['source']);
            $targetRelative = normalizeRelativePath((string) $file['target']);
            validateManagedTarget($targetRelative, $allowedPrefixes);
        } catch (InvalidArgumentException $exception) {
            $sourceErrors[] = "Dateieintrag {$index}: " . $exception->getMessage();
            continue;
        }

        if (isset($seenTargets[$targetRelative])) {
            $sourceErrors[] = 'Doppeltes Ziel im Inventar: ' . $targetRelative;
            continue;
        }
        $seenTargets[$targetRelative] = true;

        $expectedHash = strtolower((string) $file['sha256']);
        if (!isSha256($expectedHash)) {
            $sourceErrors[] = 'Ungueltiger SHA-256 im Inventar: ' . $targetRelative;
            continue;
        }

        $allowedPrevious = [];
        foreach ((array) ($file['allowedPreviousSha256'] ?? []) as $previousHash) {
            $previousHash = strtolower((string) $previousHash);
            if (!isSha256($previousHash)) {
                $sourceErrors[] = 'Ungueltiger allowedPreviousSha256: ' . $targetRelative;
                continue 2;
            }
            $allowedPrevious[] = $previousHash;
        }

        $sourcePath = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sourceRelative);
        $resolvedSource = realpath($sourcePath);
        if ($resolvedSource === false || !is_file($resolvedSource) || !isWithin($resolvedSource, $sourceRoot)) {
            $sourceErrors[] = 'Shared-Source fehlt oder liegt ausserhalb des Source-Roots: ' . $sourceRelative;
            continue;
        }

        $actualHash = hash_file('sha256', $resolvedSource);
        if ($actualHash === false || strtolower($actualHash) !== $expectedHash) {
            $sourceErrors[] = sprintf(
                'Source-Hash stimmt nicht: %s (Inventar %s, Datei %s)',
                $sourceRelative,
                $expectedHash,
                $actualHash === false ? 'nicht lesbar' : strtolower($actualHash)
            );
            continue;
        }

        $contents = file_get_contents($resolvedSource);
        if ($contents === false) {
            $sourceErrors[] = 'Shared-Source nicht lesbar: ' . $sourceRelative;
            continue;
        }

        foreach ((array) ($inventory['guardrails']['forbiddenSourceText'] ?? []) as $needle) {
            if ($needle !== '' && stripos($contents, (string) $needle) !== false) {
                $sourceErrors[] = sprintf('Verbotener seitenspezifischer oder Feed-Text "%s" in %s.', $needle, $sourceRelative);
            }
        }

        $sourceFiles[] = [
            'sourceRelative' => $sourceRelative,
            'targetRelative' => $targetRelative,
            'sourcePath' => $resolvedSource,
            'sourceHash' => $expectedHash,
            'allowedPreviousSha256' => array_values(array_unique($allowedPrevious)),
        ];
    }

    $targets = [];
    $targetErrors = [];
    $counts = ['current' => 0, 'missing' => 0, 'outdated' => 0, 'conflicts' => 0];

    foreach ($selectedTargetIds as $targetId) {
        $configuration = $configuredTargets[$targetId];
        $configuredRoot = $options['roots'][$targetId] ?? (string) $configuration['defaultRoot'];
        $rootCandidate = makeAbsolute($configuredRoot, $repositoryRoot);
        $root = realpath($rootCandidate);

        $targetReport = [
            'id' => $targetId,
            'label' => (string) ($configuration['label'] ?? $targetId),
            'root' => $root === false ? $rootCandidate : $root,
            'valid' => false,
            'files' => [],
        ];

        if ($root === false || !is_dir($root)) {
            $targetErrors[] = "{$targetId}: Zielverzeichnis fehlt: {$rootCandidate}";
            $targets[] = $targetReport;
            continue;
        }

        $joomlaMarkers = ['configuration.php', 'administrator/index.php', 'libraries/src/Version.php'];
        $missingMarker = null;
        foreach ($joomlaMarkers as $marker) {
            if (!is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $marker))) {
                $missingMarker = $marker;
                break;
            }
        }

        if ($missingMarker !== null) {
            $targetErrors[] = "{$targetId}: Kein bestaetigtes Joomla-Root; Marker fehlt: {$missingMarker}";
            $targets[] = $targetReport;
            continue;
        }

        if (samePath($root, $repositoryRoot)) {
            $targetErrors[] = "{$targetId}: Produktrepo darf nicht als Joomla-Ziel verwendet werden.";
            $targets[] = $targetReport;
            continue;
        }

        $targetReport['valid'] = true;

        foreach ($sourceFiles as $sourceFile) {
            $targetPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sourceFile['targetRelative']);
            $fileReport = $sourceFile + ['targetPath' => $targetPath, 'targetHash' => null, 'status' => 'missing'];

            if (!is_file($targetPath)) {
                if (targetEntryExists($targetPath)) {
                    $fileReport['status'] = 'conflict';
                    $fileReport['reason'] = 'Zielpfad existiert, ist aber keine regulaere Datei.';
                    $counts['conflicts']++;
                } else {
                    try {
                        assertTargetParentWithinRoot($targetPath, $root);
                        $counts['missing']++;
                    } catch (RuntimeException $exception) {
                        $fileReport['status'] = 'conflict';
                        $fileReport['reason'] = $exception->getMessage();
                        $counts['conflicts']++;
                    }
                }
                $targetReport['files'][] = $fileReport;
                continue;
            }

            $resolvedTarget = realpath($targetPath);
            if ($resolvedTarget === false || !isWithin($resolvedTarget, $root)) {
                $fileReport['status'] = 'conflict';
                $fileReport['reason'] = 'Zieldatei liegt ausserhalb des Joomla-Roots.';
                $counts['conflicts']++;
                $targetReport['files'][] = $fileReport;
                continue;
            }

            $targetHash = hash_file('sha256', $resolvedTarget);
            $fileReport['targetHash'] = $targetHash === false ? null : strtolower($targetHash);

            if ($fileReport['targetHash'] === $sourceFile['sourceHash']) {
                $fileReport['status'] = 'current';
                $counts['current']++;
            } elseif ($fileReport['targetHash'] !== null && in_array($fileReport['targetHash'], $sourceFile['allowedPreviousSha256'], true)) {
                $fileReport['status'] = 'outdated';
                $counts['outdated']++;
            } else {
                $fileReport['status'] = 'conflict';
                $fileReport['reason'] = $fileReport['targetHash'] === null
                    ? 'SHA-256 konnte nicht berechnet werden.'
                    : 'Lokaler Hash ist weder aktuelle noch freigegebene Vorversion.';
                $counts['conflicts']++;
            }

            $targetReport['files'][] = $fileReport;
        }

        $targets[] = $targetReport;
    }

    return [
        'tool' => 'LMZ J-Suite Shared-Source Sync',
        'mode' => $options['mode'],
        'inventoryPath' => $repositoryRoot . DIRECTORY_SEPARATOR . 'shared-suite' . DIRECTORY_SEPARATOR . 'inventory.json',
        'suiteVersion' => (string) ($inventory['suite']['version'] ?? 'unbekannt'),
        'sourceRoot' => $sourceRoot,
        'sourceFiles' => $sourceFiles,
        'sourceErrors' => $sourceErrors,
        'targetErrors' => $targetErrors,
        'targets' => $targets,
        'current' => $counts['current'],
        'missing' => $counts['missing'],
        'outdated' => $counts['outdated'],
        'conflicts' => $counts['conflicts'],
        'applied' => [],
        'applyErrors' => [],
        'applyBlocked' => null,
    ];
}

/** @param array<string, mixed> $report */
function applyCandidates(array &$report): void
{
    foreach ($report['targets'] as &$target) {
        if (!$target['valid']) {
            continue;
        }

        foreach ($target['files'] as &$file) {
            if (!in_array($file['status'], ['missing', 'outdated'], true)) {
                continue;
            }

            try {
                installFileAtomically(
                    $file['sourcePath'],
                    $file['targetPath'],
                    $target['root'],
                    $file['status'] === 'missing',
                    $file['targetHash'],
                    $file['sourceHash']
                );
                $oldStatus = $file['status'];
                $file['status'] = 'applied';
                $file['targetHash'] = $file['sourceHash'];
                $report['applied'][] = [
                    'target' => $target['id'],
                    'path' => $file['targetRelative'],
                    'from' => $oldStatus,
                    'sha256' => $file['sourceHash'],
                ];
            } catch (Throwable $exception) {
                $file['status'] = 'apply-error';
                $file['reason'] = $exception->getMessage();
                $report['applyErrors'][] = $target['id'] . ': ' . $file['targetRelative'] . ': ' . $exception->getMessage();
            }
        }
        unset($file);
    }
    unset($target);
}

function installFileAtomically(
    string $source,
    string $target,
    string $targetRoot,
    bool $expectedMissing,
    ?string $expectedTargetHash,
    string $expectedSourceHash
): void
{
    assertTargetState($target, $targetRoot, $expectedMissing, $expectedTargetHash);

    $directory = dirname($target);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Zielordner konnte nicht erstellt werden.');
    }

    assertFinalTargetParentWithinRoot($target, $targetRoot);

    $token = bin2hex(random_bytes(8));
    $temporary = $target . '.lmz-sync-' . $token . '.tmp';
    $backup = $target . '.lmz-sync-' . $token . '.bak';

    $sourceHandle = fopen($source, 'rb');
    $temporaryHandle = fopen($temporary, 'x+b');
    if ($sourceHandle === false || $temporaryHandle === false) {
        if (is_resource($sourceHandle)) {
            fclose($sourceHandle);
        }
        if (is_resource($temporaryHandle)) {
            fclose($temporaryHandle);
        }
        @unlink($temporary);
        throw new RuntimeException('Temporäre Zieldatei konnte nicht exklusiv erstellt werden.');
    }

    $copiedBytes = stream_copy_to_stream($sourceHandle, $temporaryHandle);
    $flushed = fflush($temporaryHandle);
    fclose($sourceHandle);
    fclose($temporaryHandle);

    if ($copiedBytes === false || !$flushed) {
        @unlink($temporary);
        throw new RuntimeException('Shared-Source konnte nicht vollständig in die temporäre Datei kopiert werden.');
    }

    $temporaryHash = hash_file('sha256', $temporary);
    if ($temporaryHash === false || strtolower($temporaryHash) !== $expectedSourceHash) {
        @unlink($temporary);
        throw new RuntimeException('SHA-256 der temporären Zieldatei stimmt nicht.');
    }

    /* Revalidate immediately before the swap. The initial preflight must not
       authorize a file that was created or changed while the temporary copy
       was being prepared. */
    try {
        assertFinalTargetParentWithinRoot($target, $targetRoot);
        assertTargetState($target, $targetRoot, $expectedMissing, $expectedTargetHash);
    } catch (Throwable $exception) {
        @unlink($temporary);
        throw $exception;
    }

    $hadTarget = !$expectedMissing;
    if ($hadTarget && !rename($target, $backup)) {
        @unlink($temporary);
        throw new RuntimeException('Bestehende Zieldatei konnte nicht sicher zwischengespeichert werden.');
    }

    if (!rename($temporary, $target)) {
        @unlink($temporary);
        $restore = $hadTarget ? restoreBackup($backup, $target) : null;
        $message = 'Temporäre Datei konnte nicht atomar aktiviert werden.';
        if ($restore === true) {
            $message .= ' Die vorherige Datei wurde wiederhergestellt.';
        } elseif ($restore === false) {
            $message .= ' Wiederherstellung fehlgeschlagen; Sicherung liegt unter: ' . $backup;
        }
        throw new RuntimeException($message);
    }

    $installedHash = hash_file('sha256', $target);
    $resolvedInstalled = realpath($target);
    if ($resolvedInstalled === false
        || !isWithin($resolvedInstalled, $targetRoot)
        || $installedHash === false
        || strtolower($installedHash) !== $expectedSourceHash) {
        $removedInstalled = !targetEntryExists($target) || unlink($target);
        $restore = $hadTarget && $removedInstalled ? restoreBackup($backup, $target) : null;
        $message = 'Zieldatei hat nach dem Schreiben den Root- oder SHA-256-Check nicht bestanden.';
        if ($restore === true) {
            $message .= ' Die vorherige Datei wurde wiederhergestellt.';
        } elseif ($hadTarget) {
            $message .= ' Automatische Wiederherstellung fehlgeschlagen; Sicherung liegt unter: ' . $backup;
        }
        throw new RuntimeException($message);
    }

    if ($hadTarget && !unlink($backup)) {
        throw new RuntimeException('Neue Datei ist aktiv, aber die Sicherung konnte nicht entfernt werden: ' . $backup);
    }
}

function assertTargetState(
    string $target,
    string $targetRoot,
    bool $expectedMissing,
    ?string $expectedTargetHash
): void {
    assertTargetParentWithinRoot($target, $targetRoot);

    if ($expectedMissing) {
        if (targetEntryExists($target)) {
            throw new RuntimeException('Zielzustand hat sich seit dem Check geaendert: Datei wurde angelegt.');
        }
        return;
    }

    if ($expectedTargetHash === null || !isSha256($expectedTargetHash)) {
        throw new RuntimeException('Erwarteter Zielhash fehlt oder ist ungueltig.');
    }

    if (!is_file($target)) {
        throw new RuntimeException('Zielzustand hat sich seit dem Check geaendert: Datei fehlt oder ist nicht regulaer.');
    }

    $resolvedTarget = realpath($target);
    if ($resolvedTarget === false || !isWithin($resolvedTarget, $targetRoot)) {
        throw new RuntimeException('Zieldatei liegt ausserhalb des Joomla-Roots.');
    }

    $currentHash = hash_file('sha256', $resolvedTarget);
    if ($currentHash === false || strtolower($currentHash) !== $expectedTargetHash) {
        throw new RuntimeException('Zielzustand hat sich seit dem Check geaendert: SHA-256 weicht ab.');
    }
}

function assertTargetParentWithinRoot(string $target, string $targetRoot): void
{
    $cursor = dirname($target);

    while (!file_exists($cursor) && !is_link($cursor)) {
        $parent = dirname($cursor);
        if ($parent === $cursor) {
            throw new RuntimeException('Kein existierender Ziel-Elternpfad gefunden.');
        }
        $cursor = $parent;
    }

    $resolvedParent = realpath($cursor);
    if ($resolvedParent === false || !is_dir($resolvedParent) || !isWithin($resolvedParent, $targetRoot)) {
        throw new RuntimeException('Ziel-Elternpfad liegt ausserhalb des Joomla-Roots oder ist kein Verzeichnis.');
    }
}

function assertFinalTargetParentWithinRoot(string $target, string $targetRoot): void
{
    $resolvedParent = realpath(dirname($target));
    if ($resolvedParent === false || !is_dir($resolvedParent) || !isWithin($resolvedParent, $targetRoot)) {
        throw new RuntimeException('Finaler Zielordner liegt ausserhalb des Joomla-Roots.');
    }
}

function targetEntryExists(string $path): bool
{
    return file_exists($path) || is_link($path);
}

function restoreBackup(string $backup, string $target): bool
{
    if (!is_file($backup) || targetEntryExists($target)) {
        return false;
    }

    return rename($backup, $target);
}

/** @param array<int, string> $allowedPrefixes */
function validateManagedTarget(string $path, array $allowedPrefixes): void
{
    if (preg_match('~(^|/)administrator/manifests/packages(?:/|$)~i', $path) === 1
        || preg_match('~(^|/)pkg_[^/]*\.xml$~i', $path) === 1) {
        throw new InvalidArgumentException('Joomla-Paket-IDs und Paketmanifeste duerfen nicht synchronisiert werden: ' . $path);
    }

    if ($allowedPrefixes === []) {
        throw new InvalidArgumentException('allowedTargetPrefixes darf nicht leer sein.');
    }

    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($path, rtrim($prefix, '/') . '/')) {
            return;
        }
    }

    throw new InvalidArgumentException('Ziel liegt ausserhalb der freigegebenen Praefixe: ' . $path);
}

function normalizeRelativePath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('~^[A-Za-z]:/~', $path) === 1) {
        throw new InvalidArgumentException('Pfad muss relativ sein: ' . $path);
    }

    $segments = explode('/', $path);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new InvalidArgumentException('Unsicherer relativer Pfad: ' . $path);
        }
    }

    return implode('/', $segments);
}

function makeAbsolute(string $path, string $base): string
{
    if (preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
        return $path;
    }

    return $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function isWithin(string $path, string $root): bool
{
    $normalize = static function (string $value): string {
        $value = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $value), DIRECTORY_SEPARATOR);
        return PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value;
    };

    $path = $normalize($path);
    $root = $normalize($root);

    return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
}

function samePath(string $left, string $right): bool
{
    $leftReal = realpath($left);
    $rightReal = realpath($right);
    if ($leftReal === false || $rightReal === false) {
        return false;
    }

    return PHP_OS_FAMILY === 'Windows'
        ? strcasecmp($leftReal, $rightReal) === 0
        : $leftReal === $rightReal;
}

function isSha256(string $hash): bool
{
    return preg_match('/^[a-f0-9]{64}$/', $hash) === 1;
}

/** @param array<string, mixed> $report */
function emitReport(array $report, bool $json): void
{
    if ($json) {
        $safeReport = $report;
        unset($safeReport['sourceFiles']);
        echo json_encode($safeReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        return;
    }

    echo $report['tool'] . PHP_EOL;
    echo 'Modus: ' . ($report['mode'] === 'apply' ? 'APPLY (Schreibzugriff explizit aktiviert)' : 'CHECK (nur lesen)') . PHP_EOL;
    echo 'Shared-Source-Version: ' . $report['suiteVersion'] . PHP_EOL;
    echo 'Shared-Source: ' . $report['sourceRoot'] . PHP_EOL;
    echo PHP_EOL;

    foreach ($report['sourceErrors'] as $error) {
        echo '[SOURCE-ERROR] ' . $error . PHP_EOL;
    }
    foreach ($report['targetErrors'] as $error) {
        echo '[TARGET-ERROR] ' . $error . PHP_EOL;
    }

    foreach ($report['targets'] as $target) {
        echo sprintf('Ziel %s (%s): %s', $target['id'], $target['label'], $target['root']) . PHP_EOL;
        if (!$target['valid']) {
            echo '  INVALID' . PHP_EOL;
            continue;
        }

        foreach ($target['files'] as $file) {
            $status = strtoupper((string) $file['status']);
            if ($file['status'] === 'current' || $file['status'] === 'applied') {
                echo sprintf('  %-11s %s sha256=%s', $status, $file['targetRelative'], $file['sourceHash']) . PHP_EOL;
            } elseif ($file['status'] === 'missing') {
                echo sprintf('  MISSING     %s expected=%s', $file['targetRelative'], $file['sourceHash']) . PHP_EOL;
            } else {
                echo sprintf(
                    '  %-11s %s target=%s source=%s%s',
                    $status,
                    $file['targetRelative'],
                    $file['targetHash'] ?? 'nicht-lesbar',
                    $file['sourceHash'],
                    isset($file['reason']) ? ' (' . $file['reason'] . ')' : ''
                ) . PHP_EOL;
            }
        }
        echo PHP_EOL;
    }

    if ($report['applyBlocked'] !== null) {
        echo '[APPLY-BLOCKED] ' . $report['applyBlocked'] . PHP_EOL;
    }
    foreach ($report['applyErrors'] as $error) {
        echo '[APPLY-ERROR] ' . $error . PHP_EOL;
    }

    echo sprintf(
        'Summe: current=%d missing=%d outdated=%d conflicts=%d applied=%d apply-errors=%d',
        $report['current'],
        $report['missing'],
        $report['outdated'],
        $report['conflicts'],
        count($report['applied']),
        count($report['applyErrors'])
    ) . PHP_EOL;

    if ($report['mode'] === 'check') {
        echo 'Keine Datei wurde veraendert.' . PHP_EOL;
    }
}

function emitFatal(bool $json, string $message): void
{
    if ($json) {
        echo json_encode(['status' => 'invalid', 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        return;
    }

    fwrite(STDERR, '[FATAL] ' . $message . PHP_EOL);
}

/** @param resource $stream */
function printUsage($stream): void
{
    fwrite($stream, <<<TXT
LMZ J-Suite Shared-Source Sync

Aufruf:
  php tools/sync-shared-suite.php
  php tools/sync-shared-suite.php --check [--target=railtime] [--target=northpaxx]
  php tools/sync-shared-suite.php --apply [--target=...] [--root=ziel-id=pfad]
  php tools/sync-shared-suite.php --json

Ohne --apply laeuft immer nur die SHA-256-Pruefung. --apply schreibt nur
fehlende Dateien oder im Inventar freigegebene Vorversionen. Unbekannte lokale
Hashes blockieren den gesamten Schreibvorgang. Es werden keine Dateien geloescht.

Exitcodes: 0 aktuell/erfolgreich, 2 Unterschiede, 3 ungueltige Quelle/Ziele,
           4 Schreibvorgang wegen Konflikt blockiert, 5 Schreibfehler.
TXT);
}
