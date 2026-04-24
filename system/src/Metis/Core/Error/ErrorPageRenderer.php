<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class ErrorPageRenderer {
    public function render( int $status, string $traceId, string $message, string $title = '' ): string {
        $page = $this->renderTemplate( $status, $traceId, $message, $title );

        if ( $page !== '' ) {
            return $page;
        }

        $reference = '<p class="metis-trace-reference">Reference ID: ' . htmlspecialchars( $traceId, ENT_QUOTES ) . '</p>';
        $resolvedTitle = $title !== '' ? $title : 'Metis is temporarily unavailable';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . htmlspecialchars( (string) $status, ENT_QUOTES )
            . ' | Metis</title></head><body style="font-family:Georgia,serif;padding:32px;color:#1f2430;background:#f5f1e8;">'
            . '<main style="max-width:720px;margin:0 auto;background:#fff;padding:32px;border:1px solid #d7d2c6;">'
            . '<h1>' . htmlspecialchars( $resolvedTitle, ENT_QUOTES ) . '</h1>'
            . '<p>' . htmlspecialchars( $message, ENT_QUOTES ) . '</p>'
            . $reference
            . '</main></body></html>';
    }

    private function renderTemplate( int $status, string $traceId, string $message, string $title ): string {
        $template = ( defined( 'METIS_ASSETS_PATH' ) ? METIS_ASSETS_PATH : dirname( __DIR__, 4 ) . '/assets/' ) . 'error-pages/error.php';

        if ( ! is_file( $template ) ) {
            return '';
        }

        $metisErrorStatus = $status;
        $metisErrorTraceId = $traceId;
        $metisErrorMessage = $message;
        $metisErrorTitle = $title;

        ob_start();
        include $template;
        $body = ob_get_clean();

        return is_string( $body ) ? $body : '';
    }
}
