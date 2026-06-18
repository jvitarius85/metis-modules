<?php
declare(strict_types=1);

namespace Metis\Modules\GrandyStash;

final class GrandyStashRepository {

    // Settings keys
    private const REQUEST_ASSIGNEE_SETTING  = 'grandys_stash_default_request_assignee_user_id';
    private const DONATION_ASSIGNEE_SETTING = 'grandys_stash_default_donation_assignee_user_id';
    private const PUBLIC_EMAIL_DOMAINS = [
        'gmail.com',
        'googlemail.com',
        'yahoo.com',
        'outlook.com',
        'hotmail.com',
        'live.com',
        'icloud.com',
        'me.com',
        'aol.com',
        'msn.com',
        'comcast.net',
        'att.net',
        'sbcglobal.net',
        'proton.me',
        'protonmail.com',
    ];

    // Locked statuses
    public const STATUSES = ['NEW', 'REVIEWING', 'WAITLIST', 'READY', 'COMPLETED', 'CLOSED'];

    // ─── Boot ────────────────────────────────────────────

    public static function ensureModuleReady(): void {
        GrandyStashSchemaManager::ensureSchema();
        \Metis\Modules\Contacts\ContactsModule::ensureSchema();
        \Metis\Modules\Forms\FormsModule::ensureSchema();
        self::ensureCatalogSeeded();
    }

    // ─── Core helpers ────────────────────────────────────

    private static function db() {
        return \metis_db();
    }

    private static function generateCode( string $prefix, string $table, string $column ): string {
        $db = self::db();
        $last = (int) $db->scalar(
            "SELECT MAX(CAST(SUBSTRING({$column}, %d) AS UNSIGNED)) FROM {$table}",
            [ strlen( $prefix ) + 2 ]
        );
        return $prefix . '-' . str_pad( (string) ( $last + 1 ), 6, '0', STR_PAD_LEFT );
    }

    private static function normalizeStatus( string $value ): string {
        $value = strtoupper( trim( $value ) );
        return in_array( $value, self::STATUSES, true ) ? $value : 'NEW';
    }

    private static function normalizeUrgency( string $value ): string {
        $value = \metis_key_clean( $value );
        return in_array( $value, ['urgent', 'standard', 'flexible'], true ) ? $value : 'standard';
    }

    private static function normalizePickupDelivery( string $value ): string {
        $value = \metis_key_clean( $value );
        return in_array( $value, ['pickup', 'delivery', 'dropoff', 'discuss'], true ) ? $value : '';
    }

    private static function normalizeCondition( string $value ): string {
        $value = \metis_key_clean( $value );
        return in_array( $value, ['excellent', 'good', 'fair', 'repair'], true ) ? $value : 'good';
    }

    private static function normalizeItemStatus( string $value ): string {
        $value = \metis_key_clean( $value );
        return in_array( $value, ['pending', 'available', 'fulfilled', 'unavailable'], true ) ? $value : 'pending';
    }

    private static function normalizeAssigneeUserId( mixed $value ): int {
        $id = (int) $value;
        return $id > 0 ? $id : 0;
    }

    private static function resolveAssigneeName( int $user_id ): ?string {
        if ( $user_id < 1 ) {
            return null;
        }
        $db    = self::db();
        $table = \Metis_Tables::get( 'auth_users' );
        $row   = $db->fetchOne( "SELECT display_name, user_email FROM {$table} WHERE id = %d LIMIT 1", [ $user_id ] );
        if ( ! $row ) {
            return null;
        }
        $label = (string) ( $row['display_name'] ?? '' );
        if ( $label === '' ) {
            $label = (string) ( $row['user_email'] ?? '' );
        }
        return $label !== '' ? $label : null;
    }

    private static function defaultAssigneeUserId( string $type ): int {
        $key = $type === 'donation' ? self::DONATION_ASSIGNEE_SETTING : self::REQUEST_ASSIGNEE_SETTING;
        return self::normalizeAssigneeUserId( \Core_Settings_Service::get( $key, 0 ) );
    }

    private static function nullableText( mixed $value ): ?string {
        $value = trim( \metis_text_clean( (string) $value ) );
        return $value !== '' ? $value : null;
    }

    private static function nullableTextArea( mixed $value ): ?string {
        $value = trim( \metis_textarea_clean( (string) $value ) );
        return $value !== '' ? $value : null;
    }

