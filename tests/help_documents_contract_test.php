<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
require_once $root . '/database/seeders/help_documents_seed.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$categories = \Metis_Help_Documents_Seed::categories();
$articles = \Metis_Help_Documents_Seed::articles();

$assert( count( $categories ) === 17, 'Help seed must define all 17 required categories.' );
$assert( count( $articles ) >= 106, 'Help seed must define at least 106 articles.' );

$seedKeys = [];
$slugs = [];

foreach ( $articles as $index => $article ) {
    $seedKey = (string) ( $article['seed_key'] ?? '' );
    $slug = (string) ( $article['slug'] ?? '' );
    $title = (string) ( $article['title'] ?? 'Untitled' );
    $summary = trim( (string) ( $article['summary'] ?? '' ) );
    $content = trim( strip_tags( (string) ( $article['content'] ?? '' ) ) );
    $tags = (array) ( $article['tags'] ?? [] );
    $terms = trim( (string) ( $article['search_terms'] ?? '' ) );

    $assert( $seedKey !== '', sprintf( 'Article %d is missing seed_key.', $index + 1 ) );
    $assert( ! isset( $seedKeys[ $seedKey ] ), sprintf( 'Duplicate seed_key detected: %s', $seedKey ) );
    $seedKeys[ $seedKey ] = true;

    $assert( $slug !== '', sprintf( 'Article "%s" is missing a slug.', $title ) );
    $assert( ! isset( $slugs[ $slug ] ), sprintf( 'Duplicate article slug detected: %s', $slug ) );
    $slugs[ $slug ] = true;

    $assert( $summary !== '', sprintf( 'Article "%s" is missing summary text.', $title ) );
    $assert( str_word_count( $content ) >= 250, sprintf( 'Article "%s" is below the 250-word minimum.', $title ) );
    $assert( count( $tags ) >= 5, sprintf( 'Article "%s" needs at least 5 tags.', $title ) );
    $assert( str_contains( strtolower( $content ), 'what this is' ), sprintf( 'Article "%s" is missing the "What this is" section.', $title ) );
    $assert( str_contains( strtolower( $content ), 'when to use it' ), sprintf( 'Article "%s" is missing the "When to use it" section.', $title ) );
    $assert( str_contains( strtolower( $content ), 'how to use it' ), sprintf( 'Article "%s" is missing the "How to use it" section.', $title ) );
    $assert( str_contains( strtolower( $content ), 'important notes' ), sprintf( 'Article "%s" is missing the "Important notes" section.', $title ) );
    $assert( str_contains( strtolower( $content ), 'common issues' ), sprintf( 'Article "%s" is missing the "Common issues" section.', $title ) );
    $assert( str_contains( strtolower( $content ), 'related areas' ), sprintf( 'Article "%s" is missing the "Related areas" section.', $title ) );
    $assert( $terms !== '', sprintf( 'Article "%s" is missing search_terms.', $title ) );
}

$requiredFiles = [
    $root . '/modules/help/module.json',
    $root . '/modules/help/admin/articles.php',
    $root . '/modules/help/admin/issue-resolution.php',
    $root . '/src/Metis/Modules/Help/HelpModule.php',
    $root . '/enclave/help/article/save.php',
    $root . '/enclave/help/article/publish.php',
    $root . '/enclave/help/article/unpublish.php',
    $root . '/enclave/help/index/rebuild.php',
    $root . '/docs/specifications/help_documents.md',
    $root . '/docs/specifications/hermes_help_issue_resolution.md',
];

foreach ( $requiredFiles as $file ) {
    $assert( is_file( $file ), sprintf( 'Required Help implementation file is missing: %s', $file ) );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Help documents contract checks passed.\n" );
