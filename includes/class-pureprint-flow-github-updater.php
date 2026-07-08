<?php
/**
 * PurePrint Flow - GitHub Updater
 * Enables automatic plugin updates from a public GitHub repository.
 * Checks GitHub Releases API for new versions and integrates with
 * the WordPress plugin update system (transient + plugin info).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PurePrint_Flow_GitHub_Updater {

	private $slug;          // Plugin slug (folder name)
	private $plugin_file;   // Full plugin file path relative to plugins dir (e.g. pureprint-flow/pureprint-flow.php)
	private $github_user;
	private $github_repo;
	private $current_version;
	private $github_response; // Cache API response for the request lifecycle

	public function __construct( $plugin_file, $github_user, $github_repo ) {
		$this->plugin_file    = plugin_basename( $plugin_file );
		$this->slug           = dirname( $this->plugin_file );
		$this->github_user    = $github_user;
		$this->github_repo    = $github_repo;
		$this->current_version = PUREPRINT_VERSION;

		// Hook into WordPress update system
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'post_install' ), 10, 3 );

		// Add a "View on GitHub" link in plugins page
		add_filter( 'plugin_action_links_' . $this->plugin_file, array( $this, 'action_links' ) );
	}

	/**
	 * Fetch latest release data from GitHub API.
	 * Cached per request lifecycle + WordPress transient (12 hours).
	 */
	private function get_github_release() {
		if ( null !== $this->github_response ) {
			return $this->github_response;
		}

		// Check transient cache first (avoid hitting GitHub API on every page load)
		$cache_key = 'pureprint_github_release';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$this->github_response = $cached;
			return $cached;
		}

		$url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			$this->github_user,
			$this->github_repo
		);

		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'PurePrint-Flow-Updater/' . $this->current_version,
			),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$this->github_response = false;
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $body ) || ! isset( $body->tag_name ) ) {
			$this->github_response = false;
			return false;
		}

		$this->github_response = $body;

		// Cache for 12 hours
		set_transient( $cache_key, $body, 12 * HOUR_IN_SECONDS );

		return $body;
	}

	/**
	 * Extract clean version number from GitHub tag (strips leading 'v' if present).
	 */
	private function parse_version( $tag ) {
		return ltrim( $tag, 'vV' );
	}

	/**
	 * Get the download URL for the release zip.
	 * Prefers a .zip asset attached to the release. Falls back to the auto-generated source zip.
	 */
	private function get_download_url( $release ) {
		// Check for uploaded .zip asset first (e.g. pureprint-flow.zip)
		if ( ! empty( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if ( substr( $asset->name, -4 ) === '.zip' ) {
					return $asset->browser_download_url;
				}
			}
		}

		// Fallback: GitHub auto-generated source zipball
		return $release->zipball_url;
	}

	/**
	 * Hook: Check for plugin updates.
	 * Injects our plugin into the WordPress update transient if a newer version exists.
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_github_release();
		if ( false === $release ) {
			return $transient;
		}

		$latest_version = $this->parse_version( $release->tag_name );

		if ( version_compare( $latest_version, $this->current_version, '>' ) ) {
			$transient->response[ $this->plugin_file ] = (object) array(
				'slug'        => $this->slug,
				'plugin'      => $this->plugin_file,
				'new_version' => $latest_version,
				'url'         => $release->html_url,
				'package'     => $this->get_download_url( $release ),
				'icons'       => array(),
				'banners'     => array(),
				'tested'      => '',
				'requires_php'=> '7.4',
			);
		}

		return $transient;
	}

	/**
	 * Hook: Provide plugin information for the "View Details" popup.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_github_release();
		if ( false === $release ) {
			return $result;
		}

		$latest_version = $this->parse_version( $release->tag_name );

		$info = new stdClass();
		$info->name          = 'PurePrint Flow';
		$info->slug          = $this->slug;
		$info->version       = $latest_version;
		$info->author        = '<a href="https://nerodigitale.it">NeroDigitale di Gianfranco Greco</a>';
		$info->homepage      = 'https://github.com/' . $this->github_user . '/' . $this->github_repo;
		$info->requires      = '5.8';
		$info->requires_php  = '7.4';
		$info->tested        = get_bloginfo( 'version' );
		$info->download_link = $this->get_download_url( $release );
		$info->trunk         = $this->get_download_url( $release );
		$info->last_updated  = $release->published_at;

		// Convert markdown release notes to HTML (basic)
		$info->sections = array(
			'description' => 'Sistema avanzato di upload e configurazione stampe (Fotoquadri/Tele) per WooCommerce.',
			'changelog'   => nl2br( esc_html( $release->body ) ),
		);

		return $info;
	}

	/**
	 * Hook: After install, ensure the plugin folder name is correct.
	 * GitHub source zips use "repo-name-hash" folder names which break the plugin path.
	 */
	public function post_install( $response, $hook_extra, $result ) {
		// Only process our plugin
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
			return $result;
		}

		global $wp_filesystem;

		$install_dir = $result['destination'];
		$proper_dir  = WP_PLUGIN_DIR . '/' . $this->slug;

		// If the installed folder name doesn't match our slug, rename it
		if ( $install_dir !== $proper_dir ) {
			$wp_filesystem->move( $install_dir, $proper_dir );
			$result['destination']    = $proper_dir;
			$result['destination_name'] = $this->slug;
		}

		// Re-activate plugin after update
		activate_plugin( $this->plugin_file );

		return $result;
	}

	/**
	 * Hook: Add "GitHub" link to plugin action links.
	 */
	public function action_links( $links ) {
		$github_link = sprintf(
			'<a href="https://github.com/%s/%s" target="_blank">GitHub</a>',
			esc_attr( $this->github_user ),
			esc_attr( $this->github_repo )
		);
		array_unshift( $links, $github_link );
		return $links;
	}

	/**
	 * Clear the cached GitHub release data.
	 * Useful to force a fresh check after pushing a new release.
	 */
	public static function clear_cache() {
		delete_transient( 'pureprint_github_release' );
	}
}
