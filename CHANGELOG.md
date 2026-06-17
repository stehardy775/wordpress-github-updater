# Changelog

All notable changes to this project are documented in this file.

## 2.0.0 - 17/06/2026

### Added
- Optional 4th constructor argument `$asset` to choose which release asset to install (e.g. `'my-plugin.zip'`). Accepts a path such as `dist/my-plugin.zip` (only the filename is used) and falls back to the source zipball when the named asset is absent.
- Unified configuration in `.githubupdater.conf`: `release.yml` now reads `SOURCE_FILE_OVERRIDE`, `SLUG_OVERRIDE`, and the new `EXCLUDE_EXTRA` from this file via a "Load project config" step. `EXCLUDE_EXTRA` adds project-specific packaging excludes (whitespace/newline-separated rsync patterns). The same file continues to hold the pre-commit hook's copy destinations.
- `release.yml`: expanded default packaging excludes — AI assistant metadata (Claude, Codex, Cursor, Aider, Windsurf, Continue, Gemini, Copilot), OS/editor cruft, `node_modules`, linting/testing config (`phpcs.xml`/`phpunit.xml` and `.dist` variants), and this updater's own tooling.
- Negative caching: failed GitHub release lookups are cached for 1 minute to avoid re-hitting an unreachable or rate-limited API on every admin page load.
- `pre-commit` can fetch the latest files via a `curl … | bash` one-liner, and optionally self-install as the Git hook when run with `INSTALL_HOOK=1` (seeding a `.githubupdater.conf` template if absent). Hook installation never overwrites an existing hook: it appends only to plain, unmanaged shell hooks and skips hooks owned by other tools (Husky, pre-commit.com, lefthook) or non-shell hooks, printing manual-wiring guidance instead.

### Changed
- Compatibility values (`tested`, `requires`, `requires_php`) are now read from the plugin/theme headers instead of being hard-coded, and omitted when not declared.
- Markdown sources (README/CHANGELOG/release notes) are rendered to a sanitised HTML subset in the "View details" popup rather than shown as raw text.
- Tightened release-package URL matching so a repository name cannot match a longer one (e.g. `me/plugin` vs `me/plugin-pro`).

### Removed
- **Breaking:** automatic selection of the first attached `.zip` release asset. The installed package is now either the asset named via `$asset` or GitHub's source zipball — nothing is auto-guessed.

### Fixed
- Corrected the GitHub API `User-Agent` string to match the release version.

## 1.0.0 - 13/03/2026
- Initial release.
