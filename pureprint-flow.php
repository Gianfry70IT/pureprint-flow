<?php
/**
 * Plugin Name: PurePrint Flow
 * Plugin URI:  https://speed-print.it
 * Description: Sistema avanzato di upload e configurazione stampe (Fotoquadri/Tele) per WooCommerce.
 * Version:     2.4.0
 * Author:      NeroDigitale di Gianfranco Greco
 * Author URI:  https://nerodigitale.it
 * License:     GPL-2.0+
 * Text Domain: pureprint-flow
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define Plugin Constants
define( 'PUREPRINT_VERSION', '2.4.0' );
define( 'PUREPRINT_PATH', plugin_dir_path( __FILE__ ) );
define( 'PUREPRINT_URL', plugin_dir_url( __FILE__ ) );

// Setup Upload Paths
$upload_dir = wp_upload_dir();
define( 'PUREPRINT_UPLOAD_DIR', $upload_dir['basedir'] . '/pureprint' );
define( 'PUREPRINT_UPLOAD_URL', $upload_dir['baseurl'] . '/pureprint' );

/**
 * Activation Handler
 */
register_activation_hook( __FILE__, 'pureprint_flow_activate' );
function pureprint_flow_activate() {
	// Create upload folder
	if ( ! file_exists( PUREPRINT_UPLOAD_DIR ) ) {
		wp_mkdir_p( PUREPRINT_UPLOAD_DIR );
	}

	// Create index.php to prevent directory listing
	$index_file = PUREPRINT_UPLOAD_DIR . '/index.php';
	if ( ! file_exists( $index_file ) ) {
		file_put_contents( $index_file, '<?php // Silence is golden' );
	}

	// Create .htaccess if it doesn't exist
	$htaccess_file = PUREPRINT_UPLOAD_DIR . '/.htaccess';
	if ( ! file_exists( $htaccess_file ) ) {
		$rules = "Options -Indexes\n";
		file_put_contents( $htaccess_file, $rules );
	}

	// Schedule cleanup event
	if ( ! wp_next_scheduled( 'pureprint_daily_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'pureprint_daily_cleanup' );
	}
}

/**
 * Deactivation Handler
 */
register_deactivation_hook( __FILE__, 'pureprint_flow_deactivate' );
function pureprint_flow_deactivate() {
	wp_clear_scheduled_hook( 'pureprint_daily_cleanup' );
}

// Load Class Files
require_once PUREPRINT_PATH . 'includes/class-pureprint-flow-frontend.php';
require_once PUREPRINT_PATH . 'includes/class-pureprint-flow-admin.php';
require_once PUREPRINT_PATH . 'includes/class-pureprint-flow-pdf.php';
require_once PUREPRINT_PATH . 'includes/class-pureprint-flow-github-updater.php';

// Initialize core components
add_action( 'plugins_loaded', 'pureprint_flow_init' );
function pureprint_flow_init() {
	if ( class_exists( 'WooCommerce' ) ) {
		PurePrint_Flow_Frontend::init();
		PurePrint_Flow_Admin::init();
	}

	// GitHub auto-updater (works independently from WooCommerce)
	if ( is_admin() ) {
		new PurePrint_Flow_GitHub_Updater(
			PUREPRINT_PATH . 'pureprint-flow.php',
			'Gianfry70IT',
			'pureprint-flow'
		);
	}
}

// Declare compatibility with WooCommerce HPOS (High-Performance Order Storage)
add_action( 'before_woocommerce_init', 'pureprint_flow_declare_hpos_compatibility' );
function pureprint_flow_declare_hpos_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}


/**
 * Daily Cleanup WP Cron Handler
 * Deletes uploaded files older than 30 days, unless they are linked to an active WooCommerce order.
 */
add_action( 'pureprint_daily_cleanup', 'pureprint_flow_run_cleanup' );
function pureprint_flow_run_cleanup() {
	if ( ! file_exists( PUREPRINT_UPLOAD_DIR ) ) {
		return;
	}

	$files = glob( PUREPRINT_UPLOAD_DIR . '/*' );
	if ( empty( $files ) ) {
		return;
	}

	$now = time();
	$expiry_time = 30 * DAY_IN_SECONDS; // 30 days retention

	global $wpdb;

	foreach ( $files as $file ) {
		if ( is_dir( $file ) ) {
			continue;
		}

		$filename = basename( $file );
		if ( $filename === 'index.php' || $filename === '.htaccess' ) {
			continue;
		}

		// Check file age
		$filemtime = filemtime( $file );
		if ( ( $now - $filemtime ) < $expiry_time ) {
			continue; // Keep recent uploads
		}

		// Query if this file is referenced in any order item meta
		$escaped_filename = '%' . $wpdb->esc_like( $filename ) . '%';
		$is_referenced = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_value LIKE %s",
			$escaped_filename
		) );

		if ( ! $is_referenced ) {
			// File is not referenced in any order, safe to delete!
			@unlink( $file );
		} else {
			// File is referenced. Let's see if the order is completed/cancelled.
			$order_item_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_value LIKE %s",
				$escaped_filename
			) );

			if ( ! empty( $order_item_ids ) ) {
				$should_delete = true;

				foreach ( $order_item_ids as $item_id ) {
					$order_id = $wpdb->get_var( $wpdb->prepare(
						"SELECT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id = %d",
						$item_id
					) );

					if ( $order_id ) {
						$order = wc_get_order( $order_id );
						if ( $order ) {
							$status = $order->get_status();
							if ( in_array( $status, array( 'pending', 'on-hold', 'processing' ), true ) ) {
								$should_delete = false;
								break;
							}
						}
					}
				}

				if ( $should_delete ) {
					@unlink( $file );
				}
			}
		}
	}
}
