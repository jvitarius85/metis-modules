<?php
declare(strict_types=1);

define( 'METIS_ROOT', dirname( __DIR__ ) );

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( string $value ): string {
        $value = strtolower( trim( $value ) );
        return preg_replace( '/[^a-z0-9_]+/', '_', $value ) ?? '';
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $value ): string {
        return trim( $value );
    }
}

if ( ! function_exists( 'sanitize_email' ) ) {
    function sanitize_email( string $value ): string {
        return trim( strtolower( $value ) );
    }
}

if ( ! function_exists( 'is_email' ) ) {
    function is_email( string $value ): bool {
        return filter_var( $value, FILTER_VALIDATE_EMAIL ) !== false;
    }
}

if ( ! function_exists( 'metis_json_encode' ) ) {
    function metis_json_encode( mixed $value ): string {
        return json_encode( $value, JSON_UNESCAPED_SLASHES );
    }
}

if ( ! function_exists( 'metis_get_transient' ) ) {
    function metis_get_transient( string $key ): mixed {
        return false;
    }
}

if ( ! function_exists( 'metis_set_transient' ) ) {
    function metis_set_transient( string $key, mixed $value, int $ttl ): bool {
        return true;
    }
}

if ( ! function_exists( 'metis_add_query_arg' ) ) {
    function metis_add_query_arg( array $params, string $url ): string {
        return $url . '?' . http_build_query( $params );
    }
}

if ( ! class_exists( 'Core_Settings_Service' ) ) {
    final class Core_Settings_Service {
        /** @var array<string, mixed> */
        public static array $values = [];

        public static function get( string $key, mixed $default = null ): mixed {
            return self::$values[ $key ] ?? $default;
        }
    }
}

if ( ! function_exists( 'metis_workspace_service_account_payload' ) ) {
    function metis_workspace_service_account_payload(): array {
        return [
            'client_email' => 'svc@example.org',
            'private_key'  => "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----\n",
            'token_uri'    => 'https://oauth2.googleapis.com/token',
        ];
    }
}

if ( ! function_exists( 'metis_workspace_service_account_error' ) ) {
    function metis_workspace_service_account_error( array $service ): string {
        return empty( $service['client_email'] ) ? 'missing client email' : '';
    }
}

if ( ! class_exists( 'Metis_Webhook_Exception' ) ) {
    final class Metis_Webhook_Exception extends RuntimeException {
        public function __construct(
            string $message,
            private readonly int $status = 400,
            private readonly string $code_name = 'webhook_error',
            private readonly array $context = []
        ) {
            parent::__construct( $message );
        }
    }
}

require_once dirname( __DIR__ ) . '/src/Metis/Modules/CommunicationsInbound/ValueObjects/ParseResult.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/CommunicationsInbound/ValueObjects/NormalizedInboundMessage.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/CommunicationsInbound/Contracts/MessageParserInterface.php';
require_once dirname( __DIR__ ) . '/src/Metis/Core/Services/EmailService.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/GrandyStash/ConversationSupport.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/Newsletter/Support.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/CommunicationsInbound/ParserRegistry.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/CommunicationsInbound/ParserEngine.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/CommunicationsInbound/WorkspaceGoogleService.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/CommunicationsInbound/InboundMessageNormalizer.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/CommunicationsInbound/PubSubPushVerifier.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/CommunicationsInbound/GmailClient.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/CommunicationsInbound/InboundMessageRepository.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/CommunicationsInbound/InboundAttachmentRepository.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/CommunicationsInbound/Settings.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/CommunicationsInbound/Parsers/BounceParser.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/CommunicationsInbound/Parsers/UnsubscribeParser.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/CommunicationsInbound/Parsers/GrandyStashParser.php';

