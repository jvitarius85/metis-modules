<?php
declare(strict_types=1);

namespace Metis\Core\Editor {
    final class BlockRegistry {
        /** @var array<string,array<string,mixed>> */
        private static array $definitions = [];

        public static function boot(): void {}
        public static function register( string $type, array $definition ): void { self::$definitions[ $type ] = $definition; }
        public static function get( string $type ): ?array { return self::$definitions[ $type ] ?? null; }
        public static function all(): array { return self::$definitions; }
        public static function exists( string $type ): bool { return isset( self::$definitions[ $type ] ); }
        public static function validateBlock( array $block ): array { return [ 'valid' => true, 'errors' => [] ]; }
    }
}

namespace Metis\Modules\Website\Services {
    final class EditorContextPolicy {
        public static function normalizeRenderMode( string $mode, string $context ): string { return $mode !== '' ? $mode : 'public'; }
        public static function sanitizeStyleForRenderMode( array $style, string $mode ): array { return $style; }
    }
    final class MenuService {}
    final class PostService {}
}

namespace Metis\Modules\Testimonies {
    final class Repository {
        public static function categoryOptions( bool $activeOnly = true ): array {
            return [
                [ 'value' => '2', 'label' => 'Healthcare', 'slug' => 'healthcare' ],
                [ 'value' => '3', 'label' => 'Advocacy', 'slug' => 'advocacy' ],
            ];
        }

        public static function publicTestimonials( array $filters = [] ): array {
            return [
                [
                    'speaker_name' => 'Avery Stone',
                    'speaker_title' => 'Executive Director',
                    'speaker_company' => 'Access Co.',
                    'quote_text' => 'This made our website stronger.',
                    'categories' => [ 'Healthcare', 'Advocacy' ],
                ],
            ];
        }
    }
}

namespace Metis\Modules\Donations { final class CampaignService { public static function normalizeDescriptionHtml( string $html ): string { return $html; } } }

namespace {
    if ( PHP_SAPI !== 'cli' ) {
        fwrite( STDERR, "This test must be run from the command line.\n" );
        exit( 1 );
    }

    function metis_escape_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
    function metis_escape_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
    function metis_escape_url( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
    function metis_runtime_kses_post( string $value ): string { return $value; }
    function metis_number_format( float $value, int $decimals = 0 ): string { return number_format( $value, $decimals, '.', ',' ); }
    function metis_key_clean( string $value ): string {
        $value = strtolower( trim( $value ) );
        return preg_replace( '/[^a-z0-9_]+/', '_', $value ) ?? '';
    }

    require_once dirname( __DIR__ ) . '/src/Metis/Modules/Website/BlockRegistry.php';
    require_once dirname( __DIR__ ) . '/src/Metis/Modules/Website/Services/EditorOptionsService.php';
    require_once dirname( __DIR__ ) . '/src/Metis/Modules/Website/Services/BlockRenderer.php';

    $failures = [];
    $assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    \Metis\Modules\Website\BlockRegistry::boot();
    $definition = \Metis\Modules\Website\BlockRegistry::get( 'testimonies_block' );
    $options = \Metis\Modules\Website\Services\EditorOptionsService::testimonyCategoryOptions();
    $html = \Metis\Modules\Website\Services\BlockRenderer::render(
        [
            'type' => 'testimonies_block',
            'data' => [
                'category_ids' => [ 2, 3 ],
                'limit' => 6,
                'layout' => 'grid',
                'show_category' => true,
            ],
            'style' => [],
        ],
        []
    );
    $rotatorHtml = \Metis\Modules\Website\Services\BlockRenderer::render(
        [
            'type' => 'testimonies_block',
            'data' => [
                'category_ids' => [ 2 ],
                'limit' => 3,
                'layout' => 'rotator',
                'show_category' => true,
            ],
            'style' => [],
        ],
        []
    );

    $assert( is_array( $definition ) && ( $definition['label'] ?? '' ) === 'Testimonies', 'Website block registry should register the testimonies block.' );
    $assert( count( $options ) === 2, 'Website editor options should expose testimony categories.' );
    $assert( str_contains( $html, 'Avery Stone' ), 'Website testimonies block should render speaker names.' );
    $assert( str_contains( $html, 'Healthcare, Advocacy' ), 'Website testimonies block should render category labels when enabled.' );
    $assert( str_contains( $html, 'This made our website stronger.' ), 'Website testimonies block should render quote text.' );
    $assert( str_contains( $rotatorHtml, 'metis-testimonies-rotator' ), 'Website testimonies block should render rotator layout markup.' );
    $assert( str_contains( $rotatorHtml, 'data-rotator-slide' ), 'Rotator layout should expose slide items.' );

    if ( $failures !== [] ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    fwrite( STDOUT, "Website testimonies block checks passed.\n" );
}
