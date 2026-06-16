<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

final class Metis_Tables {
    public static function get( string $table ): string {
        return 'metis_' . $table;
    }
}

final class MetisFakeNewsletterReadDb {
    public array $scalarCalls = [];
    public array $fetchAllCalls = [];
    public array $fetchOneCalls = [];

    public function scalar( string $sql, array $params = [] ): int|string|float|null {
        $this->scalarCalls[] = [ $sql, $params ];

        if ( str_contains( $sql, 'SELECT COUNT(*) FROM metis_newsletter_lists' ) ) {
            return 4;
        }
        if ( str_contains( $sql, 'SELECT COUNT(*) FROM metis_newsletter_campaigns' ) ) {
            return 7;
        }
        if ( str_contains( $sql, 'SELECT COUNT(*) FROM metis_newsletter_subs' ) ) {
            return 120;
        }
        if ( str_contains( $sql, "SELECT COUNT(*) FROM metis_newsletter_messages WHERE status = 'queued'" ) ) {
            return 9;
        }
        if ( str_contains( $sql, "SELECT COUNT(*) FROM metis_newsletter_messages WHERE status = 'sent'" ) ) {
            return 80;
        }
        if ( str_contains( $sql, "status='sent' AND sent_at >=" ) ) {
            return 17;
        }
        if ( str_contains( $sql, 'SELECT id FROM metis_newsletter_templates' ) ) {
            return 33;
        }
        if ( str_contains( $sql, 'SELECT id FROM metis_newsletter_campaigns' ) ) {
            return 44;
        }
        if ( str_contains( $sql, 'SELECT campaign_code FROM metis_newsletter_campaigns' ) ) {
            return 'NC-2026-44';
        }

        return null;
    }

    public function fetchAll( string $sql, array $params = [] ): array {
        $this->fetchAllCalls[] = [ $sql, $params ];

        if ( str_contains( $sql, 'FROM metis_newsletter_lists' ) && str_contains( $sql, 'WHERE is_active = 1 ORDER BY name ASC' ) ) {
            return [
                [ 'id' => 1, 'name' => 'Members', 'description' => 'Primary members', 'is_active' => 1 ],
                [ 'id' => 2, 'name' => 'Volunteers', 'description' => 'Volunteers', 'is_active' => 1 ],
            ];
        }

        if ( str_contains( $sql, 'FROM metis_newsletter_templates' ) && str_contains( $sql, 'WHERE is_active = 1 ORDER BY updated_at DESC' ) ) {
            return [
                [ 'id' => 10, 'template_code' => 'NT-10', 'name' => 'Standard', 'subject' => 'Hello', 'from_name' => 'Metis', 'from_email' => 'hello@example.com', 'reply_to' => 'reply@example.com', 'doc_json' => '{}', 'html_body' => '<p>Hello</p>' ],
            ];
        }

        if ( str_contains( $sql, 'FROM metis_newsletter_campaigns c' ) && str_contains( $sql, 'LIMIT 200' ) ) {
            return [
                [ 'id' => 44, 'campaign_code' => 'NC-2026-44', 'name' => 'Spring Update', 'status' => 'draft', 'template_id' => 10, 'template_name' => 'Standard', 'updated_at' => '2026-05-30 10:00:00' ],
                [ 'id' => 45, 'campaign_code' => 'NC-2026-45', 'name' => 'Archived Update', 'status' => 'archived', 'template_id' => 10, 'template_name' => 'Standard', 'updated_at' => '2026-05-20 10:00:00' ],
            ];
        }

        if ( str_contains( $sql, 'FROM metis_newsletter_campaign_lists cl' ) ) {
            return [
                [ 'campaign_id' => 44, 'list_id' => 1, 'name' => 'Members' ],
                [ 'campaign_id' => 44, 'list_id' => 2, 'name' => 'Volunteers' ],
                [ 'campaign_id' => 45, 'list_id' => 2, 'name' => 'Volunteers' ],
            ];
        }

        if ( str_contains( $sql, 'recent_subscribers' ) ) {
            return [];
        }

        if ( str_contains( $sql, 'GROUP_CONCAT(DISTINCT l.name ORDER BY l.name SEPARATOR') ) {
            return [
                [ 'cid' => 'C-1', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'list_names' => 'Members||Volunteers', 'updated_at' => '2026-05-30 09:00:00' ],
            ];
        }

        if ( str_contains( $sql, 'FROM metis_newsletter_campaigns c' ) && str_contains( $sql, 'LIMIT 7' ) ) {
            return [
                [ 'id' => 44, 'campaign_code' => 'NC-2026-44', 'name' => 'Spring Update', 'status' => 'draft', 'updated_at' => '2026-05-30 10:00:00', 'sent_count' => 0, 'total_recipients' => 100, 'open_count' => 0, 'click_count' => 0 ],
            ];
        }

        return [];
    }

