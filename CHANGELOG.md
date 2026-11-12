# Change Log

All notable changes to this project are documented in this file.

The format is based on [Keep a Change Log](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- The `release.yml` template now uses `actions/checkout@v7`. Projects using the pre-commit hook will pick this up on their next commit, rather than having the hook revert a manual bump back to v5.

## [2.3.5] - 2026-10-17

### Changed

- Reworked Markdown to HTML to improve readability.

## [2.3.4] - 2026-10-17

### Changed

- Dependency updates.
- Superpowers files are excluded from the release package.

### Fixed

- Resolved pre-commit hook issues.

## [2.3.3] - 2026-10-06

### Changed

- Dependency updates.

## [2.3.2] - 2026-08-10

### Changed

- Updated proxy requirements.

## [2.3.1] - 2026-06-29

### Added

- Package resolution in the proxy service.

## [2.3.0] - 2026-06-29

### Fixed

- Resolved issues with the `EXCLUDE_EXTRA` files feature.

## [2.2.1] - 2026-06-24

### Fixed

- Added a missing dependency to the proxy's `requirements.txt`.

## [2.2.0] - 2026-06-24

### Added

- **Update-proxy mode** for private repositories. The third constructor argument now accepts `[ 'proxy' => 'https://…', 'secret' => '…' ]` instead of a token. In proxy mode the plugin sends no GitHub credentials – the token lives only on the proxy server, so nothing secret is embedded in the distributed plugin/theme. Each request carries an `X-GHU-Site` header (for logging) and, when configured, an `X-GHU-Key` shared-secret header.
- A self-contained proxy under [`proxy/`](proxy/README.md): a FastAPI (Python) app with a single `?ghu=`-routed entry point (no URL-rewrite config required) and streaming package downloads.
  - **Logging** to a Log Analytics custom table via managed identity (`app/logger.py`).
  - **Admin UI** (`app/admin.py`) behind App Service Easy Auth (Entra): a dashboard reviewing logs and a settings page managing the blocklist.
  - **Domain/repo blocklist** in Azure Table Storage (`app/blocklist.py`) to cut off a specific site or repo; wildcards (`*`) supported, fails open.
  - **Shared-secret gate** (`X-GHU-Key`) so only your plugins/themes can use the proxy.
- Versioned `startup.sh` and a step-by-step Azure App Service deployment guide.

### Changed

- The constructor's third parameter is now `string|array $auth`. Passing a token string keeps the previous behaviour (direct GitHub access), so existing setups are unaffected; passing an array enables proxy mode.

## [2.1.0] - 2026-06-22

### Added

- Per-item **"Check for updates"** control. Plugins get a "Check for updates" link in their Plugins-list row; themes get a "Check for updates" button (as an admin notice on the Themes screen, since `themes.php` has no per-row action hook). Each forces a fresh GitHub lookup for that single item and reports the result.
- **Auto-updates support.** The managed plugin/theme is now registered in the update transient's `no_update` list when it is already current, so WordPress recognises it as coming from an update source and always shows the **"Enable auto-updates"** link – not only when an update happens to be available.

### Fixed

- A forced update check now bypasses the updater's own transient cache. WordPress's "Check Again" (and the new per-item button) previously kept serving the cached release – including the 1-minute failure marker – so a freshly published release would not appear in wp-admin until the cache expired.

## [2.0.1] - 2026-06-17

### Changed

- `release.yml`: additional default packaging excludes for tool caches – `.phpcs-cache` (PHP_CodeSniffer) and `.phpunit.cache` (PHPUnit 10+ cache directory).

## [2.0.0] - 2026-06-17

### Added

- Optional 4th constructor argument `$asset` to choose which release asset to install (e.g. `'my-plugin.zip'`). Accepts a path such as `dist/my-plugin.zip` (only the filename is used) and falls back to the source zipball when the named asset is absent.
- Unified configuration in `.githubupdater.conf`: `release.yml` now reads `SOURCE_FILE_OVERRIDE`, `SLUG_OVERRIDE`, and the new `EXCLUDE_EXTRA` from this file via a "Load project config" step. `EXCLUDE_EXTRA` adds project-specific packaging excludes (whitespace/newline-separated rsync patterns). The same file continues to hold the pre-commit hook's copy destinations.
- Negative caching: failed GitHub release lookups are cached for 1 minute to avoid re-hitting an unreachable or rate-limited API on every admin page load.
- `pre-commit` can fetch the latest files via a `curl … | bash` one-liner, and optionally self-install as the Git hook when run with `INSTALL_HOOK=1` (seeding a `.githubupdater.conf` template if absent). Hook installation never overwrites an existing hook: it appends only to plain, unmanaged shell hooks and skips hooks owned by other tools (Husky, pre-commit.com, lefthook) or non-shell hooks, printing manual-wiring guidance instead.

### Changed

- `release.yml`: expanded default packaging excludes – AI assistant metadata (Claude, Codex, Cursor, Aider, Windsurf, Continue, Gemini, Copilot), OS/editor cruft, `node_modules`, linting/testing config (`phpcs.xml`/`phpunit.xml` and `.dist` variants), and this updater's own tooling.
- Compatibility values (`tested`, `requires`, `requires_php`) are now read from the plugin/theme headers instead of being hard-coded, and omitted when not declared.
- Markdown sources (README/CHANGELOG/release notes) are rendered to a sanitised HTML subset in the "View details" popup rather than shown as raw text.

### Removed

- **Breaking:** automatic selection of the first attached `.zip` release asset. The installed package is now either the asset named via `$asset` or GitHub's source zipball – nothing is auto-guessed.

### Fixed

- Tightened release-package URL matching so a repository name cannot match a longer one (e.g. `me/plugin` vs `me/plugin-pro`).
- Corrected the GitHub API `User-Agent` string to match the release version.

## [1.0.0] - 2026-03-13

### Added

- Initial release.
