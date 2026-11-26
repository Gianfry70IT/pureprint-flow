<?php
/**
 * PurePrint Flow - Frontend Controller
 * Handles assets, secure AJAX upload, EXIF rotation, WooCommerce cart and checkout hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PurePrint_Flow_Frontend {

	public static function init() {
		// Enqueue scripts & styles
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// Inject customizer placeholder into product gallery area
		add_action( 'woocommerce_before_single_product_summary', array( __CLASS__, 'inject_gallery_placeholder' ), 5 );

		// AJAX Upload Handlers
		add_action( 'wp_ajax_pureprint_upload_image', array( __CLASS__, 'ajax_upload_image' ) );
		add_action( 'wp_ajax_nopriv_pureprint_upload_image', array( __CLASS__, 'ajax_upload_image' ) );

		// AJAX Session Auto-Save (Fallback for Store API & Block Themes)
		add_action( 'wp_ajax_pureprint_save_session_config', array( __CLASS__, 'ajax_save_session_config' ) );
		add_action( 'wp_ajax_nopriv_pureprint_save_session_config', array( __CLASS__, 'ajax_save_session_config' ) );

		// WooCommerce Cart Integration
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 4 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'get_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( __CLASS__, 'cart_item_thumbnail' ), 10, 3 );
		add_filter( 'woocommerce_store_api_cart_item_images', array( __CLASS__, 'store_api_cart_item_images' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'checkout_create_order_line_item' ), 10, 4 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'store_api_checkout_order_processed' ), 10, 1 );
	}

	/**
	 * Support type configured on the product: 'tela' (canvas on frame, wrapped edges) or 'piatto' (flat panel).
	 */
	public static function get_support_type( $product_id ) {
		$type = get_post_meta( $product_id, '_pureprint_support_type', true );
		return ( 'piatto' === $type ) ? 'piatto' : 'tela';
	}

	/**
	 * Bleed (mm per side) used for flat panels. Defaults to 3 mm.
	 */
	public static function get_flat_bleed_mm( $product_id ) {
		$bleed = get_post_meta( $product_id, '_pureprint_flat_bleed_mm', true );
		return ( '' === $bleed || false === $bleed ) ? 3 : max( 0, min( 20, intval( $bleed ) ) );
	}

	/**
	 * Inject a placeholder div into the product gallery column for PurePrint-enabled products.
	 * This runs at priority 5, before the default WooCommerce gallery (priority 20).
	 */
	public static function inject_gallery_placeholder() {
		if ( ! is_product() ) {
			return;
		}

		$product_id = get_the_ID();
		if ( ! $product_id ) {
			return;
		}

		$enabled = get_post_meta( $product_id, '_pureprint_enabled', true );
		if ( 'yes' !== $enabled ) {
			return;
		}

		// Output a placeholder container that JS will use as the injection point
		echo '<div id="pureprint-gallery-placeholder" class="pureprint-gallery-placeholder"></div>';

		// Add inline CSS to hide the default WooCommerce gallery
		echo '<style>.woocommerce div.product div.images.pureprint-gallery-hidden { display: none !important; }</style>';
	}

	public static function enqueue_assets() {
		if ( ! is_product() ) {
			return;
		}

		$product_id = get_the_ID();
		$enabled = get_post_meta( $product_id, '_pureprint_enabled', true );

		if ( 'yes' === $enabled ) {
			// Cache busting parameter
			$version = time();

			wp_enqueue_style( 'pureprint-frontend', PUREPRINT_URL . 'assets/css/pureprint-frontend-v2.css', array(), $version );
			
			// Load pureprint frontend script
			wp_enqueue_script( 'pureprint-frontend', PUREPRINT_URL . 'assets/js/pureprint-frontend-v2.js', array( 'jquery' ), $version, true );
			
			// Localize script for AJAX
			wp_localize_script( 'pureprint-frontend', 'pureprint_vars', array(
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'pureprint_upload_nonce' ),
				'gallery_replace' => true,
				'support_type'    => self::get_support_type( $product_id ),
				'flat_bleed_mm'   => self::get_flat_bleed_mm( $product_id ),
			) );
		}
	}

	/**
	 * Secure AJAX Image Upload Handler
	 */
	public static function ajax_upload_image() {
		// Check nonce
		if ( ! isset( $_POST['pureprint_nonce'] ) || ! wp_verify_nonce( $_POST['pureprint_nonce'], 'pureprint_upload_nonce' ) ) {
			wp_send_json_error( 'Richiesta non autorizzata o sessione scaduta.' );
		}

		// Check file field
		if ( ! isset( $_FILES['image_file'] ) || empty( $_FILES['image_file']['name'] ) ) {
			wp_send_json_error( 'Nessun file ricevuto.' );
		}

		$file = $_FILES['image_file'];

		// Validate file size (e.g. limit to 30MB)
		$max_size = 30 * 1024 * 1024;
		if ( $file['size'] > $max_size ) {
			wp_send_json_error( 'Il file è troppo grande. Dimensione massima: 30MB.' );
		}

		// Validate file type
		$filetype = wp_check_filetype( $file['name'] );
		$allowed_exts = array( 'jpg', 'jpeg', 'png' );
		$allowed_mimes = array( 'image/jpeg', 'image/png' );

		if ( ! in_array( strtolower( $filetype['ext'] ), $allowed_exts, true ) || ! in_array( $file['type'], $allowed_mimes, true ) ) {
			wp_send_json_error( 'Formato file non supportato. Carica solo immagini JPG, JPEG o PNG.' );
		}

		// Create upload directory
		if ( ! file_exists( PUREPRINT_UPLOAD_DIR ) ) {
			wp_mkdir_p( PUREPRINT_UPLOAD_DIR );
		}

		// Generate random secure filename
		$ext = '.' . $filetype['ext'];
		$random_hash = md5( uniqid( rand(), true ) );
		$new_filename = 'pp_' . substr( $random_hash, 0, 12 ) . '_' . time() . $ext;
		$destination = PUREPRINT_UPLOAD_DIR . '/' . $new_filename;

		// Move file to target dir
		if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) {
			wp_send_json_error( 'Impossibile salvare il file sul server.' );
		}

		// Auto-rotate image based on EXIF orientation metadata
		self::auto_rotate_image( $destination );

		// Retrieve dimensions of the rotated image
		$dims = @getimagesize( $destination );
		if ( ! $dims ) {
			@unlink( $destination );
			wp_send_json_error( 'Immagine non valida o corrotta.' );
		}

		// Send success response
		wp_send_json_success( array(
			'name'   => $new_filename,
			'url'    => PUREPRINT_UPLOAD_URL . '/' . $new_filename,
			'width'  => $dims[0],
			'height' => $dims[1]
		) );
	}

	/**
	 * AJAX Handler: Auto-save configuration to WooCommerce Session
	 */
	public static function ajax_save_session_config() {
		if ( isset( WC()->session ) && isset( $_POST['pureprint_file_name'] ) ) {
			// Ensure session is initialized
			if ( ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}
			WC()->session->set( 'pureprint_current_config', wp_unslash( $_POST ) );
		}
		wp_send_json_success();
	}

	/**
	 * Physical Auto-Rotation using PHP GD based on EXIF data.
	 * Resolves orientation flags 3 (180 deg), 6 (90 deg CW), 8 (90 deg CCW).
	 */
	private static function auto_rotate_image( $file_path ) {
		if ( ! function_exists( 'exif_read_data' ) ) {
			return;
		}

		$type = exif_imagetype( $file_path );
		if ( $type !== IMAGETYPE_JPEG ) {
			return; // Only JPEG photos have EXIF orientation metadata
		}

		$exif = @exif_read_data( $file_path );
		if ( empty( $exif['Orientation'] ) ) {
			return;
		}

		$ort = $exif['Orientation'];
		if ( ! in_array( $ort, array( 3, 6, 8 ), true ) ) {
			return; // Standard orientation
		}

		$image = @imagecreatefromjpeg( $file_path );
		if ( ! $image ) {
			return;
		}

		$rotated = false;
		switch ( $ort ) {
			case 3:
				$image = imagerotate( $image, 180, 0 );
				$rotated = true;
				break;
			case 6:
				$image = imagerotate( $image, -90, 0 );
				$rotated = true;
				break;
			case 8:
				$image = imagerotate( $image, 90, 0 );
				$rotated = true;
				break;
		}

		if ( $rotated && $image ) {
			// Save rotated image replacing original, maintaining high quality (95)
			imagejpeg( $image, $file_path, 95 );
		}

		if ( is_resource( $image ) || $image instanceof GDImage ) {
			imagedestroy( $image );
		}
	}

	/**
	 * WooCommerce Cart Hook: Add PurePrint data to Cart Item Session
	 */
	public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id, $quantity ) {
		$source_data = array();

		if ( isset( $_POST['pureprint_file_name'] ) && ! empty( $_POST['pureprint_file_name'] ) ) {
			$source_data = wp_unslash( $_POST );
		} elseif ( isset( WC()->session ) ) {
			$session_data = WC()->session->get( 'pureprint_current_config' );
			if ( ! empty( $session_data['pureprint_file_name'] ) ) {
				$source_data = $session_data;
				// Clear to prevent leaking to other cart additions
				WC()->session->set( 'pureprint_current_config', null );
			}
		}

		if ( ! empty( $source_data['pureprint_file_name'] ) ) {
			// Secure check: Sanitize the filename to prevent directory traversal
			$filename = sanitize_file_name( $source_data['pureprint_file_name'] );
			$filepath = PUREPRINT_UPLOAD_DIR . '/' . $filename;

			// Verify file exists
			if ( file_exists( $filepath ) ) {
				$data = array(
					'file_name'    => $filename,
					'file_url'     => PUREPRINT_UPLOAD_URL . '/' . $filename,
					'rel_x'        => floatval( $source_data['pureprint_rel_x'] ),
					'rel_y'        => floatval( $source_data['pureprint_rel_y'] ),
					'rel_w'        => floatval( $source_data['pureprint_rel_w'] ),
					'rel_h'        => floatval( $source_data['pureprint_rel_h'] ),
					'front_w_mm'   => intval( $source_data['pureprint_front_w_mm'] ),
					'front_h_mm'   => intval( $source_data['pureprint_front_h_mm'] ),
					'thickness_mm' => intval( $source_data['pureprint_thickness_mm'] ),
					'bleed_mm'     => isset( $source_data['pureprint_bleed_mm'] ) ? intval( $source_data['pureprint_bleed_mm'] ) : 0,
					'border_type'  => sanitize_text_field( $source_data['pureprint_border_type'] ),
					'dpi'          => intval( $source_data['pureprint_dpi'] ),
					'support_type' => self::get_support_type( $product_id ),
				);

				// Flat panels: no frame and no wrap. Enforce server side, whatever the browser sent.
				if ( 'piatto' === $data['support_type'] ) {
					$data['thickness_mm'] = 0;
					$data['bleed_mm']     = self::get_flat_bleed_mm( $product_id );
					$data['border_type']  = 'foto';
				}

				$cart_item_data['pureprint_data'] = $data;

				// Bulletproof fallback for Store API checkout where cart might be cleared
				// Store the configuration in a transient, linked to the user's session
				if ( isset( WC()->session ) ) {
					$session_id = WC()->session->get_customer_id();
					if ( $session_id ) {
						$fallback_data = get_transient( 'pureprint_fallback_' . $session_id );
						if ( ! is_array( $fallback_data ) ) {
							$fallback_data = array();
						}
						// Key by product_id + variation_id to match during order processing
						$key = $product_id . '_' . $variation_id;
						$fallback_data[ $key ] = $data;
						set_transient( 'pureprint_fallback_' . $session_id, $fallback_data, HOUR_IN_SECONDS * 2 );
					}
				}
			}
		}
		return $cart_item_data;
	}

	/**
	 * WooCommerce Cart Hook: Display configuration metadata inside Cart and Checkout
	 */
	public static function get_item_data( $item_data, $cart_item ) {
		if ( isset( $cart_item['pureprint_data'] ) ) {
			$data = $cart_item['pureprint_data'];
			
			$size_desc = ( $data['front_w_mm'] / 10 ) . 'x' . ( $data['front_h_mm'] / 10 ) . ' cm';
			$thick_desc = ( $data['thickness_mm'] / 10 ) . ' cm';
			$border_desc = ( $data['border_type'] === 'foto' ) ? 'Tela avvolta sui lati' : 'Bordo bianco';
			
			$item_data[] = array(
				'key'     => 'Dimensione Stampa',
				'value'   => $size_desc,
				'display' => $size_desc
			);

			// Frame thickness and wrap only make sense for canvas on frame.
			$is_flat = isset( $data['support_type'] ) && 'piatto' === $data['support_type'];
			if ( $is_flat ) {
				// The orientation may be "Automatico" (follows the photo): show the one actually printed.
				if ( $data['front_w_mm'] > $data['front_h_mm'] ) {
					$orient_desc = 'Orizzontale';
				} elseif ( $data['front_w_mm'] < $data['front_h_mm'] ) {
					$orient_desc = 'Verticale';
				} else {
					$orient_desc = 'Quadrato';
				}
				$item_data[] = array(
					'key'     => 'Orientamento stampa',
					'value'   => $orient_desc,
					'display' => $orient_desc
				);
			} else {
				$item_data[] = array(
					'key'     => 'Spessore Telaio',
					'value'   => $thick_desc,
					'display' => $thick_desc
				);

				$item_data[] = array(
					'key'     => 'Bordo',
					'value'   => $border_desc,
					'display' => $border_desc
				);
			}

			$item_data[] = array(
				'key'     => 'Risoluzione Immagine',
				'value'   => $data['dpi'] . ' DPI',
				'display' => $data['dpi'] . ' DPI'
			);
		}
		return $item_data;
	}

	/**
	 * WooCommerce Cart Hook: Customize Cart Thumbnail preview using CSS crop
	 */
	public static function cart_item_thumbnail( $thumbnail, $cart_item, $cart_item_key ) {
		if ( isset( $cart_item['pureprint_data'] ) ) {
			$data = $cart_item['pureprint_data'];
			
			$bleed_mm = isset( $data['bleed_mm'] ) ? $data['bleed_mm'] : 0;
			$total_thickness = $data['thickness_mm'] + $bleed_mm;
			
			// Total print size for aspect ratio
			$Pw = $data['front_w_mm'] + 2 * $total_thickness;
			$Ph = $data['front_h_mm'] + 2 * $total_thickness;

			// Shading sizes
			$left_pct = ( $total_thickness / $Pw ) * 100;
			$top_pct = ( $total_thickness / $Ph ) * 100;
			$w_pct = ( $data['front_w_mm'] / $Pw ) * 100;
			$h_pct = ( $data['front_h_mm'] / $Ph ) * 100;

			// Check coordinates type:
			// rel_x/rel_y are already calculated relative to the viewport (mode 'foto') or front face (mode 'bianco')
			// We can style a viewport thumbnail exactly like our editor!
			$img_css = '';
			if ( $data['border_type'] === 'foto' ) {
				// Relative to viewport
				$img_css = sprintf(
					'width: %f%%; height: auto; left: 0; top: 0; transform: translate(%f%%, %f%%);',
					$data['rel_w'] * 100,
					$data['rel_x'] * 100 / $data['rel_w'], // Adjust translate due to scaling if using nested styles
					$data['rel_y'] * 100 / $data['rel_w']
				);
				// To keep it simple in CSS absolute coordinates:
				$img_css = sprintf(
					'width: %f%%; height: auto; position: absolute; left: %f%%; top: %f%%;',
					$data['rel_w'] * 100,
					$data['rel_x'] * 100,
					$data['rel_y'] * 100
				);
			} else {
				// Relative to front face
				// To draw in viewport coordinates:
				// viewport width is Pw. front face width is Fw.
				// image width relative to viewport is rel_w * (Fw / Pw)
				// image left relative to viewport is LeftOffset + rel_x * (Fw / Pw)
				$ratio_w = $data['front_w_mm'] / $Pw;
				$ratio_h = $data['front_h_mm'] / $Ph;

				$rel_w_viewport = $data['rel_w'] * $ratio_w;
				$rel_x_viewport = $left_pct / 100 + $data['rel_x'] * $ratio_w;
				$rel_y_viewport = $top_pct / 100 + $data['rel_y'] * $ratio_h;

				$img_css = sprintf(
					'width: %f%%; height: auto; position: absolute; left: %f%%; top: %f%%;',
					$rel_w_viewport * 100,
					$rel_x_viewport * 100,
					$rel_y_viewport * 100
				);
			}

			// Render a mini viewport for the cart
			$mini_html = '
				<div class="pureprint-cart-thumb" style="position: relative; width: 60px; aspect-ratio: ' . $Pw . '/' . $Ph . '; overflow: hidden; border: 1px solid #cbd5e1; border-radius: 4px; background: #f1f5f9; display: inline-block; vertical-align: middle;">
					<img src="' . esc_url( $data['file_url'] ) . '" style="' . $img_css . ' max-width: none !important; margin: 0 !important; box-shadow: none !important;">';
			
			// Show front face guide as red line or dim the sides
			if ( $data['border_type'] === 'foto' ) {
				$mini_html .= '<div style="position: absolute; border: 1px dashed rgba(239, 68, 68, 0.8); left: ' . $left_pct . '%; top: ' . $top_pct . '%; width: ' . $w_pct . '%; height: ' . $h_pct . '%; pointer-events: none; z-index: 10;"></div>';
				// Draw semi-transparent borders
				$mini_html .= '<div style="position: absolute; left:0; top:0; width:100%; height:' . $top_pct . '%; background: rgba(0,0,0,0.1); pointer-events:none; z-index:5;"></div>';
				$mini_html .= '<div style="position: absolute; left:0; bottom:0; width:100%; height:' . $top_pct . '%; background: rgba(0,0,0,0.1); pointer-events:none; z-index:5;"></div>';
				$mini_html .= '<div style="position: absolute; left:0; top:' . $top_pct . '%; width:' . $left_pct . '%; bottom:' . $top_pct . '%; background: rgba(0,0,0,0.1); pointer-events:none; z-index:5;"></div>';
				$mini_html .= '<div style="position: absolute; right:0; top:' . $top_pct . '%; width:' . $left_pct . '%; bottom:' . $top_pct . '%; background: rgba(0,0,0,0.1); pointer-events:none; z-index:5;"></div>';
			} else {
				// Shading borders as white in Bianco mode
				$mini_html .= '<div style="position: absolute; left:0; top:0; width:100%; height:' . $top_pct . '%; background: #ffffff; pointer-events:none; z-index:5;"></div>';
				$mini_html .= '<div style="position: absolute; left:0; bottom:0; width:100%; height:' . $top_pct . '%; background: #ffffff; pointer-events:none; z-index:5;"></div>';
				$mini_html .= '<div style="position: absolute; left:0; top:' . $top_pct . '%; width:' . $left_pct . '%; bottom:' . $top_pct . '%; background: #ffffff; pointer-events:none; z-index:5;"></div>';
				$mini_html .= '<div style="position: absolute; right:0; top:' . $top_pct . '%; width:' . $left_pct . '%; bottom:' . $top_pct . '%; background: #ffffff; pointer-events:none; z-index:5;"></div>';
			}

			$mini_html .= '</div>';
			return $mini_html;
		}

		return $thumbnail;
	}

	/**
	 * WooCommerce Store API Hook: Customize Cart Thumbnail for modern Block Themes (e.g. Twenty Twenty-Five)
	 */
	public static function store_api_cart_item_images( $product_images, $cart_item, $cart_item_key ) {
		if ( isset( $cart_item['pureprint_data'] ) ) {
			$data = $cart_item['pureprint_data'];
			$custom_image_url = esc_url( $data['file_url'] );

			return array(
				(object) array(
					'id'        => 0,
					'src'       => $custom_image_url,
					'thumbnail' => $custom_image_url,
					'srcset'    => '',
					'sizes'     => '',
					'name'      => 'Immagine Stampata',
					'alt'       => 'Anteprima',
				)
			);
		}
		return $product_images;
	}

	/**
	 * WooCommerce Order Hook: Save custom cart data to order item meta
	 */
	public static function checkout_create_order_line_item( $item, $cart_item_key, $values, $order ) {
		if ( isset( $values['pureprint_data'] ) ) {
			$data = $values['pureprint_data'];

			// Save all parameters as order metadata.
			// Prefixed with underscore so they remain hidden from the standard WooCommerce detail list.
			$item->update_meta_data( '_pureprint_file_name', isset($data['file_name']) ? $data['file_name'] : '' );
			$item->update_meta_data( '_pureprint_rel_x', isset($data['rel_x']) ? $data['rel_x'] : 0 );
			$item->update_meta_data( '_pureprint_rel_y', isset($data['rel_y']) ? $data['rel_y'] : 0 );
			$item->update_meta_data( '_pureprint_rel_w', isset($data['rel_w']) ? $data['rel_w'] : 0 );
			$item->update_meta_data( '_pureprint_rel_h', isset($data['rel_h']) ? $data['rel_h'] : 0 );
			$item->update_meta_data( '_pureprint_front_w_mm', isset($data['front_w_mm']) ? $data['front_w_mm'] : 0 );
			$item->update_meta_data( '_pureprint_front_h_mm', isset($data['front_h_mm']) ? $data['front_h_mm'] : 0 );
			$item->update_meta_data( '_pureprint_thickness_mm', isset($data['thickness_mm']) ? $data['thickness_mm'] : 0 );
			$item->update_meta_data( '_pureprint_bleed_mm', isset($data['bleed_mm']) ? $data['bleed_mm'] : 0 );
			$item->update_meta_data( '_pureprint_border_type', isset($data['border_type']) ? $data['border_type'] : '' );
			$item->update_meta_data( '_pureprint_dpi', isset($data['dpi']) ? $data['dpi'] : 300 );
			$item->update_meta_data( '_pureprint_support_type', isset($data['support_type']) ? $data['support_type'] : 'tela' );
		}
	}

	/**
	 * WooCommerce Blocks Store API Hook: Save custom cart data to order item meta
	 * The Store API Checkout does not always reliably trigger the legacy `woocommerce_checkout_create_order_line_item`.
	 */
	public static function store_api_checkout_order_processed( $order ) {
		if ( ! isset( WC()->session ) ) {
			return;
		}
		
		$session_id = WC()->session->get_customer_id();
		if ( ! $session_id ) {
			return;
		}

		$fallback_data = get_transient( 'pureprint_fallback_' . $session_id );
		if ( ! is_array( $fallback_data ) || empty( $fallback_data ) ) {
			return;
		}

		// Loop through order items and match with transient data
		foreach ( $order->get_items() as $item_id => $item ) {
			$product_id = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$key = $product_id . '_' . $variation_id;
			
			if ( isset( $fallback_data[ $key ] ) ) {
				$data = $fallback_data[ $key ];
				
				// Only update if not already set by legacy hook
				if ( ! $item->meta_exists( '_pureprint_file_name' ) ) {
					$item->update_meta_data( '_pureprint_file_name', isset($data['file_name']) ? $data['file_name'] : '' );
					$item->update_meta_data( '_pureprint_rel_x', isset($data['rel_x']) ? $data['rel_x'] : 0 );
					$item->update_meta_data( '_pureprint_rel_y', isset($data['rel_y']) ? $data['rel_y'] : 0 );
					$item->update_meta_data( '_pureprint_rel_w', isset($data['rel_w']) ? $data['rel_w'] : 0 );
					$item->update_meta_data( '_pureprint_rel_h', isset($data['rel_h']) ? $data['rel_h'] : 0 );
					$item->update_meta_data( '_pureprint_front_w_mm', isset($data['front_w_mm']) ? $data['front_w_mm'] : 0 );
					$item->update_meta_data( '_pureprint_front_h_mm', isset($data['front_h_mm']) ? $data['front_h_mm'] : 0 );
					$item->update_meta_data( '_pureprint_thickness_mm', isset($data['thickness_mm']) ? $data['thickness_mm'] : 0 );
					$item->update_meta_data( '_pureprint_bleed_mm', isset($data['bleed_mm']) ? $data['bleed_mm'] : 0 );
					$item->update_meta_data( '_pureprint_border_type', isset($data['border_type']) ? $data['border_type'] : '' );
					$item->update_meta_data( '_pureprint_dpi', isset($data['dpi']) ? $data['dpi'] : 300 );
					$item->update_meta_data( '_pureprint_support_type', isset($data['support_type']) ? $data['support_type'] : 'tela' );
					$item->save();
				}
			}
		}
		
		// Clean up transient
		delete_transient( 'pureprint_fallback_' . $session_id );
	}
}

/**
 * Helper function to parse float safely
 */
function parseFloat( $value ) {
	return floatval( str_replace( ',', '.', $value ) );
}
