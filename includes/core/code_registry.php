<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Metis Universal Object Code Registry
 *
 * Central registry that maps every human-readable prefixed code (e.g. DTX-004238)
 * to the module, entity type, and internal record ID that owns it, enabling
 * system-wide lookup and navigation from a single search.
 *
 * Prefix map (canonical — matches Update_Scope.rtf):
 *   CON  Contact
 *   PPL  Person (People module)
 *   DNR  Donor
 *   DCP  Donation Campaign
 *   DTX  Donation Transaction
 *   DEP  Donation Deposit
 *   DBT  Deposit Batch
 *   NLC  Newsletter Campaign
 *   NLT  Newsletter Template
 *   NLL  Newsletter List
 *   NM   Newsletter Message        (internal, sendable)
 *   NE   Newsletter Event          (internal, tracking)
 *   AUT  Automation
 *   FRM  Forms
 *   MTG  Meetings
 *   CAL  Calendar
 *   SRL  Security Role
 *   PER  Permission
 *   ACT  Activity Log
 *   REP  Report
 *
 * Legacy short prefixes used by metis_generate_code() are mapped as aliases
 * so existing codes resolve correctly without a migration.
 */

class Metis_Code_Registry {

    private static bool $booted        = false;
    private static bool $schema_done   = false;

