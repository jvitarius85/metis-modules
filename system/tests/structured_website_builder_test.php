<?php
declare(strict_types=1);

namespace {
    if ( PHP_SAPI !== 'cli' ) {
        fwrite( STDERR, "This test must be run from the command line.\n" );
        exit( 1 );
    }

    function metis_key_clean( string $value ): string {
        $value = strtolower( trim( $value ) );
        return preg_replace( '/[^a-z0-9_]+/', '_', $value ) ?? '';
    }

    function metis_text_clean( string $value ): string {
        return trim( preg_replace( '/\s+/', ' ', $value ) ?? $value );
    }

    function metis_escape_html( string $value ): string {
        return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
    }

    function metis_escape_attr( string $value ): string {
        return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
    }

    function metis_escape_url( string $value ): string {
        return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
    }

    function metis_runtime_kses_post( string $value ): string {
        return $value;
    }

    require_once dirname( __DIR__ ) . '/src/Metis/Modules/Website/Services/StructuredWebsiteBuilderService.php';

    $failures = [];
    $assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    $result = \Metis\Modules\Website\Services\StructuredWebsiteBuilderService::normalizeLayout(
        [
            'editor_meta' => [
                'structured_builder' => [
                    'sections' => [
                        [
                            'id' => 'section_0',
                            'type' => 'testimonials',
                            'content' => [
                                'category_ids' => [ 5 ],
                                'limit' => 6,
                                'layout' => 'rotator',
                                'featured_only' => false,
                                'show_category' => true,
                                'empty_message' => 'No testimonies available yet.',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        [ 'page_type' => 'page', 'is_post' => false ]
    );

    $layout = json_decode( (string) ( $result['layout_json'] ?? '' ), true );
    $sections = is_array( $layout['sections'] ?? null ) ? $layout['sections'] : [];
    $firstSection = is_array( $sections[0] ?? null ) ? $sections[0] : [];
    $columns = is_array( $firstSection['columns'] ?? null ) ? $firstSection['columns'] : [];
    $firstColumn = is_array( $columns[0] ?? null ) ? $columns[0] : [];
    $modules = is_array( $firstColumn['modules'] ?? null ) ? $firstColumn['modules'] : [];
    $firstModule = is_array( $modules[0] ?? null ) ? $modules[0] : [];
    $firstData = is_array( $firstModule['data'] ?? null ) ? $firstModule['data'] : [];

    $assert( ( $result['sections'][0]['content']['layout'] ?? '' ) === 'rotator', 'Normalized structured builder sections must preserve testimony rotator layout.' );
    $assert( ( $firstModule['type'] ?? '' ) === 'testimonies_block', 'Structured builder must map testimonials sections to testimonies_block.' );
    $assert( ( $firstData['layout'] ?? '' ) === 'rotator', 'Structured builder layout_json must preserve testimony rotator layout.' );

    if ( $failures !== [] ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    fwrite( STDOUT, "Structured website builder checks passed.\n" );
}
