<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

require_once $root . '/src/Metis/Hermes/HermesMemoryStore.php';
require_once $root . '/src/Metis/Hermes/HermesCommandRegistry.php';
require_once $root . '/src/Metis/Hermes/HermesIntentRegistry.php';
require_once $root . '/src/Metis/Hermes/HermesAttributeRegistry.php';
require_once $root . '/src/Metis/Hermes/EntityRegistryBuilder.php';
require_once $root . '/src/Metis/Hermes/HermesIntentParser.php';
require_once $root . '/src/Metis/Hermes/Nlu/LanguagePackLoader.php';
require_once $root . '/src/Metis/Hermes/Nlu/NaturalLanguageNormalizer.php';
require_once $root . '/src/Metis/Hermes/Nlu/AmountParser.php';
require_once $root . '/src/Metis/Hermes/Nlu/DateParser.php';
require_once $root . '/src/Metis/Hermes/Nlu/EntityExtractor.php';
require_once $root . '/src/Metis/Hermes/Nlu/PhraseMatcher.php';
require_once $root . '/src/Metis/Hermes/Nlu/CommandValidator.php';
require_once $root . '/src/Metis/Hermes/Nlu/IntentMatcher.php';
require_once $root . '/src/Metis/Hermes/Nlu/ClarificationManager.php';
require_once $root . '/src/Metis/Hermes/Nlu/ContextStore.php';
require_once $root . '/src/Metis/Hermes/Nlu/NaturalLanguageProcessor.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$packs = new \Metis\Hermes\Nlu\LanguagePackLoader( $root . '/config/hermes/nlu/language-packs' );
$memoryReflection = new ReflectionClass( \Metis\Hermes\HermesMemoryStore::class );
$memory = $memoryReflection->newInstanceWithoutConstructor();
$nlu = new \Metis\Hermes\Nlu\NaturalLanguageProcessor(
    new \Metis\Hermes\Nlu\NaturalLanguageNormalizer( $packs ),
    new \Metis\Hermes\Nlu\IntentMatcher(
        $packs,
        new \Metis\Hermes\Nlu\PhraseMatcher( $packs ),
        new \Metis\Hermes\Nlu\EntityExtractor(
            $packs,
            new \Metis\Hermes\Nlu\AmountParser( $packs ),
            new \Metis\Hermes\Nlu\DateParser( $packs )
        ),
        new \Metis\Hermes\Nlu\CommandValidator()
    ),
    new \Metis\Hermes\Nlu\ClarificationManager(),
    $memory
);

$normalized = $nlu->normalizeInput( 'Please lukup doner over 1k last month' );
$assert( $normalized === 'lookup donor over 1k last month', 'Normalizer should correct misspellings and strip filler phrases.' );

$amountParser = new \Metis\Hermes\Nlu\AmountParser( $packs );
$range = $amountParser->parseAll( 'between one hundred and five hundred' );
$assert( (float) ( $range[0]['min'] ?? 0 ) === 100.0, 'Amount parser should normalize word-number minimums.' );
$assert( (float) ( $range[0]['max'] ?? 0 ) === 500.0, 'Amount parser should normalize word-number maximums.' );

$dateParser = new \Metis\Hermes\Nlu\DateParser( $packs );
$pastThirty = $dateParser->parse( 'past 30 days' );
$assert( (string) ( $pastThirty['preset'] ?? '' ) === 'past_30_days', 'Date parser should normalize relative rolling-day ranges.' );

$commands = new \Metis\Hermes\HermesCommandRegistry();
$intentRegistry = new \Metis\Hermes\HermesIntentRegistry();
$attributeRegistry = new \Metis\Hermes\HermesAttributeRegistry();
$entityRegistry = new \Metis\Hermes\EntityRegistryBuilder( $root . '/modules' );
$parser = new \Metis\Hermes\HermesIntentParser(
    $commands,
    $entityRegistry,
    $intentRegistry,
    $attributeRegistry,
    $nlu
);

$whoGave = $parser->parse( 'who gave last month' );
$assert( (string) ( $whoGave['type'] ?? '' ) === 'data', '"who gave last month" should route through the data parser.' );
$assert( (string) ( $whoGave['entity'] ?? '' ) === 'donor', '"who gave last month" should normalize to donor data.' );
$assert( (string) ( $whoGave['action'] ?? '' ) === 'list', '"who gave last month" should resolve to a bounded list/search intent.' );
$assert( (string) ( $whoGave['date_range']['preset'] ?? '' ) === 'last_month', '"who gave last month" should preserve the last-month date range.' );

$whoMadeDonations = $parser->parse( 'who made donations over $100?' );
$assert( (string) ( $whoMadeDonations['type'] ?? '' ) === 'data', '"who made donations over $100" should route through the data parser.' );
$assert( (string) ( $whoMadeDonations['entity'] ?? '' ) === 'donor', '"who made donations over $100" should normalize to donor data.' );
$assert( (string) ( $whoMadeDonations['action'] ?? '' ) === 'list', '"who made donations over $100" should resolve to a list intent.' );
$assert( empty( $whoMadeDonations['alternative_intents'] ) || is_array( $whoMadeDonations['alternative_intents'] ), '"who made donations over $100" should provide structured alternatives when needed.' );

$history = $parser->parse( "what is JD's donation history?" );
$assert( (string) ( $history['type'] ?? '' ) === 'data', '"what is JD\'s donation history?" should route through the data parser.' );
$assert( (string) ( $history['entity'] ?? '' ) === 'donation_transaction', '"what is JD\'s donation history?" should resolve to donation transactions.' );
$assert( (string) ( $history['payload']['subject'] ?? '' ) === 'JD', '"what is JD\'s donation history?" should preserve the subject before execution.' );

$needsDate = $parser->parse( 'show donors over 1k' );
$assert( ! empty( $needsDate['requires_clarification'] ), 'Amount-scoped donor queries without a date should request clarification.' );
$assert( str_contains( strtolower( (string) ( $needsDate['clarification_prompt'] ?? '' ) ), 'date range' ), 'Clarification should ask for a date range.' );

$megPhone = $parser->parse( "What is Meg's phone number?" );
$assert( (string) ( $megPhone['action'] ?? '' ) === 'get_entity_attribute', '"What is Meg\'s phone number?" should route to entity attribute lookup.' );
$assert( (string) ( $megPhone['payload']['attribute_request']['subject'] ?? '' ) === 'Meg', '"What is Meg\'s phone number?" should preserve the requested subject.' );
$assert( (string) ( $megPhone['payload']['attribute_request']['attribute'] ?? '' ) === 'phone', '"What is Meg\'s phone number?" should preserve the requested attribute.' );
$assert( (string) ( $megPhone['payload']['attribute_request']['entity_hint'] ?? '' ) === 'contact', '"What is Meg\'s phone number?" should prefer contact resolution for phone lookups.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes NLU runtime checks passed.\n" );
