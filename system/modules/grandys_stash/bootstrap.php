<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

\Metis\Modules\GrandyStash\GrandyStashModule::boot();

function metis_grandys_stash_can_view(): bool { return \Metis\Modules\GrandyStash\GrandyStashModule::canView(); }
function metis_grandys_stash_can( string $action ): bool {
    $action = metis_key_clean( $action );
    if ( $action === '' ) {
        return false;
    }

    if ( function_exists( 'metis_security_user_can' ) ) {
        return metis_security_user_can( 'grandys_stash.' . $action );
    }

    return $action === 'view' ? metis_grandys_stash_can_view() : metis_grandys_stash_can_manage();
}
function metis_grandys_stash_can_manage(): bool { return \Metis\Modules\GrandyStash\GrandyStashModule::canManage(); }
function metis_grandys_stash_can_create(): bool { return metis_grandys_stash_can( 'create' ); }
function metis_grandys_stash_can_assign(): bool { return metis_grandys_stash_can( 'assign' ); }
function metis_grandys_stash_can_comment(): bool { return metis_grandys_stash_can( 'comment' ); }
function metis_grandys_stash_can_reply(): bool { return metis_grandys_stash_can( 'reply' ); }
function metis_grandys_stash_can_inventory(): bool { return metis_grandys_stash_can( 'inventory' ); }
function metis_grandys_stash_can_settings(): bool { return metis_grandys_stash_can( 'settings' ); }
function metis_grandys_stash_can_export(): bool { return metis_grandys_stash_can( 'export' ); }
function metis_grandys_stash_can_delete(): bool { return metis_grandys_stash_can( 'delete' ); }
function metis_grandys_stash_base_url(): string { return \Metis\Modules\GrandyStash\GrandyStashModule::baseUrl(); }
function metis_grandys_stash_view_url( string $ticket_code = '' ): string { return \Metis\Modules\GrandyStash\GrandyStashModule::viewUrl( $ticket_code ); }
function metis_grandys_stash_handle_view_route( Metis_Http_Request $request ): Metis_Http_Response { return \Metis\Modules\GrandyStash\GrandyStashModule::handleViewRoute( $request ); }

// Register daily summary cron task
if ( class_exists( 'Metis_Cron_Manager' ) ) {
    \Metis_Cron_Manager::register_task(
        'grandys_stash_daily_summary',
        static function (): array {
            return \Metis\Modules\GrandyStash\GrandyStashDailySummary::send();
        },
        [
            'label'    => "Grandy's Stash Daily Summary",
            'interval' => 86400,
            'lock_ttl' => 300,
            'module'   => 'grandys_stash',
        ]
    );
}
