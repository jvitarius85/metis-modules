<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
require_once $root . '/src/Metis/Core/Runtime/SanitizationRuntime.php';
require_once $root . '/src/Metis/Modules/Website/Services/StructuredWebsiteBuilderService.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$dirty = implode( '', [
    '<p onclick="evil()">Safe <strong>text</strong></p>',
    '<p class="metis-text-align-center">Centered</p>',
    '<a href="java&#x0D;script:alert(1)" title="x" target="_blank" rel="noopener">bad link</a>',
    '<a href="https://example.com/path" title="safe" target="_blank" rel="noopener">safe link</a>',
    '<img src="data:text/html,<script>alert(1)</script>" onerror="evil()" alt="bad">',
    '<img src="/uploads/photo.jpg" alt="Safe photo" width="120" height="80">',
    '<span style="background-image:url(javascript:evil())">bad style</span>',
    '<script>alert(1)</script><style>body{display:none}</style><iframe src="https://example.com"></iframe>',
] );

$clean = metis_runtime_kses_post( $dirty );

$assert( ! str_contains( strtolower( $clean ), 'onclick' ), 'Event handler attributes must be stripped.' );
$assert( ! str_contains( strtolower( $clean ), 'onerror' ), 'Image event handler attributes must be stripped.' );
$assert( ! str_contains( strtolower( $clean ), 'javascript:' ), 'javascript: URLs must be stripped.' );
$assert( ! str_contains( strtolower( $clean ), 'data:text/html' ), 'data:text/html URLs must be stripped.' );
$assert( ! str_contains( strtolower( $clean ), '<script' ), 'Script tags must be removed.' );
$assert( ! str_contains( strtolower( $clean ), '<style' ), 'Style tags must be removed.' );
$assert( ! str_contains( strtolower( $clean ), '<iframe' ), 'Iframe tags must be removed.' );
$assert( ! str_contains( strtolower( $clean ), 'background-image' ), 'Unsafe style values must be stripped.' );
$assert( str_contains( $clean, '<strong>text</strong>' ), 'Safe rich text tags must be preserved.' );
$assert( str_contains( $clean, 'class="metis-text-align-center"' ), 'Safe rich text alignment classes must be preserved.' );
$assert( str_contains( $clean, 'href="https://example.com/path"' ), 'Safe external links must be preserved.' );
$assert( str_contains( $clean, 'src="/uploads/photo.jpg"' ), 'Safe image URLs must be preserved.' );
$assert( str_contains( $clean, 'width="120"' ) && str_contains( $clean, 'height="80"' ), 'Safe image dimensions must be preserved.' );

$fallback = metis_runtime_sanitize_html_attributes_fallback(
    '<a href="https://example.com" title="Safe" onclick="evil()">Link</a><img src="/x.jpg" alt="X" onerror="evil()">',
    metis_runtime_allowed_html_map()
);
$assert( str_contains( $fallback, 'href="https://example.com"' ) && str_contains( $fallback, 'title="Safe"' ), 'Fallback sanitizer must keep multiple safe attributes.' );
$assert( ! str_contains( strtolower( $fallback ), 'onclick' ) && ! str_contains( strtolower( $fallback ), 'onerror' ), 'Fallback sanitizer must strip event handlers.' );

$structured = \Metis\Modules\Website\Services\StructuredWebsiteBuilderService::normalizeLayout(
    json_encode(
        [
            'editor_meta' => [
                'structured_builder' => [
                    'sections' => [
                        [ 'id' => 'form', 'type' => 'form', 'content' => [ 'form_id' => '7', 'submit_label' => 'Send' ] ],
                        [ 'id' => 'donation', 'type' => 'donation_form', 'content' => [ 'preset_amounts' => '10, 20, bad, 50', 'allow_custom_amount' => '1' ] ],
                        [ 'id' => 'progress', 'type' => 'donation_progress', 'content' => [ 'goal_amount' => '1000.50', 'raised_amount' => '250', 'percent' => '125' ] ],
                        [ 'id' => 'summary', 'type' => 'campaign_summary', 'content' => [ 'content' => '<p>Safe</p><script>alert(1)</script>' ] ],
                        [ 'id' => 'divider', 'type' => 'divider', 'content' => [ 'style' => 'dashed', 'label' => 'Break' ] ],
                        [ 'id' => 'alignment', 'type' => 'text', 'content' => [ 'body' => '<p class="metis-text-align-right">Right</p>' ] ],
                        [ 'id' => 'mojibake', 'type' => 'text', 'content' => [ 'body' => '<p>this is going to beÂ amazing</p>' ] ],
                    ],
                ],
            ],
        ],
        JSON_UNESCAPED_SLASHES
    )
);
$structured_types = array_map( static fn( array $section ): string => (string) ( $section['type'] ?? '' ), $structured['sections'] );
$assert( $structured_types === [ 'form', 'donation_form', 'donation_progress', 'campaign_summary', 'divider', 'text', 'text' ], 'New Website structured block types must survive normalization.' );
$assert( ( $structured['sections'][1]['content']['preset_amounts'] ?? [] ) === [ 10, 20, 50 ], 'Donation preset amounts must be normalized.' );
$assert( ( $structured['sections'][2]['content']['percent'] ?? '' ) === '100', 'Donation progress percent must be capped.' );
$assert( ! str_contains( strtolower( (string) ( $structured['sections'][3]['content']['content'] ?? '' ) ), '<script' ), 'Campaign summary content must be sanitized.' );
$assert( str_contains( (string) ( $structured['sections'][5]['content']['body'] ?? '' ), 'metis-text-align-right' ), 'Structured content must preserve safe alignment classes.' );
$assert( str_contains( (string) ( $structured['sections'][6]['content']['body'] ?? '' ), 'be amazing' ), 'Structured content must repair common mojibake before save/render.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Website sanitization checks passed.\n" );