    // -------------------------------------------------------------------------
    // Prefix → resolution metadata
    // Each entry: [ 'entity_type', 'module' (slug), 'label' ]
    // resolve_url is stored per-row and generated dynamically when registered.
    // -------------------------------------------------------------------------
    private static array $prefix_map = [
        // Canonical prefixes (from scope)
        'CON' => [ 'entity_type' => 'contact',              'module' => 'contacts',    'label' => 'Contact' ],
        'PPL' => [ 'entity_type' => 'person',               'module' => 'people',      'label' => 'Person' ],
        'DNR' => [ 'entity_type' => 'donor',                'module' => 'donations',   'label' => 'Donor' ],
        'DCP' => [ 'entity_type' => 'donation_campaign',    'module' => 'donations',   'label' => 'Donation Campaign' ],
        'DTX' => [ 'entity_type' => 'donation_transaction', 'module' => 'donations',   'label' => 'Donation Transaction' ],
        'DEP' => [ 'entity_type' => 'deposit',              'module' => 'donations',   'label' => 'Deposit' ],
        'DBT' => [ 'entity_type' => 'deposit_batch',        'module' => 'donations',   'label' => 'Deposit Batch' ],
        'NLC' => [ 'entity_type' => 'newsletter_campaign',  'module' => 'newsletter',  'label' => 'Newsletter Campaign' ],
        'NLT' => [ 'entity_type' => 'newsletter_template',  'module' => 'newsletter',  'label' => 'Newsletter Template' ],
        'NLL' => [ 'entity_type' => 'newsletter_list',      'module' => 'newsletter',  'label' => 'Newsletter List' ],
        'NM'  => [ 'entity_type' => 'newsletter_message',   'module' => 'newsletter',  'label' => 'Newsletter Message' ],
        'NE'  => [ 'entity_type' => 'newsletter_event',     'module' => 'newsletter',  'label' => 'Newsletter Event' ],
        'AUT' => [ 'entity_type' => 'automation',           'module' => 'automations', 'label' => 'Automation' ],
        'FRM' => [ 'entity_type' => 'form',                 'module' => 'forms',       'label' => 'Form' ],
        'MTG' => [ 'entity_type' => 'meeting',              'module' => 'board',       'label' => 'Meeting' ],
        'CAL' => [ 'entity_type' => 'calendar_event',       'module' => 'calendar',    'label' => 'Calendar Event' ],
        'SRL' => [ 'entity_type' => 'security_role',        'module' => 'people',      'label' => 'Security Role' ],
        'PER' => [ 'entity_type' => 'permission',           'module' => 'people',      'label' => 'Permission' ],
        'ACT' => [ 'entity_type' => 'activity_log',         'module' => 'people',      'label' => 'Activity Log' ],
        'REP' => [ 'entity_type' => 'report',               'module' => 'donations',   'label' => 'Report' ],
        // Legacy short prefixes used by metis_generate_code() — kept as aliases
        'PE'  => [ 'entity_type' => 'person',               'module' => 'people',      'label' => 'Person' ],
        'CP'  => [ 'entity_type' => 'donation_campaign',    'module' => 'donations',   'label' => 'Donation Campaign' ],
        'TR'  => [ 'entity_type' => 'donation_transaction', 'module' => 'donations',   'label' => 'Donation Transaction' ],
        'DP'  => [ 'entity_type' => 'deposit',              'module' => 'donations',   'label' => 'Deposit' ],
        'BT'  => [ 'entity_type' => 'deposit_batch',        'module' => 'donations',   'label' => 'Deposit Batch' ],
        'CT'  => [ 'entity_type' => 'contact',              'module' => 'contacts',    'label' => 'Contact' ],
        'NL'  => [ 'entity_type' => 'newsletter_campaign',  'module' => 'newsletter',  'label' => 'Newsletter Campaign' ],
        'MW'  => [ 'entity_type' => 'donor',                'module' => 'donations',   'label' => 'Donor' ],
    ];

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public static function init(): void {
        if ( self::$booted ) return;
        self::$booted = true;
        self::ensure_schema();
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    public static function ensure_schema(): void {
        if ( self::$schema_done ) return;
        self::$schema_done = true;

        global $wpdb;
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $table           = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code          VARCHAR(24)     NOT NULL,
            prefix        VARCHAR(6)      NOT NULL,
            entity_type   VARCHAR(64)     NOT NULL,
            module_slug   VARCHAR(64)     NOT NULL,
            internal_id   BIGINT UNSIGNED DEFAULT NULL,
            resolve_url   VARCHAR(512)    DEFAULT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY prefix (prefix),
            KEY entity_type (entity_type),
            KEY module_slug (module_slug),
            KEY internal_id (internal_id)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function table(): string {
        return Metis_Tables::get( 'code_registry' );
    }

    /**
     * Extract the alphabetic prefix from a code string.
     * Supports both dash-separated (DTX-004238) and fused (DTX004238) formats.
     */
    public static function parse_prefix( string $code ): string {
        $code = strtoupper( trim( $code ) );

        // Dash-separated: "DTX-004238" → "DTX"
        if ( strpos( $code, '-' ) !== false ) {
            return explode( '-', $code )[0];
        }

        // Fused: extract leading letters "DTX004238" → "DTX"
        if ( preg_match( '/^([A-Z]+)/', $code, $m ) ) {
            return $m[1];
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Prefix metadata accessors
    // -------------------------------------------------------------------------

    /** Return the human label for a prefix, e.g. "DTX" → "Donation Transaction" */
    public static function prefix_label( string $prefix ): string {
        return self::$prefix_map[ strtoupper( $prefix ) ]['label'] ?? $prefix;
    }

    /** Return the module slug for a prefix */
    public static function prefix_module( string $prefix ): string {
        return self::$prefix_map[ strtoupper( $prefix ) ]['module'] ?? '';
    }

    /** Return the entity type for a prefix */
    public static function prefix_entity_type( string $prefix ): string {
        return self::$prefix_map[ strtoupper( $prefix ) ]['entity_type'] ?? '';
    }

    /** Return the full prefix map (for admin/documentation) */
    public static function get_prefix_map(): array {
        return self::$prefix_map;
    }

    // -------------------------------------------------------------------------
    // Register
    //
    // Call this whenever a new entity record is created and a code is assigned.
    // Idempotent: safe to call multiple times — uses ON DUPLICATE KEY UPDATE.
    //
    // $code         The generated code, e.g. "DTX004238" or "DTX-004238"
    // $internal_id  The primary-key integer of the owning record (0 if unknown)
    // $resolve_url  Optional direct URL to the record's detail page
    // -------------------------------------------------------------------------

    public static function register(
        string $code,
        int    $internal_id = 0,
        string $resolve_url = ''
    ): bool {

        if ( $code === '' ) return false;

        self::init();

        $code        = strtoupper( trim( $code ) );
        $prefix      = self::parse_prefix( $code );
        $entity_type = self::prefix_entity_type( $prefix );
        $module_slug = self::prefix_module( $prefix );

        global $wpdb;
        $table = self::table();

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table}
                    (code, prefix, entity_type, module_slug, internal_id, resolve_url)
                 VALUES (%s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    internal_id = COALESCE(VALUES(internal_id), internal_id),
                    resolve_url = COALESCE(NULLIF(VALUES(resolve_url), ''), resolve_url),
                    entity_type = VALUES(entity_type),
                    module_slug = VALUES(module_slug)",
                $code,
                $prefix,
                $entity_type,
                $module_slug,
                $internal_id > 0 ? $internal_id : null,
                $resolve_url !== '' ? $resolve_url : null
            )
        );

        return $result !== false;
    }

    /**
     * Update the resolve_url for an already-registered code.
     * Useful when the URL can only be determined after the record is inserted
     * and the insert_id is known.
     */
    public static function update_url( string $code, string $resolve_url ): bool {
        if ( $code === '' || $resolve_url === '' ) return false;

        self::init();

        global $wpdb;

        $result = $wpdb->update(
            self::table(),
            [ 'resolve_url' => $resolve_url ],
            [ 'code'        => strtoupper( trim( $code ) ) ],
            [ '%s' ],
            [ '%s' ]
        );

        return $result !== false;
    }

    // -------------------------------------------------------------------------
    // Resolve
    //
    // Look up a code and return full resolution data.
    // Returns null if the code is not in the registry.
    //
    // Return shape:
    //   [
    //     'code'        => 'DTX004238',
    //     'prefix'      => 'DTX',
    //     'label'       => 'Donation Transaction',
    //     'entity_type' => 'donation_transaction',
    //     'module_slug' => 'donations',
    //     'internal_id' => 4238,
    //     'resolve_url' => 'https://…/donations/transaction/?tid=DTX004238',
    //   ]
    // -------------------------------------------------------------------------

    public static function resolve( string $code ): ?array {
        if ( $code === '' ) return null;

        self::init();

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE code = %s LIMIT 1',
                strtoupper( trim( $code ) )
            ),
            ARRAY_A
        );

        if ( ! $row ) return null;

        $prefix = (string) $row['prefix'];

        return [
            'code'        => (string) $row['code'],
            'prefix'      => $prefix,
            'label'       => self::prefix_label( $prefix ),
            'entity_type' => (string) $row['entity_type'],
            'module_slug' => (string) $row['module_slug'],
            'internal_id' => (int)    ( $row['internal_id'] ?? 0 ),
            'resolve_url' => (string) ( $row['resolve_url'] ?? '' ),
        ];
    }

