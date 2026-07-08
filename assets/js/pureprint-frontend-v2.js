/**
 * PurePrint Flow - Frontend Customizer Engine
 * Vanilla JS crop, scale, drag, and DPI resolution check.
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Elements
        const $form = $('form.variations_form');
        if (!$form.length) return;

        const $varWrap = $form.find('.single_variation_wrap');
        if (!$varWrap.length) return;

        // Gallery replacement: locate the main product images column.
        // We look for 'div.product div.images' which is the standard Woo column, 
        // or '.woocommerce-product-gallery' as fallback.
        const $wcGallery = $('div.product div.images').length ? $('div.product div.images') : $('.woocommerce-product-gallery');
        const useGalleryMode = $wcGallery.length > 0;

        // Customizer HTML Template — visible immediately, starts disabled
        const customizerHtml = `
            <div class="pureprint-customizer-container" id="pureprint-customizer">
                <div class="pureprint-upload-section">
                    <div class="pureprint-upload-zone pureprint-disabled" id="pureprint-dropzone">
                        <span class="upload-icon">📷</span>
                        <p><strong>Trascina qui la tua foto</strong> o clicca per caricarla</p>
                        <p class="small-hint">Formati accettati: JPG, PNG. Risoluzione ottimale per la stampa.</p>
                        <div class="pureprint-disabled-overlay" id="pureprint-disabled-overlay">
                            <svg viewBox="0 0 24 24" width="28" height="28" fill="#94a3b8"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>
                            <span>Seleziona tutte le opzioni del prodotto per personalizzare</span>
                        </div>
                    </div>
                    <input type="file" id="pureprint-file-field" class="pureprint-file-input" accept="image/jpeg,image/png">
                </div>

                <div class="pureprint-loader" id="pureprint-loading">
                    <div class="pureprint-spinner"></div>
                    <span>Caricamento e ottimizzazione immagine...</span>
                </div>

                <div class="pureprint-editor-workspace" id="pureprint-workspace">
                    <div class="pureprint-viewport" id="pureprint-viewport-el">
                        <img class="pureprint-viewport-image" id="pureprint-preview-img" src="" alt="Anteprima">
                        <!-- Shading overlays for borders -->
                        <div class="pureprint-wrap-overlay" id="overlay-top"></div>
                        <div class="pureprint-wrap-overlay" id="overlay-bottom"></div>
                        <div class="pureprint-wrap-overlay" id="overlay-left"></div>
                        <div class="pureprint-wrap-overlay" id="overlay-right"></div>
                        <!-- Red Guide Line Box -->
                        <div class="pureprint-front-face-guide" id="front-guide"></div>
                    </div>

                    <div class="pureprint-controls">
                        <!-- Zoom Slider Row -->
                        <div class="pureprint-zoom-row">
                            <span class="control-icon">➖</span>
                            <input type="range" class="pureprint-zoom-slider" id="pureprint-zoom" min="100" max="500" value="100">
                            <span class="control-icon">➕</span>
                        </div>

                        <!-- Action Buttons Row -->
                        <div class="pureprint-buttons-row">
                            <button type="button" class="pureprint-btn" id="btn-center-h">
                                <svg viewBox="0 0 24 24"><path d="M4 19h16v2H4v-2zm7-14v8H8l4 4 4-4h-3V5h-2zM4 3h16v2H4V3z"/></svg> Centra Orizz.
                            </button>
                            <button type="button" class="pureprint-btn" id="btn-center-v">
                                <svg viewBox="0 0 24 24"><path d="M19 4v16h2V4h-2zm-14 7h8v-3l4 4-4 4v-3H5v-2zM3 4v16h2V4H3z"/></svg> Centra Vert.
                            </button>
                            <button type="button" class="pureprint-btn" id="btn-fit">
                                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H7c0-2.76 2.24-5 5-5s5 2.24 5 5c0 1.04-.42 1.99-1.07 2.75z"/></svg> Adatta
                            </button>
                            <button type="button" class="pureprint-btn pureprint-btn-danger" id="btn-delete-img">
                                <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg> Rimuovi
                            </button>
                        </div>

                        <!-- DPI Pill Resolution Bar -->
                        <div class="pureprint-info-row">
                            <span>Risoluzione Stampa:</span>
                            <span class="pureprint-dpi-badge dpi-excellent" id="pureprint-dpi-info">OTTIMA (300 DPI)</span>
                        </div>
                    </div>
                </div>
            </div>
        `;

        const hiddenInputsHtml = `
            <input type="hidden" name="pureprint_file_name" id="pp-input-filename" value="">
            <input type="hidden" name="pureprint_rel_x" id="pp-input-rel-x" value="0">
            <input type="hidden" name="pureprint_rel_y" id="pp-input-rel-y" value="0">
            <input type="hidden" name="pureprint_rel_w" id="pp-input-rel-w" value="1">
            <input type="hidden" name="pureprint_rel_h" id="pp-input-rel-h" value="1">
            <input type="hidden" name="pureprint_front_w_mm" id="pp-input-front-w" value="0">
            <input type="hidden" name="pureprint_front_h_mm" id="pp-input-front-h" value="0">
            <input type="hidden" name="pureprint_thickness_mm" id="pp-input-thickness" value="0">
            <input type="hidden" name="pureprint_bleed_mm" id="pp-input-bleed" value="0">
            <input type="hidden" name="pureprint_border_type" id="pp-input-border" value="">
            <input type="hidden" name="pureprint_dpi" id="pp-input-dpi" value="300">
        `;

        // Always inject hidden inputs directly into the form
        $form.append(hiddenInputsHtml);

        // Inject into gallery placeholder (left column) or fallback to form area
        if (useGalleryMode) {
            $wcGallery.html(customizerHtml);
        } else {
            $varWrap.before(customizerHtml);
        }

        // State variables
        let activeConfig = null; // Stores parsed width, height, thickness, border type
        let uploadedImage = null; // Stores natural dimensions and file name
        let imgState = {
            x: 0,
            y: 0,
            zoom: 1.0, // Multiplier on top of cover/fit dimensions
            baseW: 0,
            baseH: 0
        };

        // Cache DOM elements
        const $customizer = $('#pureprint-customizer');
        const $dropzone = $('#pureprint-dropzone');
        const $fileField = $('#pureprint-file-field');
        const $loading = $('#pureprint-loading');
        const $workspace = $('#pureprint-workspace');
        const $viewport = $('#pureprint-viewport-el');
        const $previewImg = $('#pureprint-preview-img');
        const $zoomSlider = $('#pureprint-zoom');
        const $dpiInfo = $('#pureprint-dpi-info');
        const $frontGuide = $('#front-guide');
        
        // Form's default add to cart button wrapper
        const $wcAddToCartBtn = $form.find('.single_add_to_cart_button');

        function getCombinedVariationString() {
            let combined = '';
            
            // Standard dropdowns
            $form.find('.variations select').each(function() {
                const name = ($(this).attr('name') || '').toLowerCase();
                const val = $(this).val();
                if (!val) return;
                
                const text = $(this).find('option:selected').text().toLowerCase();
                combined += ' ' + name + ' ' + val + ' ' + text;
            });
            
            // Radio buttons and hidden fields (from variation swatches)
            $form.find('.variations input[type="radio"]:checked, .variations input[type="hidden"]').each(function() {
                const name = ($(this).attr('name') || '').toLowerCase();
                const val = ($(this).val() || '').toLowerCase();
                if (!val) return;
                
                combined += ' ' + name + ' ' + val;
            });
            
            // Look for swatch labels (active)
            $form.find('.variations .selected, .variations .active').each(function() {
                const text = ($(this).text() || '').toLowerCase();
                const title = ($(this).attr('title') || '').toLowerCase();
                combined += ' ' + text + ' ' + title;
            });

            return combined;
        }

        // Parse attributes from active WooCommerce variation form selection
        function parseActiveAttributes() {
            const combinedStr = getCombinedVariationString();

            // Parse dimension string e.g. "30x40", "30×40", "30X40", "30*40"
            const dims = combinedStr.match(/(\d+)\s*[xX*\u00D7\-]\s*(\d+)/);
            if (!dims) return null;

            let w_cm = parseInt(dims[1]);
            let h_cm = parseInt(dims[2]);

            // Parse thickness
            let thickness_mm = 20; // default 2cm
            if (combinedStr.includes('4 cm') || combinedStr.includes('4cm') || combinedStr.includes('spessore-4') || combinedStr.includes('telaio-4') || combinedStr.match(/4\s*cm/)) {
                thickness_mm = 40;
            }

            // Heuristic to guess if user wants horizontal or vertical
            let isHorizontal = true;
            if (combinedStr.includes('orizzontale') || combinedStr.includes('landscape') || combinedStr.includes('horizontal')) {
                isHorizontal = true;
            } else if (combinedStr.includes('verticale') || combinedStr.includes('portrait') || combinedStr.includes('vertical')) {
                isHorizontal = false;
            } else {
                // Fallback: use dimensions ratio
                if (w_cm > h_cm) isHorizontal = true;
            }

            // Recompute orientation order
            let front_w_mm = 0, front_h_mm = 0;
            const val1 = w_cm * 10;
            const val2 = h_cm * 10;
            if (isHorizontal) {
                front_w_mm = Math.max(val1, val2);
                front_h_mm = Math.min(val1, val2);
            } else {
                front_w_mm = Math.min(val1, val2);
                front_h_mm = Math.max(val1, val2);
            }

            // Parse border type
            let borderType = 'foto'; // default
            if (combinedStr.includes('bianco') || combinedStr.includes('white')) {
                borderType = 'bianco';
            } else if (combinedStr.includes('nero') || combinedStr.includes('black')) {
                borderType = 'nero';
            }

            // Bleed margin for wrapped photo to avoid white lines (5mm = 0.5cm)
            let bleed_mm = 0;
            if (borderType === 'foto') {
                bleed_mm = 5;
            }

            return {
                front_w_mm: front_w_mm,
                front_h_mm: front_h_mm,
                thickness_mm: thickness_mm,
                bleed_mm: bleed_mm,
                border_type: borderType
            };
        }

        function renderViewport() {
			// Calculate total width and height including wrapping borders
			const totalW_mm = activeConfig.front_w_mm + (activeConfig.thickness_mm * 2) + (activeConfig.bleed_mm * 2);
			const totalH_mm = activeConfig.front_h_mm + (activeConfig.thickness_mm * 2) + (activeConfig.bleed_mm * 2);

			let workspaceW = $workspace.width();
            if (!workspaceW || workspaceW < 50) {
                // Failsafe if width is 0 due to display:none
                workspaceW = $workspace.parent().width() || 350;
            }
			const maxH = 500;

			let Vw = workspaceW;
			let Vh = Vw * (totalH_mm / totalW_mm);

			if (Vh > maxH) {
				Vh = maxH;
				Vw = Vh * (totalW_mm / totalH_mm);
			}

			// Set exact pixel dimensions on the viewport with strict constraints
			if ($viewport && $viewport.length) {
				const vp = $viewport[0];
				vp.style.setProperty('width', Vw + 'px', 'important');
				vp.style.setProperty('height', Vh + 'px', 'important');
				vp.style.setProperty('max-width', Vw + 'px', 'important');
				vp.style.setProperty('min-width', Vw + 'px', 'important');
			}

			// Update wrapper class for styling
			$viewport.removeClass('mode-foto mode-bianco');
			$viewport.addClass('mode-' + activeConfig.border_type);

			// Border overlays (Thickness + Bleed on each side)
			const sideTotal_mm = activeConfig.thickness_mm + activeConfig.bleed_mm;
			const sideTotal_px = (sideTotal_mm / totalW_mm) * Vw;

			const L_px = sideTotal_px;
			const R_px = sideTotal_px;
			const T_px = (sideTotal_mm / totalH_mm) * Vh;
			const B_px = (sideTotal_mm / totalH_mm) * Vh;

			$('#overlay-top').css({ width: '100%', height: Math.max(0, T_px) + 'px', left: 0, top: 0 });
			$('#overlay-bottom').css({ width: '100%', height: Math.max(0, B_px) + 'px', left: 0, bottom: 0 });
			$('#overlay-left').css({ width: Math.max(0, L_px) + 'px', height: '100%', left: 0, top: 0 });
			$('#overlay-right').css({ width: Math.max(0, R_px) + 'px', height: '100%', right: 0, top: 0 });

			// The red dashed box represents the exact Front Face (excluding wraps)
			const $frontGuide = $('#front-guide');
			$frontGuide.css({
				left: L_px + 'px',
				top: T_px + 'px',
				width: Math.max(0, Vw - L_px - R_px) + 'px',
				height: Math.max(0, Vh - T_px - B_px) + 'px'
			});
		}

        // Adjust Customizer state: enable/disable dropzone based on variation completeness
        function syncCustomizer() {
            const config = parseActiveAttributes();
            const $disabledOverlay = $('#pureprint-disabled-overlay');

            if (!config) {
                // Not all required dropdown attributes are selected — disable dropzone
                activeConfig = null;
                $dropzone.addClass('pureprint-disabled');
                $disabledOverlay.show();
                toggleAddToCart(false);
                return;
            }

            // All variations selected — enable dropzone
            activeConfig = config;
            $dropzone.removeClass('pureprint-disabled');
            $disabledOverlay.hide();

            // Populate hidden inputs
            $('#pp-input-front-w').val(config.front_w_mm);
            $('#pp-input-front-h').val(config.front_h_mm);
            $('#pp-input-thickness').val(config.thickness_mm);
            $('#pp-input-bleed').val(config.bleed_mm);
            $('#pp-input-border').val(config.border_type);

            // Total visual thickness for cropping includes the bleed
            const total_thickness = config.thickness_mm + config.bleed_mm;

            // Update aspect ratio and dimensions of viewport
            // Total print dimensions
            const Pw = config.front_w_mm + 2 * total_thickness;
            const Ph = config.front_h_mm + 2 * total_thickness;

            $viewport.css('aspect-ratio', `${Pw} / ${Ph}`);
            $viewport.removeClass('mode-foto mode-bianco').addClass(`mode-${config.border_type}`);

            // Calculate percentage positions for the front face guidelined box
            const left_pct = (total_thickness / Pw) * 100;
            const top_pct = (total_thickness / Ph) * 100;
            const width_pct = (config.front_w_mm / Pw) * 100;
            const height_pct = (config.front_h_mm / Ph) * 100;

            $frontGuide.css({
                left: `${left_pct}%`,
                top: `${top_pct}%`,
                width: `${width_pct}%`,
                height: `${height_pct}%`
            });

            // Set coordinates for shading overlays
            $('#overlay-top').css({ left: 0, top: 0, width: '100%', height: `${top_pct}%` });
            $('#overlay-bottom').css({ left: 0, bottom: 0, width: '100%', height: `${top_pct}%` });
            $('#overlay-left').css({ left: 0, top: `${top_pct}%`, width: `${left_pct}%`, bottom: `${top_pct}%` });
            $('#overlay-right').css({ right: 0, top: `${top_pct}%`, width: `${left_pct}%`, bottom: `${top_pct}%` });

            // If an image is already uploaded, refresh constraints and dimensions
            if (uploadedImage) {
                initializeImageDimensions();
                updateImageTransform();
                toggleAddToCart(true);
            } else {
                toggleAddToCart(false);
            }
        }

        // Enable or disable WooCommerce Add to Cart button
        function toggleAddToCart(enable) {
            if (enable) {
                $wcAddToCartBtn.removeClass('disabled').prop('disabled', false);
            } else {
                $wcAddToCartBtn.addClass('disabled').prop('disabled', true);
            }
        }

        // Listen for variation selection updates
        $form.on('change', '.variations select', function() {
            setTimeout(syncCustomizer, 100);
        });

        $form.on('show_variation hide_variation', function(e, variation) {
            setTimeout(syncCustomizer, 100);
        });

        // Also bind to change events on the selects directly. 
        // This is crucial for attributes set to 'Any' in variations, which don't always trigger show_variation.
        $form.find('.variations select').on('change', function() {
            setTimeout(syncCustomizer, 100);
        });

        // Trigger initially
        syncCustomizer();

        /**
         * Upload Handlers
         */

        // Use vanilla JS addEventListener to completely bypass jQuery/jQuery Migrate event recursion
        document.getElementById('pureprint-dropzone').addEventListener('click', function(e) {
            // Block clicks when dropzone is in disabled state
            if (this.classList.contains('pureprint-disabled')) {
                return;
            }
            var fileInput = document.getElementById('pureprint-file-field');
            if (fileInput) {
                fileInput.click();
            }
        });

        $dropzone.on('dragover', function(e) {
            e.preventDefault();
            $dropzone.addClass('drag-over');
        });

        $dropzone.on('dragleave', function() {
            $dropzone.removeClass('drag-over');
        });

        $dropzone.on('drop', function(e) {
            e.preventDefault();
            $dropzone.removeClass('drag-over');
            const files = e.originalEvent.dataTransfer.files;
            if (files.length) {
                handleFileUpload(files[0]);
            }
        });

        $fileField.on('change', function() {
            if (this.files.length) {
                handleFileUpload(this.files[0]);
            }
        });

        function handleFileUpload(file) {
            if (!file.type.match('image/jpeg') && !file.type.match('image/png')) {
                alert('Carica solo immagini in formato JPG o PNG.');
                return;
            }

            // AJAX File Upload
            const formData = new FormData();
            formData.append('action', 'pureprint_upload_image');
            formData.append('pureprint_nonce', pureprint_vars.nonce);
            formData.append('image_file', file);

            $dropzone.hide();
            $loading.css('display', 'flex');

            $.ajax({
                url: pureprint_vars.ajax_url,
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                success: function(response) {
                    if (response.success && response.data) {
                        const data = response.data;
                        
                        // Create an offscreen image to load the URL
                        const img = new Image();
                        img.crossOrigin = "Anonymous";
                        img.onload = function() {
                            uploadedImage = {
                                name: data.name,
                                url: data.url,
                                width: this.naturalWidth,
                                height: this.naturalHeight
                            };

                            // Update hidden filename field
                            $('#pp-input-filename').val(data.name);

                            // Load image in viewport
                            $previewImg.attr('src', data.url).off('load').on('load', function() {
                                $loading.hide();
                                $workspace.fadeIn(250);
                                
                                // Initialize panning/scaling coordinates
                                initializeImageDimensions();
                                centerImage();
                                toggleAddToCart(true);
                            });
                            
                            // Trigger load
                            if ($previewImg.attr('src') === data.url) {
                                $previewImg.trigger('load');
                            }
                        };
                        img.onerror = function() {
                            alert('Errore nel caricamento dell\'immagine processata.');
                            resetUploadUI();
                        };
                        img.src = data.url;
                    } else {
                        alert('Errore caricamento: ' + (response.data || 'Errore sconosciuto.'));
                        resetUploadUI();
                    }
                },
                error: function() {
                    alert('Errore server durante il caricamento dell\'immagine.');
                    resetUploadUI();
                }
            });
        }

        function resetUploadUI() {
            uploadedImage = null;
            $previewImg.attr('src', '');
            $workspace.hide();
            $loading.hide();
            $dropzone.show();
            $('#pp-input-filename').val('');
            toggleAddToCart(false);
        }

        // Delete button
        $('#btn-delete-img').on('click', function() {
            if (confirm('Sei sicuro di voler rimuovere la foto?')) {
                resetUploadUI();
            }
        });

        /**
         * Pan and Zoom Logic
         */
        function initializeImageDimensions() {
            if (!uploadedImage || !activeConfig) return;

            const Vw = $viewport.width();
            const Vh = $viewport.height();

            const imgRatio = uploadedImage.width / uploadedImage.height;

            let targetW = Vw;
            let targetH = Vh;

            if (activeConfig.border_type === 'foto') {
                // Mode 'foto': image must cover the entire viewport (Pw x Ph)
                const viewRatio = Vw / Vh;
                if (imgRatio > viewRatio) {
                    // Image wider than viewport
                    targetH = Vh;
                    targetW = Vh * imgRatio;
                } else {
                    // Image taller than viewport
                    targetW = Vw;
                    targetH = Vw / imgRatio;
                }
            } else {
                // Mode 'bianco': image must cover the central front face guide box (Fw x Fh)
                const Fw_px = $frontGuide.width();
                const Fh_px = $frontGuide.height();
                const frontRatio = Fw_px / Fh_px;

                if (imgRatio > frontRatio) {
                    // Image wider than front face
                    targetH = Fh_px;
                    targetW = Fh_px * imgRatio;
                } else {
                    // Image taller than front face
                    targetW = Fw_px;
                    targetH = Fw_px / imgRatio;
                }
            }

            imgState.baseW = targetW;
            imgState.baseH = targetH;
            
            // Reapply slider zoom value
            const zoomPercent = parseFloat($zoomSlider.val()) / 100;
            imgState.zoom = zoomPercent;

            $previewImg.css({
                width: `${imgState.baseW}px`,
                height: `${imgState.baseH}px`
            });
        }

        function updateImageTransform() {
            const w = imgState.baseW * imgState.zoom;
            const h = imgState.baseH * imgState.zoom;

            // Apply constraints
            const constraints = getDragBounds(w, h);
            imgState.x = Math.max(constraints.minX, Math.min(constraints.maxX, imgState.x));
            imgState.y = Math.max(constraints.minY, Math.min(constraints.maxY, imgState.y));

            // Use setProperty with !important to prevent block themes from overriding with width: 100%
            if ($previewImg && $previewImg.length) {
                const imgEl = $previewImg[0];
                // Calculate height from the actual image aspect ratio (server already rotated EXIF)
                const aspectH = uploadedImage ? (w / uploadedImage.width) * uploadedImage.height : h;
                imgEl.style.setProperty('width', w + 'px', 'important');
                imgEl.style.setProperty('height', aspectH + 'px', 'important');
                imgEl.style.setProperty('max-width', 'none', 'important');
                imgEl.style.setProperty('max-height', 'none', 'important');
                imgEl.style.setProperty('min-width', '0', 'important');
                imgEl.style.setProperty('min-height', '0', 'important');
                imgEl.style.setProperty('left', imgState.x + 'px', 'important');
                imgEl.style.setProperty('top', imgState.y + 'px', 'important');
                imgEl.style.setProperty('transform', 'none', 'important');
            }

            // Recompute relative values
            saveCoordinates();
            calculateDPI();
        }

        // Retrieve valid dragging bounds for current zoom/image dimensions
        function getDragBounds(currentW, currentH) {
            const Vw = $viewport.width();
            const Vh = $viewport.height();

            // When zoom is applied, we must compensate for the CSS scale(s) transform origin (0,0)
            // Scaling an element from (0,0) makes its physical visual dimensions currentW and currentH.
            // So constraints are based on currentW and currentH relative to our target box.

            if (activeConfig.border_type === 'foto') {
                // Must cover the whole viewport
                return {
                    minX: Vw - currentW,
                    maxX: 0,
                    minY: Vh - currentH,
                    maxY: 0
                };
            } else {
                // Must cover the front face box
                const Fx = $frontGuide.position().left;
                const Fy = $frontGuide.position().top;
                const Fw = $frontGuide.width();
                const Fh = $frontGuide.height();

                return {
                    minX: Fx + Fw - currentW,
                    maxX: Fx,
                    minY: Fy + Fh - currentH,
                    maxY: Fy
                };
            }
        }

        // Center the image inside its bounds
        function centerImage() {
            const Vw = $viewport.width();
            const Vh = $viewport.height();
            const currentW = imgState.baseW * imgState.zoom;
            const currentH = imgState.baseH * imgState.zoom;

            if (activeConfig.border_type === 'foto') {
                imgState.x = (Vw - currentW) / 2;
                imgState.y = (Vh - currentH) / 2;
            } else {
                const Fx = $frontGuide.position().left;
                const Fy = $frontGuide.position().top;
                const Fw = $frontGuide.width();
                const Fh = $frontGuide.height();

                imgState.x = Fx + (Fw - currentW) / 2;
                imgState.y = Fy + (Fh - currentH) / 2;
            }

            updateImageTransform();
        }

        function centerImageHorizontally() {
            const Vw = $viewport.width();
            const currentW = imgState.baseW * imgState.zoom;

            if (activeConfig.border_type === 'foto') {
                imgState.x = (Vw - currentW) / 2;
            } else {
                const Fx = $frontGuide.position().left;
                const Fw = $frontGuide.width();
                imgState.x = Fx + (Fw - currentW) / 2;
            }
            updateImageTransform();
        }

        function centerImageVertically() {
            const Vh = $viewport.height();
            const currentH = imgState.baseH * imgState.zoom;

            if (activeConfig.border_type === 'foto') {
                imgState.y = (Vh - currentH) / 2;
            } else {
                const Fy = $frontGuide.position().top;
                const Fh = $frontGuide.height();
                imgState.y = Fy + (Fh - currentH) / 2;
            }
            updateImageTransform();
        }

        function resetToFit() {
            $zoomSlider.val(100);
            imgState.zoom = 1.0;
            centerImage();
        }

        // Event hooks for button clicks
        $('#btn-center-h').on('click', centerImageHorizontally);
        $('#btn-center-v').on('click', centerImageVertically);
        $('#btn-fit').on('click', resetToFit);

        // Zoom Slider change
        $zoomSlider.on('input', function() {
            const newZoom = parseFloat($(this).val()) / 100;
            adjustZoom(newZoom);
        });

        // Mousewheel zoom inside viewport
        $viewport.on('wheel', function(e) {
            if (!uploadedImage) return;
            e.preventDefault();

            const delta = e.originalEvent.deltaY;
            let currentVal = parseInt($zoomSlider.val());
            
            if (delta < 0) {
                currentVal = Math.min(500, currentVal + 10);
            } else {
                currentVal = Math.max(100, currentVal - 10);
            }

            $zoomSlider.val(currentVal);
            adjustZoom(currentVal / 100);
        });

        function adjustZoom(newZoom) {
            const oldZoom = imgState.zoom;
            imgState.zoom = newZoom;

            // Zoom relative to viewport center
            const Vw = $viewport.width();
            const Vh = $viewport.height();
            const centerX = Vw / 2;
            const centerY = Vh / 2;

            const oldW = imgState.baseW * oldZoom;
            const oldH = imgState.baseH * oldZoom;
            const newW = imgState.baseW * newZoom;
            const newH = imgState.baseH * newZoom;

            const px = (centerX - imgState.x) / oldW;
            const py = (centerY - imgState.y) / oldH;

            imgState.x = centerX - px * newW;
            imgState.y = centerY - py * newH;

            updateImageTransform();
        }

        // Pointer Dragging (Touch + Mouse support)
        let isDragging = false;
        let startX = 0;
        let startY = 0;
        let imgStartX = 0;
        let imgStartY = 0;

        $viewport.on('pointerdown', function(e) {
            if (!uploadedImage) return;
            isDragging = true;
            $viewport[0].setPointerCapture(e.originalEvent.pointerId);

            startX = e.originalEvent.clientX;
            startY = e.originalEvent.clientY;
            imgStartX = imgState.x;
            imgStartY = imgState.y;
        });

        $viewport.on('pointermove', function(e) {
            if (!isDragging) return;
            const dx = e.originalEvent.clientX - startX;
            const dy = e.originalEvent.clientY - startY;

            imgState.x = imgStartX + dx;
            imgState.y = imgStartY + dy;

            updateImageTransform();
        });

        $viewport.on('pointerup pointercancel', function(e) {
            if (!isDragging) return;
            isDragging = false;
            try {
                $viewport[0].releasePointerCapture(e.originalEvent.pointerId);
            } catch (err) {}
        });

        /**
         * Mathematics translation: Store normalized coordinates
         */
        function saveCoordinates() {
            if (!uploadedImage || !activeConfig) return;

            const Vw = $viewport.width();
            const Vh = $viewport.height();
            const w = imgState.baseW * imgState.zoom;
            const h = imgState.baseH * imgState.zoom;

            let rel_x, rel_y, rel_w, rel_h;

            if (activeConfig.border_type === 'foto') {
                // Coordinates relative to the entire viewport (Pw x Ph)
                rel_x = imgState.x / Vw;
                rel_y = imgState.y / Vh;
                rel_w = w / Vw;
                rel_h = h / Vh;
            } else {
                // Coordinates relative to the front face box (Fw x Fh)
                const Fx = $frontGuide.position().left;
                const Fy = $frontGuide.position().top;
                const Fw = $frontGuide.width();
                const Fh = $frontGuide.height();

                rel_x = (imgState.x - Fx) / Fw;
                rel_y = (imgState.y - Fy) / Fh;
                rel_w = w / Fw;
                rel_h = h / Fh;
            }

            $('#pp-input-rel-x').val(rel_x.toFixed(6));
            $('#pp-input-rel-y').val(rel_y.toFixed(6));
            $('#pp-input-rel-w').val(rel_w.toFixed(6));
            $('#pp-input-rel-h').val(rel_h.toFixed(6));

            autoSaveConfig();
        }

        let saveTimeout = null;
        function autoSaveConfig() {
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(function() {
                const fileName = $('#pp-input-filename').val();
                if (!fileName || !window.pureprint_vars) return;

                const data = {
                    action: 'pureprint_save_session_config',
                    pureprint_file_name: fileName,
                    pureprint_rel_x: $('#pp-input-rel-x').val(),
                    pureprint_rel_y: $('#pp-input-rel-y').val(),
                    pureprint_rel_w: $('#pp-input-rel-w').val(),
                    pureprint_rel_h: $('#pp-input-rel-h').val(),
                    pureprint_front_w_mm: $('#pp-input-front-w').val(),
                    pureprint_front_h_mm: $('#pp-input-front-h').val(),
                    pureprint_thickness_mm: $('#pp-input-thickness').val(),
                    pureprint_bleed_mm: $('#pp-input-bleed').val(),
                    pureprint_border_type: $('#pp-input-border').val(),
                    pureprint_dpi: $('#pp-input-dpi').val()
                };

                $.post(pureprint_vars.ajax_url, data);
            }, 500);
        }

        // Live DPI calculation
        function calculateDPI() {
            if (!uploadedImage || !activeConfig) return;

            const rel_w = parseFloat($('#pp-input-rel-w').val());

            // Active printable dimension width (mm)
            let print_w_mm = activeConfig.front_w_mm;
            if (activeConfig.border_type === 'foto') {
                print_w_mm += 2 * activeConfig.thickness_mm;
            }

            // Calculations:
            // DPI = (image_px * 25.4) / (rel_w * print_size_mm)
            const dpi = Math.round((uploadedImage.width * 25.4) / (rel_w * print_w_mm));

            $('#pp-input-dpi').val(dpi);

            // Display updates
            $dpiInfo.text(`${dpi} DPI`);
            $dpiInfo.removeClass('dpi-excellent dpi-good dpi-poor');

            if (dpi >= 300) {
                $dpiInfo.addClass('dpi-excellent').text(`ECCELLENTE (${dpi} DPI)`);
            } else if (dpi >= 150) {
                $dpiInfo.addClass('dpi-good').text(`BUONA (${dpi} DPI)`);
            } else {
                $dpiInfo.addClass('dpi-poor').text(`BASSA QUALITÀ (${dpi} DPI)`);
            }
        }

        // Keep responsive layout correct when resizing browser window
        $(window).on('resize', function() {
            if (uploadedImage) {
                // Recalculate viewport and image positioning scale
                const oldZoom = imgState.zoom;
                
                // Track relative position before resize
                const rx = imgState.x / imgState.baseW;
                const ry = imgState.y / imgState.baseH;

                initializeImageDimensions();
                
                // Reapply relative position
                imgState.zoom = oldZoom;
                imgState.x = rx * imgState.baseW;
                imgState.y = ry * imgState.baseH;
                
                updateImageTransform();
            }
        });
    });
})(jQuery);
