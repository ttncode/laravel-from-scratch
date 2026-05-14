---
name: unioss-bump-migration
description: Bump migration timestamp, reindex files, and update migration config/tests for UNIOSS.
---

# Unioss Migration Bumper

Use this skill to update a set of migrations (and associated configs/tests) from an old timestamp to a new one.

## 1. Parameters

- **OLD_TS**: 14-digit timestamp of target migrations (e.g., `20260114101209`).
- **NEW_TS**: 14-digit replacement timestamp (provided by user or current time via `date +"%Y%m%d%H%M%S"`).

## 2. Locations

- **Migrations**: `AdminPage/application/migrations/{production,staging,testing,virtualbox_direct_domain}/`
  - Format: `<TS>_<action>_<ticket>_<index>.php`
- **Configs**: `AdminPage/application/config/{production,staging,virtualbox_direct_domain}/migration.php`
- **Tests**: `AdminPage/application/tests/migrations/`
  - File: `Migration_<TS>_test.php`

## 3. Workflow

### A. Rename & Update Migrations

1. Find all PHP files starting with `OLD_TS_`.
2. **Reindex**: Calculate a `NEW_INDEX` for each file. This must be a unique, monotonically increasing number (e.g., `001`, `002`) that is greater than any existing index in the target directory (check all files in that environment's migration folder). This ensures the new migration has the highest index in its folder.
3. **Rename**: Replace `OLD_TS` with `NEW_TS` and update the trailing index to `NEW_INDEX`.
4. **Update Class Name**: Update the class name inside the file to match the new filename `timestamp (new) + action + ticket + index (new)`.

### B. Update Config Files

In `production`, `staging`, and `virtualbox_direct_domain` config folders:

1. Locate the `migration_version` lines.
2. **Remove** any line (commented or active) containing `OLD_TS`.
3. **If OLD_TS was the active version**: Simply replace `OLD_TS` with `NEW_TS` in that line.
4. **If OLD_TS was NOT active**:
   - Comment out the current active `migration_version`.
   - Insert `$config['migration_version'] = NEW_TS;` as the new active version.
5. No empty line between migrations versions in config files

#### Example (Config Update)

**Case: OLD_TS is NOT the active version**

```php
// Before (OLD_TS = 20260109100000)
#$config['migration_version'] = 20260109100000;
#$config['migration_version'] = 20260109150000;
$config['migration_version'] = 20260110180000;

// After (NEW_TS = 20260111180000)
#$config['migration_version'] = 20260109150000;
#$config['migration_version'] = 20260110180000;
$config['migration_version'] = 20260111180000;
```

**Case: OLD_TS IS the active version**

```php
// Before (OLD_TS = 20260110180000)
#$config['migration_version'] = 20260109150000;
$config['migration_version'] = 20260110180000;

// After (NEW_TS = 20260111180000)
#$config['migration_version'] = 20260109150000;
$config['migration_version'] = 20260111180000;
```

### C. Update Migration Tests

1. **Rename**: `Migration_<OLD_TS>_test.php` to `Migration_<NEW_TS>_test.php`.
2. **Update Content**:
   - Replace `OLD_TS` with `NEW_TS` in class name and references.
   - Set `MIGRATION_VERSION_UP` constant to `NEW_TS`.
   - Set `MIGRATION_VERSION_DOWN` constant to the latest timestamp remaining in the migration directory (the version to revert to).

## 4. Verification

- Run `rg "<OLD_TS>"` to ensure no stale references remain in `AdminPage/application`.
- Verify class names exactly match new filenames.
- Ensure config files have exactly one active `migration_version` matching `NEW_TS`.
- Summarize changes: `NEW_TS` used, files renamed, and config diff snippet.