    /**
     * Shorthand: resolve a code and return only its navigation URL.
     * Returns '' if not found or has no URL.
     */
    public static function resolve_url( string $code ): string {
        $r = self::resolve( $code );
        if ( ! $r ) return '';
        if ( $r['resolve_url'] !== '' ) return $r['resolve_url'];

        // Fallback: route to the module's default landing view
        $module = $r['module_slug'];
        if ( $module !== '' && function_exists( 'metis_portal_url' ) ) {
            return metis_portal_url( $module );
        }

        return '';
    }

    /**
     * Return all registered codes. Accepts optional filters.
     * For admin/debug use — not for hot paths.
     */
    public static function all( string $prefix = '', string $module = '' ): array {
        self::init();

        global $wpdb;

        $table  = self::table();
        $where  = [];
        $args   = [];

        if ( $prefix !== '' ) {
            $where[] = 'prefix = %s';
            $args[]  = strtoupper( $prefix );
        }

        if ( $module !== '' ) {
            $where[] = 'module_slug = %s';
            $args[]  = $module;
        }

        $sql = "SELECT * FROM {$table}";

        if ( ! empty( $where ) ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
            return $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY id DESC', ...$args ), ARRAY_A ) ?: [];
        }

        return $wpdb->get_results( $sql . ' ORDER BY id DESC', ARRAY_A ) ?: [];
    }

    /**
     * Remove a registry entry. Use sparingly; tombstone semantics preferred.
     */
    public static function unregister( string $code ): bool {
        if ( $code === '' ) return false;

        self::init();

        global $wpdb;

        return $wpdb->delete(
            self::table(),
            [ 'code' => strtoupper( trim( $code ) ) ],
            [ '%s' ]
        ) !== false;
    }
}

// -------------------------------------------------------------------------
// Boot early in the runtime lifecycle (priority 3 — before modules at 4+)
// -------------------------------------------------------------------------

metis_add_action( 'init', [ 'Metis_Code_Registry', 'init' ], 3 );

// -------------------------------------------------------------------------
// AJAX: metis_resolve_code
//
// POST fields: action=metis_resolve_code, nonce, code
// Returns: { ok:true, url, label, entity_type, module_slug, code }
//       or { ok:false, message }
// -------------------------------------------------------------------------

metis_add_action( 'wp_ajax_metis_resolve_code', function () {

    if ( ! check_ajax_referer( 'metis_core', 'nonce', false ) ) {
        metis_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    if ( ! metis_user_logged_in() ) {
        metis_send_json_error( [ 'message' => 'Authentication required.' ], 401 );
    }

    $code = strtoupper( sanitize_text_field( (string) ( $_POST['code'] ?? '' ) ) );

    if ( $code === '' ) {
        metis_send_json_error( [ 'message' => 'No code provided.' ] );
    }

    $result = Metis_Code_Registry::resolve( $code );

    if ( ! $result ) {
        metis_send_json_error( [
            'message' => 'Code "' . esc_html( $code ) . '" was not found in the registry.',
            'code'    => $code,
        ] );
    }

    $url = $result['resolve_url'];
    if ( $url === '' && $result['module_slug'] !== '' && function_exists( 'metis_portal_url' ) ) {
        $url = metis_portal_url( $result['module_slug'] );
    }

    metis_send_json_success( [
        'code'        => $result['code'],
        'label'       => $result['label'],
        'entity_type' => $result['entity_type'],
        'module_slug' => $result['module_slug'],
        'internal_id' => $result['internal_id'],
        'url'         => $url,
    ] );
} );
