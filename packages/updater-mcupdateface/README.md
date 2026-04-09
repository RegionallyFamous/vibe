# Updater McUpdateface

Small, dependency-free PHP library for **WordPress plugins** that want **Dashboard → Updates** to pull new versions from **GitHub Releases**.

Composer package: **`regionallyfamous/updater-mcupdateface`**. PHP class: **`RegionallyFamous\UpdaterMcUpdateface\UpdaterMcUpdateface`**.

## Difficulty

**Easy for “drop in Composer”** — `composer require` + one `new UpdaterMcUpdateface( __FILE__, [...] )` in your main plugin file.

**Moderate for distribution** — WordPress zip installs often **don’t** run Composer, so you either:

- Ship **`src/UpdaterMcUpdateface.php`** inside your plugin (Vibe Check keeps it under `packages/updater-mcupdateface/src/`), or  
- Run `composer install --no-dev` in CI and ship **`vendor/`** (only this package + autoload).

Publishing to **Packagist** is a few minutes once this folder lives in its own repo or is subtree-split.

## Install (Composer)

From another plugin project (adjust path or use VCS once published):

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../vibe/packages/updater-mcupdateface"
    }
  ],
  "require": {
    "regionallyfamous/updater-mcupdateface": "^1.0"
  }
}
```

Then:

```php
require_once __DIR__ . '/vendor/autoload.php';

use RegionallyFamous\UpdaterMcUpdateface\UpdaterMcUpdateface;

new UpdaterMcUpdateface(
  __FILE__,
  array(
    'owner' => 'YourOrg',
    'repo'  => 'your-repo',
    'token' => defined( 'GITHUB_UPDATER_TOKEN' ) ? GITHUB_UPDATER_TOKEN : '',
  )
);
```

## Without Composer

Copy `src/UpdaterMcUpdateface.php` into your plugin and `require_once` it, then instantiate the class (or `class_alias` if you need a different global name).

## GitHub setup

1. **Published Release** (not tag-only) with semver **`v1.2.3`**.
2. Attach a **`.zip`** whose root is **`your-plugin-slug/your-main.php`**.
3. Optional: **`GITHUB_UPDATER_TOKEN`** in `wp-config.php` if you hit GitHub **rate limits** (HTTP 403).

## Filters / debug

| Hook / constant | Purpose |
|-----------------|--------|
| `rf_wp_github_release_updater_collect_slugs` | `( $slugs, $checked, $plugin_file )` — adjust which `update_plugins->checked` keys get update rows. |
| `RF_GITHUB_RELEASE_UPDATER_DEBUG` | With `WP_DEBUG_LOG`, log API failures and “no matching basename” cases. |
| `VIBE_CHECK_UPDATER_DEBUG` | Still honored when used inside Vibe Check (backward compatibility). |

## Versioning

This package uses **its own semver** in `composer.json` (start at **1.0.0**). It does not have to match your plugin’s version.

## License

GPL-2.0-or-later.
