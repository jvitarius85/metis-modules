<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

require_once dirname( __DIR__ ) . '/src/Metis/Modules/Board/BylawsFormatter.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$source = <<<TEXT
ARTICLE I - Name
The name of the organization is Mobilize Waco.

Section 1. Purpose
The corporation exists to support access, visibility, and leadership.
- Maintain governance records
- Preserve signed official copies

ARTICLE II - Board
Section 1. Directors
Directors serve according to these bylaws.
<script>alert("x")</script>
TEXT;

$formatted = \Metis\Modules\Board\BylawsFormatter::format( $source, 'Mobilize Waco Bylaws' );
$html = (string) ( $formatted['html'] ?? '' );
$outline = (array) ( $formatted['outline'] ?? [] );

$assert( str_contains( $html, '<article class="metis-board-bylaws-document">' ), 'Formatter should create the bylaws document wrapper.' );
$assert( str_contains( $html, '<h3 id="metis-bylaws-article-1">ARTICLE I - Name</h3>' ), 'Formatter should detect article headings.' );
$assert( str_contains( $html, '<h4 id="metis-bylaws-article-1-section-1">Section 1. Purpose</h4>' ), 'Formatter should detect section headings.' );
$assert( str_contains( $html, '<ul class="metis-board-bylaws-list">' ), 'Formatter should preserve unordered list structure.' );
$assert( str_contains( $html, '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;' ), 'Formatter must escape pasted HTML.' );
$assert( count( $outline ) === 4, 'Formatter should expose article and section outline entries.' );
$assert( (int) ( $formatted['word_count'] ?? 0 ) > 20, 'Formatter should report a useful word count.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Board bylaws formatter checks passed.\n" );
