<?php
declare(strict_types=1);

namespace Metis\Modules\GrandyStash;

final class GrandyStashRepository {

    // Settings keys
    private const REQUEST_ASSIGNEE_SETTING  = 'grandys_stash_default_request_assignee_user_id';
    private const DONATION_ASSIGNEE_SETTING = 'grandys_stash_default_donation_assignee_user_id';
    private const LEGACY_IMPORT_URL_SETTING = 'grandys_stash_legacy_import_url';
    private const LEGACY_IMPORT_SECRET_SETTING = 'grandys_stash_legacy_import_secret';
    private const PUBLIC_EMAIL_DOMAINS = [
        'gmail.com',
        'googlemail.com',
        'yahoo.com',
        'outlook.com',
        'hotmail.com',
        'live.com',
        'ymail.com',
        'rocketmail.com',
        'icloud.com',
        'me.com',
        'aol.com',
        'msn.com',
        'comcast.net',
        'att.net',
        'sbcglobal.net',
        'verizon.net',
        'mail.com',
        'gmx.com',
        'pm.me',
        'proton.me',
        'protonmail.com',
    ];
    private const LEGACY_FORM_CHILD_OVERRIDES = [
        17 => [
            'donation_child_form_id' => 18,
            'request_child_form_id' => 20,
        ],
    ];
    private const ITEM_MATCH_DROP_WORDS = [
        'adult',
        'adults',
        'child',
        'children',
        'kid',
        'kids',
        'male',
        'female',
        'men',
        'mens',
        'women',
        'womens',
        'woman',
        'man',
        'youth',
        'size',
        'other',
        'misc',
        'miscellaneous',
    ];
    private const LEGACY_ITEM_PREFIX_KEYWORDS = [
        'cpap',
        'wheelchair',
        'walker',
        'shower',
        'bed',
        'catheter',
        'urinary drainage bag',
        'drainage bag',
        'incontinence',
        'depends',
        'brief',
        'briefs',
        'pull up',
        'pull-up',
        'underwear',
        'pad',
        'pads',
        'wipe',
        'wipes',
        'glove',
        'mask',
        'tubing',
        'headgear',
        'headset',
        'syringe',
        'ostomy',
        'urostomy',
        'colostomy',
        'toilet',
        'commode',
        'transfer',
        'cane',
        'rollator',
    ];
    private const REQUIRED_CATALOG_ITEMS = [
        [ 'item_name' => 'Adult Diapers', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Adult Briefs - Small', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Adult Briefs - Medium', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Adult Briefs - Large', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Adult Briefs - X-Large', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Adult Briefs - 2X-Large', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Adult Pull-Ups', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Adult Pull-Ups - Medium', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Adult Pull-Ups - Large', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Adult Pull-Ups - X-Large', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Adult Pull-Ups - Small/Medium', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Adult Pull-Ups - Large/X-Large', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Disposable Underpads', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Disposable Underpads - Standard', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Disposable Underpads - Extra Large', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Disposable Underpads - Heavy Absorbency', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Reusable Bed Pads', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Reusable Bed Pads - Washable', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Incontinence Wipes', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Booster Pads', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Bladder Control Pads', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Protective Underwear - Medium', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Protective Underwear - Large', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Protective Underwear - X-Large', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Protective Liners - Men', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Protective Liners - Women', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Protective Liners', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Men Protective Shields', 'category_name' => 'Incontinence Supplies' ],
        [ 'item_name' => 'Wheelchair - Manual 18in Seat', 'category_name' => 'Wheelchair' ],
        [ 'item_name' => 'Wheelchair - Manual 20in Seat', 'category_name' => 'Wheelchair' ],
        [ 'item_name' => 'Wheelchair - Manual 22in Seat', 'category_name' => 'Wheelchair' ],
        [ 'item_name' => 'Wheelchair - Manual 24in Seat', 'category_name' => 'Wheelchair' ],
        [ 'item_name' => 'Wheelchair - Standard', 'category_name' => 'Wheelchair' ],
        [ 'item_name' => 'Wheelchair - Transport', 'category_name' => 'Wheelchair' ],
        [ 'item_name' => 'Wheelchair - Reclining Back', 'category_name' => 'Wheelchair' ],
        [ 'item_name' => 'Wheelchair - Pediatric', 'category_name' => 'Wheelchair' ],
        [ 'item_name' => 'Wheelchair - Bariatric 22in Seat', 'category_name' => 'Wheelchair' ],
        [ 'item_name' => 'Wheelchair - Bariatric 24in Seat', 'category_name' => 'Wheelchair' ],
        [ 'item_name' => 'Wheelchair - Power', 'category_name' => 'Wheelchair' ],
        [ 'item_name' => 'Wheelchair Accessory - Elevating Leg Rests', 'category_name' => 'Wheelchair' ],
        [ 'item_name' => 'Wheelchair Accessory - Footrests', 'category_name' => 'Wheelchair' ],
        [ 'item_name' => 'Wheelchair Cushion', 'category_name' => 'Wheelchair' ],
        [ 'item_name' => 'Lift Chair', 'category_name' => 'Seating Support' ],
        [ 'item_name' => 'Mobility Scooter', 'category_name' => 'Scooter' ],
        [ 'item_name' => 'Standing Frame', 'category_name' => 'Therapy Equipment' ],
        [ 'item_name' => 'Gait Belt', 'category_name' => 'Transfer Equipment' ],
        [ 'item_name' => 'Slide Sheet', 'category_name' => 'Transfer Equipment' ],
        [ 'item_name' => 'Patient Lift', 'category_name' => 'Transfer Equipment' ],
        [ 'item_name' => 'Transfer Board', 'category_name' => 'Transfer Equipment' ],
        [ 'item_name' => 'Walker - Standard', 'category_name' => 'Walker' ],
        [ 'item_name' => 'Walker - Front Wheeled', 'category_name' => 'Walker' ],
        [ 'item_name' => 'Walker - Hemi', 'category_name' => 'Walker' ],
        [ 'item_name' => 'Walker - Rollator with Seat', 'category_name' => 'Walker' ],
        [ 'item_name' => 'Walker - Platform', 'category_name' => 'Walker' ],
        [ 'item_name' => 'Walker - Bariatric Front Wheeled', 'category_name' => 'Walker' ],
        [ 'item_name' => 'Cane - Standard', 'category_name' => 'Cane' ],
        [ 'item_name' => 'Cane - Quad Small Base', 'category_name' => 'Cane' ],
        [ 'item_name' => 'Cane - Quad Large Base', 'category_name' => 'Cane' ],
        [ 'item_name' => 'Cane - Seat Cane', 'category_name' => 'Cane' ],
        [ 'item_name' => 'Crutches - Pair', 'category_name' => 'Crutches' ],
        [ 'item_name' => 'Disposable Gloves', 'category_name' => 'Personal Protection Equipment' ],
        [ 'item_name' => 'Disposable Gloves - Small', 'category_name' => 'Personal Protection Equipment' ],
        [ 'item_name' => 'Disposable Gloves - Medium', 'category_name' => 'Personal Protection Equipment' ],
        [ 'item_name' => 'Disposable Gloves - Large', 'category_name' => 'Personal Protection Equipment' ],
        [ 'item_name' => 'Disposable Gloves - X-Large', 'category_name' => 'Personal Protection Equipment' ],
        [ 'item_name' => 'Nitrile Gloves', 'category_name' => 'Personal Protection Equipment' ],
        [ 'item_name' => 'Nitrile Gloves - Small', 'category_name' => 'Personal Protection Equipment' ],
        [ 'item_name' => 'Nitrile Gloves - Medium', 'category_name' => 'Personal Protection Equipment' ],
        [ 'item_name' => 'Nitrile Gloves - Large', 'category_name' => 'Personal Protection Equipment' ],
        [ 'item_name' => 'Nitrile Gloves - X-Large', 'category_name' => 'Personal Protection Equipment' ],
        [ 'item_name' => 'Procedure Masks', 'category_name' => 'Personal Protection Equipment' ],
        [ 'item_name' => 'N95 Masks', 'category_name' => 'Personal Protection Equipment' ],
        [ 'item_name' => 'Face Shields', 'category_name' => 'Personal Protection Equipment' ],
        [ 'item_name' => 'Protective Gowns', 'category_name' => 'Personal Protection Equipment' ],
        [ 'item_name' => 'Hand Sanitizer', 'category_name' => 'Personal Protection Equipment' ],
        [ 'item_name' => 'Insulin Syringes', 'category_name' => 'Diabetes Equipment' ],
        [ 'item_name' => 'Luer Lock Syringe - 5 mL', 'category_name' => 'Diabetes Equipment' ],
        [ 'item_name' => 'Irrigation Syringe', 'category_name' => 'Diabetes Equipment' ],
        [ 'item_name' => 'Blood Glucose Test Strips', 'category_name' => 'Diabetes Equipment' ],
        [ 'item_name' => 'Lancets', 'category_name' => 'Diabetes Equipment' ],
        [ 'item_name' => 'Alcohol Prep Pads', 'category_name' => 'Diabetes Equipment' ],
        [ 'item_name' => 'Sharps Container', 'category_name' => 'Diabetes Equipment' ],
        [ 'item_name' => 'Glucometer', 'category_name' => 'Diabetes Equipment' ],
        [ 'item_name' => 'Insulin Pen Needles', 'category_name' => 'Diabetes Equipment' ],
        [ 'item_name' => 'Catheter Tray', 'category_name' => 'Urological Supplies' ],
        [ 'item_name' => 'Intermittent Catheters', 'category_name' => 'Urological Supplies' ],
        [ 'item_name' => 'Catheter Securement Device', 'category_name' => 'Urological Supplies' ],
        [ 'item_name' => 'Catheter Leg Bag', 'category_name' => 'Urological Supplies' ],
        [ 'item_name' => 'Drainage Bag - Bedside 2000 mL', 'category_name' => 'Urological Supplies' ],
        [ 'item_name' => 'Drainage Bag - Leg 1000 mL', 'category_name' => 'Urological Supplies' ],
        [ 'item_name' => 'Urinal - Male', 'category_name' => 'Urological Supplies' ],
        [ 'item_name' => 'Urinal - Female', 'category_name' => 'Urological Supplies' ],
        [ 'item_name' => 'Colostomy Supplies', 'category_name' => 'Ostomy Supplies' ],
        [ 'item_name' => 'Urostomy Pouch', 'category_name' => 'Ostomy Supplies' ],
        [ 'item_name' => 'Skin Barrier Wafers', 'category_name' => 'Ostomy Supplies' ],
        [ 'item_name' => 'Ostomy Barrier Rings', 'category_name' => 'Ostomy Supplies' ],
        [ 'item_name' => 'CPAP Mask', 'category_name' => 'CPAP' ],
        [ 'item_name' => 'CPAP Tubing', 'category_name' => 'CPAP' ],
        [ 'item_name' => 'CPAP Headgear', 'category_name' => 'CPAP' ],
        [ 'item_name' => 'Nasal Cannula', 'category_name' => 'Respiratory Supplies' ],
        [ 'item_name' => 'Airway Clearance Vest', 'category_name' => 'Respiratory Supplies' ],
        [ 'item_name' => 'Tracheostomy Care Supplies', 'category_name' => 'Respiratory Supplies' ],
        [ 'item_name' => 'Bedside Commode - Standard', 'category_name' => 'Bathroom Safety' ],
        [ 'item_name' => 'Bedside Commode - Drop Arm', 'category_name' => 'Bathroom Safety' ],
        [ 'item_name' => 'Bedside Commode - Bariatric', 'category_name' => 'Bathroom Safety' ],
        [ 'item_name' => 'Raised Toilet Seat - Standard', 'category_name' => 'Bathroom Safety' ],
        [ 'item_name' => 'Raised Toilet Seat - With Arms', 'category_name' => 'Bathroom Safety' ],
        [ 'item_name' => 'Toilet Safety Frame', 'category_name' => 'Bathroom Safety' ],
        [ 'item_name' => 'Shower Chair - Standard', 'category_name' => 'Bathroom Safety' ],
        [ 'item_name' => 'Shower Chair - With Back', 'category_name' => 'Bathroom Safety' ],
        [ 'item_name' => 'Tub Transfer Bench', 'category_name' => 'Bathroom Safety' ],
        [ 'item_name' => 'Sliding Transfer Bench', 'category_name' => 'Bathroom Safety' ],
        [ 'item_name' => 'Portable Shower Head', 'category_name' => 'Bathroom Safety' ],
        [ 'item_name' => 'Bed Assist Handle', 'category_name' => 'Bed Accessory' ],
        [ 'item_name' => 'Bed Rail', 'category_name' => 'Bed Accessory' ],
        [ 'item_name' => 'Overbed Table', 'category_name' => 'Bed Accessory' ],
        [ 'item_name' => 'Hospital Bed', 'category_name' => 'Hospital Bed' ],
        [ 'item_name' => 'Hospital Bed Linens', 'category_name' => 'Hospital Bed' ],
        [ 'item_name' => 'Hospital Bed Sheet Set', 'category_name' => 'Hospital Bed' ],
        [ 'item_name' => 'Bedpan', 'category_name' => 'Bed Accessory' ],
        [ 'item_name' => 'Compression Socks', 'category_name' => 'Compression Garments' ],
        [ 'item_name' => 'Blood Pressure Monitor', 'category_name' => 'Monitoring Equipment' ],
        [ 'item_name' => 'Pedal Exerciser', 'category_name' => 'Therapy Equipment' ],
        [ 'item_name' => 'Feeding Pump', 'category_name' => 'Enteral Feeding' ],
        [ 'item_name' => 'Tube Feeding Formula', 'category_name' => 'Enteral Feeding' ],
        [ 'item_name' => 'Disposable Oral Swabs', 'category_name' => 'Personal Care Supplies' ],
        [ 'item_name' => 'Disposable Washcloths', 'category_name' => 'Personal Care Supplies' ],
        [ 'item_name' => 'Wound VAC Dressing Kit', 'category_name' => 'Wound Care' ],
        [ 'item_name' => 'Gauze Sponges', 'category_name' => 'Wound Care' ],
        [ 'item_name' => 'Foam Dressing', 'category_name' => 'Wound Care' ],
        [ 'item_name' => 'Skin Barrier Film', 'category_name' => 'Wound Care' ],
        [ 'item_name' => 'Tubular Bandage', 'category_name' => 'Wound Care' ],
        [ 'item_name' => 'Unna Boot', 'category_name' => 'Wound Care' ],
        [ 'item_name' => 'Knee Brace', 'category_name' => 'Orthopedic Support' ],
        [ 'item_name' => 'Cold Therapy Machine', 'category_name' => 'Therapy Equipment' ],
        [ 'item_name' => 'Reacher', 'category_name' => 'Daily Living Aids' ],
        [ 'item_name' => 'Special Needs Car Seat', 'category_name' => 'Pediatric Equipment' ],
        [ 'item_name' => 'Medical Scale - Wheelchair', 'category_name' => 'Monitoring Equipment' ],
        [ 'item_name' => 'Walker Brake Assembly', 'category_name' => 'Walker' ],
        [ 'item_name' => 'Miscellaneous Supplies', 'category_name' => 'General Medical Supplies' ],
    ];

    // Locked statuses
    public const STATUSES = ['NEW', 'REVIEWING', 'WAITLIST', 'READY', 'COMPLETED', 'CLOSED'];

    // ─── Boot ────────────────────────────────────────────

    public static function ensureModuleReady(): void {
        GrandyStashSchemaManager::ensureSchema();
        \Metis\Modules\Contacts\ContactsModule::ensureSchema();
        \Metis\Modules\Forms\FormsModule::ensureSchema();
        self::ensureCatalogSeeded();
        self::ensureRequiredCatalogItems();
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

    private static function assigneePersonRows(): array {
        $db = self::db();
        $people = \Metis_Tables::get( 'people' );

        return $db->fetchAll(
            "SELECT id, display_name, first_name, last_name, email
             FROM {$people}
             WHERE status = 'active'
               AND email <> ''
             ORDER BY display_name ASC, email ASC
             LIMIT %d",
            [ 500 ]
        ) ?: [];
    }

    private static function resolveAssigneeName( int $user_id ): ?string {
        if ( $user_id < 1 ) {
            return null;
        }
        $db    = self::db();
        $table = \Metis_Tables::get( 'people' );
        $row   = $db->fetchOne( "SELECT display_name, first_name, last_name, email FROM {$table} WHERE id = %d AND status = 'active' LIMIT 1", [ $user_id ] );
        if ( ! $row ) {
            return null;
        }
        $label = (string) ( $row['display_name'] ?? '' );
        if ( $label === '' ) {
            $label = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
        }
        if ( $label === '' ) {
            $label = (string) ( $row['email'] ?? '' );
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

    /**
     * @return list<string>
     */
    private static function normalizeAlternateDomains( mixed $value, string $primary_domain = '' ): array {
        $primary_domain = self::normalizeDomain( $primary_domain );

        if ( is_array( $value ) ) {
            $parts = $value;
        } else {
            $raw = trim( (string) $value );
            if ( $raw === '' ) {
                return [];
            }

            if ( str_starts_with( $raw, '|' ) && str_ends_with( $raw, '|' ) ) {
                $parts = explode( '|', trim( $raw, '|' ) );
            } else {
                $parts = preg_split( '/[\r\n,;]+/', $raw ) ?: [];
            }
        }

        $domains = [];
        foreach ( $parts as $part ) {
            $domain = self::normalizeDomain( (string) $part );
            if ( $domain === '' || $domain === $primary_domain ) {
                continue;
            }

            if ( ! in_array( $domain, self::PUBLIC_EMAIL_DOMAINS, true ) ) {
                $domains[] = $domain;
            }
        }

        $domains = array_values( array_unique( $domains ) );
        sort( $domains, SORT_NATURAL | SORT_FLAG_CASE );

        return $domains;
    }

    /**
     * @param list<string> $domains
     */
    private static function encodeAlternateDomains( array $domains ): ?string {
        $domains = self::normalizeAlternateDomains( $domains );
        if ( $domains === [] ) {
            return null;
        }

        return '|' . implode( '|', $domains ) . '|';
    }

    /**
     * @return list<string>
     */
    private static function decodeAlternateDomains( mixed $value, string $primary_domain = '' ): array {
        return self::normalizeAlternateDomains( $value, $primary_domain );
    }

    /**
     * @return list<string>
     */
    private static function organizationDomainsForState( array $organization ): array {
        $domains = [];
        $primary = self::normalizeDomain( (string) ( $organization['domain'] ?? '' ) );
        if ( $primary !== '' ) {
            $domains[] = $primary;
        }

        foreach ( self::decodeAlternateDomains( $organization['alternate_domains'] ?? null, $primary ) as $domain ) {
            $domains[] = $domain;
        }

        return array_values( array_unique( $domains ) );
    }

    private static function organizationHasDomain( array $organization, string $domain ): bool {
        $domain = self::normalizeDomain( $domain );
        if ( $domain === '' ) {
            return false;
        }

        return in_array( $domain, self::organizationDomainsForState( $organization ), true );
    }

    private static function findOrganizationByAnyDomain( string $domain, int $exclude_id = 0 ): ?array {
        $domain = self::normalizeDomain( $domain );
        if ( $domain === '' ) {
            return null;
        }

        $db = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_organizations' );
        $params = [ $domain, '%|' . $domain . '|%' ];
        $sql = "SELECT * FROM {$table}
                WHERE (domain = %s OR alternate_domains LIKE %s)";
        if ( $exclude_id > 0 ) {
            $sql .= " AND id <> %d";
            $params[] = $exclude_id;
        }
        $sql .= " ORDER BY id ASC LIMIT 1";

        $organization = $db->fetchOne( $sql, $params );
        return is_array( $organization ) ? $organization : null;
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

    /**
     * @param list<string> $tokens
     */
    private static function singularizeCatalogTokens( array $tokens ): array {
        return array_map(
            static function ( string $token ): string {
                if ( strlen( $token ) > 4 && str_ends_with( $token, 'ies' ) ) {
                    return substr( $token, 0, -3 ) . 'y';
                }
                if ( strlen( $token ) > 3 && str_ends_with( $token, 'ses' ) ) {
                    return substr( $token, 0, -2 );
                }
                if ( strlen( $token ) > 3 && str_ends_with( $token, 's' ) && ! str_ends_with( $token, 'ss' ) ) {
                    return substr( $token, 0, -1 );
                }

                return $token;
            },
            $tokens
        );
    }

    /**
     * @return list<string>
     */
    private static function catalogMatchTokens( string $value ): array {
        $value = strtolower( trim( $value ) );
        if ( $value === '' ) {
            return [];
        }

        $value = preg_replace( '/\([^)]*\)/', ' ', $value ) ?? $value;
        $value = str_replace( '&', ' and ', $value );
        $value = preg_replace( '/[^a-z0-9]+/', ' ', $value ) ?? $value;
        $tokens = preg_split( '/\s+/', trim( $value ) ) ?: [];
        $tokens = array_values(
            array_filter(
                array_map( 'strval', $tokens ),
                static fn ( string $token ): bool => $token !== '' && ! in_array( $token, self::ITEM_MATCH_DROP_WORDS, true )
            )
        );

        return self::singularizeCatalogTokens( $tokens );
    }

    private static function catalogMatchSignature( string $value ): string {
        $tokens = self::catalogMatchTokens( $value );
        return $tokens === [] ? '' : implode( ' ', $tokens );
    }

    private static function normalizeDatetime( string $value ): ?string {
        $value = trim( $value );
        if ( $value === '' ) {
            return null;
        }
        $timestamp = strtotime( $value );
        return $timestamp ? \gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
    }

    private static function maybeDecodeStructuredValue( mixed $value ): array {
        if ( is_array( $value ) ) {
            return $value;
        }

        if ( ! is_string( $value ) || $value === '' ) {
            return [];
        }

        $decoded = json_decode( $value, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }

        $unserialized = @unserialize( $value );
        return is_array( $unserialized ) ? $unserialized : [];
    }

    private static function normalizeLegacyGravityFormsLabel( string $label ): string {
        return trim( strtolower( preg_replace( '/\s+/', ' ', $label ) ?? $label ) );
    }

    private static function splitFullName( string $name ): array {
        $name = trim( preg_replace( '/\s+/', ' ', $name ) ?? $name );
        if ( $name === '' ) {
            return [ '', '' ];
        }

        $parts = preg_split( '/\s+/', $name ) ?: [];
        if ( count( $parts ) < 2 ) {
            return [ $name, '' ];
        }

        $last_name = (string) array_pop( $parts );
        $first_name = trim( implode( ' ', $parts ) );

        return [ $first_name, $last_name ];
    }

    private static function mapLegacyTicketStatus( string $value ): string {
        $normalized = self::normalizeLegacyGravityFormsLabel( $value );
        if ( $normalized === '' ) {
            return 'NEW';
        }

        if ( str_contains( $normalized, 'complete' ) ) {
            return 'COMPLETED';
        }
        if ( str_contains( $normalized, 'ready' ) ) {
            return 'READY';
        }
        if ( str_contains( $normalized, 'wait' ) ) {
            return 'WAITLIST';
        }
        if ( str_contains( $normalized, 'review' ) ) {
            return 'REVIEWING';
        }
        if ( str_contains( $normalized, 'clos' ) ) {
            return 'CLOSED';
        }

        return 'NEW';
    }

    private static function normalizeLegacyOrganizationName( string $value ): string {
        $value = trim( \metis_text_clean( $value ) );
        if ( $value === '' ) {
            return '';
        }

        $normalized = self::normalizeLegacyGravityFormsLabel( $value );
        if ( in_array( $normalized, [ 'self', 'independent', 'none', 'n/a', 'na' ], true ) ) {
            return '';
        }

        $reject_exact = [
            'wife',
            'husband',
            'spouse',
            'daughter',
            'son',
            'mother',
            'father',
            'mom',
            'dad',
            'father in law',
            'mother in law',
            'grandmother',
            'grandfather',
            'sister',
            'brother',
            'friend',
            'neighbor',
            'church',
            'home',
        ];
        if ( in_array( $normalized, $reject_exact, true ) ) {
            return '';
        }

        $reject_contains = [
            'for my ',
            'for a ',
            'recommended by',
            'previous submission',
            'incorrect number',
            'church event',
            'my daughter',
            'my son',
            'my wife',
            'my husband',
            'my father',
            'my mother',
            'resident discharging',
        ];
        foreach ( $reject_contains as $needle ) {
            if ( str_contains( $normalized, $needle ) ) {
                return '';
            }
        }

        if ( preg_match( '/^[\(\[]/', $value ) === 1 ) {
            return '';
        }

        return $value;
    }

    private static function isAddressLikeOrganizationName( string $value ): bool {
        $value = strtolower( trim( $value ) );
        if ( $value === '' ) {
            return false;
        }

        if ( preg_match( '/\d/', $value ) !== 1 ) {
            return false;
        }

        return preg_match( '/\b(?:street|st|avenue|ave|boulevard|blvd|road|rd|drive|dr|lane|ln|court|ct|circle|cir|way|parkway|pkwy|apt|apartment|unit|suite|ste|floor|fl)\b/', $value ) === 1;
    }

    private static function chooseCanonicalOrganization( array $organizations ): ?array {
        if ( $organizations === [] ) {
            return null;
        }

        usort(
            $organizations,
            static function ( array $left, array $right ): int {
                $left_ticket_count = (int) ( $left['ticket_count'] ?? 0 );
                $right_ticket_count = (int) ( $right['ticket_count'] ?? 0 );
                if ( $left_ticket_count !== $right_ticket_count ) {
                    return $right_ticket_count <=> $left_ticket_count;
                }

                $left_open_count = (int) ( $left['open_count'] ?? 0 );
                $right_open_count = (int) ( $right['open_count'] ?? 0 );
                if ( $left_open_count !== $right_open_count ) {
                    return $right_open_count <=> $left_open_count;
                }

                return (int) ( $left['id'] ?? 0 ) <=> (int) ( $right['id'] ?? 0 );
            }
        );

        return $organizations[0] ?? null;
    }

    private static function shouldHideOrganizationSummary( array $organization ): bool {
        $ticket_count = (int) ( $organization['ticket_count'] ?? 0 );
        if ( $ticket_count > 0 ) {
            return false;
        }

        $domains = self::organizationDomainsForState( $organization );
        if ( $domains !== [] && count( array_diff( $domains, self::PUBLIC_EMAIL_DOMAINS ) ) === 0 ) {
            return true;
        }
        if ( $domains !== [] ) {
            return false;
        }

        $name = trim( (string) ( $organization['name'] ?? '' ) );
        return $name !== '' && self::normalizeLegacyOrganizationName( $name ) === '';
    }

    private static function detectLegacyGravityFormsTables(): array {
        $required = [ 'gf_entry', 'gf_entry_meta', 'gf_form_meta' ];
        $tables = [];

        foreach ( $required as $suffix ) {
            $matches = self::db()->column( 'SHOW TABLES LIKE %s', [ '%' . $suffix ] );
            $matches = array_values(
                array_filter(
                    array_map( 'strval', is_array( $matches ) ? $matches : [] ),
                    static fn ( string $table ): bool => preg_match( '/(?:^|_)' . preg_quote( $suffix, '/' ) . '$/', $table ) === 1
                )
            );

            if ( $matches === [] ) {
                return [];
            }

            usort( $matches, static fn ( string $a, string $b ): int => strlen( $a ) <=> strlen( $b ) );
            $tables[ $suffix ] = $matches[0];
        }

        return $tables;
    }

    private static function legacyGravityFormsFormMeta( string $form_meta_table, int $form_id ): array {
        $row = self::db()->fetchOne(
            "SELECT display_meta FROM {$form_meta_table} WHERE form_id = %d LIMIT 1",
            [ $form_id ]
        );

        return self::maybeDecodeStructuredValue( $row['display_meta'] ?? null );
    }

    private static function legacyGravityFormsParentFieldMap( array $form_meta ): array {
        $map = [
            'status' => '',
            'flow' => '',
            'name' => [],
            'phone' => '',
            'email' => '',
            'organization' => '',
            'location' => '',
            'best_time' => '',
            'donation_nested' => [],
            'request_nested' => [],
        ];
        $nested_candidates = [];

        foreach ( (array) ( $form_meta['fields'] ?? [] ) as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            $signals = array_values(
                array_filter(
                    array_unique(
                        array_map(
                            fn ( mixed $value ): string => self::normalizeLegacyGravityFormsLabel( (string) $value ),
                            [
                                $field['label'] ?? '',
                                $field['adminLabel'] ?? '',
                                $field['inputName'] ?? '',
                                $field['placeholder'] ?? '',
                                $field['description'] ?? '',
                            ]
                        )
                    ),
                    static fn ( string $value ): bool => $value !== ''
                )
            );
            $label = (string) ( $signals[0] ?? '' );
            $field_id = (string) ( $field['id'] ?? '' );
            $field_type = \metis_key_clean( (string) ( $field['type'] ?? '' ) );
            $is_nested = isset( $field['gpnfForm'] ) || str_contains( $field_type, 'nested' ) || str_contains( $field_type, 'form' );
            if ( $field_id === '' ) {
                continue;
            }

            $contains = static function ( array $haystack, array $needles ): bool {
                foreach ( $haystack as $signal ) {
                    foreach ( $needles as $needle ) {
                        if ( $needle !== '' && str_contains( $signal, $needle ) ) {
                            return true;
                        }
                    }
                }
                return false;
            };

            if ( $label === 'status' || $contains( $signals, [ 'status' ] ) ) {
                $map['status'] = $field_id;
            } elseif ( $field_type === 'name' || $label === 'name' ) {
                $map['name'] = $field;
            } elseif ( $field_type === 'phone' || $contains( $signals, [ 'phone', 'telephone', 'mobile', 'cell' ] ) ) {
                $map['phone'] = $field_id;
            } elseif ( $field_type === 'email' || $contains( $signals, [ 'email', 'e-mail' ] ) ) {
                $map['email'] = $field_id;
            } elseif ( $contains( $signals, [ 'agency associated with', 'organization', 'organisation', 'agency', 'facility', 'company' ] ) ) {
                $map['organization'] = $field_id;
            } elseif ( $contains( $signals, [ 'location', 'address', 'pickup address', 'delivery address' ] ) ) {
                $map['location'] = $field_id;
            } elseif ( $contains( $signals, [ 'best time to contact', 'best time', 'contact time', 'best time to reach' ] ) ) {
                $map['best_time'] = $field_id;
            } elseif ( $is_nested && $contains( $signals, [ 'donate', 'donation', 'offer' ] ) ) {
                $map['donation_nested'] = $field;
            } elseif ( $is_nested && $contains( $signals, [ 'request', 'requested', 'need', 'needed' ] ) ) {
                $map['request_nested'] = $field;
            } elseif ( ! $is_nested && $contains( $signals, [ 'donate or request', 'donation or request', 'donate or request supplies', 'donate or request equipment' ] ) ) {
                $map['flow'] = $field_id;
            } elseif ( $is_nested ) {
                $nested_candidates[] = $field;
            }
        }

        if ( $map['request_nested'] === [] && $nested_candidates !== [] ) {
            $map['request_nested'] = $nested_candidates[0];
        }
        if ( $map['donation_nested'] === [] ) {
            if ( count( $nested_candidates ) > 1 ) {
                $map['donation_nested'] = $nested_candidates[1];
            } elseif ( $nested_candidates !== [] && $map['request_nested'] === [] ) {
                $map['donation_nested'] = $nested_candidates[0];
            }
        }

        return $map;
    }

    private static function legacyGravityFormsChildFieldMap( array $form_meta ): array {
        $map = [
            'item' => '',
            'quantity' => '',
            'condition' => '',
        ];
        $fallback_item_field = '';

        foreach ( (array) ( $form_meta['fields'] ?? [] ) as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            $signals = array_values(
                array_filter(
                    array_unique(
                        array_map(
                            fn ( mixed $value ): string => self::normalizeLegacyGravityFormsLabel( (string) $value ),
                            [
                                $field['label'] ?? '',
                                $field['adminLabel'] ?? '',
                                $field['inputName'] ?? '',
                                $field['placeholder'] ?? '',
                                $field['description'] ?? '',
                            ]
                        )
                    ),
                    static fn ( string $value ): bool => $value !== ''
                )
            );
            $label = (string) ( $signals[0] ?? '' );
            $field_id = (string) ( $field['id'] ?? '' );
            $field_type = \metis_key_clean( (string) ( $field['type'] ?? '' ) );
            if ( $field_id === '' ) {
                continue;
            }

            $contains = static function ( array $haystack, array $needles ): bool {
                foreach ( $haystack as $signal ) {
                    foreach ( $needles as $needle ) {
                        if ( $needle !== '' && str_contains( $signal, $needle ) ) {
                            return true;
                        }
                    }
                }
                return false;
            };

            if ( $contains( $signals, [ 'how many', 'quantity', 'qty', 'count', 'number needed', 'number requested' ] ) ) {
                $map['quantity'] = $field_id;
                continue;
            }

            if ( $contains( $signals, [ 'condition', 'quality', 'state of item' ] ) ) {
                $map['condition'] = $field_id;
                continue;
            }

            if ( $contains( $signals, [ 'item requested', 'item to donate', 'item needed', 'equipment', 'supply', 'supplies', 'dme', 'item', 'device', 'product', 'requested' ] ) ) {
                $map['item'] = $field_id;
                continue;
            }

            if (
                $fallback_item_field === ''
                && ! in_array( $field_type, [ 'hidden', 'html', 'section', 'page', 'captcha' ], true )
                && ! $contains( $signals, [ 'name', 'email', 'phone', 'address' ] )
            ) {
                $fallback_item_field = $field_id;
            }
        }

        if ( $map['item'] === '' ) {
            $map['item'] = $fallback_item_field;
        }

        return $map;
    }

    private static function legacyGravityFormsEntryValue( array $meta_index, string $field_id ): string {
        if ( $field_id === '' ) {
            return '';
        }

        return trim( (string) ( $meta_index[ $field_id ] ?? '' ) );
    }

    /**
     * @return array<int, int>
     */
    private static function legacyGravityFormsExtractEntryIds( mixed $value ): array {
        $ids = [];

        $collect = static function ( mixed $candidate ) use ( &$ids, &$collect ): void {
            if ( is_array( $candidate ) ) {
                foreach ( $candidate as $nested ) {
                    $collect( $nested );
                }
                return;
            }

            if ( is_string( $candidate ) ) {
                $candidate = trim( $candidate );
                if ( $candidate === '' ) {
                    return;
                }

                $decoded = json_decode( $candidate, true );
                if ( is_array( $decoded ) ) {
                    $collect( $decoded );
                    return;
                }

                $unserialized = @unserialize( $candidate );
                if ( is_array( $unserialized ) ) {
                    $collect( $unserialized );
                    return;
                }

                if ( preg_match_all( '/\d+/', $candidate, $matches ) ) {
                    foreach ( (array) ( $matches[0] ?? [] ) as $match ) {
                        $id = (int) $match;
                        if ( $id > 0 ) {
                            $ids[] = $id;
                        }
                    }
                }

                return;
            }

            $id = (int) $candidate;
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        };

        $collect( $value );

        return array_values( array_unique( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) ) );
    }

    private static function syncLegacyTicketItems( int $ticket_id, string $type, array $item_rows, string $detail, bool $replace_existing = false ): bool {
        if ( $ticket_id < 1 || $item_rows === [] ) {
            return false;
        }

        $existing_items = self::getTicketItems( $ticket_id );
        if ( $existing_items !== [] && ! $replace_existing ) {
            return false;
        }

        if ( $existing_items !== [] && $replace_existing ) {
            self::db()->delete( \Metis_Tables::get( 'grandys_stash_ticket_items' ), [ 'ticket_id' => $ticket_id ] );
        }

        self::createTicketItemsFromPayload( $ticket_id, $type, [ 'items' => $item_rows ] );
        self::logActivity( $ticket_id, 'items_imported', $detail, null );

        return true;
    }

    private static function detectLegacyEntryType(
        string $flow_value,
        int $donation_nested_field_id,
        int $request_nested_field_id,
        array $meta_index = [],
        array $child_links = [],
        array $debug = []
    ): string {
        $normalized_flow = strtolower( trim( $flow_value ) );
        if ( str_contains( $normalized_flow, 'donate' ) || str_contains( $normalized_flow, 'donation' ) || str_contains( $normalized_flow, 'supplies to donate' ) ) {
            return 'donation';
        }
        if ( str_contains( $normalized_flow, 'request' ) || str_contains( $normalized_flow, 'need' ) ) {
            return 'request';
        }

        $has_donation_meta = false;
        $has_request_meta = false;
        if ( $donation_nested_field_id > 0 ) {
            $has_donation_meta = self::legacyGravityFormsExtractEntryIds( $meta_index[ (string) $donation_nested_field_id ] ?? null ) !== [];
        }
        if ( $request_nested_field_id > 0 ) {
            $has_request_meta = self::legacyGravityFormsExtractEntryIds( $meta_index[ (string) $request_nested_field_id ] ?? null ) !== [];
        }

        if ( ! $has_donation_meta && ! $has_request_meta ) {
            $has_donation_meta = self::legacyGravityFormsExtractEntryIds( $debug['parent_donation_meta_raw'] ?? null ) !== [];
            $has_request_meta = self::legacyGravityFormsExtractEntryIds( $debug['parent_request_meta_raw'] ?? null ) !== [];
        }

        $donation_child_count = 0;
        $request_child_count = 0;
        $donation_form_count = 0;
        $request_form_count = 0;
        foreach ( $child_links as $child_link ) {
            $nested_field_id = (int) ( $child_link['nested_field_id'] ?? 0 );
            $child_form_id = (int) ( $child_link['form_id'] ?? 0 );
            if ( $donation_nested_field_id > 0 && $nested_field_id === $donation_nested_field_id ) {
                $donation_child_count++;
            }
            if ( $request_nested_field_id > 0 && $nested_field_id === $request_nested_field_id ) {
                $request_child_count++;
            }
            if ( $child_form_id > 0 ) {
                if ( $child_form_id === 18 ) {
                    $donation_form_count++;
                } elseif ( $child_form_id === 20 ) {
                    $request_form_count++;
                }
            }
        }

        if ( $has_donation_meta && ! $has_request_meta ) {
            return 'donation';
        }
        if ( $has_request_meta && ! $has_donation_meta ) {
            return 'request';
        }
        if ( $donation_child_count > 0 && $request_child_count === 0 ) {
            return 'donation';
        }
        if ( $request_child_count > 0 && $donation_child_count === 0 ) {
            return 'request';
        }
        if ( $donation_form_count > 0 && $request_form_count === 0 ) {
            return 'donation';
        }
        if ( $request_form_count > 0 && $donation_form_count === 0 ) {
            return 'request';
        }

        return 'request';
    }

    private static function syncLegacyImportedTicket(
        int $ticket_id,
        string $type,
        string $status,
        int $form_id,
        int $parent_entry_id,
        string $full_name,
        string $email,
        string $phone,
        string $organization_name,
        ?int $organization_id,
        ?string $submit_address,
        ?string $submit_notes,
        string $submitted_at,
        string $updated_at,
        ?string $closed_at
    ): void {
        if ( $ticket_id < 1 ) {
            return;
        }

        $db = self::db();
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $assignee_id = self::defaultAssigneeUserId( $type );

        $db->update(
            $tickets_table,
            [
                'type' => $type,
                'status' => $status,
                'assigned_to' => $assignee_id > 0 ? $assignee_id : null,
                'assigned_name' => self::resolveAssigneeName( $assignee_id ),
                'submit_name' => $full_name !== '' ? $full_name : ( $email !== '' ? $email : 'Unknown' ),
                'submit_email' => $email !== '' ? $email : null,
                'submit_phone' => $phone !== '' ? $phone : null,
                'organization_id' => $organization_id > 0 ? $organization_id : null,
                'organization_name' => $organization_name !== '' ? $organization_name : null,
                'submit_address' => $submit_address,
                'submit_notes' => $submit_notes,
                'form_id' => $form_id,
                'form_submission_id' => $parent_entry_id,
                'submitted_at' => $submitted_at,
                'updated_at' => $updated_at,
                'closed_at' => $closed_at,
            ],
            [ 'id' => $ticket_id ]
        );

        self::logActivity( $ticket_id, 'imported', 'Synchronized legacy ticket metadata from entry #' . $parent_entry_id . '.', null );
    }

    /**
     * @return array{donation_child_form_id:int,request_child_form_id:int}
     */
    private static function legacyChildFormOverrides( int $form_id, int $donation_child_form_id, int $request_child_form_id ): array {
        $override = self::LEGACY_FORM_CHILD_OVERRIDES[ $form_id ] ?? null;
        if ( ! is_array( $override ) ) {
            return [
                'donation_child_form_id' => $donation_child_form_id,
                'request_child_form_id' => $request_child_form_id,
            ];
        }

        return [
            'donation_child_form_id' => max( 0, (int) ( $override['donation_child_form_id'] ?? $donation_child_form_id ) ),
            'request_child_form_id' => max( 0, (int) ( $override['request_child_form_id'] ?? $request_child_form_id ) ),
        ];
    }

    private static function legacyGravityFormsNameParts( array $meta_index, array $name_field ): array {
        $full_name = '';
        $first_name = '';
        $last_name = '';

        foreach ( (array) ( $name_field['inputs'] ?? [] ) as $input ) {
            if ( ! is_array( $input ) ) {
                continue;
            }

            $input_id = (string) ( $input['id'] ?? '' );
            $value = self::legacyGravityFormsEntryValue( $meta_index, $input_id );
            if ( $value === '' ) {
                continue;
            }

            $input_label = self::normalizeLegacyGravityFormsLabel( (string) ( $input['label'] ?? '' ) );
            if ( $input_label === 'first' ) {
                $first_name = $value;
            } elseif ( $input_label === 'last' ) {
                $last_name = $value;
            }
        }

        if ( $first_name === '' && $last_name === '' ) {
            $full_name = self::legacyGravityFormsEntryValue( $meta_index, (string) ( $name_field['id'] ?? '' ) );
            [ $first_name, $last_name ] = self::splitFullName( $full_name );
        } else {
            $full_name = trim( $first_name . ' ' . $last_name );
        }

        return [
            'full' => $full_name,
            'first' => $first_name,
            'last' => $last_name,
        ];
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

    /**
     * @return list<string>
     */
    private static function expandItemLabels( mixed $values, bool $drop_noise = false ): array {
        $expanded = [];
        foreach ( self::normalizeStringList( $values ) as $value ) {
            foreach ( self::splitLegacyItemLabel( $value, $drop_noise ) as $label ) {
                $expanded[] = $label;
            }
        }

        return array_values( array_unique( $expanded ) );
    }

    /**
     * @return list<string>
     */
    private static function splitLegacyItemLabel( string $value, bool $drop_noise = false ): array {
        $value = trim( \metis_text_clean( $value ) );
        if ( $value === '' ) {
            return [];
        }

        $value = str_replace( [ '·', "\r" ], [ ',', "\n" ], $value );
        $segments = preg_split( '/[\n;]+/', $value ) ?: [ $value ];
        $results = [];

        foreach ( $segments as $segment ) {
            $segment = trim( \metis_text_clean( (string) $segment ) );
            if ( $segment === '' ) {
                continue;
            }

            foreach ( self::splitLegacyItemSegment( $segment ) as $part ) {
                $part = self::normalizeLegacyItemFragment( $part );
                if ( $part === '' ) {
                    continue;
                }
                if ( $drop_noise && self::shouldIgnoreLegacyItemFragment( $part ) ) {
                    continue;
                }
                $results[] = $part;
            }
        }

        return array_values( array_unique( $results ) );
    }

    /**
     * @return list<string>
     */
    private static function splitLegacyItemSegment( string $segment ): array {
        $segment = preg_replace( '/\s+/', ' ', trim( $segment ) ) ?? trim( $segment );
        if ( $segment === '' ) {
            return [];
        }

        $parts = preg_split( '/\s*(?:,|\band\b|&|\+)\s*/i', $segment ) ?: [ $segment ];
        if ( count( $parts ) < 2 ) {
            return [ $segment ];
        }

        $shared_prefix = self::legacyItemSharedPrefix( $segment );
        $results = [];

        foreach ( $parts as $part ) {
            $part = trim( (string) $part, " \t\n\r\0\x0B,.-" );
            if ( $part === '' ) {
                continue;
            }

            if ( $shared_prefix !== '' && ! self::legacyItemContainsKeyword( $part ) ) {
                $part = $shared_prefix . ' ' . $part;
            }

            if ( self::legacyItemLooksLikeDetail( $part ) && $results !== [] ) {
                $results[ count( $results ) - 1 ] .= ' ' . $part;
                continue;
            }

            $results[] = $part;
        }

        return $results !== [] ? $results : [ $segment ];
    }

    private static function legacyItemContainsKeyword( string $value ): bool {
        $value = strtolower( trim( $value ) );
        if ( $value === '' ) {
            return false;
        }

        return preg_match(
            '/\b(wheel ?chairs?|walkers?|rollators?|canes?|crutches?|commodes?|toilets?|benches?|chairs?|beds?|rails?|pads?|briefs?|pull ?ups?|diapers?|wipes?|liners?|gloves?|masks?|shields?|syringes?|catheters?|ostomy|ostomies|urostomy|urostomies|colostomy|colostomies|drain(?:age)? bags?|cpap|tubing|headgear|headsets?|cannulas?|braces?|socks?|scales?|pumps?|formula|gauze|dressings?|reacher|scooters?|lifts?|frames?|boards?|urinals?|bedpans?|trache(?:a|ostomy)?|feeding|knee scooters?|depends|underwear)\b/i',
            $value
        ) === 1;
    }

    private static function legacyItemSharedPrefix( string $value ): string {
        $value = strtolower( $value );
        foreach ( self::LEGACY_ITEM_PREFIX_KEYWORDS as $keyword ) {
            if ( str_contains( $value, $keyword ) ) {
                return $keyword;
            }
        }

        return '';
    }

    private static function legacyItemLooksLikeDetail( string $value ): bool {
        $value = strtolower( trim( $value ) );
        if ( $value === '' ) {
            return false;
        }

        if ( self::legacyItemContainsKeyword( $value ) ) {
            return false;
        }

        return preg_match(
            '/\b(small|medium|large|x-?large|xl|xxl|bariatric|used|new|good|fair|right|left|with|without|for|size|patient|seat|width|inch|inches|lb|lbs|pounds|pair|set|box|boxes|bag|bags|ml|level|women|men|male|female)\b/i',
            $value
        ) === 1;
    }

    private static function normalizeLegacyItemFragment( string $value ): string {
        $value = trim( \metis_text_clean( $value ) );
        if ( $value === '' ) {
            return '';
        }

        $value = preg_replace( '/\s+/', ' ', $value ) ?? $value;
        $value = preg_replace( '/\bCPAC\b/i', 'CPAP', $value ) ?? $value;
        $value = preg_replace( '/\bcommmode\b/i', 'commode', $value ) ?? $value;
        $value = preg_replace( '/\bBarryatrick\b/i', 'Bariatric', $value ) ?? $value;
        $value = preg_replace( '/\bbedpads\b/i', 'bed pads', $value ) ?? $value;
        $value = preg_replace( '/\bwc\b/i', 'wheelchair', $value ) ?? $value;
        $value = preg_replace( '/\bw\/c\b/i', 'wheelchair', $value ) ?? $value;

        return trim( $value, " \t\n\r\0\x0B,.-" );
    }

    private static function shouldIgnoreLegacyItemFragment( string $value ): bool {
        $normalized = strtolower( trim( $value ) );
        if ( $normalized === '' ) {
            return true;
        }

        if ( preg_match( '/^(test|testing|none|n\/a|na|unknown|1)$/i', $normalized ) === 1 ) {
            return true;
        }

        return str_starts_with( $normalized, 'note:' )
            || str_contains( $normalized, 'no items' )
            || str_contains( $normalized, 'wrong email address');
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
        $signature = self::catalogMatchSignature( $value );
        $best_match = null;
        $best_score = PHP_INT_MIN;

        foreach ( self::catalogItems() as $item ) {
            $item_slug = trim( (string) ( $item['item_slug'] ?? '' ) );
            $item_name = trim( (string) ( $item['item_name'] ?? '' ) );
            $item_category = trim( (string) ( $item['category_slug'] ?? '' ) );
            $item_signature = self::catalogMatchSignature( $item_name !== '' ? $item_name : $item_slug );

            $matches = $item_slug === $value
                || $item_slug === $normalized_value
                || strcasecmp( $item_name, $value ) === 0
                || ( $signature !== '' && $item_signature !== '' && ( $item_signature === $signature || str_contains( $signature, $item_signature ) || str_contains( $item_signature, $signature ) ) );
            if ( ! $matches ) {
                continue;
            }

            if ( $category_slug !== '' && $category_slug !== 'other' && $item_category !== $category_slug ) {
                continue;
            }

            $score = 0;
            if ( $item_slug === $value || $item_slug === $normalized_value ) {
                $score += 500;
            }
            if ( strcasecmp( $item_name, $value ) === 0 ) {
                $score += 500;
            }
            if ( $item_signature !== '' && $item_signature === $signature ) {
                $score += 400;
            } elseif ( $signature !== '' && $item_signature !== '' && str_contains( $signature, $item_signature ) ) {
                $score += 250 + strlen( $item_signature );
            } elseif ( $signature !== '' && $item_signature !== '' && str_contains( $item_signature, $signature ) ) {
                $score += 150 + strlen( $signature );
            }

            $value_tokens = self::catalogMatchTokens( $value );
            $item_tokens = self::catalogMatchTokens( $item_name !== '' ? $item_name : $item_slug );
            if ( $value_tokens !== [] && $item_tokens !== [] ) {
                $overlap = count( array_intersect( $value_tokens, $item_tokens ) );
                $score += $overlap * 25;
                $score += min( count( $item_tokens ), $overlap ) * 5;
            }

            if ( preg_match( '/\b(sheet|sheets|linen|linens)\b/i', $value ) === 1 && preg_match( '/\b(sheet|sheets|linen|linens)\b/i', $item_name ) === 1 ) {
                $score += 200;
            }

            if ( $best_match === null || $score > $best_score ) {
                $best_match = $item;
                $best_score = $score;
            }
        }

        return $best_match;
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
        $catalog_item_id = null;

        $catalog_item = $catalog_value !== '' ? self::findCatalogItemRecord( $catalog_value, $category_slug ) : null;
        if ( ! $catalog_item && $item_name !== '' ) {
            $catalog_item = self::findCatalogItemRecord( $item_name, $category_slug );
        }

        if ( $catalog_item ) {
            $catalog_item_id = (int) ( $catalog_item['id'] ?? 0 );
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
            'catalog_item_id'   => $catalog_item_id > 0 ? $catalog_item_id : null,
            'category'         => $category_slug !== '' ? $category_slug : 'other',
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

    private static function ensureRequiredCatalogItems(): void {
        foreach ( self::REQUIRED_CATALOG_ITEMS as $entry ) {
            self::upsertCatalogEntry(
                (string) ( $entry['item_name'] ?? '' ),
                self::normalizeCategorySlug( (string) ( $entry['category_name'] ?? '' ) )
            );
        }
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
            $organization = self::findOrganizationByAnyDomain( $domain );
        }
        if ( ! $organization && $name !== '' ) {
            $organization = $db->fetchOne( "SELECT * FROM {$table} WHERE LOWER(name) = LOWER(%s) LIMIT 1", [ $name ] );
        }

        if ( is_array( $organization ) ) {
            $update = [];
            if ( $name !== '' && (string) ( $organization['name'] ?? '' ) === '' ) {
                $update['name'] = $name;
            }
            $current_domain = self::normalizeDomain( (string) ( $organization['domain'] ?? '' ) );
            $alternate_domains = self::decodeAlternateDomains( $organization['alternate_domains'] ?? null, $current_domain );
            if ( $domain !== '' && $current_domain === '' ) {
                $update['domain'] = $domain;
            } elseif ( $domain !== '' && $domain !== $current_domain && ! in_array( $domain, $alternate_domains, true ) ) {
                $alternate_domains[] = $domain;
                $update['alternate_domains'] = self::encodeAlternateDomains( $alternate_domains );
            }
            if ( $update !== [] ) {
                $db->update( $table, $update, [ 'id' => (int) $organization['id'] ] );
            }

            return (int) $organization['id'];
        }

        $db->insert( $table, [
            'code'              => self::generateCode( 'GSO', $table, 'code' ),
            'name'              => $name !== '' ? $name : self::organizationNameFromDomain( $domain ),
            'domain'            => $domain !== '' ? $domain : null,
            'alternate_domains' => null,
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

    public static function linkTicketToOrganization( int $ticket_id, int $organization_id ): array {
        $db = self::db();
        $ticket = self::getTicket( $ticket_id );
        if ( ! $ticket ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Ticket not found.' ];
        }

        $organization = $db->fetchOne(
            "SELECT id, code, name, domain FROM " . \Metis_Tables::get( 'grandys_stash_organizations' ) . " WHERE id = %d LIMIT 1",
            [ $organization_id ]
        );
        if ( ! is_array( $organization ) ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Organization not found.' ];
        }

        $db->update(
            \Metis_Tables::get( 'grandys_stash_tickets' ),
            [
                'organization_id' => $organization_id,
                'organization_name' => (string) ( $organization['name'] ?? '' ) !== '' ? (string) $organization['name'] : null,
            ],
            [ 'id' => $ticket_id ]
        );
        self::logActivity(
            $ticket_id,
            'organization_linked',
            'Linked to organization ' . (string) ( $organization['code'] ?? '#' . $organization_id ) . '.'
        );

        return [ 'ok' => true, 'ticket_id' => $ticket_id ];
    }

    public static function linkTicketToOrganizationByCode( string $ticket_code, int $organization_id ): array {
        $ticket = self::findTicketByCode( $ticket_code );
        if ( ! $ticket ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Ticket not found.' ];
        }

        return self::linkTicketToOrganization( (int) ( $ticket['id'] ?? 0 ), $organization_id );
    }

    public static function mergeOrganizations( int $source_id, int $target_id ): array {
        if ( $source_id < 1 || $target_id < 1 || $source_id === $target_id ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Valid source and target organizations are required.' ];
        }

        $db = self::db();
        $orgs_table = \Metis_Tables::get( 'grandys_stash_organizations' );
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $source = $db->fetchOne( "SELECT id, code, name, domain, alternate_domains, notes FROM {$orgs_table} WHERE id = %d LIMIT 1", [ $source_id ] );
        $target = $db->fetchOne( "SELECT id, code, name, domain, alternate_domains, notes FROM {$orgs_table} WHERE id = %d LIMIT 1", [ $target_id ] );
        if ( ! is_array( $source ) || ! is_array( $target ) ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Organization not found.' ];
        }

        $target_update = [];
        if ( trim( (string) ( $target['name'] ?? '' ) ) === '' && trim( (string) ( $source['name'] ?? '' ) ) !== '' ) {
            $target_update['name'] = (string) $source['name'];
        }
        $target_domain = self::normalizeDomain( (string) ( $target['domain'] ?? '' ) );
        $source_domain = self::normalizeDomain( (string) ( $source['domain'] ?? '' ) );
        $target_alternate = self::decodeAlternateDomains( $target['alternate_domains'] ?? null, $target_domain );
        $source_domains = self::organizationDomainsForState( $source );
        if ( $target_domain === '' && $source_domain !== '' ) {
            $target_update['domain'] = $source_domain;
            $target_domain = $source_domain;
            $source_domains = array_values( array_filter( $source_domains, static fn ( string $domain ): bool => $domain !== $source_domain ) );
        }
        $merged_alternate = array_values(
            array_filter(
                array_unique( array_merge( $target_alternate, $source_domains ) ),
                static fn ( string $domain ) => $domain !== '' && $domain !== $target_domain
            )
        );
        $target_update['alternate_domains'] = self::encodeAlternateDomains( $merged_alternate );
        if ( trim( (string) ( $target['notes'] ?? '' ) ) === '' && trim( (string) ( $source['notes'] ?? '' ) ) !== '' ) {
            $target_update['notes'] = (string) $source['notes'];
        }
        if ( $target_update !== [] ) {
            $db->update( $orgs_table, $target_update, [ 'id' => $target_id ] );
        }

        $ticket_ids = $db->fetchAll( "SELECT id FROM {$tickets_table} WHERE organization_id = %d", [ $source_id ] ) ?: [];
        $db->update(
            $tickets_table,
            [
                'organization_id' => $target_id,
                'organization_name' => (string) ( $target['name'] ?? '' ) !== '' ? (string) $target['name'] : null,
            ],
            [ 'organization_id' => $source_id ]
        );

        $db->delete( $orgs_table, [ 'id' => $source_id ] );

        foreach ( $ticket_ids as $row ) {
            $ticket_id = (int) ( $row['id'] ?? 0 );
            if ( $ticket_id > 0 ) {
                self::logActivity( $ticket_id, 'organization_merged', 'Merged from organization ' . (string) $source['code'] . ' into ' . (string) $target['code'] . '.' );
            }
        }

        return [
            'ok' => true,
            'merged_tickets' => count( $ticket_ids ),
            'source_code' => (string) ( $source['code'] ?? '' ),
            'target_code' => (string) ( $target['code'] ?? '' ),
        ];
    }

    public static function moveOrganizationToIndependent( int $organization_id ): array {
        if ( $organization_id < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Organization is required.' ];
        }

        $db = self::db();
        $orgs_table = \Metis_Tables::get( 'grandys_stash_organizations' );
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $organization = $db->fetchOne(
            "SELECT id, code, name FROM {$orgs_table} WHERE id = %d LIMIT 1",
            [ $organization_id ]
        );
        if ( ! is_array( $organization ) ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Organization not found.' ];
        }

        $ticket_ids = $db->fetchAll( "SELECT id FROM {$tickets_table} WHERE organization_id = %d", [ $organization_id ] ) ?: [];
        $db->update(
            $tickets_table,
            [
                'organization_id' => null,
                'organization_name' => 'Independent',
            ],
            [ 'organization_id' => $organization_id ]
        );
        $db->delete( $orgs_table, [ 'id' => $organization_id ] );

        foreach ( $ticket_ids as $row ) {
            $ticket_id = (int) ( $row['id'] ?? 0 );
            if ( $ticket_id > 0 ) {
                self::logActivity(
                    $ticket_id,
                    'organization_unlinked',
                    'Organization ' . (string) ( $organization['code'] ?? '#' . $organization_id ) . ' was moved to Independent.'
                );
            }
        }

        return [
            'ok' => true,
            'ticket_count' => count( $ticket_ids ),
            'source_code' => (string) ( $organization['code'] ?? '' ),
        ];
    }

    public static function mergeOrganizationsByCode( string $source_code, int $target_id ): array {
        $source_code = strtoupper( trim( \metis_text_clean( $source_code ) ) );
        if ( $source_code === '' || $target_id < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Source organization code and target organization are required.' ];
        }

        $source = self::db()->fetchOne(
            "SELECT id FROM " . \Metis_Tables::get( 'grandys_stash_organizations' ) . " WHERE code = %s LIMIT 1",
            [ $source_code ]
        );
        if ( ! is_array( $source ) ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Source organization not found.' ];
        }

        return self::mergeOrganizations( (int) ( $source['id'] ?? 0 ), $target_id );
    }

    public static function mergeOrganizationIntoByCode( int $source_id, string $target_code ): array {
        $target_code = strtoupper( trim( \metis_text_clean( $target_code ) ) );
        if ( $source_id < 1 || $target_code === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Source organization and destination organization are required.' ];
        }

        $target = self::db()->fetchOne(
            "SELECT id FROM " . \Metis_Tables::get( 'grandys_stash_organizations' ) . " WHERE code = %s LIMIT 1",
            [ $target_code ]
        );
        if ( ! is_array( $target ) ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Destination organization not found.' ];
        }

        return self::mergeOrganizations( $source_id, (int) ( $target['id'] ?? 0 ) );
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
                    foreach ( self::expandItemLabels( $free_text ) as $line ) {
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
                    foreach ( self::expandItemLabels( $free_text ) as $line ) {
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
        $rows  = self::assigneePersonRows();
        return array_map( static function ( array $row ): array {
            $label = (string) ( $row['display_name'] ?? '' );
            if ( $label === '' ) {
                $label = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
            }
            if ( $label === '' ) {
                $label = (string) ( $row['email'] ?? '' );
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

    public static function legacyImportSettings(): array {
        return [
            'endpoint_url' => trim( (string) \Core_Settings_Service::get( self::LEGACY_IMPORT_URL_SETTING, '' ) ),
            'secret_configured' => trim( \Metis\Core\Services\CredentialService::getBySetting( self::LEGACY_IMPORT_SECRET_SETTING ) ) !== '',
        ];
    }

    public static function saveLegacyImportSettings( array $payload ): array {
        $endpoint_url = trim( (string) ( $payload['endpoint_url'] ?? '' ) );
        $endpoint_url = $endpoint_url !== '' ? filter_var( $endpoint_url, FILTER_VALIDATE_URL ) : '';
        $secret = trim( (string) ( $payload['secret'] ?? '' ) );

        if ( $endpoint_url === false ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Legacy import endpoint URL is invalid.' ];
        }

        \Core_Settings_Service::set( self::LEGACY_IMPORT_URL_SETTING, (string) $endpoint_url, false );

        if ( $secret !== '' ) {
            $existing_id = trim( (string) \Core_Settings_Service::get( self::LEGACY_IMPORT_SECRET_SETTING . '_credential_id', '' ) );
            $credential_id = \Metis\Core\Services\CredentialService::storeCredential(
                'grandys_stash_legacy_import_secret',
                "Grandy's Stash Legacy Import Secret",
                $secret,
                $existing_id
            );
            if ( $credential_id === '' ) {
                return [ 'ok' => false, 'status' => 500, 'error' => 'Unable to store legacy import secret.' ];
            }
            \Core_Settings_Service::set( self::LEGACY_IMPORT_SECRET_SETTING . '_credential_id', $credential_id, false );
        }

        return [ 'ok' => true, 'legacy_import_settings' => self::legacyImportSettings() ];
    }

    public static function previewLegacyGravityForms( array $options ): array {
        $form_id = max( 1, (int) ( $options['form_id'] ?? 17 ) );
        $parent_entry_id = max( 0, (int) ( $options['parent_entry_id'] ?? 0 ) );

        if ( trim( (string) \Core_Settings_Service::get( self::LEGACY_IMPORT_URL_SETTING, '' ) ) === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Legacy preview currently requires the remote export endpoint to be configured.' ];
        }

        $remote = self::fetchLegacyGravityFormsRemoteDataset( $options, true );
        if ( empty( $remote['ok'] ) ) {
            return $remote;
        }

        $dataset = (array) ( $remote['dataset'] ?? [] );
        $diagnostics = (array) ( $dataset['diagnostics'] ?? [] );
        $returned_target = (int) ( $dataset['parent_entry_id'] ?? 0 );
        if ( $returned_target < 1 ) {
            $returned_target = (int) ( $diagnostics['target_parent_entry_id'] ?? 0 );
        }
        if ( $parent_entry_id > 0 && $returned_target !== $parent_entry_id ) {
            return [
                'ok' => false,
                'status' => 502,
                'error' => 'Legacy preview target mismatch. Requested entry #' . $parent_entry_id . ' but remote returned #' . $returned_target . '.',
            ];
        }

        return [
            'ok' => true,
            'mode' => 'remote',
            'form_id' => $form_id,
            'preview' => self::summarizeLegacyDataset( $dataset ),
        ];
    }

    public static function auditLegacyImportedTypes( array $options ): array {
        $form_id = max( 1, (int) ( $options['form_id'] ?? 17 ) );
        $limit = max( 1, min( 500, (int) ( $options['limit'] ?? 100 ) ) );
        $parent_entry_id = max( 0, (int) ( $options['parent_entry_id'] ?? 0 ) );

        $remote = self::fetchLegacyGravityFormsRemoteDataset(
            [
                'form_id' => $form_id,
                'limit' => $limit,
                'parent_entry_id' => $parent_entry_id,
            ],
            true
        );
        if ( empty( $remote['ok'] ) ) {
            return $remote;
        }

        $dataset = (array) ( $remote['dataset'] ?? [] );
        $entries = array_values( array_filter( (array) ( $dataset['entries'] ?? [] ), 'is_array' ) );
        if ( $entries === [] ) {
            return [
                'ok' => true,
                'audit' => [
                    'summary' => 'No legacy entries were returned for the audit.',
                    'counts' => [
                        'entries_checked' => 0,
                        'imported_found' => 0,
                        'mismatches' => 0,
                        'missing_imports' => 0,
                    ],
                    'rows' => [],
                ],
            ];
        }

        $parent_ids = array_values(
            array_filter(
                array_map( static fn ( array $entry ): int => (int) ( $entry['parent_entry_id'] ?? 0 ), $entries ),
                static fn ( int $value ): bool => $value > 0
            )
        );

        $tickets = [];
        if ( $parent_ids !== [] ) {
            $placeholders = implode( ', ', array_fill( 0, count( $parent_ids ), '%d' ) );
            $tickets = self::db()->fetchAll(
                "SELECT id, code, type, source, submit_name, form_submission_id
                 FROM " . \Metis_Tables::get( 'grandys_stash_tickets' ) . "
                 WHERE form_submission_id IN ({$placeholders})
                   AND source IN (%s, %s)",
                array_merge( $parent_ids, [ 'legacy_gravity_forms', 'legacy_gravity_forms_remote' ] )
            ) ?: [];
        }

        $tickets_by_submission = [];
        foreach ( $tickets as $ticket ) {
            $submission_id = (int) ( $ticket['form_submission_id'] ?? 0 );
            if ( $submission_id > 0 ) {
                $tickets_by_submission[ $submission_id ] = $ticket;
            }
        }

        $rows = [];
        $imported_found = 0;
        $mismatches = 0;
        $missing_imports = 0;
        foreach ( $entries as $entry ) {
            $submission_id = (int) ( $entry['parent_entry_id'] ?? 0 );
            if ( $submission_id < 1 ) {
                continue;
            }

            $expected_type = (string) ( $entry['type'] ?? '' ) === 'donation' ? 'donation' : 'request';
            $ticket = $tickets_by_submission[ $submission_id ] ?? null;
            if ( is_array( $ticket ) ) {
                $imported_found++;
                $actual_type = (string) ( $ticket['type'] ?? '' ) === 'donation' ? 'donation' : 'request';
                if ( $actual_type !== $expected_type ) {
                    $mismatches++;
                    $rows[] = [
                        'status' => 'mismatch',
                        'parent_entry_id' => $submission_id,
                        'ticket_code' => (string) ( $ticket['code'] ?? '' ),
                        'name' => (string) ( $entry['name'] ?? $ticket['submit_name'] ?? '' ),
                        'expected_type' => $expected_type,
                        'actual_type' => $actual_type,
                        'flow_value' => (string) ( $entry['flow_value'] ?? '' ),
                    ];
                }
            } else {
                $missing_imports++;
                $rows[] = [
                    'status' => 'missing',
                    'parent_entry_id' => $submission_id,
                    'ticket_code' => '',
                    'name' => (string) ( $entry['name'] ?? '' ),
                    'expected_type' => $expected_type,
                    'actual_type' => '',
                    'flow_value' => (string) ( $entry['flow_value'] ?? '' ),
                ];
            }
        }

        return [
            'ok' => true,
            'audit' => [
                'summary' => sprintf(
                    'Checked %d legacy entr%s. Found %d imported ticket%s, %d type mismatch%s, and %d missing import%s.',
                    count( $entries ),
                    count( $entries ) === 1 ? 'y' : 'ies',
                    $imported_found,
                    $imported_found === 1 ? '' : 's',
                    $mismatches,
                    $mismatches === 1 ? '' : 'es',
                    $missing_imports,
                    $missing_imports === 1 ? '' : 's'
                ),
                'counts' => [
                    'entries_checked' => count( $entries ),
                    'imported_found' => $imported_found,
                    'mismatches' => $mismatches,
                    'missing_imports' => $missing_imports,
                ],
                'rows' => array_slice( $rows, 0, 200 ),
            ],
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

        $rows = $db->fetchAll(
            "SELECT o.id, o.code, o.name, o.domain, o.alternate_domains, o.notes, o.is_active,
                    COUNT(t.id) AS ticket_count,
                    SUM(CASE WHEN t.status IN ('NEW', 'REVIEWING', 'WAITLIST', 'READY') THEN 1 ELSE 0 END) AS open_count,
                    MAX(t.submitted_at) AS last_ticket_at
             FROM {$orgs_table} o
             LEFT JOIN {$tickets_table} t ON t.organization_id = o.id
             GROUP BY o.id
             ORDER BY LOWER(COALESCE(NULLIF(o.name, ''), NULLIF(o.domain, ''), o.code)) ASC, o.id ASC
             LIMIT 250"
        ) ?: [];

        $rows = array_values(
            array_filter(
                $rows,
                static fn ( array $organization ): bool => ! self::shouldHideOrganizationSummary( $organization )
            )
        );

        foreach ( $rows as &$organization ) {
            $organization['additional_domains'] = self::decodeAlternateDomains(
                $organization['alternate_domains'] ?? null,
                (string) ( $organization['domain'] ?? '' )
            );
        }
        unset( $organization );

        return $rows;
    }

    public static function resolutionData(): array {
        return [
            'organizations' => self::organizationResolutionCandidates(),
            'items' => self::itemResolutionCandidates(),
        ];
    }

    public static function repairLegacyItemRows( array $payload = [] ): array {
        $limit = max( 1, min( 5000, (int) ( $payload['limit'] ?? 1000 ) ) );
        $db = self::db();
        $items_table = \Metis_Tables::get( 'grandys_stash_ticket_items' );
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );

        $rows = $db->fetchAll(
            "SELECT i.id, i.ticket_id, i.catalog_item_id, i.category, i.item_name, i.description, i.quantity, i.condition_status, i.status, i.waitlist_at, i.fulfilled_at,
                    t.source
             FROM {$items_table} i
             INNER JOIN {$tickets_table} t ON t.id = i.ticket_id
             WHERE (i.catalog_item_id IS NULL OR i.category = 'other')
               AND t.source IN ('legacy_gravity_forms', 'legacy_gravity_forms_remote')
             ORDER BY i.id ASC
             LIMIT %d",
            [ $limit ]
        ) ?: [];

        $updated_rows = 0;
        $inserted_rows = 0;
        $affected_tickets = [];
        $skipped_rows = 0;

        foreach ( $rows as $row ) {
            $item_id = (int) ( $row['id'] ?? 0 );
            $ticket_id = (int) ( $row['ticket_id'] ?? 0 );
            $raw_label = trim( (string) ( $row['item_name'] ?? '' ) );
            if ( $raw_label === '' ) {
                $raw_label = trim( (string) ( $row['description'] ?? '' ) );
            }
            if ( $item_id < 1 || $ticket_id < 1 || $raw_label === '' ) {
                $skipped_rows++;
                continue;
            }

            $labels = self::expandItemLabels( $raw_label, true );
            if ( $labels === [] ) {
                $skipped_rows++;
                continue;
            }

            $first_label = (string) ( $labels[0] ?? '' );
            $description = trim( (string) ( $row['description'] ?? '' ) );
            $new_description = $description;
            if ( count( $labels ) > 1 && $description === '' && strcasecmp( $raw_label, $first_label ) !== 0 ) {
                $new_description = $raw_label;
            }

            if ( strcasecmp( (string) ( $row['item_name'] ?? '' ), $first_label ) !== 0 || $new_description !== $description ) {
                $db->update(
                    $items_table,
                    [
                        'item_name' => $first_label,
                        'description' => $new_description !== '' ? $new_description : null,
                    ],
                    [ 'id' => $item_id ]
                );
                $updated_rows++;
            }

            if ( count( $labels ) > 1 ) {
                foreach ( array_slice( $labels, 1 ) as $label ) {
                    $db->insert( $items_table, [
                        'ticket_id' => $ticket_id,
                        'catalog_item_id' => null,
                        'category' => (string) ( $row['category'] ?? 'other' ),
                        'item_name' => $label,
                        'description' => $raw_label !== '' && strcasecmp( $raw_label, $label ) !== 0 ? $raw_label : null,
                        'quantity' => max( 1, (int) ( $row['quantity'] ?? 1 ) ),
                        'condition_status' => self::nullableText( (string) ( $row['condition_status'] ?? '' ) ),
                        'status' => (string) ( $row['status'] ?? 'pending' ),
                        'waitlist_at' => self::nullableText( (string) ( $row['waitlist_at'] ?? '' ) ),
                        'fulfilled_at' => self::nullableText( (string) ( $row['fulfilled_at'] ?? '' ) ),
                    ] );
                    $inserted_rows++;
                }
            }

            $affected_tickets[ $ticket_id ] = true;
        }

        foreach ( array_keys( $affected_tickets ) as $ticket_id ) {
            self::logActivity(
                (int) $ticket_id,
                'item_normalized',
                'Split legacy unresolved item labels into individual rows for review.'
            );
        }

        return [
            'ok' => true,
            'checked_rows' => count( $rows ),
            'updated_rows' => $updated_rows,
            'inserted_rows' => $inserted_rows,
            'affected_tickets' => count( $affected_tickets ),
            'skipped_rows' => $skipped_rows,
            'summary' => sprintf(
                'Checked %d unresolved legacy item row(s); updated %d, added %d split row(s), and affected %d ticket(s).',
                count( $rows ),
                $updated_rows,
                $inserted_rows,
                count( $affected_tickets )
            ),
        ];
    }

    private static function organizationResolutionCandidates(): array {
        $organizations = self::listOrganizationSummaries();
        if ( $organizations === [] ) {
            return [];
        }

        $candidates = [];
        $seen_source_ids = [];
        $organizations_by_domain = [];

        foreach ( $organizations as $organization ) {
            $domain = self::normalizeDomain( (string) ( $organization['domain'] ?? '' ) );
            if ( $domain === '' || in_array( $domain, self::PUBLIC_EMAIL_DOMAINS, true ) ) {
                continue;
            }
            $organizations_by_domain[ $domain ][] = $organization;
        }

        foreach ( $organizations_by_domain as $domain => $domain_organizations ) {
            if ( count( $domain_organizations ) < 2 ) {
                continue;
            }

            $canonical = self::chooseCanonicalOrganization( $domain_organizations );
            $canonical_id = (int) ( $canonical['id'] ?? 0 );
            if ( $canonical_id < 1 ) {
                continue;
            }

            foreach ( $domain_organizations as $organization ) {
                $source_id = (int) ( $organization['id'] ?? 0 );
                if ( $source_id < 1 || $source_id === $canonical_id ) {
                    continue;
                }

                $seen_source_ids[ $source_id ] = true;
                $candidates[] = [
                    'source_id' => $source_id,
                    'source_code' => (string) ( $organization['code'] ?? '' ),
                    'source_name' => (string) ( $organization['name'] ?? '' ),
                    'source_domain' => $domain,
                    'ticket_count' => (int) ( $organization['ticket_count'] ?? 0 ),
                    'open_count' => (int) ( $organization['open_count'] ?? 0 ),
                    'reason' => 'shared_domain',
                    'reason_label' => 'Shares a domain with another organization.',
                    'suggested_target_id' => $canonical_id,
                    'suggested_target_code' => (string) ( $canonical['code'] ?? '' ),
                    'suggested_target_name' => (string) ( $canonical['name'] ?? '' ),
                    'suggested_target_domain' => (string) ( $canonical['domain'] ?? '' ),
                ];
            }
        }

        foreach ( $organizations as $organization ) {
            $source_id = (int) ( $organization['id'] ?? 0 );
            if ( $source_id < 1 || isset( $seen_source_ids[ $source_id ] ) ) {
                continue;
            }

            $domain = self::normalizeDomain( (string) ( $organization['domain'] ?? '' ) );
            $name = trim( (string) ( $organization['name'] ?? '' ) );
            if ( $domain !== '' || $name === '' || ! self::isAddressLikeOrganizationName( $name ) ) {
                continue;
            }

            $candidates[] = [
                'source_id' => $source_id,
                'source_code' => (string) ( $organization['code'] ?? '' ),
                'source_name' => $name,
                'source_domain' => '',
                'ticket_count' => (int) ( $organization['ticket_count'] ?? 0 ),
                'open_count' => (int) ( $organization['open_count'] ?? 0 ),
                'reason' => 'address_like',
                'reason_label' => 'Looks like an address or household record rather than an organization.',
                'suggested_target_id' => 0,
                'suggested_target_code' => '',
                'suggested_target_name' => 'Independent',
                'suggested_target_domain' => '',
            ];
        }

        usort(
            $candidates,
            static function ( array $left, array $right ): int {
                $left_ticket_count = (int) ( $left['ticket_count'] ?? 0 );
                $right_ticket_count = (int) ( $right['ticket_count'] ?? 0 );
                if ( $left_ticket_count !== $right_ticket_count ) {
                    return $right_ticket_count <=> $left_ticket_count;
                }

                return strcasecmp(
                    (string) ( $left['source_name'] ?? '' ),
                    (string) ( $right['source_name'] ?? '' )
                );
            }
        );

        return $candidates;
    }

    private static function itemResolutionCandidates(): array {
        $db = self::db();
        $items_table = \Metis_Tables::get( 'grandys_stash_ticket_items' );
        $rows = $db->fetchAll(
            "SELECT id, ticket_id, catalog_item_id, category, item_name, description
             FROM {$items_table}
             WHERE catalog_item_id IS NULL OR category = 'other'
             ORDER BY id ASC
             LIMIT 5000"
        ) ?: [];

        if ( $rows === [] ) {
            return [];
        }

        $grouped = [];
        foreach ( $rows as $row ) {
            $label = trim( (string) ( $row['item_name'] ?? '' ) );
            if ( $label === '' ) {
                $label = trim( (string) ( $row['description'] ?? '' ) );
            }
            if ( $label === '' ) {
                continue;
            }

            $signature = self::catalogMatchSignature( $label );
            if ( $signature === '' ) {
                continue;
            }

            if ( ! isset( $grouped[ $signature ] ) ) {
                $category_slug = self::normalizeCategorySlug( (string) ( $row['category'] ?? '' ) );
                $suggested_catalog_item = self::findCatalogItemRecord( $label, $category_slug );
                if ( ! $suggested_catalog_item ) {
                    $suggested_catalog_item = self::findCatalogItemRecord( $label, '' );
                }

                $grouped[ $signature ] = [
                    'signature' => $signature,
                    'label' => $label,
                    'category' => $category_slug !== '' ? $category_slug : 'other',
                    'row_count' => 0,
                    'ticket_ids' => [],
                    'labels' => [],
                    'suggested_catalog_item_id' => (int) ( $suggested_catalog_item['id'] ?? 0 ),
                    'suggested_item_name' => (string) ( $suggested_catalog_item['item_name'] ?? '' ),
                    'suggested_category_name' => (string) ( $suggested_catalog_item['category_name'] ?? '' ),
                ];
            }

            $grouped[ $signature ]['row_count']++;
            $grouped[ $signature ]['ticket_ids'][ (int) ( $row['ticket_id'] ?? 0 ) ] = true;
            $grouped[ $signature ]['labels'][ $label ] = (int) ( $grouped[ $signature ]['labels'][ $label ] ?? 0 ) + 1;
        }

        $candidates = [];
        foreach ( $grouped as $candidate ) {
            arsort( $candidate['labels'] );
            $candidate['examples'] = array_slice(
                array_map(
                    static fn ( string $label, int $count ): array => [ 'label' => $label, 'count' => $count ],
                    array_keys( $candidate['labels'] ),
                    array_values( $candidate['labels'] )
                ),
                0,
                3
            );
            $candidate['ticket_count'] = count( $candidate['ticket_ids'] );
            unset( $candidate['ticket_ids'], $candidate['labels'] );
            $candidates[] = $candidate;
        }

        usort(
            $candidates,
            static function ( array $left, array $right ): int {
                $left_row_count = (int) ( $left['row_count'] ?? 0 );
                $right_row_count = (int) ( $right['row_count'] ?? 0 );
                if ( $left_row_count !== $right_row_count ) {
                    return $right_row_count <=> $left_row_count;
                }

                return strcasecmp(
                    (string) ( $left['label'] ?? '' ),
                    (string) ( $right['label'] ?? '' )
                );
            }
        );

        return $candidates;
    }

    public static function resolveOrganizationCandidate( array $payload ): array {
        $source_id = (int) ( $payload['source_id'] ?? 0 );
        $target_id = (int) ( $payload['target_id'] ?? 0 );

        if ( $source_id < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Source organization is required.' ];
        }

        if ( $target_id < 1 ) {
            return self::moveOrganizationToIndependent( $source_id );
        }

        return self::mergeOrganizations( $source_id, $target_id );
    }

    public static function resolveItemCandidate( array $payload ): array {
        $signature = trim( (string) ( $payload['signature'] ?? '' ) );
        $catalog_item_id = (int) ( $payload['catalog_item_id'] ?? 0 );
        if ( $signature === '' || $catalog_item_id < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'An item signature and catalog item are required.' ];
        }

        $catalog_item = null;
        foreach ( self::catalogItems() as $item ) {
            if ( (int) ( $item['id'] ?? 0 ) === $catalog_item_id ) {
                $catalog_item = $item;
                break;
            }
        }
        if ( ! is_array( $catalog_item ) ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Catalog item not found.' ];
        }

        $db = self::db();
        $items_table = \Metis_Tables::get( 'grandys_stash_ticket_items' );
        $rows = $db->fetchAll(
            "SELECT id, ticket_id, category, item_name, description
             FROM {$items_table}
             WHERE catalog_item_id IS NULL OR category = 'other'
             ORDER BY id ASC
             LIMIT 5000"
        ) ?: [];

        $item_ids = [];
        $ticket_ids = [];
        foreach ( $rows as $row ) {
            $label = trim( (string) ( $row['item_name'] ?? '' ) );
            if ( $label === '' ) {
                $label = trim( (string) ( $row['description'] ?? '' ) );
            }
            if ( $label === '' ) {
                continue;
            }

            if ( self::catalogMatchSignature( $label ) !== $signature ) {
                continue;
            }

            $item_id = (int) ( $row['id'] ?? 0 );
            $ticket_id = (int) ( $row['ticket_id'] ?? 0 );
            if ( $item_id > 0 ) {
                $item_ids[] = $item_id;
            }
            if ( $ticket_id > 0 ) {
                $ticket_ids[ $ticket_id ] = true;
            }
        }

        if ( $item_ids === [] ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'No matching unresolved ticket items were found.' ];
        }

        $placeholders = implode( ', ', array_fill( 0, count( $item_ids ), '%d' ) );
        $params = array_merge(
            [
                $catalog_item_id,
                (string) ( $catalog_item['category_slug'] ?? 'other' ),
                (string) ( $catalog_item['item_name'] ?? '' ),
            ],
            $item_ids
        );
        $db->executePrepared(
            "UPDATE {$items_table}
             SET catalog_item_id = %d,
                 category = %s,
                 item_name = %s
             WHERE id IN ({$placeholders})",
            $params
        );

        foreach ( array_keys( $ticket_ids ) as $ticket_id ) {
            self::logActivity(
                (int) $ticket_id,
                'item_normalized',
                'Resolved legacy item labels to "' . (string) ( $catalog_item['item_name'] ?? 'catalog item' ) . '".'
            );
        }

        return [
            'ok' => true,
            'updated_items' => count( $item_ids ),
            'updated_tickets' => count( $ticket_ids ),
            'catalog_item_id' => $catalog_item_id,
        ];
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
        $organization['additional_domains'] = self::decodeAlternateDomains(
            $organization['alternate_domains'] ?? null,
            (string) ( $organization['domain'] ?? '' )
        );

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

    public static function deleteTickets( array $ticket_ids ): array {
        $ticket_ids = array_values(
            array_unique(
                array_filter(
                    array_map( static fn ( $id ): int => (int) $id, $ticket_ids ),
                    static fn ( int $id ): bool => $id > 0
                )
            )
        );

        if ( $ticket_ids === [] ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'At least one ticket is required.' ];
        }

        $db = self::db();
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $items_table = \Metis_Tables::get( 'grandys_stash_ticket_items' );
        $notes_table = \Metis_Tables::get( 'grandys_stash_notes' );
        $activity_table = \Metis_Tables::get( 'grandys_stash_activity' );
        $messages_table = \Metis_Tables::get( 'grandys_stash_messages' );
        $placeholders = implode( ', ', array_fill( 0, count( $ticket_ids ), '%d' ) );

        $rows = $db->fetchAll(
            "SELECT id, code FROM {$tickets_table} WHERE id IN ({$placeholders})",
            $ticket_ids
        ) ?: [];
        if ( $rows === [] ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'No matching tickets were found.' ];
        }

        $resolved_ids = array_values(
            array_filter(
                array_map( static fn ( array $row ): int => (int) ( $row['id'] ?? 0 ), $rows ),
                static fn ( int $id ): bool => $id > 0
            )
        );
        if ( $resolved_ids === [] ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'No matching tickets were found.' ];
        }

        $resolved_placeholders = implode( ', ', array_fill( 0, count( $resolved_ids ), '%d' ) );
        $db->executePrepared( "DELETE FROM {$items_table} WHERE ticket_id IN ({$resolved_placeholders})", $resolved_ids );
        $db->executePrepared( "DELETE FROM {$notes_table} WHERE ticket_id IN ({$resolved_placeholders})", $resolved_ids );
        $db->executePrepared( "DELETE FROM {$activity_table} WHERE ticket_id IN ({$resolved_placeholders})", $resolved_ids );
        $db->executePrepared( "DELETE FROM {$messages_table} WHERE ticket_id IN ({$resolved_placeholders})", $resolved_ids );
        $db->executePrepared( "DELETE FROM {$tickets_table} WHERE id IN ({$resolved_placeholders})", $resolved_ids );

        return [
            'ok' => true,
            'deleted_count' => count( $resolved_ids ),
            'deleted_codes' => array_values(
                array_filter(
                    array_map( static fn ( array $row ): string => trim( (string) ( $row['code'] ?? '' ) ), $rows ),
                    static fn ( string $code ): bool => $code !== ''
                )
            ),
        ];
    }

    public static function wipeLegacyImportedTickets(): array {
        $db = self::db();
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $items_table = \Metis_Tables::get( 'grandys_stash_ticket_items' );
        $notes_table = \Metis_Tables::get( 'grandys_stash_notes' );
        $activity_table = \Metis_Tables::get( 'grandys_stash_activity' );
        $messages_table = \Metis_Tables::get( 'grandys_stash_messages' );

        $tickets = $db->fetchAll(
            "SELECT id
             FROM {$tickets_table}
             WHERE source IN (%s, %s)",
            [ 'legacy_gravity_forms', 'legacy_gravity_forms_remote' ]
        ) ?: [];

        if ( $tickets === [] ) {
            return [ 'ok' => true, 'deleted' => 0, 'pruned_groups' => 0, 'pruned_organizations' => 0 ];
        }

        $ticket_ids = array_values(
            array_filter(
                array_map( static fn ( array $row ): int => (int) ( $row['id'] ?? 0 ), $tickets ),
                static fn ( int $id ): bool => $id > 0
            )
        );

        if ( $ticket_ids === [] ) {
            return [ 'ok' => true, 'deleted' => 0, 'pruned_groups' => 0, 'pruned_organizations' => 0 ];
        }

        $placeholders = implode( ', ', array_fill( 0, count( $ticket_ids ), '%d' ) );
        $db->executePrepared( "DELETE FROM {$items_table} WHERE ticket_id IN ({$placeholders})", $ticket_ids );
        $db->executePrepared( "DELETE FROM {$notes_table} WHERE ticket_id IN ({$placeholders})", $ticket_ids );
        $db->executePrepared( "DELETE FROM {$activity_table} WHERE ticket_id IN ({$placeholders})", $ticket_ids );
        $db->executePrepared( "DELETE FROM {$messages_table} WHERE ticket_id IN ({$placeholders})", $ticket_ids );
        $db->executePrepared( "DELETE FROM {$tickets_table} WHERE id IN ({$placeholders})", $ticket_ids );

        return [
            'ok' => true,
            'deleted' => count( $ticket_ids ),
            'pruned_groups' => 0,
            'pruned_organizations' => 0,
        ];
    }

    public static function saveOrganization( array $payload ): array {
        $db = self::db();
        $table = \Metis_Tables::get( 'grandys_stash_organizations' );
        $id = (int) ( $payload['id'] ?? 0 );
        $name = trim( \metis_text_clean( (string) ( $payload['name'] ?? '' ) ) );
        $domain = self::normalizeDomain( (string) ( $payload['domain'] ?? '' ) );
        $alternate_domains = self::normalizeAlternateDomains( $payload['alternate_domains'] ?? '', $domain );
        $notes = self::nullableTextArea( $payload['notes'] ?? '' );
        $is_active = ! isset( $payload['is_active'] ) || (string) $payload['is_active'] !== '0';

        if ( $name === '' && $domain === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Organization name or domain is required.' ];
        }

        if ( $name === '' ) {
            $name = self::organizationNameFromDomain( $domain );
        }

        foreach ( array_merge( $domain !== '' ? [ $domain ] : [], $alternate_domains ) as $candidate_domain ) {
            $conflict = self::findOrganizationByAnyDomain( $candidate_domain, $id );
            if ( is_array( $conflict ) ) {
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'Domain "' . $candidate_domain . '" is already assigned to ' . (string) ( $conflict['name'] ?? $conflict['code'] ?? 'another organization' ) . '.',
                ];
            }
        }

        $row = [
            'name'              => $name,
            'domain'            => $domain !== '' ? $domain : null,
            'alternate_domains' => self::encodeAlternateDomains( $alternate_domains ),
            'notes'             => $notes,
            'is_active'         => $is_active ? 1 : 0,
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
        $catalog = \Metis_Tables::get( 'grandys_stash_catalog' );

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
            "SELECT COALESCE(NULLIF(c.category_slug, ''), NULLIF(i.category, ''), 'other') AS category_slug,
                    COALESCE(NULLIF(c.category_name, ''), NULLIF(i.category, ''), 'Other') AS category_name,
                    COUNT(DISTINCT t.id) AS ticket_count,
                    COUNT(*) AS item_count,
                    COUNT(CASE WHEN i.status = 'fulfilled' THEN 1 END) AS fulfilled,
                    SUM(i.quantity) AS quantity_total,
                    SUM(CASE WHEN i.status = 'fulfilled' THEN i.quantity ELSE 0 END) AS fulfilled_quantity
             FROM {$items} i
             INNER JOIN {$tickets} t ON t.id = i.ticket_id
             LEFT JOIN {$catalog} c ON c.id = i.catalog_item_id
             WHERE {$where}
             GROUP BY category_slug, category_name
             ORDER BY ticket_count DESC, item_count DESC",
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
             GROUP BY DATE_FORMAT(t.submitted_at, '%Y-%m')
             ORDER BY month DESC
             LIMIT 24",
            $params
        ) ?: [];

        $by_organization = $db->fetchAll(
            "SELECT CASE
                        WHEN COALESCE(NULLIF(o.domain, ''), '') <> '' THEN CONCAT('domain:', LOWER(o.domain))
                        WHEN COALESCE(t.organization_id, 0) > 0 THEN CONCAT('org:', t.organization_id)
                        WHEN COALESCE(NULLIF(t.organization_name, ''), '') <> '' THEN CONCAT('name:', LOWER(t.organization_name))
                        ELSE 'independent'
                    END AS organization_key,
                    MAX(COALESCE(NULLIF(o.name, ''), NULLIF(t.organization_name, ''), 'Independent')) AS organization_name,
                    MAX(COALESCE(NULLIF(o.domain, ''), '')) AS organization_domain,
                    MAX(COALESCE(t.organization_id, 0)) AS organization_id,
                    COUNT(*) AS ticket_count,
                    SUM(CASE WHEN t.type = 'request' THEN 1 ELSE 0 END) AS request_count,
                    SUM(CASE WHEN t.type = 'donation' THEN 1 ELSE 0 END) AS donation_count
             FROM {$tickets} t
             LEFT JOIN {$organizations} o ON o.id = t.organization_id
             WHERE {$where}
             GROUP BY organization_key
             ORDER BY request_count DESC, ticket_count DESC, organization_name ASC
             LIMIT 50",
            $params
        ) ?: [];

        $by_person = $db->fetchAll(
            "SELECT CASE
                        WHEN COALESCE(t.group_id, 0) > 0 THEN CONCAT('group:', t.group_id)
                        WHEN COALESCE(NULLIF(t.submit_email, ''), '') <> '' THEN CONCAT('email:', LOWER(t.submit_email))
                        ELSE CONCAT('name:', LOWER(COALESCE(NULLIF(t.submit_name, ''), 'unknown')))
                    END AS person_key,
                    MAX(COALESCE(NULLIF(g.name, ''), NULLIF(t.submit_name, ''), 'Unknown')) AS person_name,
                    MAX(COALESCE(NULLIF(g.email, ''), NULLIF(t.submit_email, ''), '')) AS person_email,
                    MAX(COALESCE(t.group_id, 0)) AS group_id,
                    COUNT(*) AS ticket_count,
                    SUM(CASE WHEN t.type = 'request' THEN 1 ELSE 0 END) AS request_count,
                    SUM(CASE WHEN t.type = 'donation' THEN 1 ELSE 0 END) AS donation_count
             FROM {$tickets} t
             LEFT JOIN {$groups} g ON g.id = t.group_id
             WHERE {$where}
             GROUP BY person_key
             ORDER BY request_count DESC, ticket_count DESC, person_name ASC
             LIMIT 50",
            $params
        ) ?: [];

        $by_equipment = $db->fetchAll(
            "SELECT CASE
                        WHEN COALESCE(i.catalog_item_id, 0) > 0 THEN CONCAT('catalog:', i.catalog_item_id)
                        WHEN COALESCE(NULLIF(i.item_name, ''), NULLIF(i.description, ''), '') <> '' THEN CONCAT('label:', LOWER(COALESCE(NULLIF(i.item_name, ''), NULLIF(i.description, ''))))
                        ELSE CONCAT('category:', COALESCE(NULLIF(c.category_slug, ''), NULLIF(i.category, ''), 'other'))
                    END AS equipment_key,
                    MAX(COALESCE(NULLIF(c.item_name, ''), NULLIF(i.item_name, ''), NULLIF(i.description, ''), 'Other')) AS equipment_name,
                    MAX(COALESCE(NULLIF(c.category_slug, ''), NULLIF(i.category, ''), 'other')) AS category_slug,
                    MAX(COALESCE(NULLIF(c.category_name, ''), NULLIF(i.category, ''), 'Other')) AS category_name,
                    COUNT(DISTINCT CASE WHEN t.type = 'request' THEN t.id END) AS request_ticket_count,
                    COUNT(DISTINCT CASE WHEN t.type = 'donation' THEN t.id END) AS donation_ticket_count,
                    COUNT(DISTINCT t.id) AS ticket_count,
                    COUNT(CASE WHEN i.status = 'fulfilled' THEN 1 END) AS fulfilled_count,
                    SUM(CASE WHEN t.type = 'request' THEN i.quantity ELSE 0 END) AS request_quantity,
                    SUM(CASE WHEN t.type = 'donation' THEN i.quantity ELSE 0 END) AS donation_quantity,
                    SUM(i.quantity) AS total_quantity,
                    SUM(CASE WHEN i.status = 'fulfilled' THEN i.quantity ELSE 0 END) AS fulfilled_quantity
             FROM {$items} i
             INNER JOIN {$tickets} t ON t.id = i.ticket_id
             LEFT JOIN {$catalog} c ON c.id = i.catalog_item_id
             WHERE {$where}
             GROUP BY equipment_key
             ORDER BY request_ticket_count DESC, donation_ticket_count DESC, ticket_count DESC, equipment_name ASC
             LIMIT 100",
            $params
        ) ?: [];

        $by_category = array_map(
            static function ( array $row ): array {
                $label = trim( (string) ( $row['category_name'] ?? '' ) );
                if ( $label === '' ) {
                    $label = self::humanizeCatalogValue( (string) ( $row['category_slug'] ?? 'Other' ) );
                } elseif ( $label === (string) ( $row['category_slug'] ?? '' ) ) {
                    $label = self::humanizeCatalogValue( $label );
                }
                $row['category_name'] = $label !== '' ? $label : 'Other';
                return $row;
            },
            $by_category
        );

        $by_equipment = array_map(
            static function ( array $row ): array {
                $category_label = trim( (string) ( $row['category_name'] ?? '' ) );
                if ( $category_label === '' ) {
                    $category_label = self::humanizeCatalogValue( (string) ( $row['category_slug'] ?? 'Other' ) );
                } elseif ( $category_label === (string) ( $row['category_slug'] ?? '' ) ) {
                    $category_label = self::humanizeCatalogValue( $category_label );
                }
                $row['category_name'] = $category_label !== '' ? $category_label : 'Other';
                return $row;
            },
            $by_equipment
        );

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

    public static function reportTickets( string $from = '', string $to = '' ): array {
        $db = self::db();
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $groups_table = \Metis_Tables::get( 'grandys_stash_groups' );
        $organizations_table = \Metis_Tables::get( 'grandys_stash_organizations' );
        $items_table = \Metis_Tables::get( 'grandys_stash_ticket_items' );
        $catalog_table = \Metis_Tables::get( 'grandys_stash_catalog' );
        $people_table = \Metis_Tables::get( 'people' );

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

        return $db->fetchAll(
            "SELECT t.id,
                    t.code,
                    t.submit_name,
                    t.submit_email,
                    t.type,
                    t.status,
                    t.urgency,
                    t.assigned_to,
                    COALESCE(NULLIF(assignee.display_name, ''), NULLIF(t.assigned_name, ''), '') AS assigned_label,
                    t.submitted_at,
                    t.updated_at,
                    t.organization_name,
                    t.organization_id,
                    t.group_id,
                    g.name AS group_name,
                    COALESCE(NULLIF(o.name, ''), NULLIF(t.organization_name, ''), 'Independent') AS organization_label,
                    CASE
                        WHEN COALESCE(NULLIF(o.domain, ''), '') <> '' THEN CONCAT('domain:', LOWER(o.domain))
                        WHEN COALESCE(t.organization_id, 0) > 0 THEN CONCAT('org:', t.organization_id)
                        WHEN COALESCE(NULLIF(t.organization_name, ''), '') <> '' THEN CONCAT('name:', LOWER(t.organization_name))
                        ELSE 'independent'
                    END AS organization_key,
                    CASE
                        WHEN COALESCE(t.group_id, 0) > 0 THEN CONCAT('group:', t.group_id)
                        WHEN COALESCE(NULLIF(t.submit_email, ''), '') <> '' THEN CONCAT('email:', LOWER(t.submit_email))
                        ELSE CONCAT('name:', LOWER(COALESCE(NULLIF(t.submit_name, ''), 'unknown')))
                    END AS person_key,
                    (SELECT GROUP_CONCAT(DISTINCT COALESCE(NULLIF(ci.category_slug, ''), NULLIF(ti.category, ''), 'other')
                                         ORDER BY COALESCE(NULLIF(ci.category_name, ''), NULLIF(ci.category_slug, ''), NULLIF(ti.category, ''), 'other')
                                         SEPARATOR ',')
                     FROM {$items_table} ti
                     LEFT JOIN {$catalog_table} ci ON ci.id = ti.catalog_item_id
                     WHERE ti.ticket_id = t.id) AS category_slugs,
                    (SELECT GROUP_CONCAT(DISTINCT COALESCE(NULLIF(ci2.category_name, ''), NULLIF(ti2.category, ''), 'Other')
                                         ORDER BY COALESCE(NULLIF(ci2.category_name, ''), NULLIF(ci2.category_slug, ''), NULLIF(ti2.category, ''), 'Other')
                                         SEPARATOR ', ')
                     FROM {$items_table} ti2
                     LEFT JOIN {$catalog_table} ci2 ON ci2.id = ti2.catalog_item_id
                     WHERE ti2.ticket_id = t.id) AS category_labels,
                    (SELECT GROUP_CONCAT(DISTINCT COALESCE(NULLIF(ci3.item_name, ''), NULLIF(ti3.item_name, ''), NULLIF(ti3.description, ''), 'Unknown')
                                         ORDER BY COALESCE(NULLIF(ci3.item_name, ''), NULLIF(ti3.item_name, ''), NULLIF(ti3.description, ''), 'Unknown')
                                         SEPARATOR ', ')
                     FROM {$items_table} ti3
                     LEFT JOIN {$catalog_table} ci3 ON ci3.id = ti3.catalog_item_id
                     WHERE ti3.ticket_id = t.id) AS items_summary
             FROM {$tickets_table} t
             LEFT JOIN {$groups_table} g ON g.id = t.group_id
             LEFT JOIN {$organizations_table} o ON o.id = t.organization_id
             LEFT JOIN {$people_table} assignee ON assignee.id = t.assigned_to
             WHERE {$where}
             ORDER BY t.submitted_at DESC, t.id DESC
             LIMIT 500",
            $params
        ) ?: [];
    }

    // ─── Email preferences ──────────────────────────────

    public static function getEmailPrefs(): array {
        $db    = self::db();
        $prefs = \Metis_Tables::get( 'grandys_stash_email_prefs' );
        $people = \Metis_Tables::get( 'people' );

        return $db->fetchAll(
            "SELECT person.id AS user_id, person.display_name, person.email AS user_email,
                    COALESCE(pref.receive_grandys_summary, 0) AS receive_grandys_summary
             FROM {$people} person
             LEFT JOIN {$prefs} pref ON pref.user_id = person.id
             WHERE person.status = 'active'
               AND person.email <> ''
             ORDER BY person.display_name ASC",
            []
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
            foreach ( self::expandItemLabels( $items_text ) as $line ) {
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

    private static function fetchLegacyGravityFormsRemoteDataset( array $options, bool $preview = false ): array {
        $endpoint_url = trim( (string) \Core_Settings_Service::get( self::LEGACY_IMPORT_URL_SETTING, '' ) );
        $shared_secret = trim( \Metis\Core\Services\CredentialService::getBySetting( self::LEGACY_IMPORT_SECRET_SETTING ) );
        if ( $endpoint_url === '' || $shared_secret === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Remote legacy import endpoint is not configured.' ];
        }

        $form_id = max( 1, (int) ( $options['form_id'] ?? 17 ) );
        $limit = max( 1, min( 1000, (int) ( $options['limit'] ?? 500 ) ) );
        $parent_entry_id = max( 0, (int) ( $options['parent_entry_id'] ?? 0 ) );
        $client = new \Metis\Core\Services\HttpClient();
        $payload = [
            'form_id' => $form_id,
            'limit' => $limit,
            'preview' => $preview ? 1 : 0,
        ];
        if ( $parent_entry_id > 0 ) {
            $payload['parent_entry_id'] = $parent_entry_id;
        }
        $response = $client->postJson(
            $endpoint_url,
            $payload,
            [
                'Authorization' => 'Bearer ' . $shared_secret,
                'X-Metis-Legacy-Import' => 'grandys-stash',
            ],
            [
                'timeout' => 60,
                'connect_timeout' => 10,
            ]
        );

        if ( (int) ( $response['status'] ?? 0 ) < 200 || (int) ( $response['status'] ?? 0 ) >= 300 ) {
            return [
                'ok' => false,
                'status' => 502,
                'error' => 'Legacy endpoint returned HTTP ' . (int) ( $response['status'] ?? 0 ) . '.',
            ];
        }

        $json = (array) ( $response['json'] ?? [] );
        if ( ! empty( $json['success'] ) && is_array( $json['data'] ?? null ) ) {
            $json = (array) $json['data'];
        }

        if ( ! empty( $json['ok'] ) && is_array( $json['entries'] ?? null ) ) {
            return [ 'ok' => true, 'dataset' => $json, 'form_id' => $form_id ];
        }

        return [ 'ok' => false, 'status' => 502, 'error' => 'Legacy endpoint returned an invalid payload.' ];
    }

    private static function importLegacyGravityFormsFromRemote( array $options ): array {
        $remote = self::fetchLegacyGravityFormsRemoteDataset( $options, false );
        if ( empty( $remote['ok'] ) ) {
            return $remote;
        }

        return self::importLegacyGravityFormsFromRemoteDataset(
            (array) ( $remote['dataset'] ?? [] ),
            (int) ( $remote['form_id'] ?? max( 1, (int) ( $options['form_id'] ?? 17 ) ) )
        );
    }

    private static function summarizeLegacyDataset( array $dataset ): array {
        $entries = array_values( array_filter( (array) ( $dataset['entries'] ?? [] ), 'is_array' ) );
        $samples = [];
        $missing_item_entries = [];
        $total_items = 0;
        $entries_with_items = 0;

        foreach ( $entries as $entry ) {
            $items = array_values( array_filter( (array) ( $entry['items'] ?? [] ), 'is_array' ) );
            $item_count = count( $items );
            $total_items += $item_count;
            if ( $item_count > 0 ) {
                $entries_with_items++;
            } else {
                $missing_item_entries[] = [
                    'parent_entry_id' => (int) ( $entry['parent_entry_id'] ?? 0 ),
                    'type' => (string) ( $entry['type'] ?? '' ),
                    'name' => (string) ( $entry['name'] ?? '' ),
                    'email' => (string) ( $entry['email'] ?? '' ),
                    'debug' => is_array( $entry['debug'] ?? null ) ? $entry['debug'] : [],
                ];
            }

            if ( count( $samples ) < 12 ) {
                $samples[] = [
                    'parent_entry_id' => (int) ( $entry['parent_entry_id'] ?? 0 ),
                    'type' => (string) ( $entry['type'] ?? '' ),
                    'name' => (string) ( $entry['name'] ?? '' ),
                    'legacy_status' => (string) ( $entry['legacy_status'] ?? '' ),
                    'item_count' => $item_count,
                    'items' => array_slice(
                        array_map(
                            static fn ( array $item ): array => [
                                'item_name' => (string) ( $item['item_name'] ?? $item['item'] ?? $item['name'] ?? '' ),
                                'quantity' => (int) ( $item['quantity'] ?? 1 ),
                                'condition' => (string) ( $item['condition'] ?? $item['condition_status'] ?? '' ),
                            ],
                            $items
                        ),
                        0,
                        5
                    ),
                    'debug' => is_array( $entry['debug'] ?? null ) ? $entry['debug'] : [],
                ];
            }
        }

        return [
            'form_id' => (int) ( $dataset['form_id'] ?? 0 ),
            'target_parent_entry_id' => (int) ( $dataset['parent_entry_id'] ?? ( (array) ( $dataset['diagnostics'] ?? [] )['target_parent_entry_id'] ?? 0 ) ),
            'exported_at' => (string) ( $dataset['exported_at'] ?? '' ),
            'entry_count' => count( $entries ),
            'entries_with_items' => $entries_with_items,
            'entries_without_items' => count( $entries ) - $entries_with_items,
            'total_items' => $total_items,
            'diagnostics' => is_array( $dataset['diagnostics'] ?? null ) ? $dataset['diagnostics'] : [],
            'sample_entries' => $samples,
            'missing_item_entries' => array_slice( $missing_item_entries, 0, 20 ),
        ];
    }

    private static function importLegacyGravityFormsFromRemoteDataset( array $dataset, int $form_id ): array {
        self::ensureModuleReady();

        $db = self::db();
        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $entries = array_values( array_filter( (array) ( $dataset['entries'] ?? [] ), 'is_array' ) );
        if ( $entries === [] ) {
            return [
                'ok' => true,
                'imported' => 0,
                'skipped' => 0,
                'errors' => [],
                'summary' => 'No legacy entries were returned by the remote endpoint.',
            ];
        }

        $parent_ids = array_values(
            array_filter(
                array_map( static fn ( array $entry ): int => (int) ( $entry['parent_entry_id'] ?? 0 ), $entries ),
                static fn ( int $value ): bool => $value > 0
            )
        );
        $existing_submission_ids = [];
        if ( $parent_ids !== [] ) {
            $placeholders = implode( ', ', array_fill( 0, count( $parent_ids ), '%d' ) );
            $existing_rows = $db->fetchAll(
                "SELECT form_submission_id
                 FROM {$tickets_table}
                 WHERE form_submission_id IN ({$placeholders})",
                $parent_ids
            ) ?: [];
            $existing_submission_ids = array_fill_keys(
                array_map( static fn ( array $row ): int => (int) ( $row['form_submission_id'] ?? 0 ), $existing_rows ),
                true
            );
        }

        $results = [
            'ok' => true,
            'imported' => 0,
            'synchronized' => 0,
            'errors' => [],
            'missing_items' => 0,
        ];

        foreach ( $entries as $entry ) {
            $parent_entry_id = (int) ( $entry['parent_entry_id'] ?? 0 );
            if ( $parent_entry_id < 1 ) {
                continue;
            }

            $type = self::detectLegacyEntryType(
                (string) ( $entry['flow_value'] ?? '' ),
                (int) ( $entry['debug']['donation_nested_field_id'] ?? 0 ),
                (int) ( $entry['debug']['request_nested_field_id'] ?? 0 ),
                [],
                [],
                is_array( $entry['debug'] ?? null ) ? $entry['debug'] : []
            );
            if ( $type !== 'donation' && (string) ( $entry['type'] ?? '' ) === 'donation' ) {
                $type = 'donation';
            }
            $status = self::mapLegacyTicketStatus( (string) ( $entry['legacy_status'] ?? $entry['status'] ?? '' ) );
            $full_name = trim( \metis_text_clean( (string) ( $entry['name'] ?? '' ) ) );
            $first_name = trim( \metis_text_clean( (string) ( $entry['first_name'] ?? '' ) ) );
            $last_name = trim( \metis_text_clean( (string) ( $entry['last_name'] ?? '' ) ) );
            $email = strtolower( trim( \metis_email_clean( (string) ( $entry['email'] ?? '' ) ) ) );
            $phone = trim( \metis_text_clean( (string) ( $entry['phone'] ?? '' ) ) );
            $organization_name = self::normalizeLegacyOrganizationName( (string) ( $entry['organization_name'] ?? '' ) );
            $submit_address = self::nullableTextArea( (string) ( $entry['location'] ?? '' ) );
            $best_time = trim( \metis_text_clean( (string) ( $entry['best_time'] ?? '' ) ) );
            $organization_domain = self::domainFromEmail( $email );
            $submitted_at = self::normalizeDatetime( (string) ( $entry['submitted_at'] ?? '' ) ) ?? \metis_current_time( 'mysql' );
            $updated_at = self::normalizeDatetime( (string) ( $entry['updated_at'] ?? '' ) ) ?? $submitted_at;
            $closed_at = in_array( $status, [ 'COMPLETED', 'CLOSED' ], true ) ? $updated_at : null;

            if ( $full_name === '' && ( $first_name !== '' || $last_name !== '' ) ) {
                $full_name = trim( $first_name . ' ' . $last_name );
            }
            if ( $full_name === '' && $email === '' ) {
                $results['errors'][] = 'Entry #' . $parent_entry_id . ' skipped because it has no name or email.';
                continue;
            }

            $item_rows = [];
            foreach ( (array) ( $entry['items'] ?? [] ) as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $item_name = trim(
                    \metis_text_clean(
                        (string) (
                            $item['item_name']
                            ?? $item['item']
                            ?? $item['name']
                            ?? $item['equipment']
                            ?? ''
                        )
                    )
                );
                if ( $item_name === '' ) {
                    continue;
                }
                foreach ( self::expandItemLabels( $item_name, true ) as $expanded_item_name ) {
                    $item_rows[] = [
                        'item_name' => $expanded_item_name,
                        'quantity' => max( 1, (int) ( $item['quantity'] ?? 1 ) ),
                        'condition' => trim( \metis_text_clean( (string) ( $item['condition'] ?? $item['condition_status'] ?? '' ) ) ),
                    ];
                }
            }
            if ( $item_rows === [] ) {
                $results['missing_items']++;
            }

            $notes = array_values(
                array_filter(
                    [
                        $best_time !== '' ? 'Best time to contact: ' . $best_time : '',
                        'Legacy Gravity Forms entry #' . $parent_entry_id,
                        trim( (string) ( $entry['legacy_status'] ?? '' ) ) !== '' ? 'Legacy status: ' . trim( (string) ( $entry['legacy_status'] ?? '' ) ) : '',
                    ],
                    static fn ( string $line ): bool => trim( $line ) !== ''
                )
            );
            $submit_notes = self::nullableTextArea( implode( "\n", $notes ) );

            $contact_cid = self::upsertContactFromPayload(
                [
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'phone' => $phone,
                ],
                ''
            );

            $group_id = self::findOrCreateGroup(
                $full_name !== '' ? $full_name : $email,
                $email,
                $phone,
                $contact_cid
            );
            $organization_id = self::findOrCreateOrganization( $organization_name, $organization_domain );
            $assignee_id = self::defaultAssigneeUserId( $type );

            if ( isset( $existing_submission_ids[ $parent_entry_id ] ) ) {
                $existing_ticket_id = (int) $db->scalar(
                    "SELECT id FROM {$tickets_table} WHERE form_submission_id = %d LIMIT 1",
                    [ $parent_entry_id ]
                );
                self::syncLegacyImportedTicket(
                    $existing_ticket_id,
                    $type,
                    $status,
                    $form_id,
                    $parent_entry_id,
                    $full_name,
                    $email,
                    $phone,
                    $organization_name,
                    $organization_id > 0 ? $organization_id : null,
                    $submit_address,
                    $submit_notes,
                    $submitted_at,
                    $updated_at,
                    $closed_at
                );
                self::syncLegacyTicketItems(
                    $existing_ticket_id,
                    $type,
                    $item_rows,
                    'Synchronized item rows from remote legacy Gravity Forms entry #' . $parent_entry_id . '.',
                    true
                );
                $results['synchronized']++;
                continue;
            }

            $db->insert( $tickets_table, [
                'code' => self::generateCode( 'GST', $tickets_table, 'code' ),
                'group_id' => $group_id > 0 ? $group_id : null,
                'type' => $type,
                'status' => $status,
                'assigned_to' => $assignee_id > 0 ? $assignee_id : null,
                'assigned_name' => self::resolveAssigneeName( $assignee_id ),
                'source' => 'legacy_gravity_forms_remote',
                'urgency' => 'standard',
                'submit_name' => $full_name !== '' ? $full_name : ( $email !== '' ? $email : 'Unknown' ),
                'submit_email' => $email !== '' ? $email : null,
                'submit_phone' => $phone !== '' ? $phone : null,
                'organization_id' => $organization_id > 0 ? $organization_id : null,
                'organization_name' => $organization_name !== '' ? $organization_name : null,
                'submit_address' => $submit_address,
                'submit_notes' => $submit_notes,
                'form_id' => $form_id,
                'form_submission_id' => $parent_entry_id,
                'submitted_at' => $submitted_at,
                'updated_at' => $updated_at,
                'closed_at' => $closed_at,
            ] );

            $ticket_id = $db->lastInsertId();
            self::createTicketItemsFromPayload( $ticket_id, $type, [ 'items' => $item_rows ] );
            self::logActivity( $ticket_id, 'imported', 'Imported from remote legacy Gravity Forms entry #' . $parent_entry_id . '.', null );
            if ( $group_id > 0 ) {
                self::logActivity( $ticket_id, 'grouped', 'Auto-grouped during remote legacy import.', null );
            }

            $results['imported']++;
        }

        $results['summary'] = sprintf(
            'Imported %d ticket(s); synchronized %d existing ticket(s); %d entr%s returned no item rows.',
            (int) $results['imported'],
            (int) $results['synchronized'],
            (int) $results['missing_items'],
            (int) $results['missing_items'] === 1 ? 'y' : 'ies'
        );

        return $results;
    }

    public static function importLegacyGravityForms( array $options ): array {
        $endpoint_url = trim( (string) \Core_Settings_Service::get( self::LEGACY_IMPORT_URL_SETTING, '' ) );
        if ( $endpoint_url !== '' ) {
            return self::importLegacyGravityFormsFromRemote( $options );
        }

        self::ensureModuleReady();

        $form_id = max( 1, (int) ( $options['form_id'] ?? 17 ) );
        $limit = max( 1, min( 1000, (int) ( $options['limit'] ?? 500 ) ) );
        $db = self::db();
        $tables = self::detectLegacyGravityFormsTables();

        if ( $tables === [] ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Legacy Gravity Forms tables were not found in this database.' ];
        }

        $parent_form_meta = self::legacyGravityFormsFormMeta( $tables['gf_form_meta'], $form_id );
        if ( $parent_form_meta === [] ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Legacy Gravity Forms form metadata could not be loaded.' ];
        }

        $parent_map = self::legacyGravityFormsParentFieldMap( $parent_form_meta );
        $donation_nested_field_id = (int) ( $parent_map['donation_nested']['id'] ?? 0 );
        $request_nested_field_id  = (int) ( $parent_map['request_nested']['id'] ?? 0 );
        $donation_child_form_id   = (int) ( $parent_map['donation_nested']['gpnfForm'] ?? 0 );
        $request_child_form_id    = (int) ( $parent_map['request_nested']['gpnfForm'] ?? 0 );
        $child_form_overrides = self::legacyChildFormOverrides( $form_id, $donation_child_form_id, $request_child_form_id );
        $donation_child_form_id = $child_form_overrides['donation_child_form_id'];
        $request_child_form_id = $child_form_overrides['request_child_form_id'];

        $parent_entries = $db->fetchAll(
            "SELECT id, form_id, date_created, date_updated, status
             FROM {$tables['gf_entry']}
             WHERE form_id = %d
               AND status = %s
             ORDER BY id ASC
             LIMIT %d",
            [ $form_id, 'active', $limit ]
        ) ?: [];

        if ( $parent_entries === [] ) {
            return [
                'ok' => true,
                'imported' => 0,
                'skipped' => 0,
                'errors' => [],
                'summary' => 'No active legacy entries were found for the selected form.',
            ];
        }

        $parent_ids = array_map( static fn ( array $row ): int => (int) ( $row['id'] ?? 0 ), $parent_entries );
        $parent_placeholders = implode( ', ', array_fill( 0, count( $parent_ids ), '%d' ) );

        $parent_meta_rows = $db->fetchAll(
            "SELECT entry_id, meta_key, meta_value
             FROM {$tables['gf_entry_meta']}
             WHERE entry_id IN ({$parent_placeholders})",
            $parent_ids
        ) ?: [];

        $parent_meta_index = [];
        foreach ( $parent_meta_rows as $row ) {
            $entry_id = (int) ( $row['entry_id'] ?? 0 );
            $meta_key = trim( (string) ( $row['meta_key'] ?? '' ) );
            if ( $entry_id < 1 || $meta_key === '' ) {
                continue;
            }
            $parent_meta_index[ $entry_id ][ $meta_key ] = (string) ( $row['meta_value'] ?? '' );
        }

        $child_form_ids = array_values(
            array_filter(
                array_unique( [ $donation_child_form_id, $request_child_form_id ] ),
                static fn ( int $value ): bool => $value > 0
            )
        );

        $child_field_maps = [];
        foreach ( $child_form_ids as $child_form_id ) {
            $child_field_maps[ $child_form_id ] = self::legacyGravityFormsChildFieldMap(
                self::legacyGravityFormsFormMeta( $tables['gf_form_meta'], $child_form_id )
            );
        }

        $child_links_by_parent = [];
        $child_meta_index = [];
        if ( $child_form_ids !== [] ) {
            $child_form_placeholders = implode( ', ', array_fill( 0, count( $child_form_ids ), '%d' ) );
            $child_link_rows = $db->fetchAll(
                "SELECT e.id,
                        e.form_id,
                        e.date_created,
                        e.date_updated,
                        parent_meta.meta_value AS parent_entry_id,
                        nested_meta.meta_value AS nested_field_id
                 FROM {$tables['gf_entry']} e
                 INNER JOIN {$tables['gf_entry_meta']} parent_meta
                    ON parent_meta.entry_id = e.id
                   AND parent_meta.meta_key = %s
                 INNER JOIN {$tables['gf_entry_meta']} nested_meta
                    ON nested_meta.entry_id = e.id
                   AND nested_meta.meta_key = %s
                 WHERE e.form_id IN ({$child_form_placeholders})
                   AND e.status = %s
                   AND CAST(parent_meta.meta_value AS UNSIGNED) IN ({$parent_placeholders})
                 ORDER BY e.id ASC",
                array_merge(
                    [ 'gpnf_entry_parent', 'gpnf_entry_nested_form_field' ],
                    $child_form_ids,
                    [ 'active' ],
                    $parent_ids
                )
            ) ?: [];

            $child_ids = [];
            foreach ( $child_link_rows as $row ) {
                $parent_entry_id = (int) ( $row['parent_entry_id'] ?? 0 );
                $child_id = (int) ( $row['id'] ?? 0 );
                if ( $parent_entry_id < 1 || $child_id < 1 ) {
                    continue;
                }
                $child_ids[] = $child_id;
                $child_links_by_parent[ $parent_entry_id ][] = [
                    'id' => $child_id,
                    'form_id' => (int) ( $row['form_id'] ?? 0 ),
                    'nested_field_id' => (int) ( $row['nested_field_id'] ?? 0 ),
                ];
            }

            if ( $child_ids !== [] ) {
                $child_ids = array_values( array_unique( $child_ids ) );
                $child_placeholders = implode( ', ', array_fill( 0, count( $child_ids ), '%d' ) );
                $child_meta_rows = $db->fetchAll(
                    "SELECT entry_id, meta_key, meta_value
                     FROM {$tables['gf_entry_meta']}
                     WHERE entry_id IN ({$child_placeholders})",
                    $child_ids
                ) ?: [];

                foreach ( $child_meta_rows as $row ) {
                    $entry_id = (int) ( $row['entry_id'] ?? 0 );
                    $meta_key = trim( (string) ( $row['meta_key'] ?? '' ) );
                    if ( $entry_id < 1 || $meta_key === '' ) {
                        continue;
                    }
                    $child_meta_index[ $entry_id ][ $meta_key ] = (string) ( $row['meta_value'] ?? '' );
                }
            }
        }

        foreach ( $parent_entries as $entry ) {
            $parent_entry_id = (int) ( $entry['id'] ?? 0 );
            if ( $parent_entry_id < 1 ) {
                continue;
            }

            $parent_meta = (array) ( $parent_meta_index[ $parent_entry_id ] ?? [] );
            foreach (
                [
                    $donation_nested_field_id => $donation_child_form_id,
                    $request_nested_field_id  => $request_child_form_id,
                ] as $nested_field_id => $child_form_id
            ) {
                if ( $nested_field_id < 1 || $child_form_id < 1 ) {
                    continue;
                }

                $child_ids = self::legacyGravityFormsExtractEntryIds( $parent_meta[ (string) $nested_field_id ] ?? null );
                foreach ( $child_ids as $child_id ) {
                    $already_linked = false;
                    foreach ( (array) ( $child_links_by_parent[ $parent_entry_id ] ?? [] ) as $link ) {
                        if ( (int) ( $link['id'] ?? 0 ) === $child_id ) {
                            $already_linked = true;
                            break;
                        }
                    }
                    if ( $already_linked ) {
                        continue;
                    }

                    $child_links_by_parent[ $parent_entry_id ][] = [
                        'id' => $child_id,
                        'form_id' => $child_form_id,
                        'nested_field_id' => (int) $nested_field_id,
                    ];
                }
            }
        }

        $linked_child_ids = [];
        foreach ( $child_links_by_parent as $links ) {
            foreach ( (array) $links as $link ) {
                $child_id = (int) ( $link['id'] ?? 0 );
                if ( $child_id > 0 ) {
                    $linked_child_ids[] = $child_id;
                }
            }
        }
        $linked_child_ids = array_values( array_unique( $linked_child_ids ) );
        if ( $linked_child_ids !== [] ) {
            $missing_child_ids = array_values(
                array_filter(
                    $linked_child_ids,
                    static fn ( int $child_id ): bool => ! isset( $child_meta_index[ $child_id ] )
                )
            );

            if ( $missing_child_ids !== [] ) {
                $child_placeholders = implode( ', ', array_fill( 0, count( $missing_child_ids ), '%d' ) );
                $child_meta_rows = $db->fetchAll(
                    "SELECT entry_id, meta_key, meta_value
                     FROM {$tables['gf_entry_meta']}
                     WHERE entry_id IN ({$child_placeholders})",
                    $missing_child_ids
                ) ?: [];

                foreach ( $child_meta_rows as $row ) {
                    $entry_id = (int) ( $row['entry_id'] ?? 0 );
                    $meta_key = trim( (string) ( $row['meta_key'] ?? '' ) );
                    if ( $entry_id < 1 || $meta_key === '' ) {
                        continue;
                    }
                    $child_meta_index[ $entry_id ][ $meta_key ] = (string) ( $row['meta_value'] ?? '' );
                }
            }
        }

        $tickets_table = \Metis_Tables::get( 'grandys_stash_tickets' );
        $existing_rows = $db->fetchAll(
            "SELECT form_submission_id
             FROM {$tickets_table}
             WHERE form_submission_id IN ({$parent_placeholders})",
            $parent_ids
        ) ?: [];
        $existing_submission_ids = array_fill_keys(
            array_map( static fn ( array $row ): int => (int) ( $row['form_submission_id'] ?? 0 ), $existing_rows ),
            true
        );

        $results = [
            'ok' => true,
            'imported' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ( $parent_entries as $entry ) {
            $parent_entry_id = (int) ( $entry['id'] ?? 0 );
            if ( $parent_entry_id < 1 ) {
                continue;
            }

            $meta = $parent_meta_index[ $parent_entry_id ] ?? [];
            $flow_value = self::legacyGravityFormsEntryValue( $meta, (string) $parent_map['flow'] );
            $type = self::detectLegacyEntryType(
                $flow_value,
                $donation_nested_field_id,
                $request_nested_field_id,
                $meta,
                (array) ( $child_links_by_parent[ $parent_entry_id ] ?? [] )
            );
            $status = self::mapLegacyTicketStatus( self::legacyGravityFormsEntryValue( $meta, (string) $parent_map['status'] ) );
            $name_parts = self::legacyGravityFormsNameParts( $meta, (array) $parent_map['name'] );
            $full_name = trim( (string) ( $name_parts['full'] ?? '' ) );
            $first_name = trim( (string) ( $name_parts['first'] ?? '' ) );
            $last_name = trim( (string) ( $name_parts['last'] ?? '' ) );
            $email = strtolower( trim( \metis_email_clean( self::legacyGravityFormsEntryValue( $meta, (string) $parent_map['email'] ) ) ) );
            $phone = trim( \metis_text_clean( self::legacyGravityFormsEntryValue( $meta, (string) $parent_map['phone'] ) ) );
            $organization_name = self::normalizeLegacyOrganizationName( self::legacyGravityFormsEntryValue( $meta, (string) $parent_map['organization'] ) );
            $submit_address = self::nullableTextArea( self::legacyGravityFormsEntryValue( $meta, (string) $parent_map['location'] ) );
            $best_time = self::legacyGravityFormsEntryValue( $meta, (string) $parent_map['best_time'] );
            $organization_domain = self::domainFromEmail( $email );

            if ( $full_name === '' && $email === '' ) {
                $results['errors'][] = 'Entry #' . $parent_entry_id . ' skipped because it has no name or email.';
                continue;
            }

            $item_rows = [];
            foreach ( (array) ( $child_links_by_parent[ $parent_entry_id ] ?? [] ) as $child_link ) {
                $child_form_id = (int) ( $child_link['form_id'] ?? 0 );
                $nested_field_id = (int) ( $child_link['nested_field_id'] ?? 0 );

                if ( $type === 'donation' && $nested_field_id !== $donation_nested_field_id ) {
                    continue;
                }
                if ( $type === 'request' && $nested_field_id !== $request_nested_field_id ) {
                    continue;
                }

                $child_map = (array) ( $child_field_maps[ $child_form_id ] ?? [] );
                $child_meta = (array) ( $child_meta_index[ (int) ( $child_link['id'] ?? 0 ) ] ?? [] );
                $item_name = self::legacyGravityFormsEntryValue( $child_meta, (string) ( $child_map['item'] ?? '' ) );
                if ( $item_name === '' ) {
                    continue;
                }

                foreach ( self::expandItemLabels( $item_name, true ) as $expanded_item_name ) {
                    $item_rows[] = [
                        'item_name' => $expanded_item_name,
                        'quantity' => max( 1, (int) self::legacyGravityFormsEntryValue( $child_meta, (string) ( $child_map['quantity'] ?? '' ) ) ),
                        'condition' => self::legacyGravityFormsEntryValue( $child_meta, (string) ( $child_map['condition'] ?? '' ) ),
                    ];
                }
            }

            $notes = array_values(
                array_filter(
                    [
                        $best_time !== '' ? 'Best time to contact: ' . $best_time : '',
                        'Legacy Gravity Forms entry #' . $parent_entry_id,
                        self::legacyGravityFormsEntryValue( $meta, (string) $parent_map['status'] ) !== '' ? 'Legacy status: ' . self::legacyGravityFormsEntryValue( $meta, (string) $parent_map['status'] ) : '',
                    ],
                    static fn ( string $line ): bool => trim( $line ) !== ''
                )
            );

            $submitted_at = self::normalizeDatetime( (string) ( $entry['date_created'] ?? '' ) ) ?? \metis_current_time( 'mysql' );
            $updated_at = self::normalizeDatetime( (string) ( $entry['date_updated'] ?? '' ) ) ?? $submitted_at;
            $closed_at = in_array( $status, [ 'COMPLETED', 'CLOSED' ], true ) ? $updated_at : null;
            $submit_notes = self::nullableTextArea( implode( "\n", $notes ) );

            $contact_cid = self::upsertContactFromPayload(
                [
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'phone' => $phone,
                ],
                ''
            );

            $group_id = self::findOrCreateGroup(
                $full_name !== '' ? $full_name : $email,
                $email,
                $phone,
                $contact_cid
            );
            $organization_id = self::findOrCreateOrganization( $organization_name, $organization_domain );
            $assignee_id = self::defaultAssigneeUserId( $type );

            if ( isset( $existing_submission_ids[ $parent_entry_id ] ) ) {
                $existing_ticket_id = (int) $db->scalar(
                    "SELECT id FROM {$tickets_table} WHERE form_submission_id = %d LIMIT 1",
                    [ $parent_entry_id ]
                );
                self::syncLegacyImportedTicket(
                    $existing_ticket_id,
                    $type,
                    $status,
                    $form_id,
                    $parent_entry_id,
                    $full_name,
                    $email,
                    $phone,
                    $organization_name,
                    $organization_id > 0 ? $organization_id : null,
                    $submit_address,
                    $submit_notes,
                    $submitted_at,
                    $updated_at,
                    $closed_at
                );
                self::syncLegacyTicketItems(
                    $existing_ticket_id,
                    $type,
                    $item_rows,
                    'Synchronized item rows from legacy Gravity Forms entry #' . $parent_entry_id . '.',
                    true
                );
                $results['skipped']++;
                continue;
            }

            $db->insert( $tickets_table, [
                'code' => self::generateCode( 'GST', $tickets_table, 'code' ),
                'group_id' => $group_id > 0 ? $group_id : null,
                'type' => $type,
                'status' => $status,
                'assigned_to' => $assignee_id > 0 ? $assignee_id : null,
                'assigned_name' => self::resolveAssigneeName( $assignee_id ),
                'source' => 'legacy_gravity_forms',
                'urgency' => 'standard',
                'submit_name' => $full_name !== '' ? $full_name : ( $email !== '' ? $email : 'Unknown' ),
                'submit_email' => $email !== '' ? $email : null,
                'submit_phone' => $phone !== '' ? $phone : null,
                'organization_id' => $organization_id > 0 ? $organization_id : null,
                'organization_name' => $organization_name !== '' ? $organization_name : null,
                'submit_address' => $submit_address,
                'submit_notes' => $submit_notes,
                'form_id' => $form_id,
                'form_submission_id' => $parent_entry_id,
                'submitted_at' => $submitted_at,
                'updated_at' => $updated_at,
                'closed_at' => $closed_at,
            ] );

            $ticket_id = $db->lastInsertId();
            self::createTicketItemsFromPayload( $ticket_id, $type, [ 'items' => $item_rows ] );
            self::logActivity( $ticket_id, 'imported', 'Imported from legacy Gravity Forms entry #' . $parent_entry_id . '.', null );
            if ( $group_id > 0 ) {
                self::logActivity( $ticket_id, 'grouped', 'Auto-grouped during legacy import.', null );
            }

            $results['imported']++;
        }

        $results['summary'] = sprintf(
            'Imported %d ticket(s); skipped %d already-imported ticket(s).',
            (int) $results['imported'],
            (int) $results['skipped']
        );

        return $results;
    }

    // ─── Dashboard data (stub — Phase 6) ─────────────────

    public static function dashboardData(): array {
        self::ensureModuleReady();
        return [
            'stats'            => self::stats(),
            'catalog'          => self::catalogSummary(),
            'resolution'       => self::resolutionData(),
            'assignees'        => self::assigneeOptions(),
            'routing_defaults' => self::routingDefaults(),
            'legacy_import_settings' => self::legacyImportSettings(),
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
        $catalog_table = \Metis_Tables::get( 'grandys_stash_catalog' );

        return $db->fetchAll(
            "SELECT t.*,
                    g.name AS group_name,
                    g.code AS group_code,
                    g.email AS group_email,
                    o.name AS organization_label,
                    o.code AS organization_code,
                    o.domain AS organization_domain,
                    (SELECT GROUP_CONCAT(ti.item_name SEPARATOR ', ')
                     FROM {$items_table} ti WHERE ti.ticket_id = t.id LIMIT 5) AS items_summary,
                    (SELECT GROUP_CONCAT(DISTINCT COALESCE(NULLIF(ci.category_slug, ''), NULLIF(ti3.category, ''), 'other')
                                         ORDER BY COALESCE(NULLIF(ci.category_name, ''), NULLIF(ci.category_slug, ''), NULLIF(ti3.category, ''), 'other')
                                         SEPARATOR ',')
                     FROM {$items_table} ti3
                     LEFT JOIN {$catalog_table} ci ON ci.id = ti3.catalog_item_id
                     WHERE ti3.ticket_id = t.id) AS category_slugs,
                    (SELECT GROUP_CONCAT(DISTINCT COALESCE(NULLIF(ci2.category_name, ''), NULLIF(ti4.category, ''), 'Other')
                                         ORDER BY COALESCE(NULLIF(ci2.category_name, ''), NULLIF(ci2.category_slug, ''), NULLIF(ti4.category, ''), 'Other')
                                         SEPARATOR ', ')
                     FROM {$items_table} ti4
                     LEFT JOIN {$catalog_table} ci2 ON ci2.id = ti4.catalog_item_id
                     WHERE ti4.ticket_id = t.id) AS category_labels,
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
