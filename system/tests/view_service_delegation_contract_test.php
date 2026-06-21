<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$system = dirname( __DIR__ );
$failures = [];
$rawSqlPattern = '/\bSELECT\s+.+\bFROM\b|\bINSERT\s+INTO\b|\bUPDATE\s+\S+\s+SET\b|\bDELETE\s+FROM\b/us';

$resolve_relative = static function ( string $relative ) use ( $system ): string {
    $normalized = ltrim( $relative, '/\\' );

    foreach ( [ 'help', 'people', 'portal', 'profile', 'settings' ] as $slug ) {
        $prefix = 'modules/' . $slug . '/';
        if ( str_starts_with( $normalized, $prefix ) ) {
            return $system . '/src/Metis/Core/BuiltInServices/' . $slug . '/' . substr( $normalized, strlen( $prefix ) );
        }
    }

    return $system . '/' . $normalized;
};

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$read = static function ( string $relative ) use ( $resolve_relative ): string {
    $contents = @file_get_contents( $resolve_relative( $relative ) );
    return is_string( $contents ) ? $contents : '';
};

$viewExpectations = [
    'modules/board/views/meeting.php' => [
        '\Metis\Modules\Board\ReadService::meetingViewContext(',
    ],
    'modules/contacts/views/contact.php' => [
        '\Metis\Modules\Contacts\ContactReadService::getByCid(',
        '\Metis\Modules\Contacts\ContactReadService::donorSummary(',
        '\Metis\Modules\Contacts\ContactReadService::detailRows(',
    ],
    'modules/contacts/views/dashboard.php' => [
        '\Metis\Modules\Contacts\ContactReadService::dashboardRows(',
        '\Metis\Modules\Contacts\ContactReadService::donationTotalsByDid(',
    ],
    'modules/donations/views/batch-detail.php' => [
        '\Metis\Modules\Donations\ReadService::batchDetailSnapshot(',
    ],
    'modules/donations/views/campaign.php' => [
        '\Metis\Modules\Donations\ReadService::campaignDetailSnapshot(',
    ],
    'modules/donations/views/campaigns.php' => [
        '\Metis\Modules\Donations\ReadService::campaignsSnapshot(',
    ],
    'modules/donations/views/dashboard.php' => [
        '\Metis\Modules\Donations\ReadService::dashboardSnapshot(',
    ],
    'modules/donations/views/deposit.php' => [
        '\Metis\Modules\Donations\ReadService::depositSnapshot(',
    ],
    'modules/donations/views/donor.php' => [
        '\Metis\Modules\Donations\ReadService::donorDetailSnapshot(',
    ],
    'modules/donations/views/donors.php' => [
        '\Metis\Modules\Donations\ReadService::donorsSnapshot(',
    ],
    'modules/donations/views/recurring.php' => [
        '\Metis\Modules\Donations\ReadService::recurringSnapshot(',
    ],
    'modules/donations/views/transaction.php' => [
        '\Metis\Modules\Donations\ReadService::transactionDetailSnapshot(',
    ],
    'modules/donations/views/transactions.php' => [
        '\Metis\Modules\Donations\ReadService::transactionsSnapshot(',
    ],
    'modules/newsletter/views/campaigns.php' => [
        '\Metis\Modules\Newsletter\ReadService::legacyCampaignRef(',
        '\Metis\Modules\Newsletter\ReadService::campaignsSnapshot(',
    ],
    'modules/newsletter/views/dashboard.php' => [
        '\Metis\Modules\Newsletter\ReadService::dashboardSnapshot(',
    ],
    'modules/newsletter/views/editor.php' => [
        '\Metis\Modules\Newsletter\ReadService::editorId(',
    ],
    'modules/newsletter/views/lists.php' => [
        '\Metis\Modules\Newsletter\ReadService::listsSnapshot(',
    ],
    'modules/newsletter/views/subscribers.php' => [
        '\Metis\Modules\Newsletter\ReadService::subscribersSnapshot(',
    ],
    'modules/people/views/access_requests.php' => [
        '\Metis\Modules\People\ReadService::accessRequestsSnapshot(',
    ],
    'modules/people/views/bulk_actions.php' => [
        '\Metis\Modules\People\ReadService::bulkActionsSnapshot(',
    ],
    'modules/people/views/dashboard.php' => [
        '\Metis\Modules\People\ReadService::dashboardSnapshot(',
    ],
    'modules/people/views/people_list.php' => [
        '\Metis\Modules\People\ReadService::peopleListSnapshot(',
    ],
    'modules/people/views/permissions.php' => [
        '\Metis\Modules\People\ReadService::permissionsSnapshot(',
    ],
    'modules/people/views/person.php' => [
        '\Metis\Modules\People\ReadService::personSnapshot(',
    ],
    'modules/people/views/positions.php' => [
        '\Metis\Modules\People\ReadService::positionsSnapshot(',
    ],
    'modules/people/views/role.php' => [
        '\Metis\Modules\People\ReadService::roleDetailSnapshot(',
    ],
    'modules/people/views/roles_list.php' => [
        '\Metis\Modules\People\ReadService::rolesListSnapshot(',
    ],
    'modules/people/views/templates.php' => [
        '\Metis\Modules\People\ReadService::templatesSnapshot(',
    ],
    'modules/people/views/workspace.php' => [
        '\Metis\Modules\People\ReadService::workspaceSnapshot(',
    ],
    'modules/portal/views/_dashboard_data.php' => [
        '\Metis\Modules\Portal\PortalDashboardService::donationStats(',
        '\Metis\Modules\Portal\BoardActionService::dashboardCounts(',
    ],
    'modules/portal/views/dashboard.php' => [
        '\Metis\Modules\Portal\PortalDashboardService::newsletterSent30d(',
        '\Metis\Modules\Portal\BoardActionService::fetchForPerson(',
    ],
    'modules/profile/views/dashboard.php' => [
        '\Metis\Modules\People\PersonProfileService::getById(',
        '\Metis\Modules\People\MfaService::activePasskeys(',
    ],
    'modules/settings/views/_settings_bootstrap.php' => [
        '\Metis\Modules\Settings\SettingsTelemetryService::stripeWebhookSnapshot(',
        '\Metis\Modules\Settings\SettingsTelemetryService::codeLookupStatus(',
        '\Metis\Modules\Settings\SettingsTelemetryService::securityPressureSummary(',
    ],
];

foreach ( $viewExpectations as $relative => $needles ) {
    $contents = $read( $relative );
    $assert( $contents !== '', 'Expected hardened view is missing: ' . $relative );
    foreach ( $needles as $needle ) {
        $assert( str_contains( $contents, $needle ), 'Hardened view must delegate through canonical service [' . $needle . ']: ' . $relative );
    }
    $assert( preg_match( $rawSqlPattern, $contents ) !== 1, 'Hardened view must not embed raw SQL: ' . $relative );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "View/service delegation contract checks passed.\n" );
