# Current state

## Confirmed

- LMZ Dev workspace initialized.
- The existing package builder still reads from the RailTime Joomla checkout; it is not a safe shared-source product build.
- The generic `plugins/system/lmzsuitemenu` tree is byte-identical in the RailTime and NorthStaff Joomla checkouts before this task.
- `shared-suite/inventory.json` is the versioned allowlist for nine generic `lmzsuitemenu` files; the product repository is now the authoritative copy for this bounded scope.
- `tools/sync-shared-suite.php` and its PowerShell wrapper are read-only by default and require explicit `--apply`/`-Apply` for writes.
- No Apply run was performed against either Joomla installation.
- During the task, `HEAD` and `origin/master` advanced externally to `5e84cdf`; that commit already contains the initialized `.lmzdev` files and eight initial shared plugin files. It was preserved without reset or amendment.

## Verification

- `C:\xampp\php\php.exe -l tools\sync-shared-suite.php`: no syntax errors.
- `C:\xampp\php\php.exe -l` for both managed plugin PHP files: no syntax errors.
- `Get-Content shared-suite\inventory.json -Raw | ConvertFrom-Json`: schema version 1, suite version 1.0.0-local.1, 9 files, 2 targets.
- `.\tools\sync-shared-suite.ps1 -Help`: exit 0 and documented check/apply interface.
- `.\tools\sync-shared-suite.ps1`: exit 0; 18 current file checks, 0 missing, 0 outdated, 0 conflicts, 0 writes.
- `.\tools\sync-shared-suite.ps1 -Json`: exit 0 with empty sourceErrors and targetErrors.
- Independent read-only security review found and then re-reviewed fixes for source-root escape, junction/symlink parent escape, target-state TOCTOU and backup restore reporting; final re-review reported no remaining findings.

## Risks and blockers

- The public release feed and automatic Joomla installation are outside this local sync foundation and remain unverified.
- Only the generic administrator suite-menu plugin is centrally managed so far; Page Builder, Content API, Studio and templates still need an explicit product-neutral source extraction before they can join the inventory.
- The explicit Apply path was security-reviewed but intentionally not executed, including against test targets, because this task required check/dry-run verification only.
