<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class FallbackResolver {
    public function resolve( mixed $fallback, ErrorContext $context, mixed $default = null ): mixed {
        if ( is_callable( $fallback ) ) {
            return $fallback( $context );
        }

        if ( $fallback !== null ) {
            return $fallback;
        }

        return $default;
    }

    public function unavailableMessage( string $message = 'This data is temporarily unavailable.' ): array {
        return [
            'ok' => false,
            'available' => false,
            'message' => $message,
        ];
    }

    public function widgetShell( string $message = 'This section is temporarily unavailable.' ): string {
        return '<section class="metis-widget metis-widget-unavailable"><div class="metis-widget__body">' . htmlspecialchars( $message, ENT_QUOTES ) . '</div></section>';
    }
}