    public function fetchOne( string $sql, array $params = [] ): ?array {
        $this->fetchOneCalls[] = [ $sql, $params ];

        if ( str_contains( $sql, 'WHERE c.campaign_code = %s' ) ) {
            return [
                'id' => 44,
                'campaign_code' => 'NC-2026-44',
                'template_name' => 'Standard',
                'template_code' => 'NT-10',
                'name' => 'Spring Update',
                'status' => 'draft',
            ];
        }

        if ( str_contains( $sql, 'WHERE c.id = %d' ) ) {
            return [
                'id' => 44,
                'campaign_code' => 'NC-2026-44',
                'template_name' => 'Standard',
                'template_code' => 'NT-10',
                'name' => 'Spring Update',
                'status' => 'draft',
            ];
        }

        return null;
    }
}

function metis_db(): MetisFakeNewsletterReadDb {
    static $db = null;
    if ( ! $db instanceof MetisFakeNewsletterReadDb ) {
        $db = new MetisFakeNewsletterReadDb();
    }
    return $db;
}

function metis_newsletter_resolved_timezone(): DateTimeZone {
    return new DateTimeZone( 'UTC' );
}

require_once dirname( __DIR__ ) . '/src/Metis/Modules/Newsletter/TemplateService.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/Newsletter/CampaignService.php';
require_once dirname( __DIR__ ) . '/src/Metis/Modules/Newsletter/ReadService.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$dashboard = \Metis\Modules\Newsletter\ReadService::dashboardSnapshot();
$campaigns = \Metis\Modules\Newsletter\ReadService::campaignsSnapshot( 'active', true, 0, 'NC-2026-44' );
$editorTemplateId = \Metis\Modules\Newsletter\ReadService::editorId( 'NT-10', 'newsletter_template' );
$editorCampaignId = \Metis\Modules\Newsletter\ReadService::editorId( 'NC-2026-44', 'newsletter_campaign' );
$legacyRef = \Metis\Modules\Newsletter\ReadService::legacyCampaignRef( 44 );
$db = metis_db();

$assert( ( $dashboard['kpi_lists'] ?? 0 ) === 4, 'Newsletter dashboard must expose active list count.' );
$assert( ( $dashboard['kpi_campaigns'] ?? 0 ) === 7, 'Newsletter dashboard must expose campaign count.' );
$assert( ( $dashboard['kpi_subscribers'] ?? 0 ) === 120, 'Newsletter dashboard must expose subscriber count.' );
$assert( ( $dashboard['kpi_queued'] ?? 0 ) === 9, 'Newsletter dashboard must expose queued message count.' );
$assert( ( $dashboard['kpi_sent_total'] ?? 0 ) === 80, 'Newsletter dashboard must expose sent total.' );
$assert( ( $dashboard['kpi_30d'] ?? 0 ) === 17, 'Newsletter dashboard must expose 30-day sent count.' );
$assert( count( $dashboard['recent_subscribers'] ?? [] ) === 1, 'Newsletter dashboard must return recent subscribers.' );
$assert( count( $dashboard['recent_campaigns'] ?? [] ) === 1, 'Newsletter dashboard must return recent campaigns.' );

$assert( count( $campaigns['lists'] ?? [] ) === 2, 'Campaign snapshot must return active lists.' );
$assert( count( $campaigns['templates'] ?? [] ) === 1, 'Campaign snapshot must return active templates.' );
$assert( count( $campaigns['campaigns'] ?? [] ) === 2, 'Campaign snapshot must return campaign rows.' );
$assert( count( $campaigns['campaign_lists_map'][44] ?? [] ) === 2, 'Campaign snapshot must map campaign lists by campaign id.' );
$assert( is_array( $campaigns['selected_campaign'] ?? null ) && ( $campaigns['selected_campaign']['campaign_code'] ?? '' ) === 'NC-2026-44', 'Campaign snapshot must load selected campaign by code.' );

$assert( $editorTemplateId === 33, 'Newsletter editorId must delegate template lookup for template context.' );
$assert( $editorCampaignId === 44, 'Newsletter editorId must delegate campaign lookup for campaign context.' );
$assert( $legacyRef === 'NC-2026-44', 'Newsletter legacyCampaignRef must prefer canonical campaign code.' );

$assert( count( $db->scalarCalls ) === 10, 'Newsletter read runtime test must exercise expected scalar calls.' );
$assert( count( $db->fetchAllCalls ) === 6, 'Newsletter read runtime test must exercise expected fetchAll calls.' );
$assert( count( $db->fetchOneCalls ) === 1, 'Newsletter read runtime test must exercise expected fetchOne calls.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Newsletter read service runtime checks passed.\n" );
