# Decisions

Record durable decisions with date, context, decision, and consequences.

## 2026-08-24 | Positivliste statt Live-Installation als Quelle

- Context: `build.php` reads extension files from the RailTime Joomla checkout and the full suite still contains customer-specific behavior.
- Decision: Manage only explicitly reviewed, generic files under `shared-suite/source/` and list every target path and SHA-256 in `inventory.json`.
- Consequence: The current shared scope is deliberately limited to `plg_system_lmzsuitemenu`; unreviewed suite parts cannot be copied accidentally.

## 2026-08-24 | Writes require known lineage

- Context: A direct two-site copy could overwrite an installation-specific hotfix.
- Decision: Default to read-only checks. `--apply` may write only missing files or hashes listed under `allowedPreviousSha256`; any unknown target hash blocks all writes.
- Consequence: A future shared change must preserve the previous central hash in the inventory before it can be rolled out.

## 2026-08-24 | Package and feed metadata remain out of scope

- Context: Existing Joomla package IDs must remain intact and no public release/feed has been verified.
- Decision: Reject package manifests and update-feed text in the managed source; do not connect or publish a feed from this tool.
- Consequence: This solves local common-source drift only, not public Joomla update discovery or unattended installation.
