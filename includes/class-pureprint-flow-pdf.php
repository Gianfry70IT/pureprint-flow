<?php
/**
 * PurePrint Flow - PDF Generator
 * Custom FPDF implementation supporting vector clipping masks and coordinate translation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load FPDF Library
require_once PUREPRINT_PATH . 'includes/fpdf/fpdf.php';

/**
 * Subclass FPDF to add PDF vector graphics state and clipping operations.
 */
class PurePrint_PDF extends FPDF {

	/**
	 * Start a rectangular clipping path.
	 * Writes PDF graphics state 'q' (save state), defines rect path 're',
	 * sets clip 'W', and discards path 'n'.
	 */
	public function StartClip( $x, $y, $w, $h ) {
		$this->_out( 'q' ); // Save graphics state
		
		// PDF coordinates start from bottom-left. FPDF internally uses Y from top-left.
		// Translation to PDF points:
		// x_pdf = x * k
		// y_pdf = (page_height - y - h_rect) * k
		// w_pdf = w * k
		// h_pdf = h_rect * k
		$hp = $this->h;
		$op = sprintf(
			'%.2F %.2F %.2F %.2F re W n',
			$x * $this->k,
			( $hp - $y - $h ) * $this->k,
			$w * $this->k,
			$h * $this->k
		);
		$this->_out( $op );
	}

	/**
	 * End clipping path.
	 * Writes PDF graphics state 'Q' (restore state).
	 */
	public function EndClip() {
		$this->_out( 'Q' ); // Restore graphics state
	}
}

/**
 * Main Controller for PDF Generation
 */
class PurePrint_Flow_PDF {

	/**
	 * Generate and stream high-res crop PDF.
	 *
	 * @param string $file_path Absolute path to the original uploaded image file
	 * @param float  $rel_x     Relative crop X position
	 * @param float  $rel_y     Relative crop Y position
	 * @param float  $rel_w     Relative crop width
	 * @param float  $rel_h     Relative crop height
	 * @param int    $front_w   Front face width (mm)
	 * @param int    $front_h   Front face height (mm)
	 * @param int    $thickness Frame thickness / bleed (mm)
	 * @param string $border    Border type ('foto' or 'bianco')
	 * @param string $output_name File name for the download PDF stream
	 * @param string $dest        Destination for FPDF (D for download, F for file, I for inline, S for string)
	 */
	public static function generate( $file_path, $rel_x, $rel_y, $rel_w, $rel_h, $front_w, $front_h, $thickness, $border, $output_name = 'print.pdf', $dest = 'D' ) {
		// Total page size (in mm) including bleed / frame wrap margins
		$Pw = $front_w + 2 * $thickness;
		$Ph = $front_h + 2 * $thickness;

		// Create PDF in Portrait custom size (using array size overrides orientation)
		$pdf = new PurePrint_PDF( 'P', 'mm', array( $Pw, $Ph ) );
		$pdf->SetAutoPageBreak( false );
		$pdf->AddPage( 'P', array( $Pw, $Ph ) );

		if ( 'foto' === $border ) {
			// Mode 'foto': image must bleed to the outer edges.
			// Image coordinates in mm relative to the page (Pw x Ph):
			$img_x = $rel_x * $Pw;
			$img_y = $rel_y * $Ph;
			$img_w = $rel_w * $Pw;
			$img_h = $rel_h * $Ph;

			// Clip image to page bounds
			$pdf->StartClip( 0, 0, $Pw, $Ph );
			$pdf->Image( $file_path, $img_x, $img_y, $img_w, $img_h );
			$pdf->EndClip();

		} else {
			// Mode 'bianco': image is centered on the front face (Fw x Fh).
			// The wrap borders (thickness mm) are left white.
			// Image coordinates in mm relative to the page, mapped to the front face:
			$img_x = $thickness + ( $rel_x * $front_w );
			$img_y = $thickness + ( $rel_y * $front_h );
			$img_w = $rel_w * $front_w;
			$img_h = $rel_h * $front_h;

			// Draw white background for page (optional, as default page is white)
			$pdf->SetFillColor( 255, 255, 255 );
			$pdf->Rect( 0, 0, $Pw, $Ph, 'F' );

			// Clip image to the front face boundaries
			$pdf->StartClip( $thickness, $thickness, $front_w, $front_h );
			$pdf->Image( $file_path, $img_x, $img_y, $img_w, $img_h );
			$pdf->EndClip();
		}

		// Save/Output PDF based on destination setting
		$pdf->Output( $dest, $output_name );
	}
}