use Metis\Modules\CommunicationsInbound\GmailClient;
use Metis\Modules\CommunicationsInbound\InboundMessageNormalizer;
use Metis\Modules\CommunicationsInbound\InboundMessageRepository;
use Metis\Modules\CommunicationsInbound\InboundAttachmentRepository;
use Metis\Modules\CommunicationsInbound\ParserEngine;
use Metis\Modules\CommunicationsInbound\ParserRegistry;
use Metis\Modules\CommunicationsInbound\Parsers\BounceParser;
use Metis\Modules\CommunicationsInbound\Parsers\GrandyStashParser;
use Metis\Modules\CommunicationsInbound\Parsers\UnsubscribeParser;
use Metis\Modules\CommunicationsInbound\PubSubPushVerifier;
use Metis\Modules\CommunicationsInbound\Settings;
use Metis\Modules\CommunicationsInbound\WorkspaceGoogleService;
use Metis\Modules\GrandyStash\ConversationSupport;
use Metis\Core\Services\EmailService;
use Metis\Modules\Newsletter\Support as NewsletterSupport;

function fixture( string $name ): array|string {
    $path = __DIR__ . '/fixtures/inbound_email/' . $name;
    $raw = (string) file_get_contents( $path );
    return str_ends_with( $name, '.json' ) ? json_decode( $raw, true ) ?? $raw : $raw;
}

