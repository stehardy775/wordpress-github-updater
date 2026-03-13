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
 * @version 1.0.0
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

	/** In-memory cache of the latest GitHub release data. */
	private ?array $release_cache = null;

	/** In-memory cache for repository file lookups by path. */
	private array $repo_file_cache = [];

	/** Transient key used to cache the GitHub API response. */
	private string $cache_key;

	// =========================================================================
	// Boot
	// =========================================================================

	public function __construct( string $file, string $repo, string $token ) {
		$this->file      = $file;
		$this->repo      = $repo;
		$this->token     = $token;
		$this->cache_key = 'ghu_' . md5( $repo );

		$this->detect_type_and_slug();

		add_action( 'init', [ $this, 'register_hooks' ] );
	}

	/**
	 * Determines whether this instance manages a plugin or a theme, then
	 * sets $this->slug and $this->current_version accordingly.
	 */
	private function detect_type_and_slug(): void {
		$themes_dir = WP_CONTENT_DIR . '/themes';

		if ( str_starts_with( $this->file, $themes_dir ) ) {

			$this->type    = 'theme';
			$this->slug    = basename( dirname( $this->file ) );
			$theme         = wp_get_theme( $this->slug );
			$this->current_version = $theme->exists() ? ( $theme->get( 'Version' ) ?: '0.0.0' ) : '0.0.0';
			$this->display_name    = $theme->exists() ? ( $theme->get( 'Name' ) ?: $this->slug ) : $this->slug;

		} else {

			$this->type    = 'plugin';
			$this->slug    = plugin_basename( $this->file );
			$headers       = get_file_data(
				$this->file,
				[
					'Name'    => 'Plugin Name',
					'Version' => 'Version',
				]
			);
			$this->current_version = $headers['Version'] ?: '0.0.0';
			$this->display_name    = $headers['Name'] ?: dirname( $this->slug );

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
	 * Returns a text file rendered safely for a WordPress plugin info section.
	 */
	private function section_from_repo_file( array $candidate_paths ): ?string {
		foreach ( $candidate_paths as $path ) {
			$content = $this->get_repo_file( $path );
			if ( is_string( $content ) && $content !== '' ) {
				return nl2br( esc_html( $content ) );
			}
		}

		return null;
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
	 * Fetches the latest release from the GitHub API.
	 * Results are cached in a transient for 5 minutes to avoid hammering the API.
	 *
	 * @return array|null  Decoded release object, or null on failure.
	 */
	private function get_latest_release(): ?array {
		// 1. In-memory cache (within a single request).
		if ( $this->release_cache !== null ) {
			return $this->release_cache;
		}

		// 2. Transient cache (across requests, 5 min TTL).
		$cached = get_transient( $this->cache_key );
		if ( is_array( $cached ) ) {
			$this->release_cache = $cached;
			return $cached;
		}

		// 3. Live API call.
		$response = wp_remote_get(
			"https://api.github.com/repos/{$this->repo}/releases/latest",
			[
				'headers' => $this->api_headers(),
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $release['tag_name'] ) ) {
			return null;
		}

		set_transient( $this->cache_key, $release, 5 * MINUTE_IN_SECONDS );
		$this->release_cache = $release;

		return $release;
	}

	/**
	 * Strips a leading "v" or "V" from a git tag to get a plain version string.
	 * e.g.  "v2.1.0"  →  "2.1.0"
	 */
	private function tag_to_version( string $tag ): string {
		return ltrim( $tag, 'vV' );
	}

	/**
	 * Returns the best download URL for a release.
	 *
	 * Priority:
	 *   1. The first .zip file attached as a release asset.
	 *      Use this when you need full control over zip contents.
	 *   2. GitHub's auto-generated source zipball.
	 *      Simple to use — no manual zip step needed when publishing.
	 */
	private function download_url( array $release ): string {
		foreach ( $release['assets'] ?? [] as $asset ) {
			if ( str_ends_with( strtolower( $asset['name'] ), '.zip' ) ) {
				// Asset API URL; needs Accept: application/octet-stream to download.
				return $asset['url'];
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
			'User-Agent'           => 'WordPress-GitHubUpdater/1.1',
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
			$transient->response[ $this->slug ] = (object) [
				'id'           => "github.com/{$this->repo}",
				'slug'         => dirname( $this->slug ),
				'plugin'       => $this->slug,
				'new_version'  => $latest,
				'package'      => $this->download_url( $release ),
				'url'          => "https://github.com/{$this->repo}",
				'icons'        => [],
				'banners'      => [],
				'banners_rtl'  => [],
				'tested'       => get_bloginfo( 'version' ),
				'requires_php' => '8.0',
			];
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
			$changelog = nl2br( esc_html( $release['body'] ?? 'See GitHub Releases for changelog.' ) );
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
			$transient->response[ $this->slug ] = [
				'theme'        => $this->slug,
				'new_version'  => $latest,
				'package'      => $this->download_url( $release ),
				'url'          => "https://github.com/{$this->repo}",
				'requires'     => '6.0',
				'requires_php' => '8.0',
			];
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
	 */
	private function is_our_package( string $url ): bool {
		return str_contains( $url, "api.github.com/repos/{$this->repo}" )
			|| str_contains( $url, "github.com/{$this->repo}" );
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
