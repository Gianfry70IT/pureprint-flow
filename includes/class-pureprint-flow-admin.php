<?php
/**
 * PurePrint Flow - Admin Controller
 * Handles product settings, order detail previews, and PDF generation trigger hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PurePrint_Flow_Admin {

	public static function init() {
		// Product Level Settings Box
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_product_settings' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_settings' ) );

		// Meta Box in Order Details Page (CPT & HPOS support)
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_metabox' ) );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( __CLASS__, 'add_order_metabox' ) );

		// Assets
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

		// PDF Download Listener (via admin-post.php)
		add_action( 'admin_post_pureprint_download_pdf', array( __CLASS__, 'handle_pdf_download' ) );

		// Admin Settings Page
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
	}

	/**
	 * Enqueue admin stylesheet
	 */
	public static function enqueue_admin_assets( $hook ) {
		// Only enqueue on edit order pages
		if ( 'post.php' === $hook || 'woocommerce_page_wc-orders' === $hook ) {
			wp_enqueue_style( 'pureprint-admin', PUREPRINT_URL . 'assets/css/pureprint-admin.css', array(), PUREPRINT_VERSION );
		}
	}

	/**
	 * Add Checkbox inside WooCommerce Product Settings Panel (General tab)
	 */
	public static function add_product_settings() {
		echo '<div class="options_group pureprint_product_options">';
		woocommerce_wp_checkbox( array(
			'id'          => '_pureprint_enabled',
			'label'       => 'Abilita PurePrint Customizer',
			'description' => 'Seleziona questa casella per abilitare il configuratore di immagini PurePrint per questo prodotto variabile.',
			'desc_tip'    => true,
		) );
		woocommerce_wp_select( array(
			'id'          => '_pureprint_support_type',
			'label'       => 'Tipo di supporto',
			'options'     => array(
				'tela'   => 'Tela su telaio (bordi avvolti)',
				'piatto' => 'Pannello piatto (plexiglass, forex, dibond...)',
			),
			'value'       => PurePrint_Flow_Frontend::get_support_type( get_the_ID() ),
			'description' => 'Tela: lo spessore del telaio viene letto dalle varianti e l\'immagine avvolge i lati. Pannello piatto: nessun telaio, l\'immagine copre il formato più l\'abbondanza.',
			'desc_tip'    => true,
		) );
		woocommerce_wp_text_input( array(
			'id'                => '_pureprint_flat_bleed_mm',
			'label'             => 'Abbondanza pannello (mm)',
			'type'              => 'number',
			'value'             => PurePrint_Flow_Frontend::get_flat_bleed_mm( get_the_ID() ),
			'custom_attributes' => array( 'min' => '0', 'max' => '20', 'step' => '1' ),
			'description'       => 'Solo per i pannelli piatti: millimetri aggiunti su ogni lato del file di stampa, da rifilare.',
			'desc_tip'          => true,
		) );
		echo '</div>';
	}

	/**
	 * Save product setting meta
	 */
	public static function save_product_settings( $product_id ) {
		$enabled = isset( $_POST['_pureprint_enabled'] ) ? 'yes' : 'no';
		update_post_meta( $product_id, '_pureprint_enabled', $enabled );

		$support = isset( $_POST['_pureprint_support_type'] ) ? sanitize_key( wp_unslash( $_POST['_pureprint_support_type'] ) ) : 'tela';
		update_post_meta( $product_id, '_pureprint_support_type', 'piatto' === $support ? 'piatto' : 'tela' );

		if ( isset( $_POST['_pureprint_flat_bleed_mm'] ) ) {
			$bleed = max( 0, min( 20, intval( $_POST['_pureprint_flat_bleed_mm'] ) ) );
			update_post_meta( $product_id, '_pureprint_flat_bleed_mm', $bleed );
		}
	}

	/**
	 * Register Order Detail Meta Box
	 */
	public static function add_order_metabox() {
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders', 'wc_order' ); // Support traditional post type and HPOS
		foreach ( $screens as $screen ) {
			add_meta_box(
				'pureprint_order_metabox',
				'PurePrint Flow - Stampe Personalizzate',
				array( __CLASS__, 'render_order_metabox' ),
				$screen,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render Customizer Meta Box in WooCommerce order details
	 */
	public static function render_order_metabox( $post_or_order ) {
		// Support both WordPress CPT and High-Performance Order Storage (HPOS)
		$order = ($post_or_order instanceof WP_Post) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order ) {
			return;
		}

		$items = $order->get_items();
		$has_customizations = false;

		foreach ( $items as $item_id => $item ) {
			$file_name = $item->get_meta( '_pureprint_file_name' );
			if ( ! $file_name ) {
				continue;
			}

			$has_customizations = true;

			// Extract metadata
			$rel_x = floatval( $item->get_meta( '_pureprint_rel_x' ) );
			$rel_y = floatval( $item->get_meta( '_pureprint_rel_y' ) );
			$rel_w = floatval( $item->get_meta( '_pureprint_rel_w' ) );
			$rel_h = floatval( $item->get_meta( '_pureprint_rel_h' ) );
			$front_w = intval( $item->get_meta( '_pureprint_front_w_mm' ) );
			$front_h = intval( $item->get_meta( '_pureprint_front_h_mm' ) );
			$thickness = intval( $item->get_meta( '_pureprint_thickness_mm' ) );
			$bleed_mm = intval( $item->get_meta( '_pureprint_bleed_mm' ) );
			$total_thickness = $thickness + $bleed_mm;
			$border = $item->get_meta( '_pureprint_border_type' );
			$dpi = $item->get_meta( '_pureprint_dpi' );
			$is_flat = ( 'piatto' === $item->get_meta( '_pureprint_support_type' ) );

			$image_url = PUREPRINT_UPLOAD_URL . '/' . $file_name;
			
			// Total physical print size
			$Pw = $front_w + 2 * $total_thickness;
			$Ph = $front_h + 2 * $total_thickness;

			// Viewport thumbnail coordinates for HTML preview
			$left_pct = ($total_thickness / $Pw) * 100;
			$top_pct = ($total_thickness / $Ph) * 100;
			$w_pct = ($front_w / $Pw) * 100;
			$h_pct = ($front_h / $Ph) * 100;

			$img_css = '';
			if ( $border === 'foto' ) {
				$img_css = sprintf(
					'width: %f%%; height: auto; position: absolute; left: %f%%; top: %f%%;',
					$rel_w * 100,
					$rel_x * 100,
					$rel_y * 100
				);
			} else {
				$ratio_w = $front_w / $Pw;
				$ratio_h = $front_h / $Ph;

				$rel_w_viewport = $rel_w * $ratio_w;
				$rel_x_viewport = ($left_pct / 100) + ($rel_x * $ratio_w);
				$rel_y_viewport = ($top_pct / 100) + ($rel_y * $ratio_h);

				$img_css = sprintf(
					'width: %f%%; height: auto; position: absolute; left: %f%%; top: %f%%;',
					$rel_w_viewport * 100,
					$rel_x_viewport * 100,
					$rel_y_viewport * 100
				);
			}

			?>
			<div class="pureprint-admin-item-preview">
				<div class="pureprint-admin-item-header">
					Prodotto: <?php echo esc_html( $item->get_name() ); ?> (ID Item: #<?php echo esc_html( $item_id ); ?>)
				</div>
				
				<div style="display: flex; gap: 20px; flex-wrap: wrap;">
					<!-- HTML Live Preview Box -->
					<div class="pureprint-admin-viewport-wrapper">
						<div class="pureprint-admin-viewport mode-<?php echo esc_attr( $border ); ?>" style="aspect-ratio: <?php echo $Pw . '/' . $Ph; ?>;">
							<img class="pureprint-admin-viewport-image" src="<?php echo esc_url( $image_url ); ?>" style="<?php echo $img_css; ?>">
							
							<?php if ( $border === 'foto' ) : ?>
								<div class="pureprint-admin-front-face-guide" style="left: <?php echo $left_pct; ?>%; top: <?php echo $top_pct; ?>%; width: <?php echo $w_pct; ?>%; height: <?php echo $h_pct; ?>%;"></div>
								<div class="pureprint-admin-wrap-overlay" style="left:0; top:0; width:100%; height:<?php echo $top_pct; ?>%;"></div>
								<div class="pureprint-admin-wrap-overlay" style="left:0; bottom:0; width:100%; height:<?php echo $top_pct; ?>%;"></div>
								<div class="pureprint-admin-wrap-overlay" style="left:0; top:<?php echo $top_pct; ?>%; width:<?php echo $left_pct; ?>%; bottom:<?php echo $top_pct; ?>%;"></div>
								<div class="pureprint-admin-wrap-overlay" style="right:0; top:<?php echo $top_pct; ?>%; width:<?php echo $left_pct; ?>%; bottom:<?php echo $top_pct; ?>%;"></div>
							<?php else : ?>
								<div class="pureprint-admin-wrap-overlay" style="left:0; top:0; width:100%; height:<?php echo $top_pct; ?>%;"></div>
								<div class="pureprint-admin-wrap-overlay" style="left:0; bottom:0; width:100%; height:<?php echo $top_pct; ?>%;"></div>
								<div class="pureprint-admin-wrap-overlay" style="left:0; top:<?php echo $top_pct; ?>%; width:<?php echo $left_pct; ?>%; bottom:<?php echo $top_pct; ?>%;"></div>
								<div class="pureprint-admin-wrap-overlay" style="right:0; top:<?php echo $top_pct; ?>%; width:<?php echo $left_pct; ?>%; bottom:<?php echo $top_pct; ?>%;"></div>
							<?php endif; ?>
						</div>
					</div>

					<!-- Details metadata -->
					<div class="pureprint-admin-details">
						<ul>
							<li><strong>Risoluzione file caricato:</strong> <?php
								$filepath = PUREPRINT_UPLOAD_DIR . '/' . $file_name;
								$real_dims = @getimagesize( $filepath );
								if ( $real_dims ) {
									echo esc_html( $real_dims[0] . ' x ' . $real_dims[1] . ' px' );
								} else {
									echo 'Non disponibile';
								}
							?></li>
							<li><strong>Dimensione ordinata:</strong> <?php echo esc_html( ($front_w/10) . 'x' . ($front_h/10) . ' cm' ); ?></li>
							<?php if ( $is_flat ) : ?>
							<li><strong>Supporto:</strong> Pannello piatto (senza telaio)</li>
							<li><strong>Abbondanza nel PDF:</strong> <?php echo esc_html( $bleed_mm . ' mm per lato' ); ?></li>
							<?php else : ?>
							<li><strong>Spessore telaio ordinato:</strong> <?php echo esc_html( ($thickness/10) . ' cm' ); ?></li>
							<li><strong>Tipo bordo:</strong> <?php echo ($border === 'foto') ? 'Tela avvolta' : 'Bordo bianco'; ?></li>
							<?php endif; ?>
							<li><strong>Risoluzione indicativa:</strong> <?php echo esc_html( $dpi ); ?> DPI</li>
							<li><strong>File sorgente:</strong> <a href="<?php echo esc_url( $image_url ); ?>" target="_blank"><?php echo esc_html( $file_name ); ?></a></li>
						</ul>
					</div>
				</div>

				<!-- Download Trigger Panel (Uses JS to prevent form nesting issues in WordPress Admin) -->
				<div class="pureprint-admin-print-actions">
					<div class="pureprint-admin-field-group">
						<label>Seleziona dimensione di stampa:</label>
						<select class="pp-print-size-select" id="pp-size-<?php echo $item_id; ?>">
							<option value="original">Dimensione ordinata (<?php echo esc_html( ($front_w/10) . 'x' . ($front_h/10) . ' cm' ); ?>)</option>
							<option value="A4">A4 (210 x 297 mm)</option>
							<option value="A3">A3 (297 x 420 mm)</option>
							<option value="A2">A2 (420 x 594 mm)</option>
							<option value="A1">A1 (594 x 841 mm)</option>
							<option value="20x30">20x30 cm</option>
							<option value="30x40">30x40 cm</option>
							<option value="40x50">40x50 cm</option>
							<option value="50x70">50x70 cm</option>
							<option value="60x80">60x80 cm</option>
							<option value="custom">Dimensione personalizzata...</option>
						</select>
					</div>

					<div class="pureprint-admin-field-group pp-custom-dims-group" id="pp-custom-group-<?php echo $item_id; ?>" style="display: none;">
						<label>Dimensioni personalizzate (mm):</label>
						<div class="pureprint-admin-custom-dims">
							<input type="number" id="pp-custom-w-<?php echo $item_id; ?>" placeholder="Larg. (mm)" min="50" max="3000">
							<span>x</span>
							<input type="number" id="pp-custom-h-<?php echo $item_id; ?>" placeholder="Alt. (mm)" min="50" max="3000">
						</div>
					</div>

					<?php if ( $is_flat ) : ?>
					<input type="hidden" id="pp-thickness-<?php echo $item_id; ?>" value="original">
					<?php else : ?>
					<div class="pureprint-admin-field-group">
						<label>Spessore telaio di stampa:</label>
						<select id="pp-thickness-<?php echo $item_id; ?>">
							<option value="original">Spessore ordinato (<?php echo esc_html( ($thickness/10) . ' cm' ); ?>)</option>
							<option value="20">2 cm</option>
							<option value="40">4 cm</option>
						</select>
					</div>
					<?php endif; ?>

					<button type="button" class="pureprint-admin-btn-pdf pp-download-btn" data-item-id="<?php echo $item_id; ?>" data-order-id="<?php echo $order->get_id(); ?>">
						<svg viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z"/></svg> Scarica PDF per Stampa
					</button>
				</div>
			</div>
			
			<script>
				jQuery(document).ready(function($) {
					// Toggle custom dimensions input row visibility based on selected size option
					$('#pp-size-<?php echo $item_id; ?>').on('change', function() {
						const $group = $('#pp-custom-group-<?php echo $item_id; ?>');
						if ($(this).val() === 'custom') {
							$group.fadeIn(150);
						} else {
							$group.fadeOut(150);
						}
					});

					// Trigger file download action dynamically
					$('.pp-download-btn[data-item-id="<?php echo $item_id; ?>"]').on('click', function(e) {
						e.preventDefault();
						const itemId = $(this).data('item-id');
						const orderId = $(this).data('order-id');

						const printSize = $('#pp-size-' + itemId).val();
						const printThickness = $('#pp-thickness-' + itemId).val();
						const customW = $('#pp-custom-w-' + itemId).val() || '';
						const customH = $('#pp-custom-h-' + itemId).val() || '';

						// Build URL pointing securely to admin-post.php
						let url = ajaxurl.replace('admin-ajax.php', 'admin-post.php');
						url += '?action=pureprint_download_pdf';
						url += '&order_id=' + orderId;
						url += '&item_id=' + itemId;
						url += '&print_size=' + encodeURIComponent(printSize);
						url += '&print_thickness=' + encodeURIComponent(printThickness);
						url += '&custom_w=' + encodeURIComponent(customW);
						url += '&custom_h=' + encodeURIComponent(customH);

						window.open(url, '_blank');
					});
				});
			</script>
			<?php
		}

		if ( ! $has_customizations ) {
			echo '<p>Nessuna stampa personalizzata PurePrint in questo ordine.</p>';
		}
	}

	/**
	 * PDF Download Listener
	 * Intercepts requests containing pureprint_download_pdf parameter in WP Admin,
	 * verifies security privileges, and streams the PDF download file.
	 */
	public static function handle_pdf_download() {
		if ( ! is_admin() ) {
			return;
		}

		// Security check: Only administrators and shop managers can download print PDFs
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Non hai i permessi necessari per scaricare questo PDF.' );
		}

		$order_id = intval( $_GET['order_id'] );
		$item_id = intval( $_GET['item_id'] );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( 'Ordine non trovato.' );
		}

		$item = $order->get_item( $item_id );
		if ( ! $item ) {
			wp_die( 'Articolo dell\'ordine non trovato.' );
		}

		$file_name = $item->get_meta( '_pureprint_file_name' );
		if ( ! $file_name ) {
			wp_die( 'Immagine personalizzata non trovata per questo articolo.' );
		}

		$file_path = PUREPRINT_UPLOAD_DIR . '/' . $file_name;
		if ( ! file_exists( $file_path ) ) {
			wp_die( 'Il file dell\'immagine originale è stato eliminato dal server o non è accessibile.' );
		}

		// Extract customer coordinates
		$rel_x = floatval( $item->get_meta( '_pureprint_rel_x' ) );
		$rel_y = floatval( $item->get_meta( '_pureprint_rel_y' ) );
		$rel_w = floatval( $item->get_meta( '_pureprint_rel_w' ) );
		$rel_h = floatval( $item->get_meta( '_pureprint_rel_h' ) );
		$front_w = intval( $item->get_meta( '_pureprint_front_w_mm' ) );
		$front_h = intval( $item->get_meta( '_pureprint_front_h_mm' ) );
		$thickness = intval( $item->get_meta( '_pureprint_thickness_mm' ) );
		$border = $item->get_meta( '_pureprint_border_type' );

		// Parse target dimensions chosen by the operator
		$target_front_w = $front_w;
		$target_front_h = $front_h;
		$print_size = sanitize_text_field( $_GET['print_size'] );

		if ( 'original' !== $print_size ) {
			if ( 'custom' === $print_size ) {
				$target_front_w = intval( $_GET['custom_w'] );
				$target_front_h = intval( $_GET['custom_h'] );
			} elseif ( 'A4' === $print_size ) {
				$target_front_w = 210;
				$target_front_h = 297;
			} elseif ( 'A3' === $print_size ) {
				$target_front_w = 297;
				$target_front_h = 420;
			} elseif ( 'A2' === $print_size ) {
				$target_front_w = 420;
				$target_front_h = 594;
			} elseif ( 'A1' === $print_size ) {
				$target_front_w = 594;
				$target_front_h = 841;
			} else {
				// Parse formats like 20x30, 30x40
				$parts = explode( 'x', $print_size );
				if ( count( $parts ) === 2 ) {
					$target_front_w = intval( $parts[0] ) * 10;
					$target_front_h = intval( $parts[1] ) * 10;
				}
			}
		}

		// Adjust orientation of target sizes to match ordered aspect ratio orientation
		$ordered_is_horizontal = ($front_w > $front_h);
		$target_is_horizontal = ($target_front_w > $target_front_h);

		if ( $ordered_is_horizontal !== $target_is_horizontal ) {
			// Swap target coordinates to match the horizontal/vertical layout
			$temp = $target_front_w;
			$target_front_w = $target_front_h;
			$target_front_h = $temp;
		}

		// Parse frame thickness override
		$bleed_mm = intval( $item->get_meta( '_pureprint_bleed_mm' ) );
		$target_thickness = $thickness;
		$print_thickness = sanitize_text_field( $_GET['print_thickness'] );
		if ( 'original' !== $print_thickness ) {
			$target_thickness = intval( $print_thickness );
		}
		
		// Add bleed margin for PDF generation
		$target_thickness += $bleed_mm;

		// Generate and download PDF
		PurePrint_Flow_PDF::generate(
			$file_path,
			$rel_x,
			$rel_y,
			$rel_w,
			$rel_h,
			$target_front_w,
			$target_front_h,
			$target_thickness,
			$border,
			'pureprint_order_' . $order_id . '_item_' . $item_id . '.pdf'
		);
		exit;
	}

	/**
	 * Register the admin submenu page under WooCommerce
	 */
	public static function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			__( 'PurePrint Flow Settings', 'pureprint-flow' ),
			__( 'PurePrint Settings', 'pureprint-flow' ),
			'manage_woocommerce',
			'pureprint-flow-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Render the main settings page with tabs
	 */
	public static function render_settings_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'settings';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PurePrint Flow - Pannello di Controllo', 'pureprint-flow' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="?page=pureprint-flow-settings&tab=settings"
					class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Impostazioni Generali</a>
				<a href="?page=pureprint-flow-settings&tab=products"
					class="nav-tab <?php echo $active_tab === 'products' ? 'nav-tab-active' : ''; ?>">Prodotti Attivi</a>
				<a href="?page=pureprint-flow-settings&tab=guide"
					class="nav-tab <?php echo $active_tab === 'guide' ? 'nav-tab-active' : ''; ?>">Manuale Operativo</a>
			</h2>

			<?php if ( $active_tab === 'settings' ) : ?>
				<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 4px rgba(0,0,0,0.05); margin-top: 20px; max-width: 1000px;">
					<h3>Impostazioni Globali</h3>
					<p>Al momento non ci sono impostazioni globali da configurare. L'attivazione di PurePrint Flow viene gestita singolarmente su ogni prodotto.</p>
					<p>Per attivare PurePrint su un prodotto:</p>
					<ol>
						<li>Vai su <strong>Prodotti</strong> &gt; <strong>Tutti i prodotti</strong> e seleziona il prodotto desiderato.</li>
						<li>Nella scheda "Generale" dei Dati prodotto, spunta la casella <strong>Abilita PurePrint Customizer</strong>.</li>
						<li>Assicurati di aver configurato correttamente gli attributi necessari (leggi il <em>Manuale Operativo</em> per i dettagli).</li>
					</ol>
				</div>
			<?php elseif ( $active_tab === 'products' ) : ?>
				<?php self::render_active_products_tab(); ?>
			<?php elseif ( $active_tab === 'guide' ) : ?>
				<?php self::render_guide_tab(); ?>
			<?php endif; ?>

			<!-- Plugin Signature -->
			<div style="margin-top: 30px; max-width: 1000px; padding: 18px 22px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border: 1px solid #dee2e6; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
				<div style="display: flex; align-items: center; gap: 14px;">
					<div style="width: 42px; height: 42px; background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
						<span style="color: #fff; font-size: 18px; font-weight: 700; letter-spacing: -0.5px;">PP</span>
					</div>
					<div>
						<div style="font-weight: 600; font-size: 14px; color: #212529;">PurePrint Flow</div>
						<div style="font-size: 12px; color: #6c757d; margin-top: 2px;">
							Versione <?php echo esc_html( PUREPRINT_VERSION ); ?> &middot; Sviluppato da
							<a href="https://nerodigitale.it" target="_blank" style="color: #495057; text-decoration: none; font-weight: 500;">NeroDigitale</a>
							di Gianfranco Greco
						</div>
					</div>
				</div>
				<div style="display: flex; align-items: center; gap: 10px;">
					<a href="https://github.com/Gianfry70IT/pureprint-flow" target="_blank" style="display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; background: #24292f; color: #fff; border-radius: 6px; font-size: 12px; font-weight: 500; text-decoration: none;">
						<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
						GitHub
					</a>
					<a href="https://speed-print.it" target="_blank" style="display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; background: #fff; color: #495057; border: 1px solid #ced4da; border-radius: 6px; font-size: 12px; font-weight: 500; text-decoration: none;">
						🌐 speed-print.it
					</a>
				</div>
			</div>

		</div>
		<?php
	}

	/**
	 * Render the Active Products tab
	 */
	private static function render_active_products_tab() {
		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'   => '_pureprint_enabled',
					'value' => 'yes',
				),
			),
		);
		$products = get_posts( $args );
		?>
		<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 4px rgba(0,0,0,0.05); margin-top: 20px; max-width: 1200px;">
			<h3>Prodotti con PurePrint Flow Attivo</h3>
			<p>Ecco un riepilogo di tutti i prodotti del tuo catalogo che utilizzano attualmente il configuratore PurePrint Flow.</p>

			<?php if ( empty( $products ) ) : ?>
				<div style="background: #fff8e5; padding: 15px; border-left: 4px solid #ffb900; margin: 15px 0;">
					<strong>⚠️ Nessun prodotto trovato.</strong> Attiva PurePrint su almeno un prodotto dalla scheda "Generale" nei Dati prodotto.
				</div>
			<?php else : ?>
				<table class="widefat fixed striped" style="margin-top: 20px;">
					<thead>
						<tr>
							<th style="width: 60px;">ID</th>
							<th>Nome Prodotto</th>
							<th style="width: 150px;">Stato</th>
							<th style="width: 120px;">Azioni</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $products as $p ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $p->ID ); ?></strong></td>
								<td><?php echo esc_html( $p->post_title ); ?></td>
								<td>
									<span style="display: inline-block; padding: 4px 8px; border-radius: 4px; background: #e8f5e9; color: #2e7d32; font-weight: 500; font-size: 12px;">Attivo</span>
								</td>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>" class="button button-small">Modifica</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Guide Tab
	 */
	private static function render_guide_tab() {
		?>
		<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 4px rgba(0,0,0,0.05); margin-top: 20px; max-width: 1000px;">
			<h3>Manuale Operativo PurePrint Flow</h3>
			<p>Il plugin <strong>PurePrint Flow</strong> sostituisce la galleria immagini classica di WooCommerce con un'area avanzata per l'upload e il ritaglio interattivo delle foto (fotoquadri e tele) da parte dei tuoi clienti.</p>

			<hr style="margin: 20px 0;">

			<h4>1. Abilitare il Configuratore</h4>
			<ol>
				<li>Crea o modifica un prodotto WooCommerce.</li>
				<li>Nel box <strong>Dati Prodotto</strong>, scheda "Generale", spunta la casella <strong>Abilita PurePrint Customizer</strong>.</li>
				<li>Assicurati che il prodotto sia un <strong>Prodotto variabile</strong>, perché PurePrint si basa sulle variazioni selezionate dal cliente per ridimensionare il riquadro di anteprima e stabilire lo spessore.</li>
			</ol>

			<hr style="margin: 20px 0;">

			<h4>2. Configurare gli Attributi necessari</h4>
			<p>Per funzionare correttamente, il prodotto variabile deve avere tre attributi specifici configurati in modo che il JS del configuratore possa "leggere" le misure dalla tendina. Crea gli attributi e configurali così:</p>

			<table class="widefat fixed striped" style="margin-bottom: 20px;">
				<thead>
					<tr>
						<th>Nome Attributo</th>
						<th>Slug (Fondamentale)</th>
						<th>Descrizione e Valori di Esempio</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong>Misure Tela</strong></td>
						<td><code>pa_misure-tela</code></td>
						<td>Le dimensioni frontali della stampa. Il valore testuale deve contenere la "x", ad esempio <code>30x40 cm</code>, <code>50x70</code>, ecc. Lo script estrarrà automaticamente "30" come base e "40" come altezza e li trasformerà in millimetri (300mm x 400mm).</td>
					</tr>
					<tr>
						<td><strong>Spessore Telaio</strong></td>
						<td><code>pa_spessore-telaio</code></td>
						<td>Lo spessore del bordo laterale. Il valore testuale deve essere un numero o contenere un numero, ad esempio <code>2 cm</code>, <code>4 cm</code>. Verrà convertito in millimetri (20mm, 40mm).</td>
					</tr>
					<tr>
						<td><strong>Colore Bordo</strong></td>
						<td><code>pa_colore-bordo</code></td>
						<td>Sceglie se il bordo laterale è specchiato (foto) o colorato. Usare esattamente i valori testuali <code>foto</code> oppure <code>bianco</code> (il bianco copre anche altri eventuali colori in futuro, lo script legge questo termine).</td>
					</tr>
				</tbody>
			</table>
			<p><em>Nota tecnica:</em> Assicurati che gli attributi siano impostati come "Usato nelle variazioni". Quando il cliente sceglie tutti i valori, l'interfaccia PurePrint si "sbloccherà" e mostrerà le guide esatte per la stampa (incluso lo spazio per il telaio o l'effetto specchiato).</p>

			<hr style="margin: 20px 0;">

			<h4>3. Generazione e Download PDF</h4>
			<p>Quando un cliente effettua un ordine:</p>
			<ul>
				<li>L'immagine originale ad alta risoluzione caricata dal cliente viene salvata sul tuo server in una cartella protetta (<code>wp-content/uploads/pureprint-uploads</code>).</li>
				<li>Nel dettaglio dell'ordine in WooCommerce vedrai un box "PurePrint - Dettagli Stampa" accanto al riepilogo dell'articolo.</li>
				<li>Da lì potrai controllare le coordinate di ritaglio scelte e cliccare su <strong>Scarica PDF Pronto (300 DPI)</strong>.</li>
				<li>Il PDF generato include i crocini di registro e i margini abbondanti già calcolati con la foto riflessa (se l'opzione bordo foto è stata scelta).</li>
			</ul>
		</div>
		<?php
	}

}
