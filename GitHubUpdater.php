<?php
/**
 * GitHubUpdater
 *
 * Full setup and usage documentation:
 * https://github.com/stehardy775/wordpress-github-updater/README.md
 *
 * License: Copyright (C) 2026 Ste Hardy (www.stehardy.co.uk)
 * https://github.com/stehardy775/wordpress-github-updater/LICENSE
 *
 * @version 2.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'GitHubUpdater' ) ) :

class GitHubUpdater {

	/** Absolute path to the plugin main file or theme's functions.php. */
	private string $file;

	/** Repository identifier: "owner/repo". */
	private string $repo;

	/** GitHub Fine-Grained Personal Access Token. */
	private string $token;

	/**
	 * Optional release asset filename to download (e.g. "my-plugin.zip").
	 * Empty string means "use GitHub's auto-generated source zipball".
	 */
	private string $asset;

	/** 'plugin' or 'theme'. Detected automatically. */
	private string $type;

	/**
	 * Unique slug used by WordPress:
	 *   plugin → "folder/file.php"  (plugin_basename output)
	 *   theme  → "folder-name"      (the theme directory name)
	 */
	private string $slug;

	/** Version string read from the plugin header or theme stylesheet. */
	private string $current_version;

	/** Human-readable plugin/theme name shown in update details. */
	private string $display_name;

	/** Minimum WordPress version, from the "Requires at least" header. '' if unset. */
	private string $requires_wp = '';

	/** Minimum PHP version, from the "Requires PHP" header. '' if unset. */
	private string $requires_php = '';

	/** WordPress version tested up to, from the "Tested up to" header. '' if unset. */
	private string $tested_wp = '';

	/** In-memory cache of the latest GitHub release data. */
	private ?array $release_cache = null;

	/** In-memory cache for repository file lookups by path. */
	private array $repo_file_cache = [];

	/** Transient key used to cache the GitHub API response. */
	private string $cache_key;

	/** Transient value stored briefly after a failed lookup to avoid retry storms. */
	private const FAILURE_MARKER = 'ghu_lookup_failed';

	// =========================================================================
	// Boot
	// =========================================================================

	/**
	 * @param string $file  Absolute path to the plugin main file or theme functions.php.
	 * @param string $repo  GitHub repository in "owner/repo" format.
	 * @param string $token GitHub Fine-Grained Personal Access Token.
	 * @param string $asset Optional release asset to download instead of the
	 *                      auto-generated source zipball. A path such as
	 *                      "dist/my-plugin.zip" is accepted — only the filename
	 *                      is used, since GitHub stores assets by filename.
	 *                      Leave empty to use GitHub's source zipball.
	 */
	public function __construct( string $file, string $repo, string $token, string $asset = '' ) {
		$this->file      = $file;
		$this->repo      = $repo;
		$this->token     = $token;
		$this->asset     = basename( $asset );
		$this->cache_key = 'ghu_' . md5( $repo );

		$this->detect_type_and_slug();

		add_action( 'init', [ $this, 'register_hooks' ] );
	}

	/**
	 * Determines whether this instance manages a plugin or a theme, then sets
	 * $this->slug, version, name and the compatibility headers accordingly.
	 *
	 * Compatibility values ("Requires at least", "Requires PHP", "Tested up to")
	 * are read from the plugin/theme headers and left empty when not declared,
	 * so update details never advertise hard-coded or invented values.
	 */
	private function detect_type_and_slug(): void {
		$themes_dir = WP_CONTENT_DIR . '/themes';

		if ( str_starts_with( $this->file, $themes_dir ) ) {

			$this->type    = 'theme';
			$this->slug    = basename( dirname( $this->file ) );
			$theme         = wp_get_theme( $this->slug );

			if ( $theme->exists() ) {
				$this->current_version = $theme->get( 'Version' ) ?: '0.0.0';
				$this->display_name    = $theme->get( 'Name' ) ?: $this->slug;
				$this->requires_wp     = (string) ( $theme->get( 'RequiresWP' ) ?: '' );
				$this->requires_php    = (string) ( $theme->get( 'RequiresPHP' ) ?: '' );
			} else {
				$this->current_version = '0.0.0';
				$this->display_name    = $this->slug;
			}

		} else {

			$this->type    = 'plugin';
			$this->slug    = plugin_basename( $this->file );
			$headers       = get_file_data(
				$this->file,
				[
					'Name'        => 'Plugin Name',
					'Version'     => 'Version',
					'RequiresWP'  => 'Requires at least',
					'RequiresPHP' => 'Requires PHP',
					'TestedWP'    => 'Tested up to',
				]
			);
			$this->current_version = $headers['Version'] ?: '0.0.0';
			$this->display_name    = $headers['Name'] ?: dirname( $this->slug );
			$this->requires_wp     = $headers['RequiresWP'];
			$this->requires_php    = $headers['RequiresPHP'];
			$this->tested_wp       = $headers['TestedWP'];

		}
	}

	/**
	 * Fetches a text file from the repository root using the GitHub Contents API.
	 * Returns null when the file is not present or cannot be read.
	 */
	private function get_repo_file( string $path ): ?string {
		if ( array_key_exists( $path, $this->repo_file_cache ) ) {
			return $this->repo_file_cache[ $path ];
		}

		$response = wp_remote_get(
			"https://api.github.com/repos/{$this->repo}/contents/{$path}",
			[
				'headers' => $this->api_headers(),
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->repo_file_cache[ $path ] = null;
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$this->repo_file_cache[ $path ] = null;
			return null;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $payload['content'] ) ) {
			$this->repo_file_cache[ $path ] = null;
			return null;
		}

		$decoded = base64_decode( str_replace( [ "\r", "\n" ], '', $payload['content'] ), true );
		$this->repo_file_cache[ $path ] = ( false === $decoded ) ? null : $decoded;

		return $this->repo_file_cache[ $path ];
	}

	/**
	 * Returns a Markdown file from the repo rendered as safe HTML for a
	 * WordPress plugin info section.
	 */
	private function section_from_repo_file( array $candidate_paths ): ?string {
		foreach ( $candidate_paths as $path ) {
			$content = $this->get_repo_file( $path );
			if ( is_string( $content ) && $content !== '' ) {
				return $this->markdown_to_html( $content );
			}
		}

		return null;
	}

	/**
	 * Renders a useful subset of Markdown to sanitised HTML for the
	 * "View details" popup: fenced/inline code, headings, bold, italic,
	 * links, and unordered/ordered lists. Anything else is shown as text.
	 *
	 * This is intentionally lightweight (no external parser) and the result
	 * is always passed through wp_kses_post(), so untrusted release content
	 * cannot inject disallowed markup.
	 */
	private function markdown_to_html( string $markdown ): string {
		$text = str_replace( [ "\r\n", "\r" ], "\n", $markdown );

		// 1. Pull out fenced code blocks first so their contents are not
		//    interpreted as Markdown, replacing them with placeholders.
		$code_blocks = [];
		$text = preg_replace_callback(
			'/```[a-zA-Z0-9_-]*\n(.*?)```/s',
			static function ( $m ) use ( &$code_blocks ) {
				$key                 = "\x00CODE" . count( $code_blocks ) . "\x00";
				$code_blocks[ $key ] = '<pre><code>' . esc_html( $m[1] ) . '</code></pre>';
				return $key;
			},
			$text
		);

		// 2. Escape everything else, then re-introduce a safe HTML subset.
		$text = esc_html( $text );

		// Headings (# .. ######).
		$text = preg_replace_callback(
			'/^(#{1,6})[ \t]+(.+?)[ \t]*$/m',
			static function ( $m ) {
				$level = strlen( $m[1] );
				return "<h{$level}>{$m[2]}</h{$level}>";
			},
			$text
		);

		// Inline code, bold, then italic.
		$text = preg_replace( '/`([^`\n]+)`/', '<code>$1</code>', $text );
		$text = preg_replace( '/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/\*([^*\n]+)\*/', '<em>$1</em>', $text );

		// Links: [text](url). esc_html() above turned & into &amp; etc., so
		// decode before validating the URL.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( $m ) {
				$url = esc_url( html_entity_decode( $m[2], ENT_QUOTES ) );
				return $url ? '<a href="' . $url . '">' . $m[1] . '</a>' : $m[0];
			},
			$text
		);

		// Group consecutive list lines into <ul>/<ol>.
		$text = preg_replace_callback(
			'/(?:^[ \t]*[*\-+][ \t]+.*(?:\n|$))+/m',
			static function ( $m ) {
				$items = preg_split( '/\n/', trim( $m[0] ) );
				$html  = '';
				foreach ( $items as $item ) {
					$html .= '<li>' . trim( preg_replace( '/^[ \t]*[*\-+][ \t]+/', '', $item ) ) . '</li>';
				}
				return '<ul>' . $html . "</ul>\n";
			},
			$text
		);
		$text = preg_replace_callback(
			'/(?:^[ \t]*\d+\.[ \t]+.*(?:\n|$))+/m',
			static function ( $m ) {
				$items = preg_split( '/\n/', trim( $m[0] ) );
				$html  = '';
				foreach ( $items as $item ) {
					$html .= '<li>' . trim( preg_replace( '/^[ \t]*\d+\.[ \t]+/', '', $item ) ) . '</li>';
				}
				return '<ol>' . $html . "</ol>\n";
			},
			$text
		);

		// 3. Restore code blocks and add paragraphs / line breaks.
		$text = strtr( $text, $code_blocks );
		$text = wpautop( $text );

		return wp_kses_post( $text );
	}

	// =========================================================================
	// Hook registration
	// =========================================================================

	public function register_hooks(): void {
		// Only run on admin pages where update checks happen.
		if ( ! is_admin() ) {
			return;
		}

		if ( $this->type === 'plugin' ) {
			add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_plugin_update' ] );
			add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
		} else {
			add_filter( 'pre_set_site_transient_update_themes', [ $this, 'inject_theme_update' ] );
		}

		// These two filters handle authenticated download + folder renaming
		// for BOTH plugin and theme updates.
		add_filter( 'upgrader_pre_download',     [ $this, 'authenticated_download' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ],         10, 4 );
	}

	// =========================================================================
	// GitHub API
	// =========================================================================

	/**
	 * Fetches the latest published release from the GitHub API.
	 *
	 * Note: the `/releases/latest` endpoint returns the most recent
	 * *published, non-prerelease* release. Drafts and pre-releases
	 * (e.g. tags like "v1.2.3-beta") are never returned, so they will not
	 * trigger a WordPress update.
	 *
	 * Successful responses are cached for 5 minutes. Failures are cached for
	 * 1 minute (see cache_failure()) so an unreachable or rate-limited API is
	 * not re-hit on every admin page load.
	 *
	 * @return array|null  Decoded release object, or null on failure.
	 */
	private function get_latest_release(): ?array {
		// 1. In-memory cache (within a single request).
		if ( $this->release_cache !== null ) {
			return $this->release_cache;
		}

		// 2. Transient cache (across requests).
		$cached = get_transient( $this->cache_key );
		if ( is_array( $cached ) ) {
			$this->release_cache = $cached;
			return $cached;
		}
		if ( self::FAILURE_MARKER === $cached ) {
			// A recent lookup failed; don't hammer the API until it expires.
			return null;
		}

		// 3. Live API call.
		$response = wp_remote_get(
			"https://api.github.com/repos/{$this->repo}/releases/latest",
			[
				'headers' => $this->api_headers(),
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $this->cache_failure();
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $release['tag_name'] ) ) {
			return $this->cache_failure();
		}

		set_transient( $this->cache_key, $release, 5 * MINUTE_IN_SECONDS );
		$this->release_cache = $release;

		return $release;
	}

	/**
	 * Records a short-lived marker after a failed release lookup so a failing
	 * GitHub API (downtime, rate limiting, a bad token) is not re-hit on every
	 * admin page load. The short TTL means a genuine fix is picked up quickly.
	 * Returns null for convenience at the call site.
	 */
	private function cache_failure(): ?array {
		set_transient( $this->cache_key, self::FAILURE_MARKER, MINUTE_IN_SECONDS );
		return null;
	}

	/**
	 * Strips a leading "v" or "V" from a git tag to get a plain version string.
	 * e.g.  "v2.1.0"  →  "2.1.0"
	 */
	private function tag_to_version( string $tag ): string {
		return ltrim( $tag, 'vV' );
	}

	/**
	 * Returns the download URL for a release.
	 *
	 * When a release asset is configured (4th constructor argument), the
	 * matching asset is used — this gives full control over the zip contents.
	 * If no asset is configured, or the named asset is not present on the
	 * release, GitHub's auto-generated source zipball is used instead.
	 */
	private function download_url( array $release ): string {
		if ( $this->asset !== '' ) {
			foreach ( $release['assets'] ?? [] as $asset ) {
				if ( $asset['name'] === $this->asset ) {
					// Asset API URL; needs Accept: application/octet-stream to download.
					return $asset['url'];
				}
			}
		}

		return $release['zipball_url'];
	}

	/**
	 * Returns the HTTP headers required for a GitHub API request.
	 *
	 * @param bool $for_asset  Pass true when downloading a release asset binary.
	 */
	private function api_headers( bool $for_asset = false ): array {
		return [
			'Authorization'        => "Bearer {$this->token}",
			'Accept'               => $for_asset
				? 'application/octet-stream'
				: 'application/vnd.github+json',
			'User-Agent'           => 'WordPress-GitHubUpdater/2.0.1',
			'X-GitHub-Api-Version' => '2022-11-28',
		];
	}

	// =========================================================================
	// Update injection — Plugins
	// =========================================================================

	/**
	 * Injects update information into the plugin update transient when a newer
	 * release exists on GitHub.
	 */
	public function inject_plugin_update( object $transient ): object {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest = $this->tag_to_version( $release['tag_name'] );

		if ( version_compare( $latest, $this->current_version, '>' ) ) {
			$data = [
				'id'           => "github.com/{$this->repo}",
				'slug'         => dirname( $this->slug ),
				'plugin'       => $this->slug,
				'new_version'  => $latest,
				'package'      => $this->download_url( $release ),
				'url'          => "https://github.com/{$this->repo}",
				'icons'        => [],
				'banners'      => [],
				'banners_rtl'  => [],
			];

			// Only advertise compatibility values that are actually declared
			// in the plugin header, so nothing is invented or hard-coded.
			if ( $this->tested_wp !== '' ) {
				$data['tested'] = $this->tested_wp;
			}
			if ( $this->requires_php !== '' ) {
				$data['requires_php'] = $this->requires_php;
			}

			$transient->response[ $this->slug ] = (object) $data;
		}

		return $transient;
	}

	/**
	 * Provides plugin information (changelog, version, etc.) for the
	 * "View details" popup in the WordPress Plugins screen.
	 */
	public function plugin_info( $result, string $action, object $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}

		// Match on the folder slug (the part before the slash).
		if ( $args->slug !== dirname( $this->slug ) ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$description = $this->section_from_repo_file( [ 'README.md', 'readme.md' ] );
		if ( ! $description ) {
			$description = esc_html( $release['name'] ?? $this->display_name );
		}

		$changelog = $this->section_from_repo_file( [ 'CHANGELOG.md', 'changelog.md' ] );
		if ( ! $changelog ) {
			// The GitHub release body is also Markdown.
			$changelog = $this->markdown_to_html( $release['body'] ?? 'See GitHub Releases for changelog.' );
		}

		return (object) [
			'name'          => $this->display_name,
			'slug'          => $args->slug,
			'version'       => $this->tag_to_version( $release['tag_name'] ),
			'download_link' => $this->download_url( $release ),
			'last_updated'  => $release['published_at'] ?? '',
			'sections'      => [
				'description' => $description,
				'changelog'   => $changelog,
			],
			'external'      => true,
		];
	}

	// =========================================================================
	// Update injection — Themes
	// =========================================================================

	/**
	 * Injects update information into the theme update transient when a newer
	 * release exists on GitHub.
	 */
	public function inject_theme_update( object $transient ): object {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest = $this->tag_to_version( $release['tag_name'] );

		if ( version_compare( $latest, $this->current_version, '>' ) ) {
			$data = [
				'theme'       => $this->slug,
				'new_version' => $latest,
				'package'     => $this->download_url( $release ),
				'url'         => "https://github.com/{$this->repo}",
			];

			// Only advertise compatibility values declared in the theme's
			// style.css headers, rather than hard-coded defaults.
			if ( $this->requires_wp !== '' ) {
				$data['requires'] = $this->requires_wp;
			}
			if ( $this->requires_php !== '' ) {
				$data['requires_php'] = $this->requires_php;
			}

			$transient->response[ $this->slug ] = $data;
		}

		return $transient;
	}

	// =========================================================================
	// Authenticated download
	// =========================================================================

	/**
	 * Intercepts the WordPress upgrade download step for our packages and
	 * performs the download with the GitHub Authorization header attached.
	 *
	 * Without this, private repos return 404 because the default WordPress
	 * downloader sends no authentication.
	 *
	 * Returns:
	 *   string   — path to the downloaded temp file (success)
	 *   WP_Error — on failure
	 *   false    — when the package does not belong to this updater (let WP handle it)
	 */
	public function authenticated_download( $result, string $package, object $upgrader ) {
		// Another filter has already handled this download.
		if ( false !== $result ) {
			return $result;
		}

		// Not our package — let WordPress proceed normally.
		if ( ! $this->is_our_package( $package ) ) {
			return false;
		}

		// Release assets use a different Accept header than the source zipball.
		$is_asset = str_contains( $package, '/releases/assets/' );
		$tmp_file  = wp_tempnam( 'github-update' );

		$response = wp_remote_get(
			$package,
			[
				'headers'  => $this->api_headers( $is_asset ),
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $tmp_file,
			]
		);

		if ( is_wp_error( $response ) ) {
			// Clean up temp file before returning the error.
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code !== 200 ) {
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
			return new WP_Error(
				'github_updater_http_error',
				/* translators: 1: HTTP status code, 2: URL */
				sprintf( 'GitHub returned HTTP %1$d for: %2$s', $code, $package )
			);
		}

		return $tmp_file;
	}

	/**
	 * Checks whether a download URL belongs to this updater's repository.
	 *
	 * The match is anchored to the GitHub host and bounded by a trailing
	 * slash, because real package URLs always carry a path segment after the
	 * repo (".../repos/owner/repo/zipball/…", ".../releases/assets/123").
	 * The trailing slash also stops a repo name from matching a longer one
	 * (e.g. "me/plugin" must not match "me/plugin-pro").
	 */
	private function is_our_package( string $url ): bool {
		return str_contains( $url, "://api.github.com/repos/{$this->repo}/" )
			|| str_contains( $url, "://github.com/{$this->repo}/" );
	}

	// =========================================================================
	// Source directory rename
	// =========================================================================

	/**
	 * GitHub zipballs extract to a randomly-named folder like
	 * "{owner}-{repo}-{short-sha}/", but WordPress expects a folder that
	 * matches the plugin/theme slug. This filter renames it.
	 *
	 * Only runs for our own plugin/theme; other updates are left untouched.
	 */
	public function fix_source_dir(
		string $source,
		string $remote_source,
		object $upgrader,
		array $hook_extra = []
	): string {
		global $wp_filesystem;

		// Only rename for our own update.
		if ( ! $this->is_our_hook_extra( $hook_extra ) ) {
			return $source;
		}

		// Derive the expected destination path.
		$folder_slug = ( $this->type === 'plugin' ) ? dirname( $this->slug ) : $this->slug;
		$expected    = trailingslashit( $remote_source ) . $folder_slug . '/';

		// Nothing to do if the folder already has the right name.
		if ( $wp_filesystem->is_dir( $expected ) ) {
			return $expected;
		}

		// Move (rename) the extracted folder.
		if ( ! $wp_filesystem->move( $source, $expected ) ) {
			// If the rename fails, return the original source so the
			// upgrade can still be attempted rather than failing silently.
			return $source;
		}

		return $expected;
	}

	/**
	 * Returns true when the hook_extra array matches this updater's plugin/theme.
	 */
	private function is_our_hook_extra( array $hook_extra ): bool {
		if ( $this->type === 'plugin' ) {
			return isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->slug;
		}

		return isset( $hook_extra['theme'] ) && $hook_extra['theme'] === $this->slug;
	}

	// =========================================================================
	// Utilities
	// =========================================================================

	/**
	 * Clears the cached release data. Call this if you need to force a fresh
	 * check, e.g. immediately after deploying a new release.
	 */
	public function clear_cache(): void {
		$this->release_cache = null;
		delete_transient( $this->cache_key );
	}
}

endif; // class_exists( 'GitHubUpdater' )
