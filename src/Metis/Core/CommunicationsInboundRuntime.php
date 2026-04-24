<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! function_exists( 'metis_communications_inbound_runtime_state' ) ) {
    /**
     * @return array{booted: bool, disabled: bool, error: string}
     */
    function &metis_communications_inbound_runtime_state(): array {
        static $state = [
            'booted'   => false,
            'disabled' => false,
            'error'    => '',
        ];

        return $state;
    }
}

if ( ! function_exists( 'metis_communications_inbound_boot_required_for_request' ) ) {
    function metis_communications_inbound_boot_required_for_request(): bool {
        $request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        $request_path = parse_url( $request_uri, PHP_URL_PATH );
        $request_path = is_string( $request_path ) ? trim( $request_path ) : '';

        $webhook_base = \function_exists( 'metis_webhook_base_path' )
            ? trim( (string) \metis_webhook_base_path(), '/' )
            : 'metis-webhooks';

        if ( $request_path !== '' && $webhook_base !== '' ) {
            $webhook_prefix = '/' . $webhook_base . '/gmail_pubsub';
            if (
                $request_path === $webhook_prefix
                || str_starts_with( $request_path, $webhook_prefix . '/' )
            ) {
                return true;
            }
        }

        $script_name = (string) ( $_SERVER['SCRIPT_NAME'] ?? '' );
        $script_filename = (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' );

        foreach ( [ $script_name, $script_filename, (string) ( $_SERVER['argv'][0] ?? '' ) ] as $candidate ) {
            if ( $candidate === '' ) {
                continue;
            }

            if (
                str_contains( $candidate, 'communications_inbound_watch.php' )
                || str_ends_with( $candidate, '/system/cron.php' )
                || $candidate === 'system/cron.php'
            ) {
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'metis_communications_inbound_disable' ) ) {
    function metis_communications_inbound_disable( \Throwable $e ): void {
        $state =& metis_communications_inbound_runtime_state();
        if ( $state['disabled'] ) {
            return;
        }

        $state['disabled'] = true;
        $state['error'] = trim( $e->getMessage() );

        if ( class_exists( 'Metis_Logger', false ) ) {
            \Metis_Logger::warn(
                'Communications inbound runtime disabled after bootstrap failure',
                [
                    'module'  => 'communications_inbound',
                    'service' => 'runtime_bootstrap',
                    'error'   => $state['error'],
                ]
            );
        }
    }
}

if ( ! function_exists( 'metis_communications_inbound_boot' ) ) {
    function metis_communications_inbound_boot(): void {
        $state =& metis_communications_inbound_runtime_state();
        if ( $state['booted'] || $state['disabled'] ) {
            return;
        }

        try {
            \Metis\Modules\CommunicationsInbound\CommunicationsInboundModule::boot();
            $state['booted'] = true;
        } catch ( \Throwable $e ) {
            metis_communications_inbound_disable( $e );
        }
    }
}

if ( ! function_exists( 'metis_communications_inbound_config' ) ) {
    function metis_communications_inbound_config(): array {
        metis_communications_inbound_boot();
        $state =& metis_communications_inbound_runtime_state();
        if ( $state['disabled'] ) {
            return [];
        }

        try {
            return \Metis\Modules\CommunicationsInbound\CommunicationsInboundModule::config();
        } catch ( \Throwable $e ) {
            metis_communications_inbound_disable( $e );
            return [];
        }
    }
}

if ( ! function_exists( 'metis_communications_inbound_mailboxes' ) ) {
    function metis_communications_inbound_mailboxes(): array {
        metis_communications_inbound_boot();
        $state =& metis_communications_inbound_runtime_state();
        if ( $state['disabled'] ) {
            return [];
        }

        try {
            return \Metis\Modules\CommunicationsInbound\CommunicationsInboundModule::mailboxes();
        } catch ( \Throwable $e ) {
            metis_communications_inbound_disable( $e );
            return [];
        }
    }
}

if ( ! function_exists( 'metis_communications_inbound_sync_mailbox' ) ) {
    function metis_communications_inbound_sync_mailbox( string $mailbox_email, array $options = [] ): array {
        metis_communications_inbound_boot();
        $state =& metis_communications_inbound_runtime_state();
        if ( $state['disabled'] ) {
            return [
                'ok'      => false,
                'message' => $state['error'] !== '' ? $state['error'] : 'Communications inbound runtime is unavailable.',
            ];
        }

        try {
            return \Metis\Modules\CommunicationsInbound\CommunicationsInboundModule::syncMailbox( $mailbox_email, $options );
        } catch ( \Throwable $e ) {
            metis_communications_inbound_disable( $e );
            return [
                'ok'      => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}

if ( ! function_exists( 'metis_communications_inbound_watch_mailbox' ) ) {
    function metis_communications_inbound_watch_mailbox( string $mailbox_email, bool $force = false ): array {
        metis_communications_inbound_boot();
        $state =& metis_communications_inbound_runtime_state();
        if ( $state['disabled'] ) {
            return [
                'ok'      => false,
                'message' => $state['error'] !== '' ? $state['error'] : 'Communications inbound runtime is unavailable.',
            ];
        }

        try {
            return \Metis\Modules\CommunicationsInbound\CommunicationsInboundModule::watchMailbox( $mailbox_email, $force );
        } catch ( \Throwable $e ) {
            metis_communications_inbound_disable( $e );
            return [
                'ok'      => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}

if ( ! function_exists( 'metis_communications_inbound_reprocess_message' ) ) {
    function metis_communications_inbound_reprocess_message( int $message_id, bool $force = false ): array {
        metis_communications_inbound_boot();
        $state =& metis_communications_inbound_runtime_state();
        if ( $state['disabled'] ) {
            return [
                'ok'      => false,
                'message' => $state['error'] !== '' ? $state['error'] : 'Communications inbound runtime is unavailable.',
            ];
        }

        try {
            return \Metis\Modules\CommunicationsInbound\CommunicationsInboundModule::reprocessMessage( $message_id, $force );
        } catch ( \Throwable $e ) {
            metis_communications_inbound_disable( $e );
            return [
                'ok'      => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}

if ( ! function_exists( 'metis_communications_inbound_message_attachments' ) ) {
    function metis_communications_inbound_message_attachments( int $message_id ): array {
        metis_communications_inbound_boot();
        $state =& metis_communications_inbound_runtime_state();
        if ( $state['disabled'] ) {
            return [];
        }

        try {
            return \Metis\Modules\CommunicationsInbound\CommunicationsInboundModule::attachmentsForMessage( $message_id );
        } catch ( \Throwable $e ) {
            metis_communications_inbound_disable( $e );
            return [];
        }
    }
}
