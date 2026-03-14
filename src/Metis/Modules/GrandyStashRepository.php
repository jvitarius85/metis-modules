<?php
declare(strict_types=1);

namespace Metis\Modules;

use Metis\Modules\Forms\Repository as FormsRepository;

final class GrandyStashRepository {
    private const REQUEST_FORM_SLUG = 'grandys-stash-supplies-request';
    private const DONATION_FORM_SLUG = 'grandys-stash-donation-offer';
    private const REQUEST_ASSIGNEE_SETTING = 'grandys_stash_default_request_assignee_user_id';
    private const DONATION_ASSIGNEE_SETTING = 'grandys_stash_default_donation_assignee_user_id';

    public static function ensureModuleReady(): void {
        GrandyStashSchemaManager::ensureSchema();
        \Metis\Modules\Contacts\ContactsModule::ensureSchema();
        \Metis\Modules\Forms\FormsModule::ensureSchema();
        self::ensureCatalogSeeded();
        self::ensureProgramForms();
        self::syncCasesFromForms();
    }

    public static function dashboardData(): array {
        self::ensureModuleReady();

        return [
            'stats' => self::stats(),
            'catalog' => self::catalogSummary(),
            'assignees' => self::assigneeOptions(),
            'routing_defaults' => self::routingDefaults(),
            'inbox' => self::buildInbox(),
            'items' => self::listItems(),
            'cases' => self::listCases(),
            'distributions' => self::listDistributions(),
        ];
    }

    public static function saveRoutingDefaults( array $payload ): array {
        self::ensureModuleReady();

        $request_assignee = self::normalizeAssigneeUserId( $payload['request_assignee_user_id'] ?? 0 );
        $donation_assignee = self::normalizeAssigneeUserId( $payload['donation_assignee_user_id'] ?? 0 );

        \Core_Settings_Service::set( self::REQUEST_ASSIGNEE_SETTING, $request_assignee, true );
        \Core_Settings_Service::set( self::DONATION_ASSIGNEE_SETTING, $donation_assignee, true );

        return [
            'ok' => true,
            'routing_defaults' => self::routingDefaults(),
        ];
    }

    public static function saveItem( array $payload ): array {
        self::ensureModuleReady();
        global $wpdb;

        $table = \Metis_Tables::get( 'grandys_stash_items' );
        $id = isset( $payload['id'] ) ? (int) $payload['id'] : 0;
        $existing = $id > 0 ? self::getItem( $id ) : null;

        $name = trim( \sanitize_text_field( (string) ( $payload['name'] ?? '' ) ) );
        if ( $name === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Equipment name is required.' ];
        }

        $category = self::normalizeCategory( (string) ( $payload['category'] ?? '' ) );
        $catalog_match = self::findCatalogItemByNameAndCategory( $name, $category );
        if ( $catalog_match ) {
            $name = (string) ( $catalog_match['item_name'] ?? $name );
            $category = (string) ( $catalog_match['category_slug'] ?? $category );
        }
        if ( ! $catalog_match ) {
            self::upsertCatalogEntry( $name, $category );
        }

        $row = [
            'name' => $name,
            'category' => $category,
            'condition_status' => self::normalizeCondition( (string) ( $payload['condition_status'] ?? '' ) ),
            'status' => self::normalizeItemStatus( (string) ( $payload['status'] ?? '' ) ),
            'storage_location' => self::nullableText( $payload['storage_location'] ?? '' ),
            'serial_number' => self::nullableText( $payload['serial_number'] ?? '' ),
            'donor_contact_cid' => self::nullableText( $payload['donor_contact_cid'] ?? '' ),
            'notes' => self::nullableTextArea( $payload['notes'] ?? '' ),
        ];

        if ( $existing ) {
            $wpdb->update( $table, $row, [ 'id' => $id ] );
        } else {
            $row['equipment_code'] = self::generateCode( 'GS', $table, 'equipment_code' );
            $row['source_case_id'] = ! empty( $payload['source_case_id'] ) ? (int) $payload['source_case_id'] : null;
            $wpdb->insert( $table, $row );
            $id = (int) $wpdb->insert_id;
        }

        return [ 'ok' => true, 'item' => self::getItem( $id ) ];
    }

    public static function saveCase( array $payload ): array {
        self::ensureModuleReady();
        global $wpdb;

        $table = \Metis_Tables::get( 'grandys_stash_cases' );
        $id = isset( $payload['id'] ) ? (int) $payload['id'] : 0;
        $existing = $id > 0 ? self::getCase( $id ) : null;

        if ( ! $existing ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Case not found.' ];
        }

        $contact_cid = self::upsertContactFromPayload( (array) ( $payload['contact'] ?? [] ), (string) ( $payload['contact_cid'] ?? $existing['contact_cid'] ?? '' ) );

        $row = [
            'status' => self::normalizeCaseStatus( (string) ( $payload['status'] ?? $existing['status'] ?? 'new' ) ),
            'contact_cid' => $contact_cid !== '' ? $contact_cid : null,
            'assignee_user_id' => self::normalizeAssigneeUserId( $payload['assignee_user_id'] ?? $existing['assignee_user_id'] ?? 0 ),
            'assignee_name' => self::resolveAssigneeName( self::normalizeAssigneeUserId( $payload['assignee_user_id'] ?? $existing['assignee_user_id'] ?? 0 ) ),
            'urgency' => self::normalizeUrgency( (string) ( $payload['urgency'] ?? $existing['urgency'] ?? 'standard' ) ),
            'pickup_delivery' => self::normalizePickupDelivery( (string) ( $payload['pickup_delivery'] ?? $existing['pickup_delivery'] ?? '' ) ),
            'notes' => self::nullableTextArea( $payload['notes'] ?? $existing['notes'] ?? '' ),
            'internal_notes' => self::nullableTextArea( $payload['internal_notes'] ?? $existing['internal_notes'] ?? '' ),
            'scheduled_for' => self::normalizeDatetime( (string) ( $payload['scheduled_for'] ?? $existing['scheduled_for'] ?? '' ) ),
        ];

        $wpdb->update( $table, $row, [ 'id' => $id ] );

        return [ 'ok' => true, 'case' => self::getCase( $id ) ];
    }

    public static function assignItem( array $payload ): array {
        self::ensureModuleReady();
        global $wpdb;

        $item_id = (int) ( $payload['item_id'] ?? 0 );
        $case_id = (int) ( $payload['case_id'] ?? 0 );
        $item = self::getItem( $item_id );
        $case = self::getCase( $case_id );

        if ( ! $item || ! $case ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Item or case not found.' ];
        }

        $recipient_cid = self::upsertContactFromPayload( (array) ( $payload['contact'] ?? [] ), (string) ( $case['contact_cid'] ?? '' ) );
        if ( $recipient_cid === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'A recipient contact is required before assignment.' ];
        }

