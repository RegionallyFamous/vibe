# wp-github-release-updater

Small, dependency-free PHP library for **WordPress plugins** that want **Dashboard → Updates** to pull new versions from **GitHub Releases** (same idea as WordPress.org, but your repo).

## Difficulty

**Easy for “drop in Composer”** — `composer require` + one `new GitHub_Plugin_Updater( __FILE__, [...] )` in your main plugin file.

**Moderate for distribution** — WordPress zip installs often **don’t** run Composer, so you either:

- Ship the **`src/GitHub_Plugin_Updater.php`** file inside your plugin (Vibe Check keeps it under `packages/wp-github-release-updater/src/`), or  
- Run `composer install --no-dev` in CI and ship **`vendor/`** (only this package + autoload).

Publishing to **Packagist** is a few minutes (connect repo, tag, done). This folder can also live in a **standalone repo** later via git subtree/split.

## Install (Composer)

From another plugin project (adjust path or use VCS once published):

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../vibe/packages/wp-github-release-updater"
    }
  ],
  "require": {
    "regionallyfamous/wp-github-release-updater": "^1.0"
  }
}
```

Then:

```php
require_once __DIR__ . '/vendor/autoload.php';

use RegionallyFamous\WpGithubReleaseUpdater\GitHub_Plugin_Updater;

new GitHub_Plugin_Updater(
  __FILE__,
  array(
    'owner' => 'YourOrg',
    'repo'  => 'your-repo',
    'token' => defined( 'GITHUB_UPDATER_TOKEN' ) ? GITHUB_UPDATER_TOKEN : '',
  )
);
```

## Without Composer

Copy `src/GitHub_Plugin_Updater.php` into your plugin (any folder) and:

```php
require_once __DIR__ . '/includes/GitHub_Plugin_Updater.php';
class_alias( \RegionallyFamous\WpGithubReleaseUpdater\GitHub_Plugin_Updater::class, 'YourPrefix_GitHub_Updater' );
// or use the FQCN directly
```

Avoid loading **two** copies with the same namespace in one request.

## GitHub setup

1. **Published Release** (not tag-only) with semver **`v1.2.3`**.
2. Attach a **`.zip`** whose root is **`your-plugin-slug/your-main.php`** (WordPress expects that layout).
3. Optional: define **`GITHUB_UPDATER_TOKEN`** in `wp-config.php` if shared hosting hits GitHub **rate limits** (HTTP 403).

## Filters / debug (generic)

| Hook / constant | Purpose |
|-----------------|--------|
| `rf_wp_github_release_updater_collect_slugs` | `( $slugs, $checked, $plugin_file )` — force or adjust which `update_plugins->checked` keys get update rows. |
| `RF_GITHUB_RELEASE_UPDATER_DEBUG` | With `WP_DEBUG_LOG`, log API failures and “no matching basename” cases. |
| `VIBE_CHECK_UPDATER_DEBUG` | Still honored for backward compatibility when this library is used inside Vibe Check. |

## Versioning

This package uses **its own semver** in `composer.json` (start at **1.0.0**). It does not have to match your plugin’s version.

## License

GPL-2.0-or-later (same as typical WordPress plugins).
