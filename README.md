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

Copy these two files from this repository into your project:

| File | Destination in your project |
|---|---|
| `GitHubUpdater.php` | `includes/GitHubUpdater.php` |
| `release.yml` | `.github/workflows/release.yml` |

### 2. Auto-update (optional)

To keep both files up to date automatically, merge `pre-commit` into your project's Git hooks folder. This safely appends the updater block to any existing hook rather than overwriting it:

```bash
if [ ! -f .git/hooks/pre-commit ]; then
  cp pre-commit .git/hooks/pre-commit
elif ! grep -qF '# BEGIN wordpress-github-updater' .git/hooks/pre-commit; then
  tail -n +2 pre-commit >> .git/hooks/pre-commit
fi
chmod +x .git/hooks/pre-commit
```

It will now run automatically on every commit. To run it manually at any time:

```bash
bash .git/hooks/pre-commit
```

To override the destination paths, copy `.githubupdater.conf` into your project root and edit:

```bash
UPDATER_DEST=includes/GitHubUpdater.php
RELEASE_DEST=.github/workflows/release.yml
```

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

The three arguments are:

| Argument | Description |
|---|---|
| `$file` | Absolute path to the plugin's main file or theme's `functions.php`. Use `__FILE__`. |
| `$repo` | GitHub repository in `owner/repo` format. |
| `$token` | A GitHub Fine-Grained Personal Access Token (see below). |

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
4. **Optionally**, attach a hand-built `.zip` as a release asset when you need to control the archive contents (e.g. exclude `node_modules`, `.github`, tests). If no asset is attached, the class falls back to GitHub's auto-generated source zipball.

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
2. (Optional) Set `SOURCE_FILE_OVERRIDE` when auto-detection is not suitable.
3. (Optional) Set `SLUG_OVERRIDE` when zip root folder should differ from repo name.
4. Commit and push.

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

### Plugin Details Popup Content

For the WordPress "View details" popup:

- Title uses the plugin header `Plugin Name` (or theme `Name`) instead of slug.
- Description tab uses `README.md` (or `readme.md`) when present; otherwise it falls back to the GitHub release title.
- Changelog tab uses `CHANGELOG.md` (or `changelog.md`) when present; otherwise it falls back to release notes body, then "See GitHub Releases for changelog.".

---

## Caching

The class uses a **two-layer cache** to minimise GitHub API calls:

| Layer | Mechanism | Lifetime | Purpose |
|---|---|---|---|
| Within-request | `$release_cache` property | Single PHP request | Prevents redundant reads when WordPress calls the update filter more than once during one page load |
| Cross-request | WordPress transient (`ghu_{md5(repo)}`) | 5 minutes | Persists across page loads; cleared automatically by WordPress's transient API |

The transient key is derived from a hash of the repository name, so multiple projects targeting different repositories never share or overwrite each other's cached data.

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
