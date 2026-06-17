# Copilot Instructions

## Project Purpose

This repository provides a reusable WordPress updater class (`GitHubUpdater.php`) and reference documentation/templates to integrate GitHub Release-based updates into plugin/theme projects.

## Key Conventions

- Keep `GitHubUpdater.php` framework-agnostic for WordPress 6.9+ and PHP 8.4+.
- Preserve backward-compatible constructor behavior unless explicitly asked to break API.
- Use transient cache keys scoped by repository (`ghu_` + `md5(repo)`) to avoid collisions.
- Keep class conflict protection via `class_exists( 'GitHubUpdater' )`.

## Release Workflow Policy

- `release.yml` in this repository is a reference template only.
- Do not store active GitHub Actions workflows for release publishing in this repository.
- For real plugin/theme projects, copy `release.yml` into `.github/workflows/release.yml` in the target project.


## Expected Downstream Repository Shapes

- Plugin repo format: `{owner}/{repo-name}` with main file `<repo-name>.php`.
- Theme repo format: `{owner}/{repo-name}` with main stylesheet `style.css`.

## Updating Docs

When changing release behavior, update all of the following together:

- `README.md`
- `release.yml`
- Any usage comments in `GitHubUpdater.php` that mention release/version flow

Treat `release.yml` as a maintained reference template. Keep action versions, packaging rules, and source-file detection logic up to date.

Keep documentation current whenever behavior changes. Prefer `README.md` as the single source of truth for setup and usage, and avoid duplicating long-form setup docs inside `GitHubUpdater.php`.

Always keep these links and files accurate:

- `https://github.com/stehardy775/wordpress-github-updater/README.md`
- `https://github.com/stehardy775/wordpress-github-updater/LICENSE`
- Local files: `README.md`, `LICENSE`

Keep this copyright reference up to date wherever it appears:

- `Copyright (C) 2026 Ste Hardy (www.stehardy.co.uk)`

## Packaging Expectations

- Release zip root must match plugin/theme slug.
- Exclude development metadata from packaged zip: version control (`.git`, `.github`, `.gitignore`, `.gitattributes`, `.gitmodules`), AI assistant config (`.claude`/`CLAUDE.md`, `.codex`/`AGENTS.md`, `.cursor`/`.cursorrules`, `.aider*`, `.windsurf`, `.continue`, `.gemini`/`GEMINI.md`, `copilot-instructions.md`), OS/editor cruft (`.DS_Store`, `Thumbs.db`, `.vscode`, `.idea`, `.editorconfig`), build artefacts (`node_modules`), and this updater's own tooling (`.githubupdater.conf`, `pre-commit`).
- Workflow options (`SOURCE_FILE_OVERRIDE`, `SLUG_OVERRIDE`, `EXCLUDE_EXTRA`) are configured in `.githubupdater.conf` at the repo root, which the workflow sources in its "Load project config" step. `EXCLUDE_EXTRA` is a whitespace/newline-separated list of extra rsync exclude patterns. The same file also holds the pre-commit hook's copy destinations; keep both concerns documented when editing it.
- Do **not** exclude `vendor/` by default — many plugins ship Composer runtime deps there.
- Keep `LICENSE` included in release artifacts.

## Testing and Safety

- Prefer non-destructive changes and preserve existing public API.
- If changing update hooks or slug detection, explain impact in README.
