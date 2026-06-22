# GitHubUpdater

[GitHub Repository](https://github.com/stehardy775/wordpress-github-updater)

A single-file drop-in that enables automatic updates for WordPress plugins and themes directly from GitHub Releases — works with both private and public repositories.

---

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.9+ |
| PHP | 8.0+ |

---

## Installation

### 1. Copy the required files

The quickest way is to run this from **inside your project's Git repository** — it fetches both files to the default destinations in one step:

```bash
# Fetch the files only:
curl -fsSL https://raw.githubusercontent.com/stehardy775/wordpress-github-updater/main/pre-commit | bash

# Fetch the files AND install the pre-commit hook for auto-updates (see step 2):
curl -fsSL https://raw.githubusercontent.com/stehardy775/wordpress-github-updater/main/pre-commit | INSTALL_HOOK=1 bash
```

Or copy these two files from this repository into your project manually:

| File | Destination in your project |
|---|---|
| `GitHubUpdater.php` | `includes/GitHubUpdater.php` |
| `release.yml` | `.github/workflows/release.yml` |

> Both paths default to those above, or whatever you set in [`.githubupdater.conf`](#workflow-options). The command must be run within a Git working tree, as it resolves paths via `git rev-parse --show-toplevel`. The plain command **does not** touch your Git hooks — pass `INSTALL_HOOK=1` to also install the auto-update hook (it safely appends to an existing `pre-commit` hook rather than overwriting it) and drop a `.githubupdater.conf` template in your repo root if one isn't already there.

### 2. Auto-update (optional)

To keep both files up to date automatically, install `pre-commit` into your project's Git hooks folder. The simplest way is the one-liner from step 1:

```bash
curl -fsSL https://raw.githubusercontent.com/stehardy775/wordpress-github-updater/main/pre-commit | INSTALL_HOOK=1 bash
```

Or, if you already have the `pre-commit` file in your project, merge it into the hooks folder manually. This safely appends the updater block to any existing hook rather than overwriting it:

```bash
if [ ! -f .git/hooks/pre-commit ]; then
  cp pre-commit .git/hooks/pre-commit
elif ! grep -qF '# BEGIN wordpress-github-updater' .git/hooks/pre-commit; then
  tail -n +2 pre-commit >> .git/hooks/pre-commit
fi
chmod +x .git/hooks/pre-commit
```

Both approaches preserve an existing `pre-commit` hook and are safe to re-run — the updater block is only added once. The `INSTALL_HOOK=1` one-liner additionally seeds a `.githubupdater.conf` template (only if you don't already have one), giving you a ready-to-edit config for paths and packaging options.

> **Other hook managers are left alone.** If your existing `pre-commit` hook is managed by another tool (Husky, [pre-commit.com](https://pre-commit.com), lefthook) or isn't a shell script, `INSTALL_HOOK=1` detects this and **won't modify it** — it prints how to wire the updater in yourself instead. It only appends to plain, unmanaged shell hooks.

It will now run automatically on every commit. To run it on demand instead, see [Fetch the latest copy manually via CLI](#3-fetch-the-latest-copy-manually-via-cli) below.

To override the destination paths, copy `.githubupdater.conf` into your project root and edit:

```bash
UPDATER_DEST=includes/GitHubUpdater.php
RELEASE_DEST=.github/workflows/release.yml
```

### 3. Fetch the latest copy manually via CLI

You don't need the hook installed to pull the latest `GitHubUpdater.php` and `release.yml`. Run any of these from **inside the target repository** (the script locates the repo root with `git` and honours `.githubupdater.conf` for destinations):

```bash
# If you installed the hook:
bash .git/hooks/pre-commit

# If you copied the pre-commit file into your project root:
bash pre-commit

# Without anything installed locally — fetch and run the latest script directly:
curl -fsSL https://raw.githubusercontent.com/stehardy775/wordpress-github-updater/main/pre-commit | bash
```

Each writes the latest files to the configured destinations (or the defaults: `includes/GitHubUpdater.php` and `.github/workflows/release.yml`) and prints what it downloaded. The command must be run from within a Git working tree — it uses `git rev-parse --show-toplevel` to resolve paths.

---

## Usage

Add the following to your main plugin file or theme's `functions.php`:

```php
require_once __DIR__ . '/includes/GitHubUpdater.php';
new GitHubUpdater( __FILE__, '{owner}/{repo-name}', 'github_pat_xxxx' );
```

### Repository Format (Your Setup)

`$repo` must be in `owner/repo` format:

- `{owner}/{repo-name}`

Expected WordPress file layout:

```text
{owner}/{repo-name}/{repo-name}.php
{owner}/{repo-name}/style.css
```

The arguments are:

| Argument | Required | Description |
|---|---|---|
| `$file` | Yes | Absolute path to the plugin's main file or theme's `functions.php`. Use `__FILE__`. |
| `$repo` | Yes | GitHub repository in `owner/repo` format. |
| `$token` | Yes | A GitHub Fine-Grained Personal Access Token (see below). |
| `$asset` | No | Filename of the release asset to install (e.g. `my-plugin.zip`). A path such as `dist/my-plugin.zip` is accepted — only the filename is used. Leave empty (the default) to install GitHub's auto-generated source zipball. See [Which zip gets installed?](#which-zip-gets-installed). |

To install a specific attached zip instead of the source zipball:

```php
new GitHubUpdater( __FILE__, '{owner}/{repo-name}', MY_GITHUB_TOKEN, 'my-plugin.zip' );
```

---

## GitHub Token Setup

1. Go to **GitHub → Settings → Developer settings → Personal access tokens → Fine-grained tokens**.
2. Click **Generate new token**.
3. Set the **Resource owner** to the organisation or user that owns the repository.
4. Under **Repository access**, select the target repository.
5. Under **Repository permissions**, grant:
   - **Contents** → Read-only
   - **Metadata** → Read-only *(selected automatically)*
6. Copy the generated token and pass it as the third argument.

> **Security note:** Store the token in a PHP constant or environment variable rather than hardcoding it in a shared file.
>
> ```php
> new GitHubUpdater( __FILE__, '{owner}/{repo-name}', MY_GITHUB_TOKEN );
> ```

---

## GitHub Release Setup

1. In your repository, go to **Releases → Draft a new release**.
2. Create a tag using [semver](https://semver.org/) — e.g. `v1.2.3`. WordPress uses this to determine whether an update is available.
3. Add release notes to the body (shown in the WordPress "View details" popup).
4. **Optionally**, attach a hand-built `.zip` to control the archive contents (e.g. exclude `node_modules`, `.github`, tests), then pass its filename as the `$asset` constructor argument.

### Which zip gets installed?

When WordPress installs an update, the updater chooses the package based on the optional `$asset` constructor argument ([`download_url()`](GitHubUpdater.php)):

- **`$asset` set** (e.g. `'my-plugin.zip'`): the updater installs the release asset with that filename, giving you full control over the archive contents. If no asset with that name exists on the release, it safely falls back to the source zipball.
- **`$asset` empty** (the default): the updater installs GitHub's **auto-generated source zipball** — no need to attach or build anything.

There is no auto-guessing of which attached zip to use: the package is either the asset you name or the source zipball. This makes the behaviour predictable when a release carries several assets (e.g. zip + checksums + screenshots).

How the named asset gets onto the release:

- **Manual release:** when drafting the release, drag your zip into the **"Attach binaries by dropping them here or selecting them"** area, then pass its filename as `$asset`.
- **Automated release (`release.yml`):** the workflow builds `<slug>.zip` (applying the [default excludes](#default-packaging-excludes) and your `EXCLUDE_EXTRA`) and attaches it. To install that curated zip rather than the raw source zipball, pass `'<slug>.zip'` as `$asset` — otherwise the workflow's excludes are not applied to what users receive.

### Zip layout requirement

Whether you attach a custom zip or rely on the auto-generated zipball, the top-level folder inside the archive **must match the plugin/theme slug**:

```
my-plugin/          ← folder name must match the slug
  my-plugin.php
  ...
```

GitHub's auto-generated zipball is renamed automatically by the `fix_source_dir` filter.

## Auto New Releases on Commit

This repository stores `release.yml` as a **reference template**, not as an active workflow.

### Deploy `release.yml` into a project

In each plugin/theme repository that should publish releases:

1. Copy `release.yml` from this repository into the target repository at `.github/workflows/release.yml`.
2. (Optional) Configure the workflow via `.githubupdater.conf` in the repo root (see below).
3. Commit and push.

### Workflow options

All workflow options live in **`.githubupdater.conf`** at the repository root — the same file the [pre-commit hook](#2-auto-update-optional) uses for copy destinations. On each run the workflow reads this file (via the **Load project config** step) and applies any keys it recognises. Lines are shell assignments (`KEY=value`); quote values containing spaces.

| Key | Purpose |
|---|---|
| `SOURCE_FILE_OVERRIDE` | Force the file the version is read from, when auto-detection isn't suitable. |
| `SLUG_OVERRIDE` | Override the zip's root folder name (defaults to the repo name). |
| `EXCLUDE_EXTRA` | Extra paths/patterns to exclude from the release zip, in addition to the built-in defaults. |

`EXCLUDE_EXTRA` is a whitespace- or newline-separated list of `rsync` patterns. Example `.githubupdater.conf`:

```bash
SLUG_OVERRIDE=my-plugin
EXCLUDE_EXTRA="tests docs *.dist phpunit.xml.dist composer.json composer.lock"
```

> A value set in `.githubupdater.conf` takes precedence. Any option not set there falls back to a matching workflow `env:` value (if you add one) and then to the built-in default — so you can still override per-run from the workflow YAML if needed.

### Default packaging excludes

The workflow already excludes the following from the release zip, so you rarely need `EXCLUDE_EXTRA` for common cases:

- **Version control & CI:** `.git`, `.github`, `.gitignore`, `.gitattributes`, `.gitmodules`
- **AI assistant config/metadata:** `.claude`, `CLAUDE.md`, `.codex`, `AGENTS.md`, `.cursor`, `.cursorrules`, `.cursorignore`, `.aider*`, `.windsurf`, `.windsurfrules`, `.continue`, `.gemini`, `GEMINI.md`, `copilot-instructions.md`, `.copilot`
- **OS & editor cruft:** `.DS_Store`, `Thumbs.db`, `.vscode`, `.idea`, `.editorconfig`
- **Dependency & build artefacts:** `node_modules`
- **Linting & testing config and caches:** `phpcs.xml`, `phpcs.xml.dist`, `.phpcs.xml.dist`, `.phpcs-cache`, `phpunit.xml`, `phpunit.xml.dist`, `.phpunit.result.cache`, `.phpunit.cache`
- **This updater's own tooling:** `.githubupdater.conf`, `pre-commit`

> **Note:** `vendor/` (Composer) is **not** excluded by default, since many plugins ship their runtime dependencies there. Add it to `EXCLUDE_EXTRA` if your build installs dependencies separately.

Maintenance note:

- Keep action versions in copied workflows up to date (for example `actions/checkout@v5`) to stay compatible with GitHub-hosted runner runtime changes.

Behavior in the target project:

1. On push to `main`, it auto-detects the source file in this order:
   - `SOURCE_FILE_OVERRIDE` if set
   - `style.css` containing `Theme Name:`
   - `<repo-name>.php`
   - `<repo-name>/<repo-name>.php`
   - first `.php` file containing `Plugin Name:`
2. It reads `Version:` from the detected source file.
3. It sets slug from the repo name by default (`owner/repo` -> `repo`) and uses `SLUG_OVERRIDE` if provided.
4. It checks whether tag `v<version>` already exists.
5. If the tag does not exist, it builds a zip and creates a GitHub Release.
6. If the tag exists, it exits without creating a duplicate release.

---

## How It Works

1. On admin page loads, `pre_set_site_transient_update_plugins` (or `_update_themes`) is hooked.
2. The latest release is fetched from `https://api.github.com/repos/{owner}/{repo}/releases/latest`.
3. If the release tag is newer than the installed version, WordPress is told an update is available.
4. When the user clicks **Update**, `upgrader_pre_download` intercepts the download and injects the `Authorization` header so private repositories are accessible.
5. After extraction, `upgrader_source_selection` renames the randomly-named GitHub folder to the correct slug.

> **Pre-releases and drafts are ignored.** The `/releases/latest` endpoint only returns the most recent **published, non-prerelease** release. Tags marked as a *draft* or *pre-release* on GitHub (e.g. `v1.2.3-beta`) will not trigger a WordPress update — publish a normal release when you want users to receive it.

> **Failed lookups are cached briefly.** Successful release lookups are cached for 5 minutes; failed ones (API down, rate limited, bad token) are cached for 1 minute so a broken setup doesn't slow every admin page load while you fix it. Call `clear_cache()` to force an immediate re-check.

> **A forced check bypasses the cache.** WordPress's **Check Again** on the updates screen, and the per-item **Check for updates** control (below), skip the transient cache and perform a live GitHub lookup — so a freshly published release shows up immediately rather than waiting for the cache to expire.

### Check for updates (per item)

Each managed item gets its own on-demand check:

- **Plugins** — a **Check for updates** link appears in the plugin's row on the **Plugins** screen.
- **Themes** — a **Check for updates** button appears as a notice on the **Appearance → Themes** screen (single-site `themes.php` has no per-row action hook).

Clicking it busts that item's cache, forces WordPress to rebuild its update data with a live GitHub lookup, and reports whether an update is available, the item is current, or GitHub couldn't be reached. The action is nonce-protected and requires the `update_plugins` / `update_themes` capability.

### Auto-updates

WordPress only shows the **Enable auto-updates** link for items it recognises as coming from an update source. The updater therefore registers the plugin/theme in the update transient's `no_update` list whenever it is already current (in `response` when an update exists). This means the **Enable auto-updates** link is always available — not only when an update happens to be pending — and WordPress can apply GitHub releases automatically once enabled.

> **The updater must be constructed from the main plugin file.** Call `new GitHubUpdater( __FILE__, … )` from the file containing the `Plugin Name:` header (or the theme's `functions.php`). The action link and update injection are keyed on `plugin_basename( __FILE__ )`; constructing it from an include in a subdirectory yields a slug that doesn't match the item's row, so neither the link nor updates appear.

### Plugin Details Popup Content

For the WordPress "View details" popup:

- Title uses the plugin header `Plugin Name` (or theme `Name`) instead of slug.
- Description tab uses `README.md` (or `readme.md`) when present; otherwise it falls back to the GitHub release title.
- Changelog tab uses `CHANGELOG.md` (or `changelog.md`) when present; otherwise it falls back to release notes body, then "See GitHub Releases for changelog.".

Markdown sources are rendered to a safe HTML subset (headings, bold, italic, inline/fenced code, links, and lists). The output is sanitised with `wp_kses_post()`, so unsupported syntax simply appears as plain text rather than being executed.

### Compatibility metadata

The "Requires PHP", "Requires at least" (minimum WordPress), and "Tested up to" values shown by WordPress are read from your plugin header / theme `style.css` — they are **not** hard-coded. Add the standard headers to advertise them, for example:

```php
/**
 * Plugin Name: My Plugin
 * Version: 1.2.3
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Tested up to: 6.9
 */
```

Any header you omit is simply left out of the update payload rather than being guessed.

---

## Caching

The class uses a **two-layer cache** to minimise GitHub API calls:

| Layer | Mechanism | Lifetime | Purpose |
|---|---|---|---|
| Within-request | `$release_cache` property | Single PHP request | Prevents redundant reads when WordPress calls the update filter more than once during one page load |
| Cross-request | WordPress transient (`ghu_{md5(repo)}`) | 5 minutes | Persists across page loads; cleared automatically by WordPress's transient API |

The transient key is derived from a hash of the repository name, so multiple projects targeting different repositories never share or overwrite each other's cached data.

A **forced check bypasses both layers** and always performs a live lookup. This applies to WordPress's **Check Again** (`force-check`) on the updates screen and to the per-item **Check for updates** control, so a manual check never returns a stale or failure-marked cache.

To force an immediate re-check (for example, right after publishing a release):

```php
$updater = new GitHubUpdater( __FILE__, 'your-org/your-repo', MY_GITHUB_TOKEN );
$updater->clear_cache();
```

---

## Multiple Projects on the Same Server

`GitHubUpdater.php` is designed to be bundled directly inside each plugin or theme. When several plugins on the same WordPress installation each include their own copy, PHP would normally throw a fatal error on the second `class GitHubUpdater` declaration.

This is prevented by guarding the class declaration with `class_exists()`:

```php
if ( ! class_exists( 'GitHubUpdater' ) ) :
    class GitHubUpdater { ... }
endif;
```

As a result, whichever plugin loads first defines the class, and subsequent `require_once` calls are silently skipped. Each plugin still creates its own **instance** with its own repository, token, slug, and transient key, so there is no cross-project interference.

> **Version skew:** if two bundled copies have different class implementations, only the first one loaded wins. Keep all bundled copies on the same version to avoid subtle mismatches.

---

## License

GNU General Public License v3.0 (GPLv3). See [LICENSE](LICENSE).
