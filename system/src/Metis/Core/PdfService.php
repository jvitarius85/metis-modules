<?php
if (!defined('METIS_ROOT')) exit;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Core PDF Service
 * Centralized DOMPDF wrapper for MW Tools
 */
class Core_PDF_Service {

    private $dompdf;

    public function __construct(array $config = []) {

        if (!class_exists('\Dompdf\\Dompdf')) {
            throw new RuntimeException('Dompdf not loaded. Ensure autoload is required.');
        }

        $defaults = [
            'isRemoteEnabled'     => true,
            'defaultFont'         => 'Figtree',
            'isHtml5ParserEnabled'=> true,
            'isPhpEnabled'        => false,
        ];

        $options = new Options();

        foreach (array_merge($defaults, $config) as $key => $value) {
            $setter = 'set' . ucfirst($key);
            if (method_exists($options, $setter)) {
                $options->$setter($value);
            }
        }

        if (defined('METIS_ASSETS_PATH')) {
            $fontDir = METIS_ASSETS_PATH . 'fonts/Figtree/';
            if (is_dir($fontDir)) {
                $options->setFontDir($fontDir);
                $options->setFontCache($fontDir);
            }
        }

        $this->dompdf = new Dompdf($options);
    }

    // -------------------------------------------------------------------------
    // Core render
    // -------------------------------------------------------------------------

    public function render(string $html, array $settings = []): string {

        $settings = array_merge([ 'paper' => 'letter', 'orientation' => 'portrait' ], $settings);

        $this->dompdf->loadHtml($html);
        $this->dompdf->setPaper($settings['paper'], $settings['orientation']);
        $this->dompdf->render();

        return $this->dompdf->output();
    }

    // -------------------------------------------------------------------------
    // Render with canvas-drawn footer on every page
    //
    // Uses dompdf's post-render canvas callback (no isPhpEnabled required).
    // Footer is split: $left_text on the left, "Page X of Y" on the right.
    // -------------------------------------------------------------------------

    public function render_with_footer(string $html, string $left_text, array $settings = []): string {

        $settings = array_merge([ 'paper' => 'letter', 'orientation' => 'portrait' ], $settings);

        $this->dompdf->loadHtml($html);
        $this->dompdf->setPaper($settings['paper'], $settings['orientation']);
        $this->dompdf->render();

        $canvas     = $this->dompdf->getCanvas();
        $font       = $this->dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $font_size  = 8;
        $page_w     = $canvas->get_width();
        $page_h     = $canvas->get_height();
        $margin     = 28;   // ~0.39in from edge
        $line_y     = $page_h - 31;  // moved up ~1/8in from original
        $text_y     = $page_h - 27;  // moved up ~1/8in from original
        $text_color = [ 0.6, 0.6, 0.6 ];   // #9ca3af equivalent
        $line_color = [ 0.898, 0.906, 0.922 ]; // #e5e7eb equivalent

        $canvas->page_script( function( $page_num, $page_count, $canvas, $font_metrics )
            use ( $font, $font_size, $page_w, $margin, $line_y, $text_y, $text_color, $line_color, $left_text )
        {
            // Separator line
            $canvas->line( $margin, $line_y, $page_w - $margin, $line_y, $line_color, 0.5 );

            // Left: org — portal — Confidential
            $canvas->text( $margin, $text_y, $left_text, $font, $font_size, $text_color );

            // Right: Page X of Y
            $right_text = "Page {$page_num} of {$page_count}";
            $text_w     = $font_metrics->getTextWidth( $right_text, $font, $font_size );
            $canvas->text( $page_w - $margin - $text_w, $text_y, $right_text, $font, $font_size, $text_color );
        });

        return $this->dompdf->output();
    }

    // -------------------------------------------------------------------------
    // Stream / download helpers
    // -------------------------------------------------------------------------

    public function stream(string $html, string $filename = 'report.pdf', array $settings = []) {

        $output = $this->render($html, $settings);
        $this->send_headers('inline', $filename, strlen($output));
        echo $output;
        exit;
    }

    public function download(string $html, string $filename = 'report.pdf', array $settings = []) {

        $output = $this->render($html, $settings);
        $this->send_headers('attachment', $filename, strlen($output));
        echo $output;
        exit;
    }

    public function download_with_footer(string $html, string $left_text, string $filename = 'report.pdf', array $settings = []) {

        $output = $this->render_with_footer($html, $left_text, $settings);
        $this->send_headers('attachment', $filename, strlen($output));
        echo $output;
        exit;
    }

    // -------------------------------------------------------------------------

    private function send_headers(string $disposition, string $filename, int $length): void {
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="' . metis_filename_clean($filename) . '"');
        header('Content-Length: ' . $length);
    }
}
