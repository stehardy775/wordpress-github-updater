# CLAUDE.md

Guidance for AI assistants working in this repository. GitHub Copilot is pointed here via `.github/instructions/claude.instructions.md`, so this file is the single source of assistant instructions – keep it current.

## Project Purpose

This repository provides:

- A reusable WordPress updater class (`GitHubUpdater.php`) plus reference documentation/templates (`README.md`, `release.yml`, `pre-commit`, `.githubupdater.conf`) for integrating GitHub Release-based updates into plugin/theme projects.
- A Python/FastAPI proxy (`proxy/`) that lets WordPress sites update from **private** GitHub repos without embedding the GitHub token in the distributed plugin/theme. See `proxy/README.md`.

## Key Conventions

- Keep `GitHubUpdater.php` framework-agnostic for WordPress 6.9+ and PHP 8.4+.
- Preserve backward-compatible constructor behaviour unless explicitly asked to break API.
- Use transient cache keys scoped by repository (`ghu_` + `md5(repo)`) to avoid collisions.
- Keep class conflict protection via `class_exists( 'GitHubUpdater' )`.
- Default to UK English in code comments and docs.

## Release Workflow Policy

- `release.yml` in the repository root is a reference template only.
- Do not store active GitHub Actions workflows for release publishing in this repository.
- For real plugin/theme projects, copy `release.yml` into `.github/workflows/release.yml` in the target project (the `pre-commit` hook does this automatically).
- The workflows that *do* live in `.github/workflows/` are for the proxy only (see below).

## Expected Downstream Repository Shapes

- Plugin repo format: `{owner}/{repo-name}` with main file `<repo-name>.php`.
- Theme repo format: `{owner}/{repo-name}` with main stylesheet `style.css`.

## Updating Docs

When changing release behaviour, update all of the following together:

- `README.md`
- `release.yml`
- Any usage comments in `GitHubUpdater.php` that mention release/version flow

Treat `release.yml` as a maintained reference template. Keep action versions, packaging rules, and source-file detection logic up to date.

Keep documentation current whenever behaviour changes. Prefer `README.md` as the single source of truth for setup and usage, and avoid duplicating long-form setup docs inside `GitHubUpdater.php`.

Record user-facing changes in `CHANGELOG.md`.

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
- Do **not** exclude `vendor/` by default – many plugins ship Composer runtime deps there.
- Keep `LICENSE` included in release artefacts.

## Proxy (`proxy/`)

- FastAPI app served by gunicorn + uvicorn workers via `proxy/startup.sh`; Python 3.14. Health endpoint: `/healthz`.
- Its HTTP contract must stay compatible with `GitHubUpdater.php` – the WordPress side should need no changes when the proxy changes.
- Deployed to two Azure App Services (`dowo-wordpress-updates`, `lumeniq-wordpress-updates`) by `.github/workflows/main_*.yml`. Only the `proxy/` folder is deployed; Oryx installs `proxy/requirements.txt` on the platform. Deploys run on push to `proxy/**`, weekly (Monday 10:00 UTC, to pick up patched transitive dependencies), and on demand, followed by a `/healthz` check. Keep both deploy workflows in step; `main_lumeniq-wordpress-updates.yml` uses CRLF line endings – preserve them.
- `proxy-dependency-audit.yml` runs `pip-audit -r requirements.txt` against a fresh install (weekly and on proxy changes) and uploads SARIF via `.github/scripts/pip_audit_to_sarif.py`. Running `pip-audit` with no arguments locally audits the local `.venv` instead, which can be stale – refresh it with `pip install --upgrade --upgrade-strategy eager -r requirements.txt`.
- `requirements.txt` pins direct dependencies to minor ranges (`==X.Y.*`); transitive dependencies are unpinned.

## Testing and Safety

- Prefer non-destructive changes and preserve existing public API.
- If changing update hooks or slug detection, explain impact in README.