    private static function normalizeDomain( string $value ): string {
        $value = strtolower( trim( $value ) );
        if ( $value === '' ) {
            return '';
        }

        $value = preg_replace( '/^https?:\/\//', '', $value ) ?? $value;
        $value = preg_replace( '/^www\./', '', $value ) ?? $value;
        $value = explode( '/', $value )[0] ?? $value;
        $value = explode( '?', $value )[0] ?? $value;

        return filter_var( $value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ? $value : '';
    }

    private static function domainFromEmail( string $email ): string {
        $email = strtolower( trim( \metis_email_clean( $email ) ) );
        if ( $email === '' || ! str_contains( $email, '@' ) ) {
            return '';
        }

        $domain = self::normalizeDomain( (string) substr( $email, strrpos( $email, '@' ) + 1 ) );
        if ( $domain === '' || in_array( $domain, self::PUBLIC_EMAIL_DOMAINS, true ) ) {
            return '';
        }

        return $domain;
    }

    private static function organizationNameFromDomain( string $domain ): string {
        $domain = self::normalizeDomain( $domain );
        if ( $domain === '' ) {
            return '';
        }

        $parts = explode( '.', $domain );
        $base = (string) ( $parts[0] ?? '' );
        if ( $base === '' ) {
            return '';
        }

        return ucwords( str_replace( [ '-', '_' ], ' ', $base ) );
    }

    private static function normalizeDatetime( string $value ): ?string {
        $value = trim( $value );
        if ( $value === '' ) {
            return null;
        }
        $timestamp = strtotime( $value );
        return $timestamp ? \gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
    }

    private static function decodeAssoc( mixed $json ): array {
        if ( ! is_string( $json ) || $json === '' ) {
            return [];
        }
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private static function normalizeStringList( mixed $values ): array {
        $values = is_array( $values ) ? $values : [ $values ];
        $out = [];
        foreach ( $values as $v ) {
            $v = trim( \metis_text_clean( (string) $v ) );
            if ( $v !== '' ) {
                $out[] = $v;
            }
        }
        return array_values( array_unique( $out ) );
    }

    private static function safeConversationString( string $value ): string {
        if ( $value === '' || preg_match( '//u', $value ) === 1 ) {
            return $value;
        }

        if ( function_exists( 'mb_convert_encoding' ) ) {
            $converted = @mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
            if ( is_string( $converted ) && preg_match( '//u', $converted ) === 1 ) {
                return $converted;
            }
        }

        if ( function_exists( 'iconv' ) ) {
            $converted = @iconv( 'UTF-8', 'UTF-8//IGNORE', $value );
            if ( is_string( $converted ) && preg_match( '//u', $converted ) === 1 ) {
                return $converted;
            }
        }

        return '';
    }

    public static function findTicketByCode( string $code ): ?array {
        $code = strtoupper( trim( \metis_text_clean( $code ) ) );
        if ( $code === '' ) {
            return null;
        }

        $table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $row = self::db()->fetchOne( "SELECT * FROM {$table} WHERE code = %s LIMIT 1", [ $code ] );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @param array<int, string> $reference_headers
     * @return array<string, mixed>|null
     */
    public static function findTicketByConversationHeaders( string $in_reply_to, array $reference_headers = [] ): ?array {
        $tokens = ConversationSupport::extractMessageIdTokens(
            array_merge(
                $in_reply_to !== '' ? [ $in_reply_to ] : [],
                $reference_headers
            )
        );

        if ( $tokens === [] ) {
            return null;
        }

        $db = self::db();
        $messages = \Metis_Tables::get( 'grandys_stash_messages' );
        $tickets = \Metis_Tables::get( 'grandys_stash_tickets' );
        $placeholders = implode( ', ', array_fill( 0, count( $tokens ), '%s' ) );
        $sql = "SELECT t.*
                FROM {$messages} m
                INNER JOIN {$tickets} t ON t.id = m.ticket_id
                WHERE m.rfc_message_id IN ({$placeholders})
                ORDER BY m.id DESC
                LIMIT 1";

        $row = $db->fetchOne( $sql, $tokens );

        return is_array( $row ) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findTicketByProviderThreadId( string $provider_thread_id ): ?array {
        $provider_thread_id = trim( $provider_thread_id );
        if ( $provider_thread_id === '' ) {
            return null;
        }

        $db = self::db();
        $messages = \Metis_Tables::get( 'grandys_stash_messages' );
        $tickets = \Metis_Tables::get( 'grandys_stash_tickets' );
        $row = $db->fetchOne(
            "SELECT t.*
             FROM {$messages} m
             INNER JOIN {$tickets} t ON t.id = m.ticket_id
             WHERE m.provider_thread_id = %s
             ORDER BY m.id DESC
             LIMIT 1",
            [ $provider_thread_id ]
        );

        return is_array( $row ) ? $row : null;
    }

    /**
     * @param array<string, mixed> $message_row
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    public static function recordInboundMessage( int $ticket_id, array $message_row, array $normalized ): array {
        $db = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_messages' );
        $inbound_message_id = (int) ( $message_row['id'] ?? 0 );
        $existing = $inbound_message_id > 0
            ? $db->fetchOne( "SELECT * FROM {$table} WHERE inbound_message_id = %d LIMIT 1", [ $inbound_message_id ] )
            : null;

        if ( is_array( $existing ) ) {
            return $existing;
        }

        $received_at = (string) ( $normalized['received_at'] ?? \metis_current_time( 'mysql' ) );
        $rfc_message_id = ConversationSupport::normalizeMessageId( (string) ( $normalized['rfc_message_id'] ?? '' ) );
        $in_reply_to = ConversationSupport::normalizeMessageId( (string) ( $normalized['headers']['in-reply-to'][0] ?? '' ) );
        $references_header = ConversationSupport::buildReferencesHeader(
            ConversationSupport::extractMessageIdTokens( (array) ( $normalized['headers']['references'] ?? [] ) ),
            $in_reply_to
        );
        $provider_message_id = trim( (string) ( $normalized['provider_message_id'] ?? '' ) );
        $attachments = (array) ( $normalized['attachments'] ?? [] );
        if (
            $inbound_message_id > 0
            && \class_exists( '\Metis\Modules\CommunicationsInbound\CommunicationsInboundModule' )
        ) {
            $stored_attachments = \Metis\Modules\CommunicationsInbound\CommunicationsInboundModule::attachmentsForMessage( $inbound_message_id );
            if ( $stored_attachments !== [] ) {
                $attachments = $stored_attachments;
            }
        }
        $insert_result = $db->insert(
            $table,
            [
                'ticket_id'           => $ticket_id,
                'inbound_message_id'  => $inbound_message_id > 0 ? $inbound_message_id : null,
                'provider_message_id' => $provider_message_id,
                'provider_thread_id'  => self::safeConversationString( (string) ( $normalized['provider_thread_id'] ?? '' ) ),
                'mailbox_email'       => strtolower( trim( self::safeConversationString( (string) ( $normalized['provider_mailbox'] ?? '' ) ) ) ),
                'direction'           => 'inbound',
                'subject'             => self::safeConversationString( (string) ( $normalized['subject'] ?? '' ) ),
                'sender_email'        => strtolower( trim( self::safeConversationString( (string) ( $normalized['canonical_sender_email'] ?? '' ) ) ) ),
                'sender_name'         => self::safeConversationString( (string) ( $normalized['from'][0]['name'] ?? '' ) ),
                'recipient_email'     => strtolower( trim( self::safeConversationString( (string) ( $normalized['canonical_recipient_emails'][0] ?? '' ) ) ) ),
                'recipients_json'     => \metis_json_encode( (array) ( $normalized['canonical_recipient_emails'] ?? [] ) ),
                'attachments_json'    => \metis_json_encode( $attachments ),
                'rfc_message_id'      => $rfc_message_id !== '' ? $rfc_message_id : null,
                'in_reply_to'         => $in_reply_to !== '' ? $in_reply_to : null,
                'references_header'   => $references_header !== '' ? self::safeConversationString( $references_header ) : null,
                'text_body'           => self::safeConversationString( (string) ( $normalized['text_body'] ?? '' ) ),
                'html_body'           => self::safeConversationString( (string) ( $normalized['html_body'] ?? '' ) ),
                'delivery_status'     => 'received',
                'message_at'          => $received_at,
                'received_at'         => $received_at,
                'created_at'          => \metis_current_time( 'mysql' ),
            ]
        );

        $row = null;
        $insert_id = (int) $db->lastInsertId();
        if ( $insert_id > 0 ) {
            $row = $db->fetchOne( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", [ $insert_id ] );
        }
        if ( ! is_array( $row ) && $inbound_message_id > 0 ) {
            $row = $db->fetchOne( "SELECT * FROM {$table} WHERE inbound_message_id = %d LIMIT 1", [ $inbound_message_id ] );
        }
        if ( ! is_array( $row ) && $provider_message_id !== '' ) {
            $row = $db->fetchOne(
                "SELECT * FROM {$table} WHERE ticket_id = %d AND provider_message_id = %s ORDER BY id DESC LIMIT 1",
                [ $ticket_id, $provider_message_id ]
            );
        }

        if ( $insert_result === false || ! is_array( $row ) || (int) ( $row['id'] ?? 0 ) < 1 ) {
            $last_error = '';
            try {
                $last_error = trim( $db->lastError() );
            } catch ( \Throwable ) {
                $last_error = '';
            }

            throw new \RuntimeException(
                'Inbound Grandy\'s Stash conversation message could not be persisted.'
                . ( $last_error !== '' ? ' ' . $last_error : '' )
            );
        }

        self::logActivity( $ticket_id, 'email_received', 'Inbound email linked to ticket.', null );
        return self::hydrateConversationMessage( $row );
    }

    // ─── Catalog ─────────────────────────────────────────

    public static function catalogSummary(): array {
        return [
            'categories' => self::catalogCategories(),
            'items'      => self::catalogItems(),
        ];
    }

    private static function catalogCategories(): array {
        $table = \Metis_Tables::get( 'grandys_stash_catalog' );
        return self::db()->fetchAll(
            "SELECT DISTINCT category_name, category_slug
             FROM {$table}
             WHERE is_active = 1
             ORDER BY category_name ASC"
        );
    }

    private static function catalogItems(): array {
        $table = \Metis_Tables::get( 'grandys_stash_catalog' );
        return self::db()->fetchAll(
            "SELECT id, catalog_code, item_name, item_slug, category_name, category_slug
             FROM {$table}
             WHERE is_active = 1
             ORDER BY category_name ASC, item_name ASC"
        );
    }

    private static function catalogLabelBySlug( string $slug ): string {
        if ( $slug === '' ) {
            return '';
        }
        foreach ( self::catalogItems() as $item ) {
            if ( (string) ( $item['item_slug'] ?? '' ) === $slug ) {
                return (string) ( $item['item_name'] ?? $slug );
            }
        }
        return $slug;
    }

    private static function catalogCategoryLabelBySlug( string $slug ): string {
        if ( $slug === '' ) {
            return '';
        }
        foreach ( self::catalogCategories() as $cat ) {
            if ( (string) ( $cat['category_slug'] ?? '' ) === $slug ) {
                return (string) ( $cat['category_name'] ?? $slug );
            }
        }
        return $slug;
    }

    private static function humanizeCatalogValue( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }

        if ( ! str_contains( $value, '-' ) && ! str_contains( $value, '_' ) ) {
            return $value;
        }

        return ucwords( str_replace( [ '-', '_' ], ' ', $value ) );
    }

    private static function normalizeCategorySlug( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }

        $slug = \metis_slug_clean( $value );
        return $slug !== '' ? $slug : '';
    }

    private static function findCatalogItemRecord( string $value, string $category_slug = '' ): ?array {
        $value = trim( $value );
        if ( $value === '' ) {
            return null;
        }

        $normalized_value = self::normalizeCategorySlug( $value );
        foreach ( self::catalogItems() as $item ) {
            $item_slug = trim( (string) ( $item['item_slug'] ?? '' ) );
            $item_name = trim( (string) ( $item['item_name'] ?? '' ) );
            $item_category = trim( (string) ( $item['category_slug'] ?? '' ) );

            $matches = $item_slug === $value
                || $item_slug === $normalized_value
                || strcasecmp( $item_name, $value ) === 0;
            if ( ! $matches ) {
                continue;
            }

            if ( $category_slug === '' || $category_slug === 'other' || $item_category === $category_slug ) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function resolveTicketItemRow( array $row ): array {
        $stored_category = trim( (string) ( $row['category'] ?? '' ) );
        $stored_name = trim( (string) ( $row['item_name'] ?? '' ) );
        $description = trim( (string) ( $row['description'] ?? '' ) );

        $category_slug = self::normalizeCategorySlug( $stored_category );
        $catalog_item = self::findCatalogItemRecord( $stored_name, $category_slug );
        if ( ! $catalog_item && $description !== '' ) {
            $catalog_item = self::findCatalogItemRecord( $description, $category_slug );
        }

        if ( $catalog_item && ( $category_slug === '' || $category_slug === 'other' ) ) {
            $category_slug = trim( (string) ( $catalog_item['category_slug'] ?? '' ) );
        }

        $category_label = $category_slug !== '' ? self::catalogCategoryLabelBySlug( $category_slug ) : '';
        if ( $category_label === '' && $stored_category !== '' && $stored_category !== 'other' ) {
            $category_label = self::humanizeCatalogValue( $stored_category );
        }
        if ( $category_label === '' && $catalog_item ) {
            $category_label = trim( (string) ( $catalog_item['category_name'] ?? '' ) );
        }
        if ( $category_label === '' ) {
            $category_label = 'Other';
        }

        $item_label = '';
        if ( $catalog_item ) {
            $item_label = trim( (string) ( $catalog_item['item_name'] ?? '' ) );
        }
        if ( $item_label === '' && $stored_name !== '' ) {
            $resolved = self::catalogLabelBySlug( $stored_name );
            $item_label = $resolved !== '' && $resolved !== $stored_name
                ? $resolved
                : self::humanizeCatalogValue( $stored_name );
        }
        if ( $item_label === '' && $description !== '' ) {
            $item_label = $description;
        }

        $row['category_slug'] = $category_slug !== '' ? $category_slug : 'other';
        $row['category'] = $category_label;
        $row['item_name'] = $item_label;
        $row['item_label'] = $item_label;

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeTicketItemInputRow( array $row ): array {
        $category_slug = self::normalizeCategorySlug( (string) ( $row['category'] ?? '' ) );
        $catalog_value = trim( (string) ( $row['catalog_slug'] ?? '' ) );
        $item_name = trim( (string) ( $row['item_name'] ?? '' ) );
        $description = self::nullableTextArea( $row['description'] ?? '' );
        $quantity = max( 1, (int) ( $row['quantity'] ?? 1 ) );
        $condition = self::normalizeCondition( (string) ( $row['condition'] ?? '' ) );

        $catalog_item = $catalog_value !== '' ? self::findCatalogItemRecord( $catalog_value, $category_slug ) : null;
        if ( ! $catalog_item && $item_name !== '' ) {
            $catalog_item = self::findCatalogItemRecord( $item_name, $category_slug );
        }

        if ( $catalog_item ) {
            $item_name = trim( (string) ( $catalog_item['item_name'] ?? $item_name ) );
            $catalog_value = trim( (string) ( $catalog_item['item_slug'] ?? $catalog_value ) );
            if ( $category_slug === '' || $category_slug === 'other' ) {
                $category_slug = trim( (string) ( $catalog_item['category_slug'] ?? $category_slug ) );
            }
        } elseif ( $catalog_value !== '' && $item_name === '' ) {
            $item_name = self::humanizeCatalogValue( $catalog_value );
        } elseif ( $item_name !== '' ) {
            $item_name = self::humanizeCatalogValue( $item_name );
        }

        return [
            'category'         => $category_slug !== '' ? $category_slug : 'other',
            'catalog_slug'     => $catalog_value,
            'item_name'        => $item_name,
            'description'      => $description,
            'condition_status' => $condition,
            'quantity'         => $quantity,
            'status'           => 'pending',
        ];
    }

    private static function findCatalogItemByNameAndCategory( string $name, string $category ): ?array {
        foreach ( self::catalogItems() as $item ) {
            if ( strcasecmp( (string) ( $item['item_name'] ?? '' ), $name ) === 0 ) {
                if ( $category === '' || $category === 'other' || (string) ( $item['category_slug'] ?? '' ) === $category ) {
                    return $item;
                }
            }
        }
        return null;
    }

    private static function upsertCatalogEntry( string $name, string $category ): void {
        $db   = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_catalog' );
        $slug  = \metis_slug_clean( $name );
        if ( $slug === '' ) {
            return;
        }
        $exists = (int) $db->scalar( "SELECT id FROM {$table} WHERE item_slug = %s LIMIT 1", [ $slug ] );
        if ( $exists > 0 ) {
            return;
        }
        $category_slug = $category !== '' ? $category : 'other';
        $category_name = self::catalogCategoryLabelBySlug( $category_slug );
        if ( $category_name === $category_slug ) {
            $category_name = ucfirst( str_replace( '_', ' ', $category_slug ) );
        }
        $code = self::generateCode( 'GI', $table, 'catalog_code' );
        $db->insert( $table, [
            'catalog_code'  => $code,
            'item_name'     => $name,
            'item_slug'     => $slug,
            'category_name' => $category_name,
            'category_slug' => $category_slug,
        ] );
    }

    private static function ensureCatalogSeeded(): void {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_catalog' );
        $count = (int) $db->scalar( "SELECT COUNT(*) FROM {$table}" );
        if ( $count > 0 ) {
            return;
        }
        $csv_path = METIS_MODULES_PATH . 'grandys_stash/catalog.seed.csv';
        if ( ! file_exists( $csv_path ) ) {
            return;
        }
        $handle = fopen( $csv_path, 'r' );
        if ( ! $handle ) {
            return;
        }
        $header = fgetcsv( $handle );
        $seq    = 0;
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( count( $row ) < 2 ) {
                continue;
            }
            $item_name     = trim( $row[0] ?? '' );
            $category_name = trim( $row[1] ?? '' );
            if ( $item_name === '' || $category_name === '' ) {
                continue;
            }
            $seq++;
            $db->insert( $table, [
                'catalog_code'  => 'GI-' . str_pad( (string) $seq, 6, '0', STR_PAD_LEFT ),
                'item_name'     => $item_name,
                'item_slug'     => \metis_slug_clean( $item_name ),
                'category_name' => $category_name,
                'category_slug' => \metis_slug_clean( $category_name ),
                'sort_order'    => $seq,
            ] );
        }
        fclose( $handle );
    }

    // ─── Contact upsert ──────────────────────────────────

    private static function upsertContactFromPayload( array $contact, string $fallback_cid ): string {
        $db = self::db();

        $cid        = trim( $fallback_cid );
        $first_name = trim( \metis_text_clean( (string) ( $contact['first_name'] ?? '' ) ) );
        $last_name  = trim( \metis_text_clean( (string) ( $contact['last_name'] ?? '' ) ) );
        $email      = strtolower( trim( \metis_email_clean( (string) ( $contact['email'] ?? '' ) ) ) );
        $phone      = trim( \metis_text_clean( (string) ( $contact['phone'] ?? '' ) ) );

        $contacts_table = \Metis_Tables::get( 'contacts' );
        $details_table  = \Metis_Tables::get( 'contact_details' );

        $contact_id = 0;
        if ( $cid !== '' ) {
            $existing = $db->fetchOne( "SELECT id, cid FROM {$contacts_table} WHERE cid = %s LIMIT 1", [ $cid ] );
        } elseif ( $email !== '' ) {
            $existing = $db->fetchOne( "SELECT id, cid FROM {$contacts_table} WHERE email = %s LIMIT 1", [ $email ] );
        } else {
            $existing = null;
        }

        if ( $existing ) {
            $contact_id = (int) $existing['id'];
            $cid        = (string) $existing['cid'];
            $update     = [];
            if ( $first_name !== '' ) { $update['first_name'] = $first_name; }
            if ( $last_name !== '' )  { $update['last_name']  = $last_name; }
            if ( $email !== '' )      { $update['email']      = $email; }
            if ( $update !== [] ) {
                $db->update( $contacts_table, $update, [ 'id' => $contact_id ] );
            }
        } elseif ( $first_name !== '' || $last_name !== '' || $email !== '' ) {
            $contact_row = [
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'email'      => $email,
            ];
            if ( function_exists( 'metis_entity_id_service' ) ) {
                $contact_row = \metis_entity_id_service()->assignForInsert( 'contact', $contact_row );
            } else {
                $contact_row['cid'] = self::generateCode( 'CN', $contacts_table, 'cid' );
            }
            $cid = (string) ( $contact_row['contact_uid'] ?? $contact_row['cid'] ?? '' );
            $db->insert( $contacts_table, $contact_row );
            $contact_id = $db->lastInsertId();
            if ( $contact_id > 0 && function_exists( 'metis_entity_id_service' ) ) {
                \metis_entity_id_service()->register( 'contact', $contact_id, $cid );
            }
        }

        if ( $contact_id < 1 || $cid === '' ) {
            return '';
        }

        $detail_id = (int) $db->scalar(
            "SELECT id FROM {$details_table} WHERE contact_cid = %s OR contact_id = %d LIMIT 1",
            [ $cid, $contact_id ]
        );
        $detail_row = [
            'contact_cid' => $cid,
            'contact_id'  => $contact_id,
            'phone'       => $phone !== '' ? $phone : null,
        ];
        if ( $detail_id > 0 ) {
            $db->update( $details_table, $detail_row, [ 'id' => $detail_id ] );
        } else {
            $db->insert( $details_table, $detail_row );
        }

        return $cid;
    }

    // ─── Auto-grouping ───────────────────────────────────

    public static function findOrCreateOrganization( string $name = '', string $domain = '' ): int {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_organizations' );

        $name = trim( \metis_text_clean( $name ) );
        $domain = self::normalizeDomain( $domain );
        if ( $name === '' && $domain === '' ) {
            return 0;
        }

        $organization = null;
        if ( $domain !== '' ) {
            $organization = $db->fetchOne( "SELECT * FROM {$table} WHERE domain = %s LIMIT 1", [ $domain ] );
        }
        if ( ! $organization && $name !== '' ) {
            $organization = $db->fetchOne( "SELECT * FROM {$table} WHERE name = %s LIMIT 1", [ $name ] );
        }

        if ( is_array( $organization ) ) {
            $update = [];
            if ( $name !== '' && (string) ( $organization['name'] ?? '' ) === '' ) {
                $update['name'] = $name;
            }
            if ( $domain !== '' && (string) ( $organization['domain'] ?? '' ) === '' ) {
                $update['domain'] = $domain;
            }
            if ( $update !== [] ) {
                $db->update( $table, $update, [ 'id' => (int) $organization['id'] ] );
            }

            return (int) $organization['id'];
        }

        $db->insert( $table, [
            'code'   => self::generateCode( 'GSO', $table, 'code' ),
            'name'   => $name !== '' ? $name : self::organizationNameFromDomain( $domain ),
            'domain' => $domain !== '' ? $domain : null,
        ] );

        return (int) $db->lastInsertId();
    }

    public static function findOrCreateGroup( string $name, string $email, string $phone, string $contact_cid = '' ): int {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_groups' );

        // Match priority: email > phone > exact name
        $group = null;
        if ( $email !== '' ) {
            $group = $db->fetchOne( "SELECT * FROM {$table} WHERE email = %s LIMIT 1", [ $email ] );
        }
        if ( ! $group && $phone !== '' ) {
            $normalized_phone = preg_replace( '/[^0-9]/', '', $phone );
            if ( strlen( $normalized_phone ) >= 7 ) {
                $group = $db->fetchOne(
                    "SELECT * FROM {$table} WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, '-', ''), '(', ''), ')', ''), ' ', '') LIKE %s LIMIT 1",
                    [ '%' . $db->escapeLike( $normalized_phone ) ]
                );
            }
        }
        if ( ! $group && $name !== '' ) {
            $group = $db->fetchOne( "SELECT * FROM {$table} WHERE name = %s LIMIT 1", [ $name ] );
        }

        if ( $group ) {
            $update = [];
            if ( $email !== '' && ( $group['email'] ?? '' ) === '' )             { $update['email'] = $email; }
            if ( $phone !== '' && ( $group['phone'] ?? '' ) === '' )             { $update['phone'] = $phone; }
            if ( $contact_cid !== '' && ( $group['contact_cid'] ?? '' ) === '' ) { $update['contact_cid'] = $contact_cid; }
            if ( $update !== [] ) {
                $db->update( $table, $update, [ 'id' => (int) $group['id'] ] );
            }
            return (int) $group['id'];
        }

        $code = self::generateCode( 'GSG', $table, 'code' );
        $db->insert( $table, [
            'code'        => $code,
            'name'        => $name,
            'email'       => $email !== '' ? $email : null,
            'phone'       => $phone !== '' ? $phone : null,
            'contact_cid' => $contact_cid !== '' ? $contact_cid : null,
        ] );

        return $db->lastInsertId();
    }

    public static function searchGroups( string $query ): array {
        $db = self::db();
        $groups_table = \Metis_Tables::get( 'grandys_stash_groups' );
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $query = trim( $query );

        $sql = "SELECT g.id, g.code, g.name, g.email, g.phone,
                       COUNT(t.id) AS ticket_count,
                       MAX(t.submitted_at) AS last_ticket_at
                FROM {$groups_table} g
                LEFT JOIN {$tickets_table} t ON t.group_id = g.id";
        $params = [];
        if ( $query !== '' ) {
            $like = '%' . $db->escapeLike( $query ) . '%';
            $sql .= " WHERE g.code LIKE %s OR g.name LIKE %s OR g.email LIKE %s OR g.phone LIKE %s";
            $params = [ $like, $like, $like, $like ];
        }

        $sql .= " GROUP BY g.id ORDER BY ticket_count DESC, g.updated_at DESC, g.id DESC LIMIT 50";
        return $db->fetchAll( $sql, $params ) ?: [];
    }

    public static function searchOrganizations( string $query ): array {
        $db = self::db();
        $orgs_table = \Metis_Tables::get( 'grandys_stash_organizations' );
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $query = trim( $query );

        $sql = "SELECT o.id, o.code, o.name, o.domain, o.notes, o.is_active,
                       COUNT(t.id) AS ticket_count,
                       MAX(t.submitted_at) AS last_ticket_at
                FROM {$orgs_table} o
                LEFT JOIN {$tickets_table} t ON t.organization_id = o.id";
        $params = [];
        if ( $query !== '' ) {
            $like = '%' . $db->escapeLike( $query ) . '%';
            $sql .= " WHERE o.code LIKE %s OR o.name LIKE %s OR o.domain LIKE %s";
            $params = [ $like, $like, $like ];
        }

        $sql .= " GROUP BY o.id ORDER BY ticket_count DESC, o.updated_at DESC, o.id DESC LIMIT 50";
        return $db->fetchAll( $sql, $params ) ?: [];
    }

    public static function linkTicketToGroup( int $ticket_id, int $group_id ): array {
        $db = self::db();
        $ticket = self::getTicket( $ticket_id );
        if ( ! $ticket ) {
            return [ 'ok' => false, 'status' => 404 ];
        }

        $group = $db->fetchOne(
            "SELECT id, code, name FROM " . \Metis_Tables::get( 'grandys_stash_groups' ) . " WHERE id = %d LIMIT 1",
            [ $group_id ]
        );
        if ( ! is_array( $group ) ) {
            return [ 'ok' => false, 'status' => 404 ];
        }

        $db->update( \Metis_Tables::get( 'grandys_stash_tickets' ), [ 'group_id' => $group_id ], [ 'id' => $ticket_id ] );
        self::logActivity( $ticket_id, 'grouped', 'Linked to person group ' . (string) ( $group['code'] ?? '#' . $group_id ) . '.' );

        return [ 'ok' => true, 'ticket_id' => $ticket_id ];
    }

    public static function mergeGroups( int $source_id, int $target_id ): array {
        if ( $source_id < 1 || $target_id < 1 || $source_id === $target_id ) {
            return [ 'ok' => false, 'status' => 422 ];
        }

        $db = self::db();
        $groups_table = \Metis_Tables::get( 'grandys_stash_groups' );
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $source = $db->fetchOne( "SELECT id, code FROM {$groups_table} WHERE id = %d LIMIT 1", [ $source_id ] );
        $target = $db->fetchOne( "SELECT id, code FROM {$groups_table} WHERE id = %d LIMIT 1", [ $target_id ] );
        if ( ! is_array( $source ) || ! is_array( $target ) ) {
            return [ 'ok' => false, 'status' => 404 ];
        }

        $ticket_ids = $db->fetchAll( "SELECT id FROM {$tickets_table} WHERE group_id = %d", [ $source_id ] ) ?: [];
        $db->update( $tickets_table, [ 'group_id' => $target_id ], [ 'group_id' => $source_id ] );
        foreach ( $ticket_ids as $row ) {
            self::logActivity( (int) ( $row['id'] ?? 0 ), 'grouped', 'Merged from person group ' . (string) $source['code'] . ' into ' . (string) $target['code'] . '.' );
        }

        return [ 'ok' => true ];
    }

    public static function unlinkTicketFromGroup( int $ticket_id ): array {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $db->update( $table, [ 'group_id' => null ], [ 'id' => $ticket_id ] );
        self::logActivity( $ticket_id, 'ungrouped', 'Manually unlinked from group.' );
        return [ 'ok' => true ];
    }

    // ─── Activity logging ────────────────────────────────

    public static function logActivity( int $ticket_id, string $action, ?string $detail = null, ?int $user_id = null ): void {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_activity' );
        $db->insert( $table, [
            'ticket_id'  => $ticket_id,
            'user_id'    => $user_id ?? ( (int) \metis_current_user_id() ?: null ),
            'action'     => $action,
            'detail'     => $detail,
        ] );
    }

    // ─── Conversation ───────────────────────────────────

    public static function getTicketMessages( int $ticket_id ): array {
        $db = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_messages' );
        $rows = $db->fetchAll(
            "SELECT *,
                    COALESCE(message_at, sent_at, received_at, created_at) AS timeline_at
             FROM {$table}
             WHERE ticket_id = %d
             ORDER BY COALESCE(message_at, sent_at, received_at, created_at) ASC, id ASC",
            [ $ticket_id ]
        ) ?: [];

        return array_map( [ self::class, 'hydrateConversationMessage' ], $rows );
    }

    public static function sendTicketReply( int $ticket_id, string $body, string $subject = '' ): array {
        $ticket = self::getTicket( $ticket_id );
        if ( ! $ticket ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Ticket not found.' ];
        }

        $body = trim( \metis_textarea_clean( $body ) );
        if ( $body === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Reply content is required.' ];
        }

        $mailbox = self::resolveConversationMailbox( $ticket_id );
        if ( ! is_array( $mailbox ) ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'No Grandy\'s Stash mailbox is available for replies.' ];
        }

        $to_email = self::resolveReplyRecipientEmail( $ticket_id, $ticket );
        if ( $to_email === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'No public recipient email is available for this ticket.' ];
        }

        $context = self::latestConversationContext( $ticket_id );
        $thread_subject = trim( $subject ) !== ''
            ? trim( \metis_text_clean( $subject ) )
            : (string) ( $context['subject'] ?? '' );
        $ticket_code = (string) ( $ticket['code'] ?? '' );
        $thread_subject = ConversationSupport::ensureTicketCodeInSubject( $thread_subject, $ticket_code );
        $internal_id = ConversationSupport::internalReferenceToken( $ticket_code );

        $message_id = self::generateConversationMessageId( (string) ( $mailbox['mailbox_email'] ?? '' ), $ticket_id );
        $in_reply_to = ConversationSupport::normalizeMessageId( (string) ( $context['rfc_message_id'] ?? '' ) );
        $references_header = ConversationSupport::buildReferencesHeader(
            ConversationSupport::extractMessageIdTokens( (string) ( $context['references_header'] ?? '' ) ),
            $in_reply_to
        );
        $text_body = ConversationSupport::appendInternalIdFooterToText( $body, $internal_id );
        $html_body = ConversationSupport::appendInternalIdFooterToHtml( self::htmlBodyFromPlainText( $body ), $internal_id );
        $raw_mime = self::buildOutboundMimeMessage(
            [
                'to_email'          => $to_email,
                'from_email'        => strtolower( trim( (string) ( $mailbox['mailbox_email'] ?? '' ) ) ),
                'from_name'         => trim( (string) ( $mailbox['display_name'] ?? '' ) ),
                'subject'           => $thread_subject,
                'text_body'         => $text_body,
                'html_body'         => $html_body,
                'message_id'        => $message_id,
                'in_reply_to'       => $in_reply_to,
                'references_header' => $references_header,
            ]
        );

        if ( ! class_exists( '\Metis\Modules\CommunicationsInbound\GmailClient' ) || ! class_exists( '\Metis\Modules\CommunicationsInbound\WorkspaceGoogleService' ) ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Inbound communications services are unavailable.' ];
        }

        $gmail = new \Metis\Modules\CommunicationsInbound\GmailClient(
            new \Metis\Modules\CommunicationsInbound\WorkspaceGoogleService()
        );
        $send = $gmail->sendRawMessage(
            $mailbox,
            $raw_mime,
            (string) ( $context['provider_thread_id'] ?? '' )
        );

        $message_row = self::persistOutboundMessage(
            $ticket_id,
            [
                'mailbox_email'       => (string) ( $mailbox['mailbox_email'] ?? '' ),
                'provider_message_id' => (string) ( $send['gmail_id'] ?? '' ),
                'provider_thread_id'  => (string) ( $send['thread_id'] ?? $context['provider_thread_id'] ?? '' ),
                'subject'             => $thread_subject,
                'sender_email'        => strtolower( trim( (string) ( $mailbox['mailbox_email'] ?? '' ) ) ),
                'sender_name'         => trim( (string) ( $mailbox['display_name'] ?? '' ) ),
                'sender_user_id'      => (int) \metis_current_user_id(),
                'recipient_email'     => $to_email,
                'recipients_json'     => [ $to_email ],
                'rfc_message_id'      => $message_id,
                'in_reply_to'         => $in_reply_to,
                'references_header'   => $references_header,
                'text_body'           => $text_body,
                'html_body'           => $html_body,
                'delivery_status'     => ! empty( $send['ok'] ) ? 'sent' : 'failed',
                'error_message'       => (string) ( $send['error'] ?? '' ),
            ]
        );

        if ( empty( $send['ok'] ) ) {
            self::logActivity(
                $ticket_id,
                'email_failed',
                'Reply to ' . $to_email . ' failed: ' . (string) ( $send['error'] ?? 'Unknown error.' )
            );

            return [
                'ok'      => false,
                'status'  => (int) ( $send['status'] ?? 422 ),
                'error'   => (string) ( $send['error'] ?? 'Reply send failed.' ),
                'message' => $message_row,
            ];
        }

        self::logActivity( $ticket_id, 'email_sent', 'Reply sent to ' . $to_email . '.', (int) \metis_current_user_id() );

        return [
            'ok'      => true,
            'message' => $message_row,
            'send'    => $send,
        ];
    }

    private static function hydrateConversationMessage( array $row ): array {
        $row['recipients'] = self::decodeAssoc( $row['recipients_json'] ?? null );
        $row['attachments'] = self::decodeAssoc( $row['attachments_json'] ?? null );
        $row['timeline_at'] = (string) ( $row['timeline_at'] ?? $row['message_at'] ?? $row['sent_at'] ?? $row['received_at'] ?? $row['created_at'] ?? '' );
        $row['delivery_status'] = (string) ( $row['delivery_status'] ?? ( (string) ( $row['direction'] ?? '' ) === 'outbound' ? 'sent' : 'received' ) );
        $row['body_text_display'] = trim( (string) ( $row['text_body'] ?? '' ) );
        $html = (string) ( $row['html_body'] ?? '' );
        if ( $html !== '' && ( $row['body_text_display'] === '' || self::bodyTextLooksFlattened( (string) $row['body_text_display'] ) ) ) {
            $row['body_text_display'] = self::conversationTextFromHtml( $html );
        } elseif ( $row['body_text_display'] === '' ) {
            $row['body_text_display'] = trim( strip_tags( $html ) );
        }
        if ( (string) ( $row['direction'] ?? '' ) === 'inbound' ) {
            $row['body_text_display'] = ConversationSupport::extractLatestReplyText( (string) $row['body_text_display'] );
        }
        $row['author_label'] = (string) ( $row['sender_name'] ?? '' ) !== ''
            ? (string) $row['sender_name']
            : ( (string) ( $row['sender_email'] ?? '' ) !== '' ? (string) $row['sender_email'] : 'System' );

        return $row;
    }

    private static function bodyTextLooksFlattened( string $text ): bool {
        $text = trim( $text );
        if ( $text === '' ) {
            return false;
        }

        return str_contains( $text, 'Submitted InformationFirst name' )
            || str_contains( $text, 'Ticket: GST-' )
            || ( ! str_contains( $text, "\n" ) && preg_match( '/[a-z][A-Z]/', $text ) === 1 );
    }

    private static function conversationTextFromHtml( string $html ): string {
        if ( \class_exists( '\Metis\Modules\Newsletter\Support' ) ) {
            return trim( \Metis\Modules\Newsletter\Support::plainTextFromHtml( $html ) );
        }

        $text = trim( strip_tags( $html ) );
        return preg_replace( "/\n{3,}/", "\n\n", $text ) ?? $text;
    }

    public static function conversationMailboxForTicket( int $ticket_id ): ?array {
        return self::resolveConversationMailbox( $ticket_id );
    }

    public static function recordOutboundNotificationMessage( int $ticket_id, array $payload ): array {
        if ( $ticket_id < 1 ) {
            return [];
        }

        return self::persistOutboundMessage( $ticket_id, $payload );
    }

    private static function persistOutboundMessage( int $ticket_id, array $payload ): array {
        $db = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_messages' );
        $message_at = \metis_current_time( 'mysql' );

        $db->insert(
            $table,
            [
                'ticket_id'           => $ticket_id,
                'provider_message_id' => (string) ( $payload['provider_message_id'] ?? '' ),
                'provider_thread_id'  => (string) ( $payload['provider_thread_id'] ?? '' ),
                'mailbox_email'       => strtolower( trim( (string) ( $payload['mailbox_email'] ?? '' ) ) ),
                'direction'           => 'outbound',
                'subject'             => (string) ( $payload['subject'] ?? '' ),
                'sender_email'        => strtolower( trim( (string) ( $payload['sender_email'] ?? '' ) ) ),
                'sender_name'         => (string) ( $payload['sender_name'] ?? '' ),
                'sender_user_id'      => (int) ( $payload['sender_user_id'] ?? 0 ) ?: null,
                'recipient_email'     => strtolower( trim( (string) ( $payload['recipient_email'] ?? '' ) ) ),
                'recipients_json'     => \metis_json_encode( (array) ( $payload['recipients_json'] ?? [] ) ),
                'rfc_message_id'      => (string) ( $payload['rfc_message_id'] ?? '' ) ?: null,
                'in_reply_to'         => (string) ( $payload['in_reply_to'] ?? '' ) ?: null,
                'references_header'   => (string) ( $payload['references_header'] ?? '' ) ?: null,
                'text_body'           => (string) ( $payload['text_body'] ?? '' ),
                'html_body'           => (string) ( $payload['html_body'] ?? '' ),
                'delivery_status'     => (string) ( $payload['delivery_status'] ?? 'sent' ),
                'error_message'       => (string) ( $payload['error_message'] ?? '' ) ?: null,
                'message_at'          => $message_at,
                'sent_at'             => $message_at,
                'created_at'          => $message_at,
            ]
        );

        $row = $db->fetchOne( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", [ (int) $db->lastInsertId() ] ) ?: [];

        return self::hydrateConversationMessage( $row );
    }

    private static function resolveReplyRecipientEmail( int $ticket_id, array $ticket ): string {
        $context = self::latestConversationContext( $ticket_id );
        $sender_email = strtolower( trim( \metis_email_clean( (string) ( $context['sender_email'] ?? '' ) ) ) );
        $mailbox_email = strtolower( trim( \metis_email_clean( (string) ( $context['mailbox_email'] ?? '' ) ) ) );

        if ( $sender_email !== '' && $sender_email !== $mailbox_email && \metis_email_is_valid( $sender_email ) ) {
            return $sender_email;
        }

        $submit_email = strtolower( trim( \metis_email_clean( (string) ( $ticket['submit_email'] ?? '' ) ) ) );
        return \metis_email_is_valid( $submit_email ) ? $submit_email : '';
    }

    private static function resolveConversationMailbox( int $ticket_id ): ?array {
        if ( ! class_exists( '\Metis\Modules\CommunicationsInbound\Settings' ) ) {
            return null;
        }

        $context = self::latestConversationContext( $ticket_id );
        $context_mailbox = strtolower( trim( \metis_email_clean( (string) ( $context['mailbox_email'] ?? '' ) ) ) );
        if ( $context_mailbox !== '' ) {
            $mailbox = \Metis\Modules\CommunicationsInbound\Settings::mailboxByEmail( $context_mailbox );
            if ( is_array( $mailbox ) && ! empty( $mailbox['enabled'] ) ) {
                return $mailbox;
            }
        }

        $mailboxes = array_values(
            array_filter(
                \Metis\Modules\CommunicationsInbound\Settings::mailboxes(),
                static fn ( array $row ): bool => ! empty( $row['enabled'] )
            )
        );

        foreach ( $mailboxes as $mailbox ) {
            $email = strtolower( trim( (string) ( $mailbox['mailbox_email'] ?? '' ) ) );
            $name = strtolower( trim( (string) ( $mailbox['display_name'] ?? '' ) ) );
            if ( str_contains( $email, 'grandy' ) || str_contains( $name, 'grandy' ) ) {
                return $mailbox;
            }
        }

        return count( $mailboxes ) === 1 ? $mailboxes[0] : null;
    }

    private static function latestConversationContext( int $ticket_id ): array {
        $db = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_messages' );
        $row = $db->fetchOne(
            "SELECT *
             FROM {$table}
             WHERE ticket_id = %d
             ORDER BY COALESCE(message_at, sent_at, received_at, created_at) DESC, id DESC
             LIMIT 1",
            [ $ticket_id ]
        );

        return is_array( $row ) ? $row : [];
    }

    private static function generateConversationMessageId( string $mailbox_email, int $ticket_id ): string {
        $domain = substr( strrchr( $mailbox_email, '@' ) ?: '', 1 );
        if ( $domain === '' ) {
            $domain = 'localhost';
        }

        return ConversationSupport::normalizeMessageId(
            'grandys-stash-' . $ticket_id . '-' . str_replace( '.', '', uniqid( '', true ) ) . '@' . $domain
        );
    }

    private static function htmlBodyFromPlainText( string $body ): string {
        $escaped = function_exists( 'metis_escape_html' )
            ? metis_escape_html( trim( $body ) )
            : htmlspecialchars( trim( $body ), ENT_QUOTES, 'UTF-8' );
        $escaped = nl2br( $escaped, false );

        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#101828;">' . $escaped . '</div>';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function buildOutboundMimeMessage( array $payload ): string {
        $boundary = 'metis_stash_' . \metis_runtime_generate_password( 18, false, false );
        $subject = trim( (string) ( $payload['subject'] ?? '' ) );
        $from_email = trim( (string) ( $payload['from_email'] ?? '' ) );
        $from_name = trim( (string) ( $payload['from_name'] ?? '' ) );
        $to_email = trim( (string) ( $payload['to_email'] ?? '' ) );
        $text_body = (string) ( $payload['text_body'] ?? '' );
        $html_body = (string) ( $payload['html_body'] ?? '' );
        $message_id = ConversationSupport::normalizeMessageId( (string) ( $payload['message_id'] ?? '' ) );
        $in_reply_to = ConversationSupport::normalizeMessageId( (string) ( $payload['in_reply_to'] ?? '' ) );
        $references_header = trim( (string) ( $payload['references_header'] ?? '' ) );

        $headers = [
            'MIME-Version: 1.0',
            'To: ' . $to_email,
            'Subject: ' . $subject,
            'From: ' . ( $from_name !== '' ? $from_name . ' <' . $from_email . '>' : $from_email ),
            'Reply-To: ' . $from_email,
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        if ( $message_id !== '' ) {
            $headers[] = 'Message-ID: ' . $message_id;
        }
        if ( $in_reply_to !== '' ) {
            $headers[] = 'In-Reply-To: ' . $in_reply_to;
        }
        if ( $references_header !== '' ) {
            $headers[] = 'References: ' . $references_header;
        }

        return implode( "\r\n", $headers ) . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $text_body . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $html_body . "\r\n\r\n"
            . '--' . $boundary . '--';
    }

    private static function createTicketItemsFromPayload( int $ticket_id, string $type, array $payload ): void {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_ticket_items' );

        if ( $type === 'request' ) {
            $repeater_rows = self::normalizeRepeaterTicketItems( $payload['items'] ?? [] );
            foreach ( $repeater_rows as $row ) {
                $item = self::normalizeTicketItemInputRow( $row );
                if ( trim( (string) ( $item['item_name'] ?? '' ) ) !== '' ) {
                    $db->insert( $table, array_merge( [ 'ticket_id' => $ticket_id ], $item ) );
                }
            }

            if ( $repeater_rows === [] ) {
                $category_slug = (string) ( $payload['requested_category'] ?? '' );
                $catalog_slug  = (string) ( $payload['requested_catalog_item'] ?? '' );
                $free_text     = (string) ( $payload['requested_items'] ?? '' );

                // Catalog item selection
                if ( $catalog_slug !== '' ) {
                    $label = self::catalogLabelBySlug( $catalog_slug );
                    $db->insert( $table, [
                        'ticket_id' => $ticket_id,
                        'category'  => $category_slug !== '' ? $category_slug : 'other',
                        'item_name' => $label !== '' ? $label : $catalog_slug,
                        'quantity'  => 1,
                        'status'    => 'pending',
                    ] );
                }

                // Free-text items
                if ( $free_text !== '' ) {
                    $lines = preg_split( '/[\r\n,]+/', $free_text );
                    foreach ( self::normalizeStringList( $lines ?: [] ) as $line ) {
                        $db->insert( $table, [
                            'ticket_id'   => $ticket_id,
                            'category'    => $category_slug !== '' ? $category_slug : 'other',
                            'item_name'   => $line,
                            'description' => null,
                            'quantity'    => 1,
                            'status'      => 'pending',
                        ] );
                    }
                }

                // If nothing was parsed, create a generic line item from the category
                $item_count = (int) $db->scalar( "SELECT COUNT(*) FROM {$table} WHERE ticket_id = %d", [ $ticket_id ] );
                if ( $item_count === 0 && $category_slug !== '' ) {
                    $db->insert( $table, [
                        'ticket_id' => $ticket_id,
                        'category'  => $category_slug,
                        'item_name' => self::catalogCategoryLabelBySlug( $category_slug ) ?: $category_slug,
                        'quantity'  => 1,
                        'status'    => 'pending',
                    ] );
                }
            }
        } else {
            $repeater_rows = self::normalizeRepeaterTicketItems( $payload['items'] ?? [] );
            foreach ( $repeater_rows as $row ) {
                $item = self::normalizeTicketItemInputRow( $row );
                if ( trim( (string) ( $item['item_name'] ?? '' ) ) !== '' ) {
                    $db->insert( $table, array_merge( [ 'ticket_id' => $ticket_id ], $item ) );
                }
            }

            if ( $repeater_rows === [] ) {
                // Donation
                $category_slug = (string) ( $payload['offered_category'] ?? '' );
                $catalog_slug  = (string) ( $payload['offered_catalog_item'] ?? '' );
                $free_text     = (string) ( $payload['offered_items'] ?? '' );
                $condition     = self::normalizeCondition( (string) ( $payload['condition_status'] ?? '' ) );

                if ( $catalog_slug !== '' ) {
                    $label = self::catalogLabelBySlug( $catalog_slug );
                    $db->insert( $table, [
                        'ticket_id'        => $ticket_id,
                        'category'         => $category_slug !== '' ? $category_slug : 'other',
                        'item_name'        => $label !== '' ? $label : $catalog_slug,
                        'condition_status' => $condition,
                        'quantity'         => 1,
                        'status'           => 'pending',
                    ] );
                }

                if ( $free_text !== '' ) {
                    $lines = preg_split( '/[\r\n,]+/', $free_text );
                    foreach ( self::normalizeStringList( $lines ?: [] ) as $line ) {
                        $db->insert( $table, [
                            'ticket_id'        => $ticket_id,
                            'category'         => $category_slug !== '' ? $category_slug : 'other',
                            'item_name'        => $line,
                            'condition_status' => $condition,
                            'quantity'         => 1,
                            'status'           => 'pending',
                        ] );
                    }
                }

                $item_count = (int) $db->scalar( "SELECT COUNT(*) FROM {$table} WHERE ticket_id = %d", [ $ticket_id ] );
                if ( $item_count === 0 ) {
                    $db->insert( $table, [
                        'ticket_id'        => $ticket_id,
                        'category'         => $category_slug !== '' ? $category_slug : 'other',
                        'item_name'        => 'Donated equipment (see notes)',
                        'condition_status' => $condition,
                        'quantity'         => 1,
                        'status'           => 'pending',
                    ] );
                }
            }
        }
    }

    private static function normalizeRepeaterTicketItems( mixed $rows ): array {
        $rows = is_array( $rows ) ? array_values( $rows ) : [];
        $normalized = [];

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $category = trim( (string) ( $row['requested_category'] ?? $row['offered_category'] ?? $row['itemcategory'] ?? $row['category'] ?? '' ) );
            $catalog_slug = trim( (string) ( $row['requested_catalog_item'] ?? $row['offered_catalog_item'] ?? $row['catalog_slug'] ?? '' ) );
            if ( $catalog_slug === '' ) {
                $item_value = trim( (string) ( $row['itemname'] ?? '' ) );
                $catalog_item = self::findCatalogItemRecord( $item_value, self::normalizeCategorySlug( $category ) );
                if ( $catalog_item ) {
                    $catalog_slug = trim( (string) ( $catalog_item['item_slug'] ?? '' ) );
                }
            }
            $item_name = trim( (string) ( $row['item_label'] ?? $row['item_name'] ?? $row['name'] ?? '' ) );
            $description = trim( (string) ( $row['requested_items'] ?? $row['offered_items'] ?? $row['description'] ?? $row['details'] ?? '' ) );
            $condition = trim( (string) ( $row['condition_status'] ?? $row['itemcondition'] ?? $row['condition'] ?? '' ) );
            $quantity = max( 1, (int) ( $row['quantity'] ?? 1 ) );

            if ( $catalog_slug !== '' && $item_name === '' ) {
                $item_name = self::catalogLabelBySlug( $catalog_slug );
            }

            if ( $catalog_slug === '' && $item_name === '' && $description === '' ) {
                continue;
            }

            $normalized[] = [
                'category'    => $category,
                'catalog_slug'=> $catalog_slug,
                'item_name'   => $item_name,
                'description' => $description,
                'condition'   => $condition,
                'quantity'    => $quantity,
            ];
        }

        return $normalized;
    }

    // ─── Assignees ───────────────────────────────────────

    public static function assigneeOptions(): array {
        $db    = self::db();
        $table = \Metis_Tables::get( 'auth_users' );
        $limit = 500;
        $rows  = $db->fetchAll(
            "SELECT id, display_name, user_email FROM {$table} WHERE is_active = 1 ORDER BY display_name ASC LIMIT %d",
            [ $limit ]
        );
        return array_map( static function ( array $row ): array {
            $label = (string) ( $row['display_name'] ?? '' );
            if ( $label === '' ) {
                $label = (string) ( $row['user_email'] ?? '' );
            }
            return [ 'id' => (int) ( $row['id'] ?? 0 ), 'label' => $label ];
        }, $rows );
    }

    public static function routingDefaults(): array {
        return [
            'request_assignee_user_id'  => self::normalizeAssigneeUserId( \Core_Settings_Service::get( self::REQUEST_ASSIGNEE_SETTING, 0 ) ),
            'donation_assignee_user_id' => self::normalizeAssigneeUserId( \Core_Settings_Service::get( self::DONATION_ASSIGNEE_SETTING, 0 ) ),
        ];
    }

    public static function saveRoutingDefaults( array $payload ): array {
        $request_assignee  = self::normalizeAssigneeUserId( $payload['request_assignee_user_id'] ?? 0 );
        $donation_assignee = self::normalizeAssigneeUserId( $payload['donation_assignee_user_id'] ?? 0 );

        \Core_Settings_Service::set( self::REQUEST_ASSIGNEE_SETTING, $request_assignee, true );
        \Core_Settings_Service::set( self::DONATION_ASSIGNEE_SETTING, $donation_assignee, true );

        return [ 'ok' => true, 'routing_defaults' => self::routingDefaults() ];
    }

    // ─── Contact search ──────────────────────────────────

    public static function searchContacts( string $query ): array {
        $db = self::db();

        $contacts_table = \Metis_Tables::get( 'contacts' );
        $details_table  = \Metis_Tables::get( 'contact_details' );
        $like           = '%' . $db->escapeLike( trim( $query ) ) . '%';

        $rows = $db->fetchAll(
            "SELECT c.cid, c.first_name, c.last_name, c.email, MAX(d.phone) AS phone
             FROM {$contacts_table} c
             LEFT JOIN {$details_table} d ON d.contact_cid = c.cid OR d.contact_id = c.id
             WHERE c.first_name LIKE %s
                OR c.last_name LIKE %s
                OR c.email LIKE %s
             GROUP BY c.id
             ORDER BY c.last_name ASC, c.first_name ASC
             LIMIT 12",
            [ $like, $like, $like ]
        );

        return array_map( static function ( array $row ): array {
            return [
                'cid'   => (string) ( $row['cid'] ?? '' ),
                'name'  => trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) ),
                'email' => (string) ( $row['email'] ?? '' ),
                'phone' => (string) ( $row['phone'] ?? '' ),
            ];
        }, $rows );
    }

    // ─── Notes ───────────────────────────────────────────

    public static function addNote( int $ticket_id, string $content ): array {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_notes' );
        $content = trim( \metis_textarea_clean( $content ) );
        if ( $content === '' ) {
            return [ 'ok' => false, 'error' => 'Note content is required.' ];
        }
        $user_id = (int) \metis_current_user_id();
        $db->insert( $table, [
            'ticket_id' => $ticket_id,
            'user_id'   => $user_id,
            'content'   => $content,
        ] );
        self::logActivity( $ticket_id, 'note_added', null, $user_id );
        return [ 'ok' => true ];
    }

    public static function getTicketNotes( int $ticket_id ): array {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_notes' );
        return $db->fetchAll(
            "SELECT n.*, u.display_name AS author_name
             FROM {$table} n
             LEFT JOIN " . \Metis_Tables::get( 'auth_users' ) . " u ON u.id = n.user_id
             WHERE n.ticket_id = %d
             ORDER BY n.created_at ASC",
            [ $ticket_id ]
        );
    }

    public static function getTicketActivity( int $ticket_id ): array {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_activity' );
        return $db->fetchAll(
            "SELECT a.*, u.display_name AS author_name
             FROM {$table} a
             LEFT JOIN " . \Metis_Tables::get( 'auth_users' ) . " u ON u.id = a.user_id
             WHERE a.ticket_id = %d
             ORDER BY a.created_at ASC",
            [ $ticket_id ]
        );
    }


    // ─── Ticket items query ──────────────────────────────

    public static function getTicketItems( int $ticket_id ): array {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_ticket_items' );
        $rows = $db->fetchAll(
            "SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY id ASC",
            [ $ticket_id ]
        );

        return array_map( [ self::class, 'resolveTicketItemRow' ], is_array( $rows ) ? $rows : [] );
    }

    public static function listGroupSummaries(): array {
        $db = self::db();
        $groups_table = \Metis_Tables::get( 'grandys_stash_groups' );
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );

        return $db->fetchAll(
            "SELECT g.id, g.code, g.name, g.email, g.phone, g.notes,
                    COUNT(t.id) AS ticket_count,
                    SUM(CASE WHEN t.status IN ('NEW', 'REVIEWING', 'WAITLIST', 'READY') THEN 1 ELSE 0 END) AS open_count,
                    MAX(t.submitted_at) AS last_ticket_at
             FROM {$groups_table} g
             LEFT JOIN {$tickets_table} t ON t.group_id = g.id
             GROUP BY g.id
             ORDER BY ticket_count DESC, g.updated_at DESC, g.id DESC
             LIMIT 250"
        ) ?: [];
    }

    public static function listOrganizationSummaries(): array {
        $db = self::db();
        $orgs_table = \Metis_Tables::get( 'grandys_stash_organizations' );
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );

        return $db->fetchAll(
            "SELECT o.id, o.code, o.name, o.domain, o.notes, o.is_active,
                    COUNT(t.id) AS ticket_count,
                    SUM(CASE WHEN t.status IN ('NEW', 'REVIEWING', 'WAITLIST', 'READY') THEN 1 ELSE 0 END) AS open_count,
                    MAX(t.submitted_at) AS last_ticket_at
             FROM {$orgs_table} o
             LEFT JOIN {$tickets_table} t ON t.organization_id = o.id
             GROUP BY o.id
             ORDER BY ticket_count DESC, o.updated_at DESC, o.id DESC
             LIMIT 250"
        ) ?: [];
    }

    // ─── Group for ticket ────────────────────────────────

    public static function getOrganizationForTicket( int $ticket_id ): ?array {
        $db = self::db();
        $ticket = self::getTicket( $ticket_id );
        if ( ! $ticket ) {
            return null;
        }

        $organization_id = (int) ( $ticket['organization_id'] ?? 0 );
        if ( $organization_id < 1 ) {
            $domain = self::domainFromEmail( (string) ( $ticket['submit_email'] ?? '' ) );
            if ( $domain === '' ) {
                return null;
            }

            $organization_id = self::findOrCreateOrganization(
                trim( (string) ( $ticket['organization_name'] ?? '' ) ) !== '' ? (string) $ticket['organization_name'] : self::organizationNameFromDomain( $domain ),
                $domain
            );
            if ( $organization_id > 0 ) {
                self::db()->update(
                    \Metis_Tables::get( 'grandys_stash_tickets' ),
                    [
                        'organization_id' => $organization_id,
                        'organization_name' => self::organizationNameFromDomain( $domain ),
                    ],
                    [ 'id' => $ticket_id ]
                );
                $ticket = self::getTicket( $ticket_id ) ?? $ticket;
            }
        }

        if ( $organization_id < 1 ) {
            return null;
        }

        $orgs_table = \Metis_Tables::get( 'grandys_stash_organizations' );
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $organization = $db->fetchOne( "SELECT * FROM {$orgs_table} WHERE id = %d LIMIT 1", [ $organization_id ] );
        if ( ! is_array( $organization ) ) {
            return null;
        }

        $organization['tickets'] = $db->fetchAll(
            "SELECT id, code, type, status, submit_name, submitted_at
             FROM {$tickets_table}
             WHERE organization_id = %d
             ORDER BY submitted_at DESC, id DESC",
            [ $organization_id ]
        ) ?: [];
        $organization['ticket_count'] = count( $organization['tickets'] );

        return $organization;
    }

    public static function getGroupForTicket( int $ticket_id ): ?array {
        $db    = self::db();
        $ticket = self::getTicket( $ticket_id );
        if ( ! $ticket || empty( $ticket['group_id'] ) ) {
            return null;
        }
        $groups_table  = \Metis_Tables::get( 'grandys_stash_groups' );
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $group = $db->fetchOne(
            "SELECT * FROM {$groups_table} WHERE id = %d LIMIT 1",
            [ (int) $ticket['group_id'] ]
        );
        if ( ! $group ) {
            return null;
        }
        $group['tickets'] = $db->fetchAll(
            "SELECT id, code, type, status, submit_name, submitted_at
             FROM {$tickets_table}
             WHERE group_id = %d
             ORDER BY submitted_at DESC",
            [ (int) $group['id'] ]
        );
        $group['ticket_count'] = count( $group['tickets'] );
        return $group;
    }

    // ─── Ticket CRUD ─────────────────────────────────────

    public static function getTicket( int $id ): ?array {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $row   = $db->fetchOne( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", [ $id ] );
        return $row ?: null;
    }

    public static function getTicketDetailData( int $ticket_id ): ?array {
        $ticket = self::getTicket( $ticket_id );
        if ( ! $ticket ) {
            return null;
        }

        return [
            'ticket'   => $ticket,
            'items'    => self::getTicketItems( $ticket_id ),
            'messages' => self::getTicketMessages( $ticket_id ),
            'notes'    => self::getTicketNotes( $ticket_id ),
            'activity' => self::getTicketActivity( $ticket_id ),
            'organization' => self::getOrganizationForTicket( $ticket_id ),
            'group'    => self::getGroupForTicket( $ticket_id ),
        ];
    }

    public static function saveTicket( array $payload ): array {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $id    = (int) ( $payload['id'] ?? 0 );

        $existing = $id > 0 ? self::getTicket( $id ) : null;
        if ( ! $existing ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Ticket not found.' ];
        }

        $old_status = (string) ( $existing['status'] ?? 'NEW' );
        $new_status = self::normalizeStatus( (string) ( $payload['status'] ?? $old_status ) );
        $assignee_id = self::normalizeAssigneeUserId( $payload['assigned_to'] ?? $existing['assigned_to'] ?? 0 );

        $row = [
            'status'          => $new_status,
            'assigned_to'     => $assignee_id > 0 ? $assignee_id : null,
            'assigned_name'   => self::resolveAssigneeName( $assignee_id ),
            'urgency'         => self::normalizeUrgency( (string) ( $payload['urgency'] ?? $existing['urgency'] ?? 'standard' ) ),
            'pickup_delivery' => self::normalizePickupDelivery( (string) ( $payload['pickup_delivery'] ?? $existing['pickup_delivery'] ?? '' ) ) ?: null,
            'closed_at'       => in_array( $new_status, ['COMPLETED', 'CLOSED'], true ) ? \gmdate( 'Y-m-d H:i:s' ) : null,
        ];

        $db->update( $table, $row, [ 'id' => $id ] );

        if ( $old_status !== $new_status ) {
            self::logActivity( $id, 'status_changed', "Status changed from {$old_status} to {$new_status}." );
        }
        if ( (int) ( $existing['assigned_to'] ?? 0 ) !== $assignee_id && $assignee_id > 0 ) {
            self::logActivity( $id, 'assigned', 'Assigned to ' . ( self::resolveAssigneeName( $assignee_id ) ?? 'user #' . $assignee_id ) . '.' );
        }

        return [ 'ok' => true, 'ticket' => self::getTicket( $id ) ];
    }

    public static function deleteTicket( int $ticket_id ): array {
        $ticket = self::getTicket( $ticket_id );
        if ( ! $ticket ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Ticket not found.' ];
        }

        $db = self::db();
        $db->delete( \Metis_Tables::get( 'grandys_stash_ticket_items' ), [ 'ticket_id' => $ticket_id ] );
        $db->delete( \Metis_Tables::get( 'grandys_stash_notes' ), [ 'ticket_id' => $ticket_id ] );
        $db->delete( \Metis_Tables::get( 'grandys_stash_activity' ), [ 'ticket_id' => $ticket_id ] );
        $db->delete( \Metis_Tables::get( 'grandys_stash_messages' ), [ 'ticket_id' => $ticket_id ] );
        $db->delete( \Metis_Tables::get( 'grandys_stash_tickets' ), [ 'id' => $ticket_id ] );

        return [ 'ok' => true, 'deleted_code' => (string) ( $ticket['code'] ?? '' ) ];
    }

    public static function saveOrganization( array $payload ): array {
        $db = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_organizations' );
        $id = (int) ( $payload['id'] ?? 0 );
        $name = trim( \metis_text_clean( (string) ( $payload['name'] ?? '' ) ) );
        $domain = self::normalizeDomain( (string) ( $payload['domain'] ?? '' ) );
        $notes = self::nullableTextArea( $payload['notes'] ?? '' );
        $is_active = ! isset( $payload['is_active'] ) || (string) $payload['is_active'] !== '0';

        if ( $name === '' && $domain === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Organization name or domain is required.' ];
        }

        if ( $name === '' ) {
            $name = self::organizationNameFromDomain( $domain );
        }

        $row = [
            'name' => $name,
            'domain' => $domain !== '' ? $domain : null,
            'notes' => $notes,
            'is_active' => $is_active ? 1 : 0,
        ];
        if ( $id > 0 ) {
            $db->update( $table, $row, [ 'id' => $id ] );
        } else {
            $row['code'] = self::generateCode( 'GSO', $table, 'code' );
            $db->insert( $table, $row );
            $id = (int) $db->lastInsertId();
        }

        return [ 'ok' => true, 'organization_id' => $id ];
    }

    public static function saveGroup( array $payload ): array {
        $db = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_groups' );
        $id = (int) ( $payload['id'] ?? 0 );
        if ( $id < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Group ID is required.' ];
        }

        $row = [
            'name' => trim( \metis_text_clean( (string) ( $payload['name'] ?? '' ) ) ),
            'email' => self::nullableText( $payload['email'] ?? '' ),
            'phone' => self::nullableText( $payload['phone'] ?? '' ),
            'notes' => self::nullableTextArea( $payload['notes'] ?? '' ),
        ];
        if ( $row['name'] === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Group name is required.' ];
        }

        $db->update( $table, $row, [ 'id' => $id ] );
        return [ 'ok' => true, 'group_id' => $id ];
    }

    // ─── Ticket items CRUD ───────────────────────────────

    public static function updateTicketItemStatus( int $item_id, string $status ): array {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_ticket_items' );
        $item  = $db->fetchOne( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", [ $item_id ] );
        if ( ! $item ) {
            return [ 'ok' => false, 'error' => 'Item not found.' ];
        }

        $status = self::normalizeItemStatus( $status );
        $update = [ 'status' => $status ];

        if ( $status === 'unavailable' && empty( $item['waitlist_at'] ) ) {
            $update['waitlist_at'] = \gmdate( 'Y-m-d H:i:s' );
        }
        if ( $status === 'fulfilled' ) {
            $update['fulfilled_at'] = \gmdate( 'Y-m-d H:i:s' );
        }

        $db->update( $table, $update, [ 'id' => $item_id ] );

        $ticket_id = (int) $item['ticket_id'];
        self::logActivity( $ticket_id, 'item_' . $status, 'Item "' . ( $item['item_name'] ?? '' ) . '" marked ' . $status . '.' );

        // Auto-update ticket status based on item statuses
        self::recalculateTicketStatus( $ticket_id );

        return [ 'ok' => true ];
    }

    private static function recalculateTicketStatus( int $ticket_id ): void {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_ticket_items' );
        $ticket_table = \Metis_Tables::get( 'grandys_stash_tickets' );

        $ticket = self::getTicket( $ticket_id );
        if ( ! $ticket || in_array( $ticket['status'], ['COMPLETED', 'CLOSED'], true ) ) {
            return;
        }

        $counts = $db->fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'fulfilled' THEN 1 ELSE 0 END) AS fulfilled,
                    SUM(CASE WHEN status = 'unavailable' THEN 1 ELSE 0 END) AS unavailable,
                    SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available
             FROM {$table} WHERE ticket_id = %d",
            [ $ticket_id ]
        );

        $total       = (int) ( $counts['total'] ?? 0 );
        $fulfilled   = (int) ( $counts['fulfilled'] ?? 0 );
        $unavailable = (int) ( $counts['unavailable'] ?? 0 );
        $available   = (int) ( $counts['available'] ?? 0 );

        $new_status = null;
        if ( $total > 0 && $fulfilled === $total ) {
            $new_status = 'COMPLETED';
        } elseif ( $unavailable === $total ) {
            $new_status = 'WAITLIST';
        } elseif ( $available > 0 ) {
            $new_status = 'READY';
        }

        if ( $new_status && $new_status !== $ticket['status'] ) {
            $old = $ticket['status'];
            $update = [ 'status' => $new_status ];
            if ( $new_status === 'COMPLETED' ) {
                $update['closed_at'] = \gmdate( 'Y-m-d H:i:s' );
            }
            $db->update( $ticket_table, $update, [ 'id' => $ticket_id ] );
            self::logActivity( $ticket_id, 'status_changed', "Auto-updated from {$old} to {$new_status} based on item statuses." );
        }
    }




    // ─── Reporting ───────────────────────────────────────

    public static function reportData( string $from = '', string $to = '' ): array {
        $db      = self::db();
        $tickets = \Metis_Tables::get( 'grandys_stash_tickets' );
        $items   = \Metis_Tables::get( 'grandys_stash_ticket_items' );
        $groups  = \Metis_Tables::get( 'grandys_stash_groups' );
        $organizations = \Metis_Tables::get( 'grandys_stash_organizations' );

        $where = "1=1";
        $params = [];
        if ( $from !== '' ) {
            $where .= " AND t.submitted_at >= %s";
            $params[] = $from . ' 00:00:00';
        }
        if ( $to !== '' ) {
            $where .= " AND t.submitted_at <= %s";
            $params[] = $to . ' 23:59:59';
        }

        // Summary counts
        $summary = $db->fetchOne(
            "SELECT COUNT(*) AS total_tickets,
                    SUM(CASE WHEN t.type = 'request' THEN 1 ELSE 0 END) AS total_requests,
                    SUM(CASE WHEN t.type = 'donation' THEN 1 ELSE 0 END) AS total_donations,
                    SUM(CASE WHEN t.status = 'COMPLETED' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN t.status = 'CLOSED' THEN 1 ELSE 0 END) AS closed,
                    SUM(CASE WHEN t.status IN ('NEW','REVIEWING','WAITLIST','READY') THEN 1 ELSE 0 END) AS open_tickets
             FROM {$tickets} t
             WHERE {$where}",
            $params
        ) ?: [];

        // Unique people served
        $people_served = (int) $db->scalar(
            "SELECT COUNT(DISTINCT t.group_id) FROM {$tickets} t WHERE t.group_id IS NOT NULL AND {$where}",
            $params
        );

        // Items fulfilled
        $items_fulfilled = (int) $db->scalar(
            "SELECT COUNT(*)
             FROM {$items} i
             INNER JOIN {$tickets} t ON t.id = i.ticket_id
             WHERE i.status = 'fulfilled' AND {$where}",
            $params
        );

        // Items by category
        $by_category = $db->fetchAll(
            "SELECT i.category,
                    COUNT(*) AS item_count,
                    SUM(CASE WHEN i.status = 'fulfilled' THEN 1 ELSE 0 END) AS fulfilled
             FROM {$items} i
             INNER JOIN {$tickets} t ON t.id = i.ticket_id
             WHERE {$where}
             GROUP BY i.category
             ORDER BY item_count DESC",
            $params
        ) ?: [];

        // Monthly breakdown
        $monthly = $db->fetchAll(
            "SELECT DATE_FORMAT(t.submitted_at, '%Y-%m') AS month,
                    DATE_FORMAT(t.submitted_at, '%b %Y') AS month_label,
                    COUNT(*) AS tickets,
                    SUM(CASE WHEN t.type = 'request' THEN 1 ELSE 0 END) AS requests,
                    SUM(CASE WHEN t.type = 'donation' THEN 1 ELSE 0 END) AS donations,
                    SUM(CASE WHEN t.status = 'COMPLETED' THEN 1 ELSE 0 END) AS completed
             FROM {$tickets} t
             WHERE {$where}
             GROUP BY DATE_FORMAT(t.submitted_at, '%%Y-%%m')
             ORDER BY month DESC
             LIMIT 24",
            $params
        ) ?: [];

        $by_organization = $db->fetchAll(
            "SELECT COALESCE(NULLIF(t.organization_name, ''), o.name, 'Independent') AS organization_name,
                    COALESCE(NULLIF(o.domain, ''), '') AS organization_domain,
                    COUNT(*) AS ticket_count,
                    SUM(CASE WHEN t.type = 'request' THEN 1 ELSE 0 END) AS request_count,
                    SUM(CASE WHEN t.type = 'donation' THEN 1 ELSE 0 END) AS donation_count
             FROM {$tickets} t
             LEFT JOIN {$organizations} o ON o.id = t.organization_id
             WHERE {$where}
             GROUP BY COALESCE(t.organization_id, 0), organization_name, organization_domain
             ORDER BY request_count DESC, ticket_count DESC, organization_name ASC
             LIMIT 50",
            $params
        ) ?: [];

        $by_person = $db->fetchAll(
            "SELECT COALESCE(NULLIF(g.name, ''), NULLIF(t.submit_name, ''), 'Unknown') AS person_name,
                    COALESCE(NULLIF(g.email, ''), NULLIF(t.submit_email, ''), '') AS person_email,
                    COUNT(*) AS ticket_count,
                    SUM(CASE WHEN t.type = 'request' THEN 1 ELSE 0 END) AS request_count,
                    SUM(CASE WHEN t.type = 'donation' THEN 1 ELSE 0 END) AS donation_count
             FROM {$tickets} t
             LEFT JOIN {$groups} g ON g.id = t.group_id
             WHERE {$where}
             GROUP BY COALESCE(t.group_id, 0), person_name, person_email
             ORDER BY request_count DESC, ticket_count DESC, person_name ASC
             LIMIT 50",
            $params
        ) ?: [];

        $by_equipment = $db->fetchAll(
            "SELECT COALESCE(NULLIF(i.item_name, ''), NULLIF(i.description, ''), 'Other') AS equipment_name,
                    COALESCE(NULLIF(i.category, ''), 'other') AS category,
                    SUM(CASE WHEN t.type = 'request' THEN i.quantity ELSE 0 END) AS request_quantity,
                    SUM(CASE WHEN t.type = 'donation' THEN i.quantity ELSE 0 END) AS donation_quantity,
                    SUM(i.quantity) AS total_quantity,
                    SUM(CASE WHEN i.status = 'fulfilled' THEN i.quantity ELSE 0 END) AS fulfilled_quantity
             FROM {$items} i
             INNER JOIN {$tickets} t ON t.id = i.ticket_id
             WHERE {$where}
             GROUP BY equipment_name, category
             ORDER BY request_quantity DESC, total_quantity DESC, equipment_name ASC
             LIMIT 100",
            $params
        ) ?: [];

        // Urgency breakdown
        $by_urgency = $db->fetchAll(
            "SELECT t.urgency, COUNT(*) AS count
             FROM {$tickets} t
             WHERE {$where}
             GROUP BY t.urgency
             ORDER BY FIELD(t.urgency, 'urgent', 'standard', 'flexible')",
            $params
        ) ?: [];

        // Source breakdown
        $by_source = $db->fetchAll(
            "SELECT t.source, COUNT(*) AS count
             FROM {$tickets} t
             WHERE {$where}
             GROUP BY t.source
             ORDER BY count DESC",
            $params
        ) ?: [];

        // Average time to completion (days)
        $avg_completion = $db->fetchOne(
            "SELECT AVG(DATEDIFF(t.closed_at, t.submitted_at)) AS avg_days
             FROM {$tickets} t
             WHERE t.status = 'COMPLETED'
               AND t.closed_at IS NOT NULL
               AND {$where}",
            $params
        );

        return [
            'summary'         => $summary,
            'people_served'   => $people_served,
            'items_fulfilled' => $items_fulfilled,
            'by_category'     => $by_category,
            'monthly'         => $monthly,
            'by_urgency'      => $by_urgency,
            'by_source'       => $by_source,
            'by_organization' => $by_organization,
            'by_person'       => $by_person,
            'by_equipment'    => $by_equipment,
            'avg_days_to_complete' => round( (float) ( $avg_completion['avg_days'] ?? 0 ), 1 ),
        ];
    }

    // ─── Email preferences ──────────────────────────────

    public static function getEmailPrefs(): array {
        $db    = self::db();
        $prefs = \Metis_Tables::get( 'grandys_stash_email_prefs' );
        $auth  = \Metis_Tables::get( 'auth_users' );

        return $db->fetchAll(
            "SELECT u.id AS user_id, u.display_name, u.user_email,
                    COALESCE(p.receive_grandys_summary, 0) AS receive_grandys_summary
             FROM {$auth} u
             LEFT JOIN {$prefs} p ON p.user_id = u.id
             WHERE u.is_active = 1
             ORDER BY u.display_name ASC"
        ) ?: [];
    }

    public static function setEmailPref( int $user_id, bool $enabled ): array {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_email_prefs' );

        $existing = (int) $db->scalar(
            "SELECT user_id FROM {$table} WHERE user_id = %d LIMIT 1",
            [ $user_id ]
        );

        if ( $existing > 0 ) {
            $db->update( $table, [ 'receive_grandys_summary' => $enabled ? 1 : 0 ], [ 'user_id' => $user_id ] );
        } else {
            $db->insert( $table, [ 'user_id' => $user_id, 'receive_grandys_summary' => $enabled ? 1 : 0 ] );
        }

        return [ 'ok' => true ];
    }

    // ─── Manual ticket creation ──────────────────────────

    public static function createTicket( array $payload ): array {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $items_table = \Metis_Tables::get( 'grandys_stash_ticket_items' );

        $type = in_array( (string) ( $payload['type'] ?? '' ), ['request', 'donation'], true )
            ? (string) $payload['type']
            : 'request';

        $first_name = trim( \metis_text_clean( (string) ( $payload['first_name'] ?? '' ) ) );
        $last_name  = trim( \metis_text_clean( (string) ( $payload['last_name'] ?? '' ) ) );
        $email      = strtolower( trim( \metis_email_clean( (string) ( $payload['email'] ?? '' ) ) ) );
        $phone      = trim( \metis_text_clean( (string) ( $payload['phone'] ?? '' ) ) );
        $name       = trim( $first_name . ' ' . $last_name );
        $organization_name = trim( \metis_text_clean( (string) ( $payload['organization_name'] ?? '' ) ) );
        $organization_domain = self::normalizeDomain( (string) ( $payload['organization_domain'] ?? '' ) );
        if ( $organization_domain === '' ) {
            $organization_domain = self::domainFromEmail( $email );
        }
        if ( $organization_name === '' ) {
            $organization_name = self::organizationNameFromDomain( $organization_domain );
        }

        if ( $name === '' && $email === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Name or email is required.' ];
        }

        $contact_cid = self::upsertContactFromPayload( [
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'email'      => $email,
            'phone'      => $phone,
        ], '' );

        $group_id = self::findOrCreateGroup(
            $name !== '' ? $name : $email,
            $email,
            $phone,
            $contact_cid
        );
        $organization_id = self::findOrCreateOrganization( $organization_name, $organization_domain );

        $assignee_id = self::normalizeAssigneeUserId( $payload['assigned_to'] ?? 0 );

        $db->insert( $table, [
            'code'            => self::generateCode( 'GST', $table, 'code' ),
            'group_id'        => $group_id > 0 ? $group_id : null,
            'type'            => $type,
            'status'          => 'NEW',
            'assigned_to'     => $assignee_id > 0 ? $assignee_id : null,
            'assigned_name'   => self::resolveAssigneeName( $assignee_id ),
            'source'          => \metis_key_clean( (string) ( $payload['source'] ?? 'staff' ) ) ?: 'staff',
            'urgency'         => self::normalizeUrgency( (string) ( $payload['urgency'] ?? 'standard' ) ),
            'pickup_delivery' => self::normalizePickupDelivery( (string) ( $payload['pickup_delivery'] ?? '' ) ) ?: null,
            'submit_name'     => $name !== '' ? $name : 'Unknown',
            'submit_email'    => $email !== '' ? $email : null,
            'submit_phone'    => $phone !== '' ? $phone : null,
            'organization_id' => $organization_id > 0 ? $organization_id : null,
            'organization_name'=> $organization_name !== '' ? $organization_name : null,
            'submit_notes'    => self::nullableTextArea( $payload['notes'] ?? '' ),
        ] );

        $ticket_id = $db->lastInsertId();

        $items_text = trim( (string) ( $payload['items'] ?? '' ) );
        if ( $items_text !== '' ) {
            $lines = preg_split( '/[\r\n,]+/', $items_text );
            foreach ( self::normalizeStringList( $lines ?: [] ) as $line ) {
                $db->insert( $items_table, [
                    'ticket_id' => $ticket_id,
                    'category'  => 'other',
                    'item_name' => $line,
                    'quantity'  => 1,
                    'status'    => 'pending',
                ] );
            }
        }

        self::logActivity( $ticket_id, 'created', 'Ticket created manually by staff.' );
        if ( $group_id > 0 ) {
            self::logActivity( $ticket_id, 'grouped', 'Auto-grouped by ' . ( $email !== '' ? 'email' : ( $phone !== '' ? 'phone' : 'name' ) ) . '.' );
        }

        return [ 'ok' => true, 'ticket_id' => $ticket_id ];
    }

    public static function createTicketFromFormSubmission( string $flow, array $payload, int $form_id, int $form_submission_id ): array {
        self::ensureModuleReady();

        $db      = self::db();
        $tickets = \Metis_Tables::get( 'grandys_stash_tickets' );
        $type    = $flow === 'donation' ? 'donation' : 'request';
        $first_name  = trim( \metis_text_clean( (string) ( $payload['first_name'] ?? '' ) ) );
        $last_name   = trim( \metis_text_clean( (string) ( $payload['last_name'] ?? '' ) ) );
        $email       = strtolower( trim( \metis_email_clean( (string) ( $payload['email'] ?? '' ) ) ) );
        $phone       = trim( \metis_text_clean( (string) ( $payload['phone'] ?? '' ) ) );
        $name        = trim( $first_name . ' ' . $last_name );
        $organization_name = trim( \metis_text_clean( (string) ( $payload['organization_name'] ?? '' ) ) );
        $organization_domain = self::normalizeDomain( (string) ( $payload['organization_domain'] ?? '' ) );
        if ( $organization_domain === '' ) {
            $organization_domain = self::domainFromEmail( $email );
        }
        if ( $organization_name === '' ) {
            $organization_name = self::organizationNameFromDomain( $organization_domain );
        }

        if ( $name === '' && $email === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Name or email is required.' ];
        }

        $existing = $db->scalar(
            "SELECT id FROM {$tickets} WHERE form_submission_id = %d LIMIT 1",
            [ $form_submission_id ]
        );
        if ( (int) $existing > 0 ) {
            return [ 'ok' => true, 'ticket_id' => (int) $existing ];
        }

        $contact_cid = self::upsertContactFromPayload( [
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'email'      => $email,
            'phone'      => $phone,
        ], '' );

        $group_id = self::findOrCreateGroup(
            $name !== '' ? $name : $email,
            $email,
            $phone,
            $contact_cid
        );
        $organization_id = self::findOrCreateOrganization( $organization_name, $organization_domain );

        $assignee_id = self::defaultAssigneeUserId( $type );
        $db->insert( $tickets, [
            'code'               => self::generateCode( 'GST', $tickets, 'code' ),
            'group_id'           => $group_id > 0 ? $group_id : null,
            'type'               => $type,
            'status'             => 'NEW',
            'assigned_to'        => $assignee_id > 0 ? $assignee_id : null,
            'assigned_name'      => self::resolveAssigneeName( $assignee_id ),
            'source'             => 'web',
            'urgency'            => self::normalizeUrgency( (string) ( $payload['urgency'] ?? 'standard' ) ),
            'pickup_delivery'    => self::normalizePickupDelivery( (string) ( $payload['pickup_delivery'] ?? '' ) ) ?: null,
            'submit_name'        => $name !== '' ? $name : ( $email !== '' ? $email : 'Unknown' ),
            'submit_email'       => $email !== '' ? $email : null,
            'submit_phone'       => $phone !== '' ? $phone : null,
            'organization_id'    => $organization_id > 0 ? $organization_id : null,
            'organization_name'  => $organization_name !== '' ? $organization_name : null,
            'submit_notes'       => self::nullableTextArea( $payload['notes'] ?? '' ),
            'form_id'            => $form_id > 0 ? $form_id : null,
            'form_submission_id' => $form_submission_id > 0 ? $form_submission_id : null,
        ] );

        $ticket_id = $db->lastInsertId();
        self::createTicketItemsFromPayload( $ticket_id, $type, $payload );

        self::logActivity( $ticket_id, 'created', 'Ticket created from form submission.', null );
        if ( $group_id > 0 ) {
            self::logActivity( $ticket_id, 'grouped', 'Auto-grouped by ' . ( $email !== '' ? 'email' : ( $phone !== '' ? 'phone' : 'name' ) ) . '.', null );
        }

        return [ 'ok' => true, 'ticket_id' => $ticket_id ];
    }

    // ─── Dashboard data (stub — Phase 6) ─────────────────

    public static function dashboardData(): array {
        self::ensureModuleReady();
        return [
            'stats'            => self::stats(),
            'catalog'          => self::catalogSummary(),
            'assignees'        => self::assigneeOptions(),
            'routing_defaults' => self::routingDefaults(),
            'tickets'          => self::listTickets(),
            'groups'           => self::listGroupSummaries(),
            'organizations'    => self::listOrganizationSummaries(),
        ];
    }

    private static function stats(): array {
        $db = self::db();
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $items_table   = \Metis_Tables::get( 'grandys_stash_ticket_items' );

        $ticket_stats = $db->fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'NEW' THEN 1 ELSE 0 END) AS new_count,
                    SUM(CASE WHEN status = 'REVIEWING' THEN 1 ELSE 0 END) AS reviewing_count,
                    SUM(CASE WHEN status = 'WAITLIST' THEN 1 ELSE 0 END) AS waitlist_count,
                    SUM(CASE WHEN status = 'READY' THEN 1 ELSE 0 END) AS ready_count,
                    SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN type = 'request' THEN 1 ELSE 0 END) AS requests,
                    SUM(CASE WHEN type = 'donation' THEN 1 ELSE 0 END) AS donations
             FROM {$tickets_table}"
        ) ?: [];

        $item_stats = $db->fetchOne(
            "SELECT SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_items,
                    SUM(CASE WHEN status = 'unavailable' THEN 1 ELSE 0 END) AS waitlist_items
             FROM {$items_table}"
        ) ?: [];

        return [
            'total_tickets'   => (int) ( $ticket_stats['total'] ?? 0 ),
            'new_tickets'     => (int) ( $ticket_stats['new_count'] ?? 0 ),
            'reviewing'       => (int) ( $ticket_stats['reviewing_count'] ?? 0 ),
            'waitlist'        => (int) ( $ticket_stats['waitlist_count'] ?? 0 ),
            'ready'           => (int) ( $ticket_stats['ready_count'] ?? 0 ),
            'completed'       => (int) ( $ticket_stats['completed_count'] ?? 0 ),
            'requests'        => (int) ( $ticket_stats['requests'] ?? 0 ),
            'donations'       => (int) ( $ticket_stats['donations'] ?? 0 ),
            'pending_items'   => (int) ( $item_stats['pending_items'] ?? 0 ),
            'waitlist_items'  => (int) ( $item_stats['waitlist_items'] ?? 0 ),
        ];
    }

    private static function listTickets(): array {
        $db    = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $groups_table = \Metis_Tables::get( 'grandys_stash_groups' );
        $items_table  = \Metis_Tables::get( 'grandys_stash_ticket_items' );

        return $db->fetchAll(
            "SELECT t.*,
                    g.name AS group_name,
                    g.code AS group_code,
                    o.name AS organization_label,
                    o.code AS organization_code,
                    o.domain AS organization_domain,
                    (SELECT GROUP_CONCAT(ti.item_name SEPARATOR ', ')
                     FROM {$items_table} ti WHERE ti.ticket_id = t.id LIMIT 5) AS items_summary,
                    (SELECT COUNT(*) FROM {$items_table} ti2 WHERE ti2.ticket_id = t.id) AS item_count
             FROM {$table} t
             LEFT JOIN {$groups_table} g ON g.id = t.group_id
             LEFT JOIN " . \Metis_Tables::get( 'grandys_stash_organizations' ) . " o ON o.id = t.organization_id
             ORDER BY FIELD(t.status, 'NEW', 'REVIEWING', 'WAITLIST', 'READY', 'COMPLETED', 'CLOSED'),
                      t.submitted_at DESC, t.id DESC
             LIMIT 200"
        );
    }

}

\class_alias( __NAMESPACE__ . '\\GrandyStashRepository', 'Metis\\Modules\\GrandyStashRepository' );
