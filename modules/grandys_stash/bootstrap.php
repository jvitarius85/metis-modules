<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

\Metis\Modules\GrandyStash\GrandyStashModule::boot();

function metis_grandys_stash_can_view(): bool { return \Metis\Modules\GrandyStash\GrandyStashModule::canView(); }
function metis_grandys_stash_can_manage(): bool { return \Metis\Modules\GrandyStash\GrandyStashModule::canManage(); }
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
