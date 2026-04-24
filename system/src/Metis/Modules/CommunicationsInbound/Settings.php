<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

final class Settings {
    /**
     * @return array<string, mixed>
     */
    public static function config(): array {
        $project_id = self::stringSetting( 'communications_inbound_google_project_id' );
        $topic = self::stringSetting( 'communications_inbound_pubsub_topic' );

        return [
            'google_project_id'                     => $project_id,
            'pubsub_topic'                          => $topic,
            'pubsub_topic_name'                     => self::normalizeTopicName( $project_id, $topic ),
            'pubsub_audience'                       => self::stringSetting( 'communications_inbound_pubsub_audience' ),
            'pubsub_service_account_email'          => strtolower( self::stringSetting( 'communications_inbound_pubsub_service_account_email' ) ),
            'mailboxes'                             => self::mailboxes(),
            'log_verbosity'                         => self::normalizeVerbosity( self::stringSetting( 'communications_inbound_log_verbosity', 'standard' ) ),
            'full_sync_days'                        => self::intSetting( 'communications_inbound_full_sync_days', 30, 1, 90 ),
            'allow_reprocess'                       => self::boolSetting( 'communications_inbound_allow_reprocess', false ),
            'enable_bounce_handler'                 => self::boolSetting( 'communications_inbound_enable_bounce_handler', true ),
            'enable_unsubscribe_handler'            => self::boolSetting( 'communications_inbound_enable_unsubscribe_handler', true ),
            'enable_grandys_stash_handler'          => self::boolSetting( 'communications_inbound_enable_grandys_stash_handler', true ),
            'workspace_service_account_available'   => self::workspaceServiceAccountAvailable(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function mailboxes(): array {
        $raw = self::setting( 'communications_inbound_mailboxes', [] );
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            $raw = is_array( $decoded ) ? $decoded : [];
        }

        if ( ! is_array( $raw ) ) {
            $raw = [];
        }

        $rows = [];
        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $email = strtolower( trim( self::cleanEmail( (string) ( $row['mailbox_email'] ?? $row['email'] ?? '' ) ) ) );
            if ( $email === '' || ! self::isValidEmail( $email ) ) {
                continue;
            }

            $delegated_user = strtolower( trim( self::cleanEmail( (string) ( $row['delegated_user'] ?? $row['subject'] ?? $email ) ) ) );
            if ( $delegated_user === '' || ! self::isValidEmail( $delegated_user ) ) {
                $delegated_user = $email;
            }

            $label_ids = $row['label_ids'] ?? [];
            if ( is_string( $label_ids ) ) {
                $label_ids = preg_split( '/[\s,]+/', $label_ids ) ?: [];
            }
            if ( ! is_array( $label_ids ) ) {
                $label_ids = [];
            }

            $normalized_label_ids = [];
            foreach ( $label_ids as $label_id ) {
                $label_id = trim( (string) $label_id );
                if ( $label_id !== '' && ! in_array( $label_id, $normalized_label_ids, true ) ) {
                    $normalized_label_ids[] = $label_id;
                }
            }

            $mailbox_key = self::cleanKey( (string) ( $row['mailbox_key'] ?? '' ) );
            if ( $mailbox_key === '' ) {
                $mailbox_key = self::cleanKey( str_replace( [ '@', '.' ], '_', $email ) );
            }

            $label_filter_behavior = self::cleanKey( (string) ( $row['label_filter_behavior'] ?? '' ) );
            if ( ! in_array( $label_filter_behavior, [ 'include', 'exclude' ], true ) ) {
                $label_filter_behavior = '';
            }

            $rows[] = [
                'mailbox_key'           => $mailbox_key,
                'provider'              => 'gmail',
                'mailbox_email'         => $email,
                'display_name'          => trim( self::cleanText( (string) ( $row['display_name'] ?? '' ) ) ),
                'delegated_user'        => $delegated_user,
                'enabled'               => ! isset( $row['enabled'] ) || (int) $row['enabled'] === 1 || $row['enabled'] === true,
                'label_ids'             => $normalized_label_ids,
                'label_filter_behavior' => $label_filter_behavior,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function mailboxByEmail( string $email ): ?array {
        $email = strtolower( trim( self::cleanEmail( $email ) ) );
        foreach ( self::mailboxes() as $row ) {
            if ( (string) ( $row['mailbox_email'] ?? '' ) === $email ) {
                return $row;
            }
        }

        return null;
    }

    private static function normalizeTopicName( string $project_id, string $topic ): string {
        $project_id = trim( $project_id );
        $topic = trim( $topic );

        if ( $topic === '' ) {
            return '';
        }

        if ( str_contains( $topic, '/topics/' ) ) {
            return $topic;
        }

        if ( $project_id === '' ) {
            return '';
        }

        return 'projects/' . $project_id . '/topics/' . $topic;
    }

    private static function normalizeVerbosity( string $value ): string {
        $value = self::cleanKey( $value );
        if ( ! in_array( $value, [ 'quiet', 'standard', 'verbose' ], true ) ) {
            return 'standard';
        }

        return $value;
    }

    private static function stringSetting( string $key, string $default = '' ): string {
        $value = self::setting( $key, $default );
        if ( ! is_scalar( $value ) && $value !== null ) {
            return $default;
        }

        $normalized = self::cleanText( (string) $value );

        return $normalized !== '' ? $normalized : $default;
    }

    private static function intSetting( string $key, int $default, int $min, int $max ): int {
        $value = self::setting( $key, $default );
        if ( ! is_scalar( $value ) || $value === '' ) {
            return $default;
        }

        return max( $min, min( $max, (int) $value ) );
    }

    private static function boolSetting( string $key, bool $default = false ): bool {
        $value = self::setting( $key, $default ? 1 : 0 );

        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_int( $value ) || is_float( $value ) ) {
            return (int) $value === 1;
        }

        if ( is_string( $value ) ) {
            $normalized = strtolower( trim( $value ) );
            if ( in_array( $normalized, [ '1', 'true', 'yes', 'on' ], true ) ) {
                return true;
            }
            if ( in_array( $normalized, [ '0', 'false', 'no', 'off', '' ], true ) ) {
                return false;
            }
        }

        return $default;
    }

    private static function setting( string $key, mixed $default = null ): mixed {
        if ( \class_exists( '\Core_Settings_Service', false ) ) {
            return \Core_Settings_Service::get( $key, $default );
        }

        if (
            \class_exists( '\Metis\Core\Application', false )
            && \Metis\Core\Application::has_service( 'settings' )
        ) {
            try {
                return \Metis\Core\Application::service( 'settings' )->get( $key, $default );
            } catch ( \Throwable ) {
                return $default;
            }
        }

        return $default;
    }

    private static function cleanEmail( string $value ): string {
        if ( \function_exists( 'metis_email_clean' ) ) {
            return (string) \metis_email_clean( $value );
        }

        return (string) filter_var( $value, FILTER_SANITIZE_EMAIL );
    }

    private static function isValidEmail( string $value ): bool {
        if ( \function_exists( 'metis_email_is_valid' ) ) {
            return (bool) \metis_email_is_valid( $value );
        }

        return filter_var( $value, FILTER_VALIDATE_EMAIL ) !== false;
    }

    private static function cleanKey( string $value ): string {
        if ( \function_exists( 'metis_key_clean' ) ) {
            return (string) \metis_key_clean( $value );
        }

        $value = strtolower( trim( $value ) );
        return preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '';
    }

    private static function cleanText( string $value ): string {
        if ( \function_exists( 'metis_text_clean' ) ) {
            return (string) \metis_text_clean( $value );
        }

        return trim( $value );
    }

    private static function workspaceServiceAccountAvailable(): bool {
        try {
            if ( ! \function_exists( 'metis_workspace_service_account_payload' ) ) {
                return false;
            }

            $payload = \metis_workspace_service_account_payload();
            if ( ! is_array( $payload ) || empty( $payload ) ) {
                return false;
            }

            foreach ( [ 'client_email', 'private_key', 'token_uri' ] as $key ) {
                if ( empty( $payload[ $key ] ) ) {
                    return false;
                }
            }

            $client_email = trim( (string) $payload['client_email'] );
            if ( ! self::isValidEmail( $client_email ) ) {
                return false;
            }

            $private_key = trim( (string) $payload['private_key'] );
            if ( ! str_contains( $private_key, '-----BEGIN PRIVATE KEY-----' ) || ! str_contains( $private_key, '-----END PRIVATE KEY-----' ) ) {
                return false;
            }

            return true;
        } catch ( \Throwable $e ) {
            return false;
        }
    }
}
