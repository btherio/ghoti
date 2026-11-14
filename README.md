# Ghoti

Ghoti is a PHP content management framework with editable pages, account and administration menus, themes, and optional modules. Modules can add components to ordinary Ghoti pages through shortcodes and use the same session, navigation and page renderer.

- [Build an extension module](#build-an-extension-module)
- [Use server hooks](#use-server-hooks)
- [Handle browser lifecycle events](#handle-browser-lifecycle-events)
- [Create a child workspace theme](#create-a-child-workspace-theme)
- [Upgrade and verify extensions](#upgrade-and-verify-extensions)
- [Full module API reference](docs/module-extensions.md)
- [Database upgrade behavior](docs/upgrading.md)

## Requirements for extension modules

The examples below require PHP 8.1+ and a Ghoti checkout that provides `GHOTI_MODULE_API = 1`. Older releases, including stock v2.15, do not provide this API. Use a release or branch containing the extension-hooks changes before installing these modules.

Install module code through the server filesystem or your deployment process. Modules are trusted PHP code; discovery is not an upload or remote installation facility. Keep credentials in the installation's ignored `db.config.local.php` or an operator-owned configuration file. The example below uses an existing, configured Ghoti installation and adds no database tables or RPC endpoints.

## Build an extension module

Create these four files under the Ghoti installation root:

```text
mod/example/
├── module.json
├── example.php
├── example.async.php
└── public/
    └── example.js
```

### 1. Declare the module

`mod/example/module.json`:

```json
{
  "apiVersion": 1,
  "name": "Example component",
  "description": "A small component rendered inside a native Ghoti page.",
  "requires": [],
  "schema": false
}
```

The directory name is the module ID. IDs start with a lowercase letter, use lowercase letters, digits and underscores, and are at most 48 characters. The two PHP entry files must use the same ID. Built-in module IDs are reserved, and linked directories or entry files are rejected.

Discovery reads the manifest without executing the module. Installed modules start disabled. To depend on another module, list its ID in `requires`; it must be installed and enabled. Dependencies load before their consumers. Missing or disabled dependencies prevent dependent modules from loading, and enabled dependency cycles are rejected.

### 2. Register assets from the module entry point

`mod/example/example.php`:

```php
<?php
declare(strict_types=1);

if (!defined('GHOTI_MODULE_API') || GHOTI_MODULE_API !== 1) {
    throw new RuntimeException('Example requires Ghoti module API 1.');
}

ghoti_add_hook('document.head', function (array $context): void {
    $url = $context['asset']('mod/example/public/example.js');
    echo '<script type="module" src="'
        .htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'"></script>';
});
```

The asset helper produces an installation-relative URL with a file modification timestamp for cache invalidation. Asset paths are relative to the Ghoti root. Register assets through the hook rather than adding module conditions to `ghoti.header.php`.

### 3. Register a native page component

`mod/example/example.async.php`:

```php
<?php
declare(strict_types=1);

ghoti_register_shortcode('example', function (array $matches): string {
    if (strtolower($matches[1]) !== 'demo') {
        return '<p>Unknown example component.</p>';
    }
    return '<section data-example>'
        .'<h2>Example component</h2>'
        .'<button type="button" data-example-button>Try the hook</button>'
        .'<p data-example-message role="status">Ready.</p>'
        .'</section>';
});
```

Ghoti loads both PHP entry points when the module is enabled. An `.async.php` file is required even if a module has no asynchronous endpoints; this example uses it only to register a shortcode. Shortcode handlers receive the regex matches, with the argument in `$matches[1]`, and return HTML. Escape any dynamic content and enforce authorization before reading or rendering private data.

### 4. Mount and clean up browser behavior

`mod/example/public/example.js`:

```javascript
const mountedExamples = new Map();

function mountExamples() {
    document.querySelectorAll('#ghotiContent [data-example]').forEach(root => {
        if (mountedExamples.has(root)) return;
        const controller = new AbortController();
        mountedExamples.set(root, controller);
        root.querySelector('[data-example-button]').addEventListener('click', () => {
            root.querySelector('[data-example-message]').textContent = 'Hooks connected.';
        }, {signal: controller.signal});
    });
}

function unmountExamples() {
    mountedExamples.forEach(controller => controller.abort());
    mountedExamples.clear();
}

document.addEventListener('ghoti:before-page-render', unmountExamples);
document.addEventListener('ghoti:page-rendered', mountExamples);
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountExamples, {once: true});
} else {
    mountExamples();
}
```

The immediate/DOMContentLoaded mount handles an already-rendered page even if the module script missed Ghoti's initial event. The map makes repeated mount calls harmless. Cleanup removes listeners before navigation replaces the page; components with polling, observers or subscriptions should release those resources too.

### 5. Enable it and create a page

1. Sign in as an administrator and open **Site Settings → Optional modules**.
2. Enable **Example component**, save, then reload the page so its PHP entry points load.
3. Create or edit a page in **Pages** and put `[example:demo]` in its content.
4. Save the page and open it through Ghoti's native page menu. Click **Try the hook**, navigate away, then return to check that the component mounts again.

Use page visibility settings to control access to the page. Keep module components inside Ghoti's existing `#ghotiContent` outlet; do not emit another HTML document or duplicate the site menu/login controls. Ordinary `ghotiMenu` page links work with native navigation and Back/Forward history.

Ghoti stores enable preferences in the ignored `ghoti.settings.json` file as an `extensionModules` map. Prefer Site Settings to editing this file by hand. Use `ghoti::isModuleEnabled('example')` for capability checks rather than introducing a new core property. A manifest can specify `"legacyEnabledSetting": "enableExample"` when migrating an old core switch; an explicit new-map value takes precedence.

### Optional database schema

For a module that needs storage, set `"schema": true` and add `mod/example/example.sql`. The declaration permits that schema to use the existing provisioner; it does not itself execute SQL. A module database class extending `ghotidb` calls `parent::__construct()` followed by `parent::loadModuleSql('example')` in its constructor. See [the existing Boards database class](mod/boards/boards.db.php) and [schema upgrade rules](docs/upgrading.md).

Use prepared statements, explicit ownership checks and additive migrations. Shortcodes and lifecycle hooks do not grant access to data automatically. RPC endpoints must also validate input, enforce authorization and use Ghoti's CSRF-protected dispatcher.

## Use server hooks

Register hooks while the module entry point is being included. `ghoti_add_hook($name, $callback, $priority)` registers an action; `ghoti_add_filter($name, $callback, $priority)` transforms a value. Priority defaults to 10; lower numbers run first and equal priorities retain registration order. Action callbacks receive a context array; filter callbacks receive the current value and context and must return the next value.

| Hook | When it runs / useful context |
| --- | --- |
| `request.init` | After modules and built-in services load and the session is validated, before RPC dispatch. Use for request-scoped initialization. |
| `request.render` | HTML requests only, after theme selection and before rendering. Use for legacy route redirects. A handler that exits must call `ghoti_free_request_objects()` first. |
| `document.head` | Shared document header. Context supplies `asset` (URL helper) and `base` (installation URL path). Use for scripts and styles. |
| `document.icons` (filter) | Receives the current icon list. Return entries with `rel`, `path` and optional `type`/`sizes`. |
| `theme.before-content` | Ghoti CMS template, before the page outlet. Context supplies `theme` (`ghoticms`) and `asset`. Other themes must opt in to this hook. |
| `request.cleanup` | Before normal/RPC completion removes core request services from the session. Remove any module service objects stored there, without deleting account state. |

For example, to add a touch icon, first provide `mod/example/public/touch-icon.png`, then add this filter to `example.php`:

```php
ghoti_add_filter('document.icons', function (array $icons, array $context): array {
    $icons[] = [
        'rel' => 'apple-touch-icon',
        'sizes' => '180x180',
        'path' => 'mod/example/public/touch-icon.png',
    ];
    return $icons;
});
```

Prefer request-local services over services stored in sessions. Cleanup is not a guaranteed shutdown handler after a fatal failure. The [API reference](docs/module-extensions.md#server-hooks) defines each context and lifecycle boundary.

## Handle browser lifecycle events

Listen on `document`. Besides the page events shown in the example, Ghoti exposes authentication and logout events:

| Event | How a component should use it |
| --- | --- |
| `ghoti:before-page-render` | Dispose or detach the current component before the page outlet is replaced. |
| `ghoti:page-rendered` | Mount the new component. Initial server rendering supplies `detail.initial === true`; later replacements have no detail payload. |
| `ghoti:authenticated` | Read `detail.userId` and `detail.existing`. Existing-session checks do not navigate or regenerate the session. On a new sign-in, call `preventDefault()` synchronously to preserve a draft instead of loading Home; the component then handles its own authenticated state. |
| `ghoti:before-logout` | Cancelable before logout is sent. Call `preventDefault()` synchronously if the user chooses to keep editing unsaved work. |
| `ghoti:logged-out` | Successful logout, immediately before normal navigation. |

These events describe UI state; server-side authorization remains authoritative. Do not infer permission from a browser event alone. Initial page HTML is rendered on the server. `getPage(id, query)` can preserve component query parameters during native navigation; `query` is an optional query string, and legacy `view`/`theme` parameters are removed. The page RPC receives only the page ID, so client components read their other state from the address bar.

## Create a child workspace theme

Create `css/example-workspace/example-workspace.php`:

```php
<?php
if (!isset($_SESSION['ghotiObj']) || !class_exists('ghoti')) {
    http_response_code(404);
    return;
}

$ghotiThemeOptions = [
    'layout' => 'workspace',
    'bodyClass' => 'example-workspace',
    'description' => 'A workspace built with native Ghoti components.',
    'adminLabel' => 'Site tools',
];
include __DIR__.'/../ghoticms/ghoticms.php';
```

Select `example-workspace` as the **Default theme** in Site Settings, save and reload. An existing session theme override can still take precedence; visit `?theme=example-workspace` to switch that session as well.

Workspace layout retains Ghoti's header, menus, account controls, page outlet and footer, without the portfolio hero, stats or animation scripts. `bodyClass` provides a styling scope. `description` is used only when the SEO description is empty, and `adminLabel` changes the workspace menu label. Omitting `layout` retains the portfolio layout.

Keep custom styles in the child theme directory and include them using an enabled module's `document.head` hook, as with the script example. A theme-specific hook can check `ghoti::$defaultTheme` before emitting its assets or `theme.before-content` markup. Do not copy and modify the shared Ghoti CMS template for each application.

## Upgrade and verify extensions

Keep application modules and child themes under version control alongside the framework. Merge framework updates into a candidate checkout that retains those directories, then verify the candidate before deployment. Preserve private configuration, runtime settings and database contents separately. Until an upstream release contains module API 1, retain the extension-hooks commits; replacing the framework with stock v2.15 would remove the API.

From the candidate Ghoti root, run:

```sh
php tests/module-extensions.php
node --test tests/module-lifecycle.mjs
php tests/site-settings-contract.php
php tests/schema-upgrade.php
php tests/security.php
```

These framework checks need no database or real mail server. Run your module's own integration tests as well; the framework tests cannot verify an application's business behavior. Releases changing the module API incompatibly require an explicit extension migration. See [the complete compatibility contract](docs/module-extensions.md#upgrade-and-operational-notes).

This framework update scopes session cookie names by installation path; existing installations require one new sign-in, and moving an installation changes its cookie name. CLI services can set `GHOTI_DB_CONFIG` to a trusted operator-owned PHP connection file instead of `db.config.local.php`; never derive this path from an HTTP request or commit the credentials.
