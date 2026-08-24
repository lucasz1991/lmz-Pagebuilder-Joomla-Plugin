[CmdletBinding()]
param(
    [switch] $Apply,
    [ValidateSet('railtime', 'northstaff')]
    [string[]] $Target = @(),
    [string] $RailTimeRoot,
    [string] $NorthStaffRoot,
    [switch] $Json,
    [switch] $Help
)

$ErrorActionPreference = 'Stop'

$toolPath = Join-Path $PSScriptRoot 'sync-shared-suite.php'
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$phpExecutable = if ($null -ne $phpCommand) {
    $phpCommand.Source
} elseif (Test-Path -LiteralPath 'C:\xampp\php\php.exe') {
    'C:\xampp\php\php.exe'
} else {
    throw 'PHP wurde weder im PATH noch unter C:\xampp\php\php.exe gefunden.'
}

$toolArguments = @($toolPath)

if ($Help) {
    $toolArguments += '--help'
} elseif ($Apply) {
    $toolArguments += '--apply'
} else {
    $toolArguments += '--check'
}

foreach ($targetId in $Target) {
    $toolArguments += "--target=$targetId"
}

if ($RailTimeRoot) {
    $toolArguments += "--root=railtime=$RailTimeRoot"
}

if ($NorthStaffRoot) {
    $toolArguments += "--root=northstaff=$NorthStaffRoot"
}

if ($Json) {
    $toolArguments += '--json'
}

& $phpExecutable @toolArguments
exit $LASTEXITCODE
