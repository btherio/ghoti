# Module extension API 1

Ghoti supports installed extension modules without adding per-module conditions to core files. PHP 8.1 or newer is required. Existing built-in modules keep their settings and loading conventions. New extensions are disabled by default.

## Installation contract

Install trusted server code in `mod/<id>/`. IDs contain lowercase letters, digits and underscores, start with a letter, and are at most 48 characters. Built-in module names are reserved. The directory must contain `<id>.php`, `<id>.async.php`, and `module.json`:

```json
{
  "apiVersion": 1,
  "name": "Example",
  "description": "Provides an example component.",
  "requires": ["mail"],
  "schema": true
}
```

`name` and `description` are required nonempty strings (100 and 500 bytes maximum). `requires` is optional; enabled dependencies load first. Built-in module dependencies use their existing settings. Missing or disabled dependencies prevent a dependent extension from loading. Cycles fail explicitly. `schema` defaults to false; when true, `<id>.sql` is required and Ghoti's existing schema provisioner accepts the module. This declaration does not automatically run SQL: use the established module database constructor. The provisioner drains multi-statement results before querying the connection again.

Discovery reads JSON only; it never executes disabled modules. Malformed/incompatible manifests and linked module directories or entry files are rejected. This is a trusted-code extension mechanism, not an executable upload or remote installation API. Manifest installation is an operator filesystem action. Extensions remain responsible for authorization, CSRF protection, validation, ownership, prepared queries and output escaping.

Site Settings automatically lists installed manifests. `extensionModules` in the private settings file maps IDs to booleans. Saves validate dependency combinations before writing. Unknown saved IDs are retained so temporarily unavailable modules do not lose their preference, but they never load. An optional `legacyEnabledSetting` (for example `enableExample`) migrates an older setting on load; an explicit new-map value always wins. Saving settings writes the new map and removes obsolete keys. Do not invent a new core property for each extension.

`ghoti::enabledModules()` resolves the enabled load order; `ghoti::isModuleEnabled($id)` is the capability check. Normal HTTP bootstrap includes only enabled entry points. Entry points register RPC endpoints and shortcodes using the existing APIs. Explicit trusted calls to `loadModules()` accept only built-ins or installed manifests.

## Server hooks

Register during module inclusion:

```php
ghoti_add_hook('document.head', function (array $context): void {
    echo '<script defer src="'.htmlspecialchars(
        $context['asset']('mod/example/public/example.js'), ENT_QUOTES
    ).'"></script>';
});
```

Actions receive one context array. Filters receive the previous value and a context array and return the replacement. Both accept an optional integer priority (default 10); smaller priorities run first and ties retain registration order. Exceptions propagate to the normal error handler. Callbacks run only after their module has loaded.

| Hook | Contract |
| --- | --- |
| `request.init` | After module loading, built-in service creation and session validation; before RPC dispatch. Empty context. Initialize request-scoped services here if necessary. |
| `request.render` | Normal HTML requests only, after security headers and theme selection, before theme inclusion. Empty context. Suitable for legacy route redirects; a terminating handler must clean up its request objects. |
| `document.head` | Shared header; context contains `asset` (versioned local asset URL callable) and `base` (installation URL path). Output head markup here. |
| `document.icons` (filter) | Replace the default icon array. Each entry has `rel`, `path`, and optional `type`/`sizes`. The shared header escapes values and versions asset paths. |
| `theme.before-content` | Ghoti CMS template, immediately before the page outlet; context contains `theme` (`ghoticms`) and `asset`. Other themes may opt in to this hook. |
| `request.cleanup` | Before core services are removed from session at normal or RPC completion. Empty context. Remove module service objects here; do not destroy account/session state. |

Prefer ordinary request-local services over serializing services into sessions. Cleanup is not a guaranteed shutdown/finally hook after a fatal failure.

## Client lifecycle and native pages

Listen on `document`:

- `ghoti:before-page-render`: dispose mounted components before the page outlet changes.
- `ghoti:page-rendered`: mount new components after replacement. Initial server rendering emits it with `detail.initial === true`. Later replacements have no detail payload. Mounting should be idempotent.
- `ghoti:authenticated`: `detail.userId` and `detail.existing`. Existing-session checks are informational and do not regenerate sessions or navigate. A new sign-in event is cancelable: call `preventDefault()` to preserve a mounted draft; otherwise Ghoti loads Home.
- `ghoti:before-logout`: cancelable before the logout request, for unsaved-change confirmation.
- `ghoti:logged-out`: successful logout, immediately before normal navigation.

Normal page bodies are rendered on the server and marked `data-server-rendered`; the startup client does not fetch them again. Shortcodes are expanded after legacy stored escaping is removed, preserving JSON emitted by components. Native `ghotiMenu` links use real `?page=N` URLs, delegated navigation, and Back/Forward support. `getPage(id, query)` accepts an optional query string and preserves component query parameters while removing legacy `view` and `theme` parameters. RPC page loading itself still receives only the page ID; client components read their query state from the address bar.

## Child themes

A child template can set `$ghotiThemeOptions` and include `css/ghoticms/ghoticms.php`. Supported options are `layout` (`portfolio` default or `workspace`), `bodyClass`, `description` (fallback only when SEO description is empty), and `adminLabel` (`Workspace` default). Workspace layout retains the shared header, native menus, account controls, page outlet and footer, without the portfolio hero/stats/animation scripts. Child assets belong to the child directory or module and are included through the head hook. No child theme names are embedded in core.

## Upgrade and operational notes

`GHOTI_MODULE_API = 1` denotes this contract. Compatible framework releases must retain API 1 behavior; incompatible changes require an explicit migration and API version change. An extension requiring API 1 cannot run on older Ghoti releases that predate these hooks. Test upgrades in a separate checkout with the installed modules before deploying.

This release also scopes session cookie names by installation path. Existing upstream installations sign in again once; sibling installations no longer share a cookie. Moving an installation changes that suffix. `GHOTI_DB_CONFIG` optionally names a trusted operator-owned PHP configuration file for CLI services; otherwise `db.config.local.php` remains the default. Never point this variable at user input.

Validation without installed extensions, a database, browser or mail server:

```sh
php tests/module-extensions.php
node --test tests/module-lifecycle.mjs
php tests/site-settings-contract.php
php tests/schema-upgrade.php
php tests/security.php
```
