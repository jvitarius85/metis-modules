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
1. First duty

2. Second duty with boardâ€™s records

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
$assert( str_contains( $html, '<ol class="metis-board-bylaws-list" type="1">' ), 'Formatter should preserve ordered list structure.' );
$assert( str_contains( $html, '<li value="2">Second duty with board&#039;s records</li>' ), 'Formatter should preserve pasted numbering and repair mojibake.' );
$assert( str_contains( $html, '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;' ), 'Formatter must escape pasted HTML.' );
$assert( count( $outline ) === 4, 'Formatter should expose article and section outline entries.' );
$assert( (int) ( $formatted['word_count'] ?? 0 ) > 20, 'Formatter should report a useful word count.' );

$source_with_toc = <<<TEXT
Table of Contents
ARTICLE I - Name
Section 1. Corporation Name
ARTICLE II - Board
Section 1. Directors

ARTICLE I - Name
The legal name is Mobilize Waco.

Section 1. Corporation Name
The corporation name is Mobilize Waco.

ARTICLE II - Board
Section 1. Directors
Directors serve according to these bylaws.
TEXT;

$formatted_with_toc = \Metis\Modules\Board\BylawsFormatter::format( $source_with_toc, 'Bylaws' );
$html_with_toc = (string) ( $formatted_with_toc['html'] ?? '' );

$assert( ! str_contains( $html_with_toc, 'Table of Contents' ), 'Formatter should remove pasted table of contents text.' );
$assert( substr_count( $html_with_toc, '<h3 id="metis-bylaws-article-1">ARTICLE I - Name</h3>' ) === 1, 'Formatter should link to actual article content, not pasted TOC entries.' );
$assert( str_contains( $html_with_toc, 'The legal name is Mobilize Waco.' ), 'Formatter should keep actual article content after removing pasted TOC.' );

$mojibake_source = <<<TEXT
ARTICLE I - Encoding
Section 1. Punctuation
The executive director shall provide the yearâ€™s report â€œas writtenâ€� â€“ with notes.
TEXT;

$formatted_mojibake = \Metis\Modules\Board\BylawsFormatter::format( $mojibake_source, 'Bylaws' );
$html_mojibake = (string) ( $formatted_mojibake['html'] ?? '' );

$assert( ! str_contains( $html_mojibake, 'â' ), 'Formatter should remove visible mojibake marker sequences.' );
$assert( str_contains( $html_mojibake, 'year&#039;s report &quot;as written&quot; - with notes.' ), 'Formatter should normalize common mojibake punctuation.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Board bylaws formatter checks passed.\n" );