function assert_true( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function assert_same( mixed $expected, mixed $actual, string $message ): void {
    if ( $expected !== $actual ) {
        throw new RuntimeException( $message . ' Expected ' . var_export( $expected, true ) . ' got ' . var_export( $actual, true ) );
    }
}

$tests = [];

$tests['parser_framework_dispatches_in_priority_order'] = static function (): void {
    $registry = new ParserRegistry();
    $registry->register( new UnsubscribeParser() );
    $registry->register( new GrandyStashParser() );
    $registry->register( new BounceParser() );
    $engine = new ParserEngine( $registry );
    $normalizer = new InboundMessageNormalizer();

    $bounce = $normalizer->normalizeGmailMessage( [ 'mailbox_email' => 'newsletter@example.org' ], (array) fixture( 'bounce.json' ) );
    $result = $engine->evaluate( $bounce )['result'];

    assert_same( 'bounce', $result->classification(), 'Bounce parser should match first for bounce messages.' );
};

$tests['bounce_detection_is_deterministic'] = static function (): void {
    $normalizer = new InboundMessageNormalizer();
    $message = $normalizer->normalizeGmailMessage( [ 'mailbox_email' => 'newsletter@example.org' ], (array) fixture( 'bounce.json' ) );
    $result = ( new BounceParser() )->parse( $message );

    assert_true( $result->matchedMessage(), 'Bounce message should match.' );
    assert_same( 'failed@example.org', $result->metadata()['bounced_recipient'] ?? '', 'Bounce parser should extract the failed recipient.' );
};

$tests['unsubscribe_detection_is_conservative'] = static function (): void {
    $normalizer = new InboundMessageNormalizer();
    $message = $normalizer->normalizeGmailMessage( [ 'mailbox_email' => 'newsletter@example.org' ], (array) fixture( 'unsubscribe.json' ) );
    $result = ( new UnsubscribeParser() )->parse( $message );

    assert_true( $result->matchedMessage(), 'Unsubscribe reply should match.' );
    assert_same( 'unsubscribe', $result->classification(), 'Unsubscribe parser should classify correctly.' );
};

$tests['grandys_stash_routing_uses_ticket_token'] = static function (): void {
    $normalizer = new InboundMessageNormalizer();
    $message = $normalizer->normalizeGmailMessage( [ 'mailbox_email' => 'stash@example.org' ], (array) fixture( 'grandys_stash.json' ) );
    $result = ( new GrandyStashParser() )->parse( $message );

    assert_true( $result->matchedMessage(), 'Grandy’s Stash message should match.' );
    assert_same( 'GST-000123', $result->metadata()['ticket_code'] ?? '', 'Ticket code should be extracted from the subject.' );
};

$tests['grandys_stash_routing_uses_internal_id_footer_when_subject_has_no_ticket_code'] = static function (): void {
    $message = new \Metis\Modules\CommunicationsInbound\ValueObjects\NormalizedInboundMessage(
        [
            'provider'           => 'gmail',
            'provider_mailbox'   => 'stash@example.org',
            'provider_message_id'=> 'msg-body-token-001',
            'provider_thread_id' => 'thread-body-token-001',
            'subject'            => 'Re: Grandy\'s Stash Update',
            'text_body'          => "Thanks for the update.\n\nInternal ID: GST-000123",
            'html_body'          => '',
            'headers'            => [],
            'canonical_sender_email' => 'jane@example.org',
        ]
    );
    $result = ( new GrandyStashParser() )->parse( $message );

    assert_true( $result->matchedMessage(), 'Body token should match when subject is missing the ticket code.' );
    assert_same( 'GST-000123', $result->metadata()['ticket_code'] ?? '', 'Ticket code should be extracted from the Internal ID footer.' );
    assert_same( 'body_token', $result->metadata()['match_via'] ?? '', 'Parser should report the body token match path.' );
};

$tests['grandys_stash_subjects_keep_ticket_token_when_replying'] = static function (): void {
    $subject = ConversationSupport::ensureTicketCodeInSubject( 'Re: Need wheelchair pickup', 'GST-000123' );

    assert_same( 'Re: [GST-000123] Need wheelchair pickup', $subject, 'Reply subjects should preserve the thread token after reply prefixes.' );
};

$tests['conversation_support_extracts_message_ids_from_references_headers'] = static function (): void {
    $tokens = ConversationSupport::extractMessageIdTokens( [ '<one@example.org> <two@example.org>' ] );

    assert_same( [ '<one@example.org>', '<two@example.org>' ], $tokens, 'Reference header parsing should return normalized message ids.' );
};

$tests['conversation_support_trims_quoted_reply_chain_from_display_text'] = static function (): void {
    $raw = "whoa!\n\nOn Tue, Apr 21, 2026 at 10:06 PM Grandys <grandys@mobilizewaco.org> wrote:\n> test\n>";
    $trimmed = ConversationSupport::extractLatestReplyText( $raw );

    assert_same( 'whoa!', $trimmed, 'Quoted reply chains should be removed from the display text.' );
};

$tests['conversation_support_appends_internal_id_footer_to_text'] = static function (): void {
    $body = ConversationSupport::appendInternalIdFooterToText( 'Hello there', 'GST-000123' );

    assert_same( "Hello there\n\nInternal ID: GST-000123", $body, 'Outbound text replies should include the Internal ID footer.' );
};

$tests['email_service_internal_reference_helpers_are_idempotent'] = static function (): void {
    $text = EmailService::appendInternalReferenceToText( 'Hello there', 'form-12-eny123' );
    $text = EmailService::appendInternalReferenceToText( $text, 'FORM-12-ENY123' );
    $html = EmailService::appendInternalReferenceToHtml( '<p>Hello there</p>', 'form-12-eny123' );
    $html = EmailService::appendInternalReferenceToHtml( $html, 'FORM-12-ENY123' );

    assert_same( "Hello there\n\nInternal ID: FORM-12-ENY123", $text, 'Text helpers should normalize references and avoid duplicate footers.' );
    assert_true( substr_count( $html, 'Internal ID: FORM-12-ENY123' ) === 1, 'HTML helpers should normalize references and avoid duplicate footers.' );
};

$tests['newsletter_plain_text_from_html_preserves_block_boundaries'] = static function (): void {
    $html = '<div>JD,</div><p>We received your request.</p><hr><div><strong>Ticket:</strong> GST-000012</div><div><strong>Submitted Information</strong></div><div>First name</div><div>JD</div>';
    $text = NewsletterSupport::plainTextFromHtml( $html );

    assert_true( str_contains( $text, "JD,\n\nWe received your request." ), 'HTML to text conversion should preserve paragraph boundaries.' );
    assert_true( str_contains( $text, "\n\nTicket: GST-000012" ), 'HTML to text conversion should preserve ticket metadata boundaries.' );
    assert_true( ! str_contains( $text, 'Submitted InformationFirst nameJD' ), 'HTML to text conversion should not collapse adjacent block labels into a single run-on string.' );
};

$tests['duplicate_detection_uses_stable_provider_key'] = static function (): void {
    $left = InboundMessageRepository::dedupeKey( 'gmail', 'newsletter@example.org', 'abc123' );
    $right = InboundMessageRepository::dedupeKey( 'gmail', 'newsletter@example.org', 'abc123' );
    $different = InboundMessageRepository::dedupeKey( 'gmail', 'newsletter@example.org', 'xyz999' );

    assert_same( $left, $right, 'Same provider/mailbox/message should hash identically.' );
    assert_true( $left !== $different, 'Different provider message ids should not share a dedupe key.' );
};

$tests['attachment_dedupe_key_uses_stable_attachment_identity'] = static function (): void {
    $left = InboundAttachmentRepository::dedupeKey( 10, 'att-1', '2', 'photo.jpg' );
    $right = InboundAttachmentRepository::dedupeKey( 10, 'att-1', '2', 'PHOTO.jpg' );
    $different = InboundAttachmentRepository::dedupeKey( 10, 'att-2', '2', 'photo.jpg' );

    assert_same( $left, $right, 'Attachment dedupe should be case-insensitive for file names.' );
    assert_true( $left !== $different, 'Different attachment ids should not share a dedupe key.' );
};

$tests['malformed_pubsub_payload_is_rejected'] = static function (): void {
    $verifier = new PubSubPushVerifier();
    $thrown = false;

    try {
        $verifier->decodeNotification( (array) fixture( 'malformed.json' ) );
    } catch ( Throwable ) {
        $thrown = true;
    }

    assert_true( $thrown, 'Malformed Pub/Sub payloads should throw.' );
};

$tests['gmail_normalization_extracts_representative_fields'] = static function (): void {
    $normalizer = new InboundMessageNormalizer();
    $message = $normalizer->normalizeGmailMessage( [ 'mailbox_email' => 'newsletter@example.org' ], (array) fixture( 'normal_reply.json' ) );

    assert_same( 'msg-normal-001', $message->providerMessageId(), 'Provider message id should be captured.' );
    assert_same( 'jane@example.org', $message->senderEmail(), 'Canonical sender email should be derived.' );
    assert_same( 'Re: Spring Update', $message->subject(), 'Subject should be preserved.' );
};

$tests['gmail_normalization_extracts_attachment_metadata'] = static function (): void {
    $normalizer = new InboundMessageNormalizer();
    $message = $normalizer->normalizeGmailMessage(
        [ 'mailbox_email' => 'newsletter@example.org' ],
        [
            'id' => 'msg-attach-001',
            'threadId' => 'thread-attach-001',
            'historyId' => '1001',
            'internalDate' => '1776900000000',
            'payload' => [
                'mimeType' => 'multipart/mixed',
                'headers' => [
                    [ 'name' => 'Subject', 'value' => 'Attachment test' ],
                    [ 'name' => 'From', 'value' => 'Jane <jane@example.org>' ],
                    [ 'name' => 'To', 'value' => 'grandys@example.org' ],
                ],
                'parts' => [
                    [
                        'partId' => '0',
                        'mimeType' => 'text/plain',
                        'filename' => '',
                        'body' => [ 'data' => 'SGVsbG8' ],
                    ],
                    [
                        'partId' => '1',
                        'mimeType' => 'application/pdf',
                        'filename' => 'chair-order.pdf',
                        'body' => [ 'attachmentId' => 'att-123', 'size' => 2048 ],
                    ],
                ],
            ],
        ]
    );

    $attachments = (array) $message->get( 'attachments', [] );
    assert_same( 1, count( $attachments ), 'Attachment metadata should be extracted from Gmail payloads.' );
    assert_same( 'chair-order.pdf', (string) ( $attachments[0]['filename'] ?? '' ), 'Attachment filenames should be preserved.' );
    assert_same( 'att-123', (string) ( $attachments[0]['attachment_id'] ?? '' ), 'Attachment ids should be preserved.' );
};

$tests['mailbox_history_sync_falls_back_when_history_is_invalid'] = static function (): void {
    $fake_google = new class extends WorkspaceGoogleService {
        public function request( string $method, string $url, array $cfg, array|string|null $body = null, array $headers = [] ): array {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Not found' ];
        }
    };

    $client = new class( $fake_google ) extends GmailClient {
        public bool $fallback_called = false;

        public function fullSyncRecentMessages( array $mailbox, bool $recovered_from_history_gap = false ): array {
            $this->fallback_called = true;
            return [
                'ok'                => true,
                'mode'              => 'full_fallback',
                'messages'          => [],
                'latest_history_id' => '9999',
            ];
        }
    };

    $result = $client->collectChangedMessages( [ 'mailbox_email' => 'newsletter@example.org' ], '1234', false );

    assert_true( $client->fallback_called, 'History sync should fall back to a bounded full sync when Gmail returns 404.' );
    assert_same( 'full_fallback', $result['mode'] ?? '', 'Fallback mode should be reported.' );
};

$tests['settings_config_validates_workspace_payload_without_argument_errors'] = static function (): void {
    Core_Settings_Service::$values = [
        'communications_inbound_google_project_id' => 'metis-prod',
        'communications_inbound_pubsub_topic' => 'gmail-inbound',
        'communications_inbound_enable_bounce_handler' => 1,
    ];

    $config = Settings::config();

    assert_same( 'metis-prod', $config['google_project_id'] ?? '', 'Project id should load from settings.' );
    assert_same( true, $config['workspace_service_account_available'] ?? false, 'Workspace service account availability should be computed from the payload helper.' );
};

$tests['workspace_google_request_preserves_token_error_details'] = static function (): void {
    $google = new class extends WorkspaceGoogleService {
        public function accessToken( array $cfg ): array {
            return [ 'ok' => false, 'error' => 'Workspace token request failed (400): unauthorized_client: Client is unauthorized.', 'stage' => 'token_request', 'status' => 400 ];
        }
    };

    $result = $google->request( 'GET', 'https://gmail.googleapis.com/gmail/v1/users/me/profile', [] );

    assert_same( false, $result['ok'] ?? true, 'Request should fail when token acquisition fails.' );
    assert_same( 'Workspace token request failed (400): unauthorized_client: Client is unauthorized.', $result['error'] ?? '', 'Underlying token error should be preserved.' );
    assert_same( 'token_request', $result['stage'] ?? '', 'Failure stage should be surfaced.' );
};

$tests['workspace_google_diagnostic_reports_service_account_validation_errors'] = static function (): void {
    $google = new class extends WorkspaceGoogleService {
        public function configForMailbox( array $mailbox, array $scopes = [] ): array {
            return [
                'service' => [],
                'subject' => 'grandys@example.org',
                'scopes'  => [ 'https://www.googleapis.com/auth/gmail.modify' ],
            ];
        }
    };

    $result = $google->diagnoseMailbox( [
        'mailbox_email'  => 'grandys@example.org',
        'delegated_user' => 'grandys@example.org',
    ] );

    assert_same( false, $result['ok'] ?? true, 'Diagnostic should fail when service account config is incomplete.' );
    assert_same( 'missing client email', $result['error'] ?? '', 'Diagnostic should expose the helper validation message.' );
    assert_same( 'config', $result['stage'] ?? '', 'Diagnostic should identify config-stage failures.' );
};

foreach ( $tests as $name => $test ) {
    $test();
}

fwrite( STDOUT, "Inbound email engine tests passed.\n" );