        $table = \Metis_Tables::get( 'grandys_stash_distributions' );
        $distribution_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE item_id = %d AND case_id = %d LIMIT 1",
                $item_id,
                $case_id
            )
        );

        $row = [
            'item_id' => $item_id,
            'case_id' => $case_id,
            'recipient_cid' => $recipient_cid,
            'status' => self::normalizeDistributionStatus( (string) ( $payload['status'] ?? 'assigned' ) ),
            'fulfillment_method' => self::normalizePickupDelivery( (string) ( $payload['fulfillment_method'] ?? $case['pickup_delivery'] ?? '' ) ),
            'scheduled_for' => self::normalizeDatetime( (string) ( $payload['scheduled_for'] ?? $case['scheduled_for'] ?? '' ) ),
            'completed_at' => ! empty( $payload['completed_at'] ) ? self::normalizeDatetime( (string) $payload['completed_at'] ) : null,
            'notes' => self::nullableTextArea( $payload['notes'] ?? '' ),
        ];

        if ( $distribution_id > 0 ) {
            $wpdb->update( $table, $row, [ 'id' => $distribution_id ] );
        } else {
            $row['distribution_code'] = self::generateCode( 'GD', $table, 'distribution_code' );
            $wpdb->insert( $table, $row );
        }

        $wpdb->update( \Metis_Tables::get( 'grandys_stash_items' ), [ 'status' => 'assigned' ], [ 'id' => $item_id ] );
        $wpdb->update(
            \Metis_Tables::get( 'grandys_stash_cases' ),
            [
                'status' => in_array( $row['status'], [ 'completed', 'fulfilled' ], true ) ? 'fulfilled' : 'ready',
                'contact_cid' => $recipient_cid,
                'pickup_delivery' => $row['fulfillment_method'],
                'scheduled_for' => $row['scheduled_for'],
            ],
            [ 'id' => $case_id ]
        );

        return [ 'ok' => true, 'distributions' => self::listDistributions() ];
    }

    public static function searchContacts( string $query ): array {
        self::ensureModuleReady();
        global $wpdb;

        $contacts_table = \Metis_Tables::get( 'contacts' );
        $details_table = \Metis_Tables::get( 'contact_details' );
        $like = '%' . $wpdb->esc_like( trim( $query ) ) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.cid, c.first_name, c.last_name, c.email, MAX(d.phone) AS phone
                 FROM {$contacts_table} c
                 LEFT JOIN {$details_table} d ON d.contact_cid = c.cid OR d.contact_id = c.id
                 WHERE c.first_name LIKE %s
                    OR c.last_name LIKE %s
                    OR c.email LIKE %s
                 GROUP BY c.id
                 ORDER BY c.last_name ASC, c.first_name ASC
                 LIMIT 12",
                $like,
                $like,
                $like
            ),
            ARRAY_A
        ) ?: [];

        return array_map( static function ( array $row ): array {
            return [
                'cid' => (string) ( $row['cid'] ?? '' ),
                'name' => trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) ),
                'email' => (string) ( $row['email'] ?? '' ),
                'phone' => (string) ( $row['phone'] ?? '' ),
            ];
        }, $rows );
    }

    public static function catalogSummary(): array {
        return [
            'categories' => self::catalogCategories(),
            'items' => self::catalogItems(),
        ];
    }

    private static function stats(): array {
        global $wpdb;

        $items_table = \Metis_Tables::get( 'grandys_stash_items' );
        $cases_table = \Metis_Tables::get( 'grandys_stash_cases' );
        $distributions_table = \Metis_Tables::get( 'grandys_stash_distributions' );

        $item_row = $wpdb->get_row(
            "SELECT COUNT(*) AS total_items,
                    SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_items,
                    SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) AS assigned_items,
                    SUM(CASE WHEN status = 'intake_review' THEN 1 ELSE 0 END) AS intake_items
             FROM {$items_table}",
            ARRAY_A
        ) ?: [];
        $case_row = $wpdb->get_row(
            "SELECT COUNT(*) AS total_cases,
                    SUM(CASE WHEN status IN ('new', 'review') THEN 1 ELSE 0 END) AS open_cases,
                    SUM(CASE WHEN intake_type = 'request' THEN 1 ELSE 0 END) AS request_cases,
                    SUM(CASE WHEN intake_type = 'donation' THEN 1 ELSE 0 END) AS donation_cases
             FROM {$cases_table}",
            ARRAY_A
        ) ?: [];
        $distribution_row = $wpdb->get_row(
            "SELECT COUNT(*) AS active_distributions
             FROM {$distributions_table}
             WHERE status IN ('assigned', 'scheduled', 'completed')",
            ARRAY_A
        ) ?: [];
        $catalog_row = $wpdb->get_row(
            "SELECT COUNT(*) AS catalog_items, COUNT(DISTINCT category_slug) AS catalog_categories
             FROM " . \Metis_Tables::get( 'grandys_stash_catalog' ) . "
             WHERE is_active = 1",
            ARRAY_A
        ) ?: [];

        return [
            'total_items' => (int) ( $item_row['total_items'] ?? 0 ),
            'available_items' => (int) ( $item_row['available_items'] ?? 0 ),
            'assigned_items' => (int) ( $item_row['assigned_items'] ?? 0 ),
            'intake_items' => (int) ( $item_row['intake_items'] ?? 0 ),
            'total_cases' => (int) ( $case_row['total_cases'] ?? 0 ),
            'open_cases' => (int) ( $case_row['open_cases'] ?? 0 ),
            'request_cases' => (int) ( $case_row['request_cases'] ?? 0 ),
            'donation_cases' => (int) ( $case_row['donation_cases'] ?? 0 ),
            'active_distributions' => (int) ( $distribution_row['active_distributions'] ?? 0 ),
            'catalog_items' => (int) ( $catalog_row['catalog_items'] ?? 0 ),
            'catalog_categories' => (int) ( $catalog_row['catalog_categories'] ?? 0 ),
        ];
    }

    private static function listItems(): array {
        global $wpdb;

        $table = \Metis_Tables::get( 'grandys_stash_items' );
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $rows = $wpdb->get_results(
            "SELECT i.*,
                    c.first_name AS donor_first_name,
                    c.last_name AS donor_last_name
             FROM {$table} i
             LEFT JOIN {$contacts_table} c ON c.cid = i.donor_contact_cid
             ORDER BY FIELD(i.status, 'intake_review', 'available', 'assigned', 'maintenance'), i.updated_at DESC, i.id DESC
             LIMIT 200",
            ARRAY_A
        ) ?: [];

        return array_map( static function ( array $row ): array {
            $row['donor_name'] = trim( (string) ( $row['donor_first_name'] ?? '' ) . ' ' . (string) ( $row['donor_last_name'] ?? '' ) );
            return $row;
        }, $rows );
    }

    private static function listCases(): array {
        global $wpdb;

        $table = \Metis_Tables::get( 'grandys_stash_cases' );
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $details_table = \Metis_Tables::get( 'contact_details' );
        $submissions_table = \Metis_Tables::get( 'form_submissions' );
        $rows = $wpdb->get_results(
            "SELECT c.*,
                    ct.first_name,
                    ct.last_name,
                    ct.email,
                    MAX(cd.phone) AS phone,
                    s.submission_key,
                    s.submission_status,
                    s.created_at AS submission_created_at,
                    s.payload_json,
                    s.normalized_json
             FROM {$table} c
             LEFT JOIN {$contacts_table} ct ON ct.cid = c.contact_cid
             LEFT JOIN {$details_table} cd ON cd.contact_cid = c.contact_cid
             LEFT JOIN {$submissions_table} s ON s.id = c.form_submission_id
             GROUP BY c.id
             ORDER BY FIELD(c.status, 'new', 'review', 'ready', 'fulfilled', 'closed'), c.updated_at DESC, c.id DESC
             LIMIT 200",
            ARRAY_A
        ) ?: [];

        return array_map( static function ( array $row ): array {
            $row['contact_name'] = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
            $row['requested_categories'] = self::decodeJsonList( $row['requested_categories_json'] ?? null );
            $row['requested_items'] = self::decodeJsonList( $row['requested_items_json'] ?? null );
            $row['offered_items'] = self::decodeJsonList( $row['offered_items_json'] ?? null );
            $row['submission_payload'] = self::decodeAssoc( $row['normalized_json'] ?? null );
            if ( $row['submission_payload'] === [] ) {
                $row['submission_payload'] = self::decodeAssoc( $row['payload_json'] ?? null );
            }
            $row['summary'] = $row['intake_type'] === 'donation'
                ? implode( ', ', array_slice( $row['offered_items'], 0, 3 ) )
                : implode( ', ', array_slice( array_merge( $row['requested_categories'], $row['requested_items'] ), 0, 3 ) );
            $row['submission_preview'] = self::submissionPreview( $row );
            return $row;
        }, $rows );
    }

    private static function listDistributions(): array {
        global $wpdb;

        $table = \Metis_Tables::get( 'grandys_stash_distributions' );
        $items_table = \Metis_Tables::get( 'grandys_stash_items' );
        $cases_table = \Metis_Tables::get( 'grandys_stash_cases' );
        $contacts_table = \Metis_Tables::get( 'contacts' );

        return $wpdb->get_results(
            "SELECT d.*,
                    i.name AS item_name,
                    i.equipment_code,
                    c.case_code,
                    ct.first_name,
                    ct.last_name
             FROM {$table} d
             INNER JOIN {$items_table} i ON i.id = d.item_id
             LEFT JOIN {$cases_table} c ON c.id = d.case_id
             LEFT JOIN {$contacts_table} ct ON ct.cid = d.recipient_cid
             ORDER BY d.updated_at DESC, d.id DESC
             LIMIT 100",
            ARRAY_A
        ) ?: [];
    }

    private static function recentSubmissions(): array {
        global $wpdb;

        $submissions_table = \Metis_Tables::get( 'form_submissions' );
        $forms_table = \Metis_Tables::get( 'forms' );
        $cases_table = \Metis_Tables::get( 'grandys_stash_cases' );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.id,
                        s.form_id,
                        s.submission_key,
                        s.submission_status,
                        s.created_at,
                        s.payload_json,
                        s.normalized_json,
                        c.id AS case_id,
                        c.case_code,
                        c.status AS case_status,
                        c.contact_cid,
                        c.assignee_user_id,
                        c.assignee_name,
                        c.urgency,
                        c.pickup_delivery,
                        c.notes,
                        c.internal_notes,
                        c.scheduled_for,
                        f.name AS form_name,
                        f.slug AS form_slug
                 FROM {$submissions_table} s
                 INNER JOIN {$forms_table} f ON f.id = s.form_id
                 LEFT JOIN {$cases_table} c ON c.form_submission_id = s.id
                 WHERE f.slug IN (%s, %s)
                 ORDER BY s.created_at DESC, s.id DESC
                 LIMIT 50",
                self::REQUEST_FORM_SLUG,
                self::DONATION_FORM_SLUG
            ),
            ARRAY_A
        ) ?: [];

        return array_map( static function ( array $row ): array {
            $payload = self::decodeAssoc( $row['normalized_json'] ?? null );
            if ( $payload === [] ) {
                $payload = self::decodeAssoc( $row['payload_json'] ?? null );
            }

            return [
                'id' => (int) ( $row['id'] ?? 0 ),
                'form_id' => (int) ( $row['form_id'] ?? 0 ),
                'form_name' => (string) ( $row['form_name'] ?? '' ),
                'form_slug' => (string) ( $row['form_slug'] ?? '' ),
                'case_id' => (int) ( $row['case_id'] ?? 0 ),
                'case_code' => (string) ( $row['case_code'] ?? '' ),
                'case_status' => (string) ( $row['case_status'] ?? '' ),
                'contact_cid' => (string) ( $row['contact_cid'] ?? '' ),
                'assignee_user_id' => (int) ( $row['assignee_user_id'] ?? 0 ),
                'assignee_name' => (string) ( $row['assignee_name'] ?? '' ),
                'urgency' => (string) ( $row['urgency'] ?? '' ),
                'pickup_delivery' => (string) ( $row['pickup_delivery'] ?? '' ),
                'notes' => (string) ( $row['notes'] ?? '' ),
                'internal_notes' => (string) ( $row['internal_notes'] ?? '' ),
                'scheduled_for' => (string) ( $row['scheduled_for'] ?? '' ),
                'submission_key' => (string) ( $row['submission_key'] ?? '' ),
                'submission_status' => (string) ( $row['submission_status'] ?? '' ),
                'created_at' => (string) ( $row['created_at'] ?? '' ),
                'payload' => $payload,
                'preview' => self::submissionPreviewFromPayload( $payload ),
            ];
        }, $rows );
    }

    private static function buildInbox(): array {
        $cases = self::listCases();
        $submissions = self::recentSubmissions();
        $submissions_by_id = [];

        foreach ( $submissions as $submission ) {
            $submission_id = (int) ( $submission['id'] ?? 0 );
            if ( $submission_id > 0 ) {
                $submissions_by_id[ $submission_id ] = $submission;
            }
        }

        $inbox = array_map( static function ( array $case ) use ( $submissions_by_id ): array {
            $submission = null;
            $submission_id = (int) ( $case['form_submission_id'] ?? 0 );
            if ( $submission_id > 0 && isset( $submissions_by_id[ $submission_id ] ) ) {
                $submission = $submissions_by_id[ $submission_id ];
            }

            return [
                'id' => (int) ( $case['id'] ?? 0 ),
                'form_submission_id' => $submission_id,
                'case_code' => (string) ( $case['case_code'] ?? '' ),
                'submission_key' => (string) ( $case['submission_key'] ?? $submission['submission_key'] ?? '' ),
                'submission_created_at' => (string) ( $case['submission_created_at'] ?? $submission['created_at'] ?? '' ),
                'status' => (string) ( $case['status'] ?? 'new' ),
                'intake_type' => (string) ( $case['intake_type'] ?? 'request' ),
                'contact_name' => (string) ( $case['contact_name'] ?? trim( (string) ( $submission['payload']['first_name'] ?? '' ) . ' ' . (string) ( $submission['payload']['last_name'] ?? '' ) ) ),
                'first_name' => (string) ( $case['first_name'] ?? $submission['payload']['first_name'] ?? '' ),
                'last_name' => (string) ( $case['last_name'] ?? $submission['payload']['last_name'] ?? '' ),
                'email' => (string) ( $case['email'] ?? $submission['payload']['email'] ?? '' ),
                'phone' => (string) ( $case['phone'] ?? $submission['payload']['phone'] ?? '' ),
                'assignee_user_id' => (int) ( $case['assignee_user_id'] ?? 0 ),
                'assignee_name' => (string) ( $case['assignee_name'] ?? '' ),
                'urgency' => (string) ( $case['urgency'] ?? $submission['urgency'] ?? $submission['payload']['urgency'] ?? 'standard' ),
                'pickup_delivery' => (string) ( $case['pickup_delivery'] ?? $submission['pickup_delivery'] ?? $submission['payload']['pickup_delivery'] ?? '' ),
                'notes' => (string) ( $case['notes'] ?? $submission['notes'] ?? $submission['payload']['notes'] ?? '' ),
                'internal_notes' => (string) ( $case['internal_notes'] ?? $submission['internal_notes'] ?? '' ),
                'scheduled_for' => (string) ( $case['scheduled_for'] ?? $submission['scheduled_for'] ?? '' ),
                'summary' => (string) ( $case['summary'] ?? '' ),
                'submission_preview' => (array) ( $case['submission_preview'] ?? $submission['preview'] ?? [] ),
            ];
        }, $cases );

        foreach ( $submissions as $submission ) {
            if ( (int) ( $submission['case_id'] ?? 0 ) > 0 ) {
                continue;
            }

            $inbox[] = [
                'id' => 0,
                'form_submission_id' => (int) ( $submission['id'] ?? 0 ),
                'case_code' => (string) ( $submission['submission_key'] ?? '' ),
                'submission_key' => (string) ( $submission['submission_key'] ?? '' ),
                'submission_created_at' => (string) ( $submission['created_at'] ?? '' ),
                'status' => 'new',
                'intake_type' => str_contains( (string) ( $submission['form_slug'] ?? '' ), 'donation' ) ? 'donation' : 'request',
                'contact_name' => trim( (string) ( $submission['payload']['first_name'] ?? '' ) . ' ' . (string) ( $submission['payload']['last_name'] ?? '' ) ),
                'first_name' => (string) ( $submission['payload']['first_name'] ?? '' ),
                'last_name' => (string) ( $submission['payload']['last_name'] ?? '' ),
                'email' => (string) ( $submission['payload']['email'] ?? '' ),
                'phone' => (string) ( $submission['payload']['phone'] ?? '' ),
                'assignee_user_id' => (int) ( $submission['assignee_user_id'] ?? 0 ),
                'assignee_name' => (string) ( $submission['assignee_name'] ?? '' ),
                'urgency' => (string) ( $submission['urgency'] ?? $submission['payload']['urgency'] ?? 'standard' ),
                'pickup_delivery' => (string) ( $submission['pickup_delivery'] ?? $submission['payload']['pickup_delivery'] ?? '' ),
                'notes' => (string) ( $submission['notes'] ?? $submission['payload']['notes'] ?? '' ),
                'internal_notes' => (string) ( $submission['internal_notes'] ?? '' ),
                'scheduled_for' => (string) ( $submission['scheduled_for'] ?? '' ),
                'summary' => '',
                'submission_preview' => (array) ( $submission['preview'] ?? [] ),
            ];
        }

        return $inbox;
    }

    private static function getItem( int $id ): ?array {
        foreach ( self::listItems() as $item ) {
            if ( (int) ( $item['id'] ?? 0 ) === $id ) {
                return $item;
            }
        }
        return null;
    }

    private static function getCase( int $id ): ?array {
        foreach ( self::listCases() as $case ) {
            if ( (int) ( $case['id'] ?? 0 ) === $id ) {
                return $case;
            }
        }
        return null;
    }

    private static function ensureProgramForms(): void {
        self::saveManagedForm(
            self::REQUEST_FORM_SLUG,
            'Supplies Request',
            'Request durable medical equipment through Grandy\'s Stash.',
            [
                self::field( 'text', 'first_name', 'First name', true, 'half' ),
                self::field( 'text', 'last_name', 'Last name', true, 'half' ),
                self::field( 'email', 'email', 'Email', true, 'half' ),
                self::field( 'text', 'phone', 'Phone', false, 'half', [ 'format' => 'phone_us' ] ),
                self::field( 'select', 'requested_category', 'Requested category', false, 'half', [ 'options' => self::categoryOptions() ] ),
                self::field( 'select', 'requested_catalog_item', 'Requested item', false, 'half', [ 'options' => self::itemOptions() ] ),
                self::field( 'textarea', 'requested_items', 'Specific equipment details', false, 'full', [ 'help' => 'Use this for size, quantity, or anything not listed in the catalog.' ] ),
                self::field( 'radio', 'urgency', 'Urgency', true, 'full', [ 'options' => [
                    [ 'label' => 'Urgent', 'value' => 'urgent' ],
                    [ 'label' => 'Within two weeks', 'value' => 'standard' ],
                    [ 'label' => 'Flexible timing', 'value' => 'flexible' ],
                ] ] ),
                self::field( 'select', 'pickup_delivery', 'Preferred coordination', false, 'half', [ 'options' => [
                    [ 'label' => 'Pick up', 'value' => 'pickup' ],
                    [ 'label' => 'Delivery', 'value' => 'delivery' ],
                    [ 'label' => 'Need to discuss', 'value' => 'discuss' ],
                ] ] ),
                self::field( 'textarea', 'notes', 'Anything else staff should know?', false, 'full' ),
            ]
        );

        self::saveManagedForm(
            self::DONATION_FORM_SLUG,
            'Donation Offer',
            'Offer durable medical equipment for Grandy\'s Stash review.',
            [
                self::field( 'text', 'first_name', 'First name', true, 'half' ),
                self::field( 'text', 'last_name', 'Last name', true, 'half' ),
                self::field( 'email', 'email', 'Email', true, 'half' ),
                self::field( 'text', 'phone', 'Phone', false, 'half', [ 'format' => 'phone_us' ] ),
                self::field( 'select', 'offered_category', 'Equipment category', false, 'half', [ 'options' => self::categoryOptions() ] ),
                self::field( 'select', 'offered_catalog_item', 'Equipment item', false, 'half', [ 'options' => self::itemOptions() ] ),
                self::field( 'textarea', 'offered_items', 'Describe the equipment', true, 'full', [ 'help' => 'Add model, size, quantity, or anything not covered by the catalog.' ] ),
                self::field( 'select', 'condition_status', 'Condition', true, 'half', [ 'options' => [
                    [ 'label' => 'Excellent', 'value' => 'excellent' ],
                    [ 'label' => 'Good', 'value' => 'good' ],
                    [ 'label' => 'Fair', 'value' => 'fair' ],
                    [ 'label' => 'Needs repair', 'value' => 'repair' ],
                ] ] ),
                self::field( 'select', 'pickup_delivery', 'Coordination preference', false, 'half', [ 'options' => [
                    [ 'label' => 'Drop off', 'value' => 'dropoff' ],
                    [ 'label' => 'Pick up from donor', 'value' => 'pickup' ],
                    [ 'label' => 'Need to discuss', 'value' => 'discuss' ],
                ] ] ),
                self::field( 'textarea', 'notes', 'Additional notes', false, 'full' ),
            ]
        );
    }

    private static function saveManagedForm( string $slug, string $name, string $description, array $schema ): void {
        $existing = FormsRepository::getFormBySlug( $slug );
        $normalized_schema = FormsRepository::normalizeSchema( $schema );
        $normalized_settings = FormsRepository::normalizeSettings( [
            'confirmation' => [
                'message' => 'Thanks. Grandy\'s Stash received your information and will follow up after review.',
                'redirect_url' => '',
            ],
            'notifications' => [
                'submitter' => [
                    'enabled' => true,
                    'subject' => 'Grandy\'s Stash received your submission',
                    'message' => 'Thank you. Our team will review your submission and follow up soon.',
                    'recipient_field' => 'email',
                    'emails' => [],
                    'rules' => [],
                ],
                'receiver' => [ 'enabled' => false, 'subject' => '', 'message' => '', 'recipient_field' => '', 'emails' => [], 'rules' => [] ],
            ],
            'payments' => [ 'enabled' => false ],
            'design' => [
                'accent_color' => '#24506e',
                'button_bg' => '#24506e',
                'button_text' => '#ffffff',
                'field_radius' => '14',
                'surface_style' => 'soft',
            ],
        ] );

        if ( $existing
            && (string) ( $existing['name'] ?? '' ) === $name
            && (string) ( $existing['description'] ?? '' ) === $description
            && (string) ( $existing['status'] ?? '' ) === 'published'
            && (array) ( $existing['schema'] ?? [] ) === $normalized_schema
            && (array) ( $existing['settings'] ?? [] ) === $normalized_settings
        ) {
            return;
        }

        $payload = [
            'id' => (int) ( $existing['id'] ?? 0 ),
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'status' => 'published',
            'schema' => $normalized_schema,
            'settings' => $normalized_settings,
            'version_notes' => 'Managed by Grandy\'s Stash',
        ];

        $result = FormsRepository::saveForm( $payload, (int) \get_current_user_id() );
        if ( ! empty( $result['ok'] ) && ! empty( $result['form']['id'] ) ) {
            FormsRepository::publishForm( (int) $result['form']['id'] );
        }
    }

    private static function syncCasesFromForms(): void {
        global $wpdb;

        $cases_table = \Metis_Tables::get( 'grandys_stash_cases' );
        $submissions_table = \Metis_Tables::get( 'form_submissions' );

        foreach ( [
            'request' => self::REQUEST_FORM_SLUG,
            'donation' => self::DONATION_FORM_SLUG,
        ] as $intake_type => $slug ) {
            $form = FormsRepository::getFormBySlug( $slug );
            if ( ! $form ) {
                continue;
            }

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT s.*
                     FROM {$submissions_table} s
                     LEFT JOIN {$cases_table} c ON c.form_submission_id = s.id
                     WHERE s.form_id = %d
                       AND c.id IS NULL
                     ORDER BY s.created_at ASC, s.id ASC",
                    (int) $form['id']
                ),
                ARRAY_A
            ) ?: [];

            foreach ( $rows as $row ) {
                $normalized = self::decodeAssoc( $row['normalized_json'] ?? null );
                $payload = $normalized !== [] ? $normalized : self::decodeAssoc( $row['payload_json'] ?? null );
                $contact_cid = self::upsertContactFromPayload( [
                    'first_name' => (string) ( $payload['first_name'] ?? '' ),
                    'last_name' => (string) ( $payload['last_name'] ?? '' ),
                    'email' => (string) ( $payload['email'] ?? '' ),
                    'phone' => (string) ( $payload['phone'] ?? '' ),
                ], '' );

                $wpdb->insert(
                    $cases_table,
                    [
                        'case_code' => self::generateCode( 'GC', $cases_table, 'case_code' ),
                        'intake_type' => $intake_type,
                        'status' => 'new',
                        'contact_cid' => $contact_cid !== '' ? $contact_cid : null,
                        'assignee_user_id' => self::defaultAssigneeUserId( $intake_type ),
                        'assignee_name' => self::resolveAssigneeName( self::defaultAssigneeUserId( $intake_type ) ),
                        'urgency' => self::normalizeUrgency( (string) ( $payload['urgency'] ?? 'standard' ) ),
                        'pickup_delivery' => self::normalizePickupDelivery( (string) ( $payload['pickup_delivery'] ?? '' ) ),
                        'requested_categories_json' => $intake_type === 'request' ? \metis_json_encode( self::normalizeRequestedCategories( $payload ) ) : null,
                        'requested_items_json' => $intake_type === 'request' ? \metis_json_encode( self::normalizeRequestedItemsFromPayload( $payload ) ) : null,
                        'offered_items_json' => $intake_type === 'donation' ? \metis_json_encode( self::normalizeOfferedItems( $payload ) ) : null,
                        'notes' => self::nullableTextArea( $payload['notes'] ?? '' ),
                        'form_id' => (int) $form['id'],
                        'form_submission_id' => (int) $row['id'],
                    ]
                );
            }
        }
    }

    private static function upsertContactFromPayload( array $contact, string $fallback_cid ): string {
        global $wpdb;

        $cid = trim( $fallback_cid );
        $first_name = trim( \sanitize_text_field( (string) ( $contact['first_name'] ?? '' ) ) );
        $last_name = trim( \sanitize_text_field( (string) ( $contact['last_name'] ?? '' ) ) );
        $email = strtolower( trim( \sanitize_email( (string) ( $contact['email'] ?? '' ) ) ) );
        $phone = trim( \sanitize_text_field( (string) ( $contact['phone'] ?? '' ) ) );

        $contacts_table = \Metis_Tables::get( 'contacts' );
        $details_table = \Metis_Tables::get( 'contact_details' );

        $contact_id = 0;
        if ( $cid !== '' ) {
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, cid FROM {$contacts_table} WHERE cid = %s LIMIT 1", $cid ) );
        } elseif ( $email !== '' ) {
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, cid FROM {$contacts_table} WHERE email = %s LIMIT 1", $email ) );
        } else {
            $existing = null;
        }

        if ( $existing ) {
            $contact_id = (int) $existing->id;
            $cid = (string) $existing->cid;
            $update = [];
            if ( $first_name !== '' ) {
                $update['first_name'] = $first_name;
            }
            if ( $last_name !== '' ) {
                $update['last_name'] = $last_name;
            }
            if ( $email !== '' ) {
                $update['email'] = $email;
            }
            if ( $update !== [] ) {
                $wpdb->update( $contacts_table, $update, [ 'id' => $contact_id ] );
            }
        } elseif ( $first_name !== '' || $last_name !== '' || $email !== '' ) {
            $cid = self::generateCode( 'CN', $contacts_table, 'cid' );
            $wpdb->insert( $contacts_table, [
                'cid' => $cid,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
            ] );
            $contact_id = (int) $wpdb->insert_id;
        }

        if ( $contact_id < 1 || $cid === '' ) {
            return '';
        }

        $detail_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$details_table} WHERE contact_cid = %s OR contact_id = %d LIMIT 1",
                $cid,
                $contact_id
            )
        );
        $detail_row = [
            'contact_cid' => $cid,
            'contact_id' => $contact_id,
            'phone' => $phone !== '' ? $phone : null,
        ];
        if ( $detail_id > 0 ) {
            $wpdb->update( $details_table, $detail_row, [ 'id' => $detail_id ] );
        } else {
            $wpdb->insert( $details_table, $detail_row );
        }

        return $cid;
    }

    private static function field( string $type, string $key, string $label, bool $required, string $width, array $extra = [] ): array {
        return array_merge(
            [
                'id' => 'stash_' . $key,
                'type' => $type,
                'key' => $key,
                'label' => $label,
                'required' => $required,
                'width' => $width,
                'help' => '',
                'placeholder' => '',
            ],
            $extra
        );
    }

    private static function categoryOptions(): array {
        $options = [];
        foreach ( self::catalogCategories() as $category ) {
            $options[] = [
                'label' => (string) ( $category['category_name'] ?? '' ),
                'value' => (string) ( $category['category_slug'] ?? '' ),
            ];
        }

        return $options;
    }

    private static function itemOptions(): array {
        $options = [];
        foreach ( self::catalogItems() as $item ) {
            $options[] = [
                'label' => (string) ( $item['item_name'] ?? '' ) . ' (' . (string) ( $item['category_name'] ?? '' ) . ')',
                'value' => (string) ( $item['item_slug'] ?? '' ),
            ];
        }

        return $options;
    }

    private static function normalizeRequestedItems( mixed $value ): array {
        if ( is_array( $value ) ) {
            return self::normalizeStringList( $value );
        }
        return self::normalizeStringList( preg_split( '/[\r\n,]+/', (string) $value ) ?: [] );
    }

    private static function normalizeRequestedCategories( array $payload ): array {
        return self::normalizeStringList( array_filter( [
            $payload['requested_category'] ?? '',
            ...( is_array( $payload['requested_categories'] ?? null ) ? (array) $payload['requested_categories'] : [] ),
        ] ) );
    }

    private static function normalizeRequestedItemsFromPayload( array $payload ): array {
        return array_values( array_unique( array_merge(
            self::normalizeStringList( [ self::catalogLabelBySlug( (string) ( $payload['requested_catalog_item'] ?? '' ) ) ] ),
            self::normalizeRequestedItems( $payload['requested_items'] ?? '' )
        ) ) );
    }

    private static function normalizeOfferedItems( array $payload ): array {
        return array_values( array_unique( array_merge(
            self::normalizeStringList( [
                self::catalogCategoryLabelBySlug( (string) ( $payload['offered_category'] ?? '' ) ),
                ...( is_array( $payload['offered_categories'] ?? null ) ? (array) $payload['offered_categories'] : [] ),
            ] ),
            self::normalizeStringList( [ self::catalogLabelBySlug( (string) ( $payload['offered_catalog_item'] ?? '' ) ) ] ),
            self::normalizeRequestedItems( $payload['offered_items'] ?? '' )
        ) ) );
    }

    private static function normalizeStringList( mixed $values ): array {
        $values = is_array( $values ) ? $values : [ $values ];
        $normalized = [];
        foreach ( $values as $value ) {
            $value = trim( \sanitize_text_field( (string) $value ) );
            if ( $value !== '' ) {
                $normalized[] = $value;
            }
        }
        return array_values( array_unique( $normalized ) );
    }

    private static function normalizeCategory( string $value ): string {
        $value = \sanitize_key( $value );
        if ( $value === '' ) {
            return 'other';
        }
        foreach ( self::catalogCategories() as $category ) {
            if ( (string) ( $category['category_slug'] ?? '' ) === $value ) {
                return $value;
            }
        }
        return 'other';
    }

    private static function normalizeCondition( string $value ): string {
        $value = \sanitize_key( $value );
        return in_array( $value, [ 'excellent', 'good', 'fair', 'repair' ], true ) ? $value : 'good';
    }

    private static function normalizeItemStatus( string $value ): string {
        $value = \sanitize_key( $value );
        return in_array( $value, [ 'available', 'assigned', 'maintenance', 'intake_review' ], true ) ? $value : 'available';
    }

    private static function normalizeCaseStatus( string $value ): string {
        $value = \sanitize_key( $value );
        return in_array( $value, [ 'new', 'review', 'ready', 'fulfilled', 'closed' ], true ) ? $value : 'new';
    }

    private static function normalizeDistributionStatus( string $value ): string {
        $value = \sanitize_key( $value );
        return in_array( $value, [ 'assigned', 'scheduled', 'completed', 'fulfilled' ], true ) ? $value : 'assigned';
    }

    private static function normalizeUrgency( string $value ): string {
        $value = \sanitize_key( $value );
        return in_array( $value, [ 'urgent', 'standard', 'flexible' ], true ) ? $value : 'standard';
    }

    private static function normalizePickupDelivery( string $value ): string {
        $value = \sanitize_key( $value );
        return in_array( $value, [ 'pickup', 'delivery', 'dropoff', 'discuss' ], true ) ? $value : '';
    }

    private static function normalizeDatetime( string $value ): ?string {
        $value = trim( $value );
        if ( $value === '' ) {
            return null;
        }
        $timestamp = strtotime( $value );
        return $timestamp ? \gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
    }

    private static function nullableText( mixed $value ): ?string {
        $value = trim( \sanitize_text_field( (string) $value ) );
        return $value !== '' ? $value : null;
    }

    private static function nullableTextArea( mixed $value ): ?string {
        $value = trim( \sanitize_textarea_field( (string) $value ) );
        return $value !== '' ? $value : null;
    }

    private static function decodeAssoc( mixed $json ): array {
        if ( ! is_string( $json ) || $json === '' ) {
            return [];
        }
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private static function decodeJsonList( mixed $json ): array {
        if ( ! is_string( $json ) || $json === '' ) {
            return [];
        }
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? self::normalizeStringList( $decoded ) : [];
    }

    private static function submissionPreview( array $row ): array {
        $payload = is_array( $row['submission_payload'] ?? null ) ? (array) $row['submission_payload'] : [];
        return self::submissionPreviewFromPayload( $payload, $row );
    }

    private static function submissionPreviewFromPayload( array $payload, array $row = [] ): array {
        $preview = [];
        $submitted_by = trim(
            (string) ( $payload['first_name'] ?? $row['first_name'] ?? '' ) . ' ' .
            (string) ( $payload['last_name'] ?? $row['last_name'] ?? '' )
        );

        if ( $submitted_by !== '' ) {
            $preview['Submitted by'] = $submitted_by;
        } elseif ( ! empty( $row['contact_name'] ) ) {
            $preview['Submitted by'] = (string) $row['contact_name'];
        }

        if ( ! empty( $payload['email'] ) ) {
            $preview['Email'] = (string) $payload['email'];
        } elseif ( ! empty( $row['email'] ) ) {
            $preview['Email'] = (string) $row['email'];
        }

        if ( ! empty( $payload['phone'] ) ) {
            $preview['Phone'] = (string) $payload['phone'];
        } elseif ( ! empty( $row['phone'] ) ) {
            $preview['Phone'] = (string) $row['phone'];
        }

        if ( ! empty( $payload['requested_category'] ) ) {
            $preview['Category'] = self::catalogCategoryLabelBySlug( (string) $payload['requested_category'] ) ?: (string) $payload['requested_category'];
        } elseif ( ! empty( $payload['offered_category'] ) ) {
            $preview['Category'] = self::catalogCategoryLabelBySlug( (string) $payload['offered_category'] ) ?: (string) $payload['offered_category'];
        } elseif ( ! empty( $row['requested_categories'] ) ) {
            $preview['Category'] = implode( ', ', array_slice( (array) $row['requested_categories'], 0, 2 ) );
        }

        if ( ! empty( $payload['requested_catalog_item'] ) ) {
            $preview['Item'] = self::catalogLabelBySlug( (string) $payload['requested_catalog_item'] );
        } elseif ( ! empty( $payload['offered_catalog_item'] ) ) {
            $preview['Item'] = self::catalogLabelBySlug( (string) $payload['offered_catalog_item'] );
        } elseif ( ! empty( $row['requested_items'] ) ) {
            $preview['Item'] = implode( ', ', array_slice( (array) $row['requested_items'], 0, 2 ) );
        } elseif ( ! empty( $row['offered_items'] ) ) {
            $preview['Item'] = implode( ', ', array_slice( (array) $row['offered_items'], 0, 2 ) );
        }

        $details = self::normalizeRequestedItems( $payload['requested_items'] ?? '' );
        if ( $details !== [] ) {
            $preview['Details'] = implode( ', ', array_slice( $details, 0, 3 ) );
        } else {
            $offered = self::normalizeRequestedItems( $payload['offered_items'] ?? '' );
            if ( $offered !== [] ) {
                $preview['Details'] = implode( ', ', array_slice( $offered, 0, 3 ) );
            }
        }

        if ( ! empty( $payload['urgency'] ) ) {
            $preview['Urgency'] = (string) $payload['urgency'];
        } elseif ( ! empty( $row['urgency'] ) ) {
            $preview['Urgency'] = (string) $row['urgency'];
        }

        if ( ! empty( $payload['pickup_delivery'] ) ) {
            $preview['Coordination'] = (string) $payload['pickup_delivery'];
        } elseif ( ! empty( $row['pickup_delivery'] ) ) {
            $preview['Coordination'] = (string) $row['pickup_delivery'];
        }

        if ( ! empty( $payload['notes'] ) ) {
            $preview['Notes'] = (string) $payload['notes'];
        } elseif ( ! empty( $row['notes'] ) ) {
            $preview['Notes'] = (string) $row['notes'];
        }

        return $preview;
    }

    private static function generateCode( string $prefix, string $table, string $column ): string {
        return \metis_generate_code( $prefix, $table, $column, 16 );
    }

    private static function routingDefaults(): array {
        $request_assignee = self::defaultAssigneeUserId( 'request' );
        $donation_assignee = self::defaultAssigneeUserId( 'donation' );

        return [
            'request_assignee_user_id' => $request_assignee,
            'request_assignee_name' => self::resolveAssigneeName( $request_assignee ),
            'donation_assignee_user_id' => $donation_assignee,
            'donation_assignee_name' => self::resolveAssigneeName( $donation_assignee ),
        ];
    }

    private static function defaultAssigneeUserId( string $intake_type ): int {
        $key = $intake_type === 'donation'
            ? self::DONATION_ASSIGNEE_SETTING
            : self::REQUEST_ASSIGNEE_SETTING;

        return self::normalizeAssigneeUserId( \Core_Settings_Service::get( $key, 0 ) );
    }

    private static function ensureCatalogSeeded(): void {
        global $wpdb;

        $table = \Metis_Tables::get( 'grandys_stash_catalog' );
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count > 0 ) {
            return;
        }

        foreach ( self::loadCatalogSeedRows() as $row ) {
            $item_name = trim( \sanitize_text_field( (string) ( $row['name'] ?? '' ) ) );
            $category_name = trim( \sanitize_text_field( (string) ( $row['category'] ?? '' ) ) );
            if ( $item_name === '' || $category_name === '' ) {
                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'catalog_code' => self::generateCode( 'GI', $table, 'catalog_code' ),
                    'item_name' => $item_name,
                    'item_slug' => \sanitize_title( $item_name ),
                    'category_name' => $category_name,
                    'category_slug' => \sanitize_title( $category_name ),
                    'is_active' => 1,
                    'sort_order' => max( 0, (int) ( $row['sort_order'] ?? 0 ) ),
                ]
            );
        }
    }

    private static function loadCatalogSeedRows(): array {
        $path = METIS_PATH . 'includes/modules/grandys_stash/catalog.seed.csv';
        if ( ! file_exists( $path ) ) {
            return [];
        }

        $handle = fopen( $path, 'r' );
        if ( ! is_resource( $handle ) ) {
            return [];
        }

        fgetcsv( $handle, 0, ',', '"', '' );
        $rows = [];
        while ( ( $data = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) {
            if ( ! is_array( $data ) || count( $data ) < 3 ) {
                continue;
            }
            $rows[] = [
                'name' => (string) $data[0],
                'category' => (string) $data[1],
                'sort_order' => (int) $data[2],
            ];
        }
        fclose( $handle );

        return $rows;
    }

    private static function catalogCategories(): array {
        global $wpdb;

        $table = \Metis_Tables::get( 'grandys_stash_catalog' );
        return $wpdb->get_results(
            "SELECT category_slug, category_name, COUNT(*) AS item_count
             FROM {$table}
             WHERE is_active = 1
             GROUP BY category_slug, category_name
             ORDER BY category_name ASC",
            ARRAY_A
        ) ?: [];
    }

    private static function catalogItems(): array {
        global $wpdb;

        $table = \Metis_Tables::get( 'grandys_stash_catalog' );
        return $wpdb->get_results(
            "SELECT id, catalog_code, item_name, item_slug, category_name, category_slug, sort_order
             FROM {$table}
             WHERE is_active = 1
             ORDER BY category_name ASC, sort_order ASC, item_name ASC",
            ARRAY_A
        ) ?: [];
    }

    private static function catalogLabelBySlug( string $slug ): string {
        $slug = \sanitize_title( $slug );
        if ( $slug === '' ) {
            return '';
        }
        foreach ( self::catalogItems() as $item ) {
            if ( (string) ( $item['item_slug'] ?? '' ) === $slug ) {
                return (string) ( $item['item_name'] ?? '' );
            }
        }
        return '';
    }

    private static function catalogCategoryLabelBySlug( string $slug ): string {
        $slug = \sanitize_title( $slug );
        if ( $slug === '' ) {
            return '';
        }
        foreach ( self::catalogCategories() as $category ) {
            if ( (string) ( $category['category_slug'] ?? '' ) === $slug ) {
                return (string) ( $category['category_name'] ?? '' );
            }
        }
        return '';
    }

    private static function findCatalogItemByNameAndCategory( string $name, string $category_slug ): ?array {
        $needle = strtolower( trim( $name ) );
        if ( $needle === '' ) {
            return null;
        }

        foreach ( self::catalogItems() as $item ) {
            if ( strtolower( trim( (string) ( $item['item_name'] ?? '' ) ) ) !== $needle ) {
                continue;
            }

            if ( $category_slug !== '' && (string) ( $item['category_slug'] ?? '' ) !== $category_slug ) {
                continue;
            }

            return $item;
        }

        return null;
    }

    private static function upsertCatalogEntry( string $item_name, string $category_slug ): void {
        global $wpdb;

        $item_name = trim( \sanitize_text_field( $item_name ) );
        if ( $item_name === '' ) {
            return;
        }

        $table = \Metis_Tables::get( 'grandys_stash_catalog' );
        $item_slug = \sanitize_title( $item_name );
        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE item_slug = %s LIMIT 1", $item_slug )
        );
        if ( $existing_id > 0 ) {
            return;
        }

        $category_label = self::catalogCategoryLabelBySlug( $category_slug );
        if ( $category_label === '' ) {
            $category_label = self::humanizeCategorySlug( $category_slug );
        }

        $next_sort = (int) $wpdb->get_var( "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$table}" );
        $wpdb->insert(
            $table,
            [
                'catalog_code' => self::generateCode( 'GI', $table, 'catalog_code' ),
                'item_name' => $item_name,
                'item_slug' => $item_slug,
                'category_name' => $category_label !== '' ? $category_label : 'Other',
                'category_slug' => $category_slug !== '' ? $category_slug : 'other',
                'is_active' => 1,
                'sort_order' => max( 1, $next_sort ),
            ]
        );
    }

    private static function humanizeCategorySlug( string $slug ): string {
        $slug = trim( \sanitize_title( $slug ) );
        if ( $slug === '' ) {
            return '';
        }

        return ucwords( str_replace( '-', ' ', $slug ) );
    }

    private static function assigneeOptions(): array {
        $users = function_exists( 'get_users' ) ? get_users( [ 'orderby' => 'display_name', 'order' => 'ASC' ] ) : [];
        return array_map( static function ( $user ): array {
            $id = isset( $user->ID ) ? (int) $user->ID : 0;
            $label = isset( $user->display_name ) ? (string) $user->display_name : '';
            if ( $label === '' && isset( $user->user_email ) ) {
                $label = (string) $user->user_email;
            }
            return [ 'id' => $id, 'label' => $label ];
        }, is_array( $users ) ? $users : [] );
    }

    private static function normalizeAssigneeUserId( mixed $value ): int {
        $id = (int) $value;
        return $id > 0 ? $id : 0;
    }

    private static function resolveAssigneeName( int $user_id ): ?string {
        if ( $user_id < 1 || ! function_exists( 'get_user_by' ) ) {
            return null;
        }
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            return null;
        }
        $label = (string) ( $user->display_name ?? '' );
        if ( $label === '' ) {
            $label = (string) ( $user->user_email ?? '' );
        }
        return $label !== '' ? $label : null;
    }
}
