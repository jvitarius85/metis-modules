<?php
declare(strict_types=1);

namespace Metis\Modules\Forms;

final class Repository {
    public static function listForms(): array {
        self::ensureSchema();
        global $wpdb;

        $forms_table = \Metis_Tables::get( 'forms' );
        $versions_table = \Metis_Tables::get( 'form_versions' );
        $submissions_table = \Metis_Tables::get( 'form_submissions' );

        $rows = $wpdb->get_results(
            "SELECT f.*,
                    v.version_number AS latest_version_number,
                    COUNT(s.id) AS submission_count,
                    MAX(s.created_at) AS last_submission_at
             FROM {$forms_table} f
             LEFT JOIN {$versions_table} v ON v.id = f.latest_version_id
             LEFT JOIN {$submissions_table} s ON s.form_id = f.id
             GROUP BY f.id
             ORDER BY f.updated_at DESC, f.id DESC",
            ARRAY_A
        ) ?: [];

        return array_map( [ self::class, 'formatSummary' ], $rows );
    }

    public static function getFormById( int $form_id ): ?array {
        self::ensureSchema();
        global $wpdb;
        $forms_table = \Metis_Tables::get( 'forms' );

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$forms_table} WHERE id = %d LIMIT 1", $form_id ),
            ARRAY_A
        );

        return is_array( $row ) ? self::hydrateForm( $row ) : null;
    }

    public static function getFormBySlug( string $slug, bool $published_only = false ): ?array {
        self::ensureSchema();
        global $wpdb;
        $forms_table = \Metis_Tables::get( 'forms' );

        $sql = "SELECT * FROM {$forms_table} WHERE slug = %s";
        if ( $published_only ) {
            $sql .= " AND status = 'published' AND published_version_id IS NOT NULL";
        }
        $sql .= ' LIMIT 1';

        $row = $wpdb->get_row( $wpdb->prepare( $sql, \sanitize_title( $slug ) ), ARRAY_A );
        return is_array( $row ) ? self::hydrateForm( $row ) : null;
    }

    public static function getSubmissionByKey( string $submission_key ): ?array {
        self::ensureSchema();
        global $wpdb;
        $table = \Metis_Tables::get( 'form_submissions' );
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE submission_key = %s LIMIT 1", trim( $submission_key ) ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        $row['payload'] = self::decodeJson( $row['payload_json'] ?? null, [] );
        $row['normalized'] = self::decodeJson( $row['normalized_json'] ?? null, [] );
        $row['totals'] = self::decodeJson( $row['totals_json'] ?? null, [] );

        return $row;
    }

    public static function saveForm( array $payload, ?int $user_id = null ): array {
        self::ensureSchema();
        global $wpdb;

        $forms_table = \Metis_Tables::get( 'forms' );
        $versions_table = \Metis_Tables::get( 'form_versions' );
        $user_id = $user_id ?? (int) \get_current_user_id();
        $form_id = isset( $payload['id'] ) ? (int) $payload['id'] : 0;
        $existing = $form_id > 0 ? self::getFormById( $form_id ) : null;

        $name = trim( \sanitize_text_field( (string) ( $payload['name'] ?? '' ) ) );
        if ( $name === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Form name is required.' ];
        }

        $slug = self::uniqueSlug(
            (string) ( $payload['slug'] ?? $name ),
            $form_id > 0 ? $form_id : null
        );

        $status = \sanitize_key( (string) ( $payload['status'] ?? 'draft' ) );
        if ( ! in_array( $status, [ 'draft', 'published', 'archived' ], true ) ) {
            $status = 'draft';
        }

        $schema = self::normalizeSchema( (array) ( $payload['schema'] ?? [] ) );
        $settings = self::normalizeSettings( (array) ( $payload['settings'] ?? [] ) );
        $checksum = hash( 'sha256', \metis_json_encode( [ 'schema' => $schema, 'settings' => $settings ] ) ?: '' );

        $form_row = [
            'slug' => $slug,
            'name' => $name,
            'description' => \sanitize_textarea_field( (string) ( $payload['description'] ?? '' ) ),
            'status' => $status,
            'payment_enabled' => ! empty( $settings['payments']['enabled'] ) ? 1 : 0,
            'settings_json' => \metis_json_encode( $settings ),
            'updated_by' => $user_id > 0 ? $user_id : null,
        ];

        if ( $existing ) {
            $wpdb->update( $forms_table, $form_row, [ 'id' => $form_id ] );
        } else {
            $form_row['form_uuid'] = self::generateCode( 'FM', $forms_table, 'form_uuid' );
            $form_row['created_by'] = $user_id > 0 ? $user_id : null;
            $wpdb->insert( $forms_table, $form_row );
            $form_id = (int) $wpdb->insert_id;
        }

        $last_version_number = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT MAX(version_number) FROM {$versions_table} WHERE form_id = %d", $form_id )
        );

        $latest = $form_id > 0 ? self::getLatestVersion( $form_id ) : null;
        $latest_checksum = (string) ( $latest['checksum'] ?? '' );
        $version_id = (int) ( $latest['id'] ?? 0 );

        if ( $latest_checksum !== $checksum ) {
            $wpdb->insert( $versions_table, [
                'form_id' => $form_id,
                'version_number' => $last_version_number + 1,
                'schema_json' => \metis_json_encode( $schema ),
                'checksum' => $checksum,
                'notes' => \sanitize_textarea_field( (string) ( $payload['version_notes'] ?? '' ) ),
                'created_by' => $user_id > 0 ? $user_id : null,
                'is_published' => $status === 'published' ? 1 : 0,
            ] );
            $version_id = (int) $wpdb->insert_id;
        }

        $update = [ 'latest_version_id' => $version_id ];
        if ( $status === 'published' && $version_id > 0 ) {
            $update['published_version_id'] = $version_id;
            $wpdb->update( $versions_table, [ 'is_published' => 0 ], [ 'form_id' => $form_id ] );
            $wpdb->update( $versions_table, [ 'is_published' => 1 ], [ 'id' => $version_id ] );
        }
        $wpdb->update( $forms_table, $update, [ 'id' => $form_id ] );

        $saved = self::getFormById( $form_id );
        return [ 'ok' => true, 'form' => $saved ];
    }

    public static function duplicateForm( int $form_id, ?int $user_id = null ): array {
        $form = self::getFormById( $form_id );
        if ( ! $form ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Form not found.' ];
        }

        $payload = [
            'name' => $form['name'] . ' Copy',
            'slug' => $form['slug'] . '-copy',
            'description' => $form['description'],
            'status' => 'draft',
            'settings' => $form['settings'],
            'schema' => $form['schema'],
            'version_notes' => 'Duplicated from form #' . $form_id,
        ];

        return self::saveForm( $payload, $user_id );
    }

    public static function publishForm( int $form_id ): array {
        $form = self::getFormById( $form_id );
        if ( ! $form || (int) ( $form['latest_version_id'] ?? 0 ) < 1 ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Form version not found.' ];
        }

        global $wpdb;
        $forms_table = \Metis_Tables::get( 'forms' );
        $versions_table = \Metis_Tables::get( 'form_versions' );
        $version_id = (int) $form['latest_version_id'];

        $wpdb->update( $versions_table, [ 'is_published' => 0 ], [ 'form_id' => $form_id ] );
        $wpdb->update( $versions_table, [ 'is_published' => 1 ], [ 'id' => $version_id ] );
        $wpdb->update( $forms_table, [
            'status' => 'published',
            'published_version_id' => $version_id,
            'payment_enabled' => ! empty( $form['settings']['payments']['enabled'] ) ? 1 : 0,
        ], [ 'id' => $form_id ] );

        return [ 'ok' => true, 'form' => self::getFormById( $form_id ) ];
    }

    public static function deleteForm( int $form_id ): array {
        self::ensureSchema();
        global $wpdb;
        $forms_table = \Metis_Tables::get( 'forms' );
        $versions_table = \Metis_Tables::get( 'form_versions' );
        $submissions_table = \Metis_Tables::get( 'form_submissions' );

        $wpdb->delete( $submissions_table, [ 'form_id' => $form_id ] );
        $wpdb->delete( $versions_table, [ 'form_id' => $form_id ] );
        $wpdb->delete( $forms_table, [ 'id' => $form_id ] );

        return [ 'ok' => true ];
    }

    public static function listSubmissions( int $form_id ): array {
        self::ensureSchema();
        global $wpdb;
        $table = \Metis_Tables::get( 'form_submissions' );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE form_id = %d ORDER BY created_at DESC, id DESC LIMIT 500",
                $form_id
            ),
            ARRAY_A
        ) ?: [];

        return array_map( static function ( array $row ): array {
            $row['payload'] = self::decodeJson( $row['payload_json'] ?? null, [] );
            $row['normalized'] = self::decodeJson( $row['normalized_json'] ?? null, [] );
            $row['totals'] = self::decodeJson( $row['totals_json'] ?? null, [] );
            unset( $row['payload_json'], $row['normalized_json'], $row['totals_json'], $row['automation_json'] );
            return $row;
        }, $rows );
    }

    public static function summarizeSubmissions( int $form_id ): array {
        self::ensureSchema();
        global $wpdb;
        $table = \Metis_Tables::get( 'form_submissions' );
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS submission_count,
                        SUM(amount_total) AS revenue_total,
                        MAX(created_at) AS last_submission_at,
                        SUM(CASE WHEN payment_status IN ('requires_payment', 'pending') THEN 1 ELSE 0 END) AS payment_pending_count
                 FROM {$table}
                 WHERE form_id = %d",
                $form_id
            ),
            ARRAY_A
        );

        return [
            'submission_count' => (int) ( $row['submission_count'] ?? 0 ),
            'revenue_total' => round( (float) ( $row['revenue_total'] ?? 0 ), 2 ),
            'last_submission_at' => (string) ( $row['last_submission_at'] ?? '' ),
            'payment_pending_count' => (int) ( $row['payment_pending_count'] ?? 0 ),
        ];
    }

    public static function exportSubmissionsCsv( int $form_id ): string {
        $rows = self::listSubmissions( $form_id );
        $stream = fopen( 'php://temp', 'r+' );
        if ( ! is_resource( $stream ) ) {
            return '';
        }

        fputcsv( $stream, [ 'submission_key', 'created_at', 'status', 'payment_status', 'email', 'amount_total', 'currency', 'payload_json' ] );
        foreach ( $rows as $row ) {
            fputcsv( $stream, [
                (string) ( $row['submission_key'] ?? '' ),
                (string) ( $row['created_at'] ?? '' ),
                (string) ( $row['submission_status'] ?? '' ),
                (string) ( $row['payment_status'] ?? '' ),
                (string) ( $row['submitter_email'] ?? '' ),
                (string) ( $row['amount_total'] ?? '0.00' ),
                (string) ( $row['currency'] ?? 'usd' ),
                \metis_json_encode( $row['normalized'] ?? [] ),
            ] );
        }
        rewind( $stream );
        return (string) stream_get_contents( $stream );
    }

    public static function resolveDynamicOptions( array $source ): array {
        global $wpdb;

        $type = \sanitize_key( (string) ( $source['type'] ?? '' ) );
        if ( $type === 'custom' ) {
            $items = [];
            foreach ( (array) ( $source['items'] ?? [] ) as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $label = trim( \sanitize_text_field( (string) ( $item['label'] ?? '' ) ) );
                $value = trim( \sanitize_text_field( (string) ( $item['value'] ?? $label ) ) );
                $category = trim( \sanitize_text_field( (string) ( $item['category'] ?? '' ) ) );
                if ( $label === '' || $value === '' ) {
                    continue;
                }
                $items[] = [ 'label' => $label, 'value' => $value, 'category' => $category ];
            }
            return $items;
        }

        return match ( $type ) {
            'contacts' => self::resolveContactsOptions( (int) ( $source['limit'] ?? 100 ) ),
            'campaigns' => self::resolveCampaignOptions(),
            'events' => self::resolveEventOptions(),
            'grandys_stash_categories' => self::resolveGrandysStashCategoryOptions(),
            'grandys_stash_items' => self::resolveGrandysStashItemOptions(),
            default => [],
        };
    }

    public static function submitForm( array $form, array $payload, array $files = [], string $source_url = '' ): array {
        self::ensureSchema();
        global $wpdb;

        $schema = (array) ( $form['schema'] ?? [] );
        $settings = (array) ( $form['settings'] ?? [] );
        $normalized_files = self::normalizeIncomingFiles( $files );
        $normalized = self::normalizeSubmissionPayload( $schema, $payload, $normalized_files );
        $errors = self::validateSubmission( $schema, $normalized );
        if ( $errors ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Validation failed.', 'errors' => $errors ];
        }

        $totals = self::calculateTotals( $schema, $settings, $normalized );
        $payment_status = ( $totals['amount_total'] ?? 0 ) > 0 && self::hasPaymentField( $schema )
            ? 'pending'
            : 'not_required';

        $submissions_table = \Metis_Tables::get( 'form_submissions' );
        $submission_key = self::generateCode( 'FS', $submissions_table, 'submission_key' );
        $wpdb->insert( $submissions_table, [
            'form_id' => (int) $form['id'],
            'version_id' => (int) ( $form['published_version_id'] ?: $form['latest_version_id'] ),
            'submission_key' => $submission_key,
            'submission_status' => 'submitted',
            'payment_status' => $payment_status,
            'amount_total' => (float) ( $totals['amount_total'] ?? 0 ),
            'currency' => (string) ( $settings['payments']['currency'] ?? 'usd' ),
            'submitter_email' => self::extractSubmitterEmail( $schema, $normalized ),
            'source_url' => $source_url,
            'payload_json' => \metis_json_encode( $payload ),
            'normalized_json' => \metis_json_encode( $normalized ),
            'totals_json' => \metis_json_encode( $totals ),
            'automation_json' => \metis_json_encode( self::buildAutomationLog( $form, $normalized, $totals ) ),
        ] );

        $submission_id = (int) $wpdb->insert_id;
        $stripe = self::maybeCreatePaymentIntent( $form, $submission_id, $totals );
        if ( ! empty( $stripe['payment_intent_id'] ) ) {
            $wpdb->update( $submissions_table, [
                'payment_intent_id' => $stripe['payment_intent_id'],
                'payment_status' => 'requires_payment',
            ], [ 'id' => $submission_id ] );
        }

        self::dispatchNotifications( $form, $normalized, $totals, $submission_key );

        return [
            'ok' => true,
            'submission_key' => $submission_key,
            'submission_id' => $submission_id,
            'totals' => $totals,
            'payment' => $stripe,
            'message' => (string) ( $settings['confirmation']['message'] ?? 'Thanks, your submission has been received.' ),
        ];
    }

    public static function syncPaymentStatus( string $submission_key ): array {
        $submission = self::getSubmissionByKey( $submission_key );
        if ( ! $submission ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Submission not found.' ];
        }

        $payment_intent_id = (string) ( $submission['payment_intent_id'] ?? '' );
        if ( $payment_intent_id === '' ) {
            return [ 'ok' => true, 'submission' => $submission ];
        }

        if ( ! \function_exists( 'metis_stripe_init' ) ) {
            $stripe_bootstrap = dirname( __DIR__, 4 ) . '/includes/apis/stripe/bootstrap.php';
            if ( is_file( $stripe_bootstrap ) ) {
                require_once $stripe_bootstrap;
            }
        }

        if ( ! class_exists( '\Stripe\PaymentIntent' ) ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Stripe SDK is unavailable.' ];
        }

        \metis_stripe_init();
        if ( \Stripe\Stripe::getApiKey() === null ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Stripe is not configured.' ];
        }

        try {
            $intent = \Stripe\PaymentIntent::retrieve( $payment_intent_id );
            $status_map = [
                'succeeded' => 'paid',
                'processing' => 'processing',
                'requires_payment_method' => 'requires_payment',
                'requires_confirmation' => 'requires_payment',
                'requires_action' => 'requires_action',
                'canceled' => 'canceled',
            ];
            $payment_status = $status_map[ (string) $intent->status ] ?? 'pending';

            global $wpdb;
            $table = \Metis_Tables::get( 'form_submissions' );
            $wpdb->update( $table, [
                'payment_status' => $payment_status,
                'submission_status' => $payment_status === 'paid' ? 'completed' : 'submitted',
            ], [ 'submission_key' => $submission_key ] );

            return [ 'ok' => true, 'submission' => self::getSubmissionByKey( $submission_key ) ];
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'status' => 500, 'error' => $e->getMessage() ];
        }
    }

    public static function calculateTotals( array $schema, array $settings, array $payload ): array {
        $subtotal = 0.0;
        $line_items = [];

        foreach ( $schema as $field ) {
            if ( ! is_array( $field ) || ! self::fieldVisible( $field, $payload ) ) {
                continue;
            }

            $field_key = (string) ( $field['key'] ?? '' );
            if ( $field_key === '' ) {
                continue;
            }

            $price = self::calculateFieldPrice( $field, $payload[ $field_key ] ?? null );
            if ( $price <= 0 ) {
                continue;
            }

            $subtotal += $price;
            $line_items[] = [
                'field' => $field_key,
                'label' => (string) ( $field['label'] ?? $field_key ),
                'amount' => round( $price, 2 ),
            ];
        }

        $total_source = (string) ( $settings['payments']['total_source'] ?? 'calculated' );
        if ( $total_source === 'field_value' ) {
            $field_key = \sanitize_key( (string) ( $settings['payments']['total_field_key'] ?? '' ) );
            $subtotal = $field_key !== '' ? max( 0, (float) ( $payload[ $field_key ] ?? 0 ) ) : 0.0;
            $line_items = $field_key !== '' ? [
                [
                    'field' => $field_key,
                    'label' => $field_key,
                    'amount' => round( $subtotal, 2 ),
                ],
            ] : [];
        }

        $discount_total = 0.0;
        $discount_code = trim( \sanitize_text_field( (string) ( $payload['_discount_code'] ?? '' ) ) );
        foreach ( (array) ( $settings['payments']['discounts'] ?? [] ) as $discount ) {
            if ( ! is_array( $discount ) ) {
                continue;
            }
            $code = strtoupper( trim( (string) ( $discount['code'] ?? '' ) ) );
            if ( $code === '' || strtoupper( $discount_code ) !== $code ) {
                continue;
            }
            $type = \sanitize_key( (string) ( $discount['type'] ?? 'fixed' ) );
            $amount = (float) ( $discount['amount'] ?? 0 );
            $discount_total = $type === 'percent'
                ? round( $subtotal * ( $amount / 100 ), 2 )
                : min( $subtotal, $amount );
            break;
        }

        $processing_fee_total = self::calculateProcessingFees(
            $subtotal,
            $discount_total,
            (array) ( $settings['payments']['processing_fees'] ?? [] )
        );

        $total = max( 0, round( $subtotal - $discount_total + $processing_fee_total, 2 ) );

        return [
            'subtotal' => round( $subtotal, 2 ),
            'discount_total' => round( $discount_total, 2 ),
            'processing_fee_total' => round( $processing_fee_total, 2 ),
            'amount_total' => $total,
            'currency' => (string) ( $settings['payments']['currency'] ?? 'usd' ),
            'line_items' => $line_items,
            'discount_code' => $discount_code,
        ];
    }

    public static function normalizeSchema( array $schema ): array {
        $normalized = [];
        foreach ( array_values( $schema ) as $index => $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $type = \sanitize_key( (string) ( $field['type'] ?? 'text' ) );
            if ( ! in_array( $type, [ 'text', 'email', 'number', 'textarea', 'select', 'checkbox', 'radio', 'file', 'date', 'repeater', 'payment' ], true ) ) {
                $type = 'text';
            }
            $key = trim( \sanitize_key( (string) ( $field['key'] ?? 'field_' . ( $index + 1 ) ) ) );
            if ( $key === '' ) {
                $key = 'field_' . ( $index + 1 );
            }
            $row = [
                'id' => trim( \sanitize_text_field( (string) ( $field['id'] ?? uniqid( 'fld_', false ) ) ) ),
                'key' => $key,
                'type' => $type,
                'label' => trim( \sanitize_text_field( (string) ( $field['label'] ?? ucwords( str_replace( '_', ' ', $key ) ) ) ) ),
                'help' => \sanitize_text_field( (string) ( $field['help'] ?? '' ) ),
                'placeholder' => \sanitize_text_field( (string) ( $field['placeholder'] ?? '' ) ),
                'required' => ! empty( $field['required'] ),
                'width' => in_array( (string) ( $field['width'] ?? 'full' ), [ 'full', 'half' ], true ) ? (string) $field['width'] : 'full',
                'format' => self::normalizeFieldFormat( $type, (string) ( $field['format'] ?? '' ) ),
                'validation' => self::normalizeFieldValidation( (array) ( $field['validation'] ?? [] ) ),
                'options' => self::normalizeOptions( (array) ( $field['options'] ?? [] ) ),
                'options_source' => self::normalizeOptionsSource( (array) ( $field['options_source'] ?? [] ) ),
                'searchable' => ! empty( $field['searchable'] ),
                'depends_on' => \sanitize_key( (string) ( $field['depends_on'] ?? '' ) ),
                'conditions' => self::normalizeConditions( (array) ( $field['conditions'] ?? [] ) ),
                'pricing' => self::normalizePricing( (array) ( $field['pricing'] ?? [] ) ),
                'min' => isset( $field['min'] ) ? (string) $field['min'] : '',
                'max' => isset( $field['max'] ) ? (string) $field['max'] : '',
                'repeat_limit' => max( 1, (int) ( $field['repeat_limit'] ?? 10 ) ),
            ];

            if ( $type === 'repeater' ) {
                $row['subfields'] = self::normalizeSchema( (array) ( $field['subfields'] ?? [] ) );
            }
            if ( $type === 'payment' ) {
                $row['required'] = false;
                $row['width'] = 'full';
                $row['pricing'] = self::normalizePricing( [] );
                $row['conditions'] = [];
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    public static function normalizeSettings( array $settings ): array {
        $custom_datasets = [];
        foreach ( (array) ( $settings['custom_datasets'] ?? [] ) as $dataset ) {
            if ( ! is_array( $dataset ) ) {
                continue;
            }
            $key = \sanitize_key( (string) ( $dataset['key'] ?? '' ) );
            if ( $key === '' ) {
                continue;
            }
            $custom_datasets[] = [
                'key' => $key,
                'label' => \sanitize_text_field( (string) ( $dataset['label'] ?? $key ) ),
                'items' => self::normalizeOptions( (array) ( $dataset['items'] ?? [] ) ),
            ];
        }

        $discounts = [];
        foreach ( (array) ( $settings['payments']['discounts'] ?? [] ) as $discount ) {
            if ( ! is_array( $discount ) ) {
                continue;
            }
            $code = strtoupper( trim( \sanitize_text_field( (string) ( $discount['code'] ?? '' ) ) ) );
            if ( $code === '' ) {
                continue;
            }
            $discounts[] = [
                'code' => $code,
                'type' => in_array( (string) ( $discount['type'] ?? 'fixed' ), [ 'fixed', 'percent' ], true ) ? (string) $discount['type'] : 'fixed',
                'amount' => (float) ( $discount['amount'] ?? 0 ),
            ];
        }

        return [
            'confirmation' => [
                'message' => \metis_kses_post( (string) ( $settings['confirmation']['message'] ?? 'Thanks, your submission has been received.' ) ),
                'redirect_url' => esc_url_raw( (string) ( $settings['confirmation']['redirect_url'] ?? '' ) ),
            ],
            'notifications' => [
                'submitter' => self::normalizeNotificationProfile(
                    (array) ( $settings['notifications']['submitter'] ?? [] ),
                    [
                        'enabled' => true,
                        'subject' => 'We received your submission',
                        'message' => 'Thank you for your submission.',
                        'recipient_field' => '',
                        'emails' => [],
                        'rules' => [],
                    ]
                ),
                'receiver' => self::normalizeNotificationProfile(
                    (array) ( $settings['notifications']['receiver'] ?? [] ),
                    [
                        'enabled' => true,
                        'subject' => 'New form submission received',
                        'message' => 'A new submission has been received.',
                        'recipient_field' => '',
                        'emails' => [],
                        'rules' => [],
                    ]
                ),
                'webhook_url' => esc_url_raw( (string) ( $settings['notifications']['webhook_url'] ?? '' ) ),
            ],
            'payments' => [
                'enabled' => ! empty( $settings['payments']['enabled'] ),
                'currency' => strtolower( \sanitize_key( (string) ( $settings['payments']['currency'] ?? 'usd' ) ) ) ?: 'usd',
                'label' => \sanitize_text_field( (string) ( $settings['payments']['label'] ?? 'Total due' ) ),
                'discounts' => $discounts,
                'allow_discount_code' => ! empty( $settings['payments']['allow_discount_code'] ),
                'total_source' => in_array( (string) ( $settings['payments']['total_source'] ?? 'calculated' ), [ 'calculated', 'field_value' ], true )
                    ? (string) ( $settings['payments']['total_source'] ?? 'calculated' )
                    : 'calculated',
                'total_field_key' => \sanitize_key( (string) ( $settings['payments']['total_field_key'] ?? '' ) ),
                'processing_fees' => [
                    'enabled' => ! empty( $settings['payments']['processing_fees']['enabled'] ),
                    'mode' => in_array( (string) ( $settings['payments']['processing_fees']['mode'] ?? 'pass_through' ), [ 'pass_through', 'absorb' ], true )
                        ? (string) ( $settings['payments']['processing_fees']['mode'] ?? 'pass_through' )
                        : 'pass_through',
                    'percent' => max( 0, (float) ( $settings['payments']['processing_fees']['percent'] ?? 0 ) ),
                    'fixed' => max( 0, (float) ( $settings['payments']['processing_fees']['fixed'] ?? 0 ) ),
                    'apply_to' => in_array( (string) ( $settings['payments']['processing_fees']['apply_to'] ?? 'net' ), [ 'subtotal', 'net' ], true )
                        ? (string) ( $settings['payments']['processing_fees']['apply_to'] ?? 'net' )
                        : 'net',
                ],
            ],
            'design' => [
                'accent_color' => self::normalizeHexColor( (string) ( $settings['design']['accent_color'] ?? '#126497' ), '#126497' ),
                'button_bg' => self::normalizeHexColor( (string) ( $settings['design']['button_bg'] ?? '#126497' ), '#126497' ),
                'button_text' => self::normalizeHexColor( (string) ( $settings['design']['button_text'] ?? '#ffffff' ), '#ffffff' ),
                'field_radius' => in_array( (string) ( $settings['design']['field_radius'] ?? '14' ), [ '10', '14', '20' ], true )
                    ? (string) ( $settings['design']['field_radius'] ?? '14' )
                    : '14',
                'surface_style' => in_array( (string) ( $settings['design']['surface_style'] ?? 'clean' ), [ 'clean', 'soft', 'outline' ], true )
                    ? (string) ( $settings['design']['surface_style'] ?? 'clean' )
                    : 'clean',
            ],
            'custom_datasets' => $custom_datasets,
        ];
    }

    public static function ensureSchema(): void {
        SchemaManager::ensureSchema();
    }

    private static function hydrateForm( array $row ): array {
        global $wpdb;

        $versions_table = \Metis_Tables::get( 'form_versions' );
        $form_id = (int) $row['id'];
        $version_id = (int) ( $row['latest_version_id'] ?? 0 );
        $published_version_id = (int) ( $row['published_version_id'] ?? 0 );
        $versions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, version_number, notes, is_published, created_at FROM {$versions_table} WHERE form_id = %d ORDER BY version_number DESC",
                $form_id
            ),
            ARRAY_A
        ) ?: [];

        $schema_json = '';
        if ( $version_id > 0 ) {
            $schema_json = (string) $wpdb->get_var(
                $wpdb->prepare( "SELECT schema_json FROM {$versions_table} WHERE id = %d LIMIT 1", $version_id )
            );
        }

        return [
            'id' => $form_id,
            'form_uuid' => (string) ( $row['form_uuid'] ?? '' ),
            'slug' => (string) ( $row['slug'] ?? '' ),
            'name' => (string) ( $row['name'] ?? '' ),
            'description' => (string) ( $row['description'] ?? '' ),
            'status' => (string) ( $row['status'] ?? 'draft' ),
            'latest_version_id' => $version_id,
            'published_version_id' => $published_version_id,
            'settings' => self::decodeJson( $row['settings_json'] ?? null, self::normalizeSettings( [] ) ),
            'schema' => self::decodeJson( $schema_json, [] ),
            'versions' => $versions,
            'public_url' => Support::publicUrl( (string) ( $row['slug'] ?? '' ) ),
            'created_at' => (string) ( $row['created_at'] ?? '' ),
            'updated_at' => (string) ( $row['updated_at'] ?? '' ),
        ];
    }

    private static function getLatestVersion( int $form_id ): ?array {
        global $wpdb;
        $table = \Metis_Tables::get( 'form_versions' );
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %d ORDER BY version_number DESC LIMIT 1", $form_id ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    private static function formatSummary( array $row ): array {
        return [
            'id' => (int) ( $row['id'] ?? 0 ),
            'form_uuid' => (string) ( $row['form_uuid'] ?? '' ),
            'slug' => (string) ( $row['slug'] ?? '' ),
            'name' => (string) ( $row['name'] ?? '' ),
            'status' => (string) ( $row['status'] ?? 'draft' ),
            'submission_count' => (int) ( $row['submission_count'] ?? 0 ),
            'latest_version_number' => (int) ( $row['latest_version_number'] ?? 0 ),
            'last_submission_at' => (string) ( $row['last_submission_at'] ?? '' ),
            'updated_at' => (string) ( $row['updated_at'] ?? '' ),
            'public_url' => Support::publicUrl( (string) ( $row['slug'] ?? '' ) ),
        ];
    }

    private static function normalizeOptions( array $options ): array {
        $normalized = [];
        foreach ( $options as $option ) {
            if ( ! is_array( $option ) ) {
                continue;
            }
            $label = trim( \sanitize_text_field( (string) ( $option['label'] ?? '' ) ) );
            $value = trim( \sanitize_text_field( (string) ( $option['value'] ?? $label ) ) );
            if ( $label === '' || $value === '' ) {
                continue;
            }
            $normalized[] = [ 'label' => $label, 'value' => $value ];
            $category = trim( \sanitize_text_field( (string) ( $option['category'] ?? '' ) ) );
            $normalized[ array_key_last( $normalized ) ]['category'] = $category;
        }
        return $normalized;
    }

    private static function normalizeOptionsSource( array $source ): array {
        $type = \sanitize_key( (string) ( $source['type'] ?? '' ) );
        if ( ! in_array( $type, [ '', 'contacts', 'campaigns', 'events', 'grandys_stash_categories', 'grandys_stash_items', 'custom' ], true ) ) {
            $type = '';
        }

        return [
            'type' => $type,
            'limit' => max( 1, min( 500, (int) ( $source['limit'] ?? 100 ) ) ),
            'parent_field' => \sanitize_key( (string) ( $source['parent_field'] ?? '' ) ),
            'items' => self::normalizeOptions( (array) ( $source['items'] ?? [] ) ),
        ];
    }

    private static function normalizeConditions( array $conditions ): array {
        $normalized = [];
        foreach ( $conditions as $condition ) {
            if ( ! is_array( $condition ) ) {
                continue;
            }
            $source = \sanitize_key( (string) ( $condition['source'] ?? '' ) );
            $operator = in_array( (string) ( $condition['operator'] ?? 'equals' ), [ 'equals', 'not_equals', 'contains', 'gt', 'lt' ], true )
                ? (string) $condition['operator']
                : 'equals';
            $value = \sanitize_text_field( (string) ( $condition['value'] ?? '' ) );
            if ( $source === '' ) {
                continue;
            }
            $normalized[] = [
                'source' => $source,
                'operator' => $operator,
                'value' => $value,
            ];
        }
        return $normalized;
    }

    private static function normalizePricing( array $pricing ): array {
        $raw_type = (string) ( $pricing['type'] ?? 'fixed' );
        $type = in_array( $raw_type, [ 'fixed', 'choice', 'quantity' ], true )
            ? $raw_type
            : 'fixed';
        $choice_amounts = [];
        foreach ( (array) ( $pricing['choice_amounts'] ?? [] ) as $key => $amount ) {
            $choice_key = trim( \sanitize_text_field( (string) $key ) );
            if ( $choice_key === '' ) {
                continue;
            }
            $choice_amounts[ $choice_key ] = (float) $amount;
        }

        return [
            'enabled' => ! empty( $pricing['enabled'] ),
            'type' => $type,
            'amount' => (float) ( $pricing['amount'] ?? 0 ),
            'choice_amounts' => $choice_amounts,
        ];
    }

    private static function normalizeNotificationProfile( array $profile, array $defaults ): array {
        return [
            'enabled' => array_key_exists( 'enabled', $profile ) ? ! empty( $profile['enabled'] ) : ! empty( $defaults['enabled'] ),
            'subject' => \sanitize_text_field( (string) ( $profile['subject'] ?? $defaults['subject'] ?? '' ) ),
            'message' => \metis_kses_post( (string) ( $profile['message'] ?? $defaults['message'] ?? '' ) ),
            'recipient_field' => \sanitize_key( (string) ( $profile['recipient_field'] ?? $defaults['recipient_field'] ?? '' ) ),
            'emails' => self::normalizeEmails( is_array( $profile['emails'] ?? null ) ? implode( ',', (array) $profile['emails'] ) : (string) ( $profile['emails'] ?? '' ) ),
            'rules' => self::normalizeNotificationRules( (array) ( $profile['rules'] ?? $defaults['rules'] ?? [] ) ),
        ];
    }

    private static function normalizeNotificationRules( array $rules ): array {
        $normalized = [];
        foreach ( $rules as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }

            $source = \sanitize_key( (string) ( $rule['source'] ?? '' ) );
            if ( $source === '' ) {
                continue;
            }

            $normalized[] = [
                'source' => $source,
                'operator' => in_array( (string) ( $rule['operator'] ?? 'equals' ), [ 'equals', 'not_equals', 'contains' ], true )
                    ? (string) ( $rule['operator'] ?? 'equals' )
                    : 'equals',
                'value' => \sanitize_text_field( (string) ( $rule['value'] ?? '' ) ),
                'emails' => self::normalizeEmails( is_array( $rule['emails'] ?? null ) ? implode( ',', (array) $rule['emails'] ) : (string) ( $rule['emails'] ?? '' ) ),
                'subject' => \sanitize_text_field( (string) ( $rule['subject'] ?? '' ) ),
                'message' => \metis_kses_post( (string) ( $rule['message'] ?? '' ) ),
            ];
        }

        return $normalized;
    }

    private static function normalizeFieldFormat( string $type, string $format ): string {
        $format = \sanitize_key( $format );
        $allowed = match ( $type ) {
            'text' => [ '', 'phone_us', 'ssn', 'zip', 'uppercase' ],
            'number' => [ '', 'currency', 'integer' ],
            default => [ '' ],
        };

        return in_array( $format, $allowed, true ) ? $format : '';
    }

    private static function normalizeFieldValidation( array $validation ): array {
        return [
            'min_length' => max( 0, (int) ( $validation['min_length'] ?? 0 ) ),
            'max_length' => max( 0, (int) ( $validation['max_length'] ?? 0 ) ),
            'pattern' => trim( (string) ( $validation['pattern'] ?? '' ) ),
        ];
    }

    private static function normalizeHexColor( string $value, string $fallback ): string {
        $value = trim( $value );
        return preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? strtolower( $value ) : $fallback;
    }

    private static function normalizeEmails( string $raw ): array {
        $emails = [];
        foreach ( preg_split( '/[\s,;]+/', $raw ) ?: [] as $email ) {
            $email = trim( \sanitize_email( (string) $email ) );
            if ( $email !== '' && \is_email( $email ) ) {
                $emails[] = $email;
            }
        }
        return array_values( array_unique( $emails ) );
    }

    private static function resolveGrandysStashCategoryOptions(): array {
        global $wpdb;

        $table = \Metis_Tables::get( 'grandys_stash_catalog' );
        $rows = $wpdb->get_results(
            "SELECT category_slug, category_name, COUNT(*) AS item_count
             FROM {$table}
             WHERE is_active = 1
             GROUP BY category_slug, category_name
             ORDER BY category_name ASC",
            ARRAY_A
        ) ?: [];

        $options = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $label = trim( \sanitize_text_field( (string) ( $row['category_name'] ?? '' ) ) );
            $value = trim( \sanitize_title( (string) ( $row['category_slug'] ?? '' ) ) );
            if ( $label === '' || $value === '' ) {
                continue;
            }
            $options[] = [
                'label' => $label,
                'value' => $value,
                'category' => '',
            ];
        }

        return $options;
    }

    private static function resolveGrandysStashItemOptions(): array {
        global $wpdb;

        $table = \Metis_Tables::get( 'grandys_stash_catalog' );
        $rows = $wpdb->get_results(
            "SELECT item_name, item_slug, category_name, category_slug, sort_order
             FROM {$table}
             WHERE is_active = 1
             ORDER BY category_name ASC, sort_order ASC, item_name ASC",
            ARRAY_A
        ) ?: [];

        $options = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $label = trim( \sanitize_text_field( (string) ( $row['item_name'] ?? '' ) ) );
            $value = trim( \sanitize_title( (string) ( $row['item_slug'] ?? '' ) ) );
            $category = trim( \sanitize_title( (string) ( $row['category_slug'] ?? '' ) ) );
            if ( $label === '' || $value === '' ) {
                continue;
            }
            $options[] = [
                'label' => $label,
                'value' => $value,
                'category' => $category,
            ];
        }

        return $options;
    }

    private static function normalizeSubmissionPayload( array $schema, array $payload, array $files ): array {
        $normalized = [];
        foreach ( $schema as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $key = (string) ( $field['key'] ?? '' );
            $type = (string) ( $field['type'] ?? 'text' );
            if ( $key === '' ) {
                continue;
            }

            if ( $type === 'repeater' ) {
                $rows = is_array( $payload[ $key ] ?? null ) ? (array) $payload[ $key ] : [];
                $normalized_rows = [];
                foreach ( $rows as $row_index => $row_payload ) {
                    $row_payload = is_array( $row_payload ) ? $row_payload : [];
                    $normalized_rows[] = self::normalizeSubmissionPayload(
                        (array) ( $field['subfields'] ?? [] ),
                        $row_payload,
                        is_array( $files[ $key ][ $row_index ] ?? null ) ? (array) $files[ $key ][ $row_index ] : []
                    );
                }
                $normalized[ $key ] = $normalized_rows;
                continue;
            }

            $normalized_value = self::normalizeSubmissionValue(
                $type,
                $payload[ $key ] ?? null,
                is_array( $files[ $key ] ?? null ) ? $files[ $key ] : null
            );
            $normalized[ $key ] = self::applyFieldFormatting( $field, $normalized_value );
        }

        $discount_code = trim( \sanitize_text_field( (string) ( $payload['_discount_code'] ?? '' ) ) );
        if ( $discount_code !== '' ) {
            $normalized['_discount_code'] = strtoupper( $discount_code );
        }

        return $normalized;
    }

    private static function normalizeSubmissionValue( string $type, mixed $value, mixed $file ): mixed {
        return match ( $type ) {
            'email' => strtolower( trim( \sanitize_email( (string) $value ) ) ),
            'number' => is_numeric( $value ) ? (float) $value : 0,
            'checkbox' => is_array( $value )
                ? array_values( array_filter( array_map( static fn ( mixed $item ): string => trim( \sanitize_text_field( (string) $item ) ), $value ), static fn ( string $item ): bool => $item !== '' ) )
                : ( $value ? [ '1' ] : [] ),
            'file' => self::normalizeUploadedFile( $file ),
            'date' => \preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ? (string) $value : '',
            default => trim( \sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) ),
        };
    }

    private static function normalizeUploadedFile( mixed $file ): array {
        if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
            return [];
        }

        $uploaded = \metis_handle_upload( $file, [ 'test_form' => false ] );
        if ( ! is_array( $uploaded ) || ! empty( $uploaded['error'] ) ) {
            return [];
        }

        return [
            'name' => \sanitize_file_name( (string) ( $file['name'] ?? '' ) ),
            'url' => esc_url_raw( (string) ( $uploaded['url'] ?? '' ) ),
            'path' => (string) ( $uploaded['file'] ?? '' ),
            'type' => \sanitize_text_field( (string) ( $file['type'] ?? '' ) ),
        ];
    }

    private static function validateSubmission( array $schema, array $payload ): array {
        $errors = [];
        foreach ( $schema as $field ) {
            if ( ! is_array( $field ) || ! self::fieldVisible( $field, $payload ) ) {
                continue;
            }
            $key = (string) ( $field['key'] ?? '' );
            if ( $key === '' ) {
                continue;
            }
            $value = $payload[ $key ] ?? null;
            if ( ! empty( $field['required'] ) && self::valueIsEmpty( $value ) ) {
                $errors[ $key ] = (string) ( $field['label'] ?? $key ) . ' is required.';
                continue;
            }
            if ( ( $field['type'] ?? '' ) === 'email' && $value !== null && $value !== '' && ! \is_email( (string) $value ) ) {
                $errors[ $key ] = 'Please enter a valid email address.';
                continue;
            }
            $validation_error = self::validateFieldValue( $field, $value );
            if ( $validation_error !== '' ) {
                $errors[ $key ] = $validation_error;
            }
        }
        return $errors;
    }

    private static function fieldVisible( array $field, array $payload ): bool {
        $conditions = (array) ( $field['conditions'] ?? [] );
        if ( $conditions === [] ) {
            return true;
        }
        foreach ( $conditions as $condition ) {
            if ( ! is_array( $condition ) ) {
                continue;
            }
            $source = (string) ( $condition['source'] ?? '' );
            $operator = (string) ( $condition['operator'] ?? 'equals' );
            $expected = (string) ( $condition['value'] ?? '' );
            $actual = $payload[ $source ] ?? null;
            $actual_string = is_array( $actual ) ? implode( ',', $actual ) : (string) $actual;
            $pass = match ( $operator ) {
                'not_equals' => $actual_string !== $expected,
                'contains' => str_contains( strtolower( $actual_string ), strtolower( $expected ) ),
                'gt' => (float) $actual > (float) $expected,
                'lt' => (float) $actual < (float) $expected,
                default => $actual_string === $expected,
            };
            if ( ! $pass ) {
                return false;
            }
        }
        return true;
    }

    private static function calculateFieldPrice( array $field, mixed $value ): float {
        $pricing = (array) ( $field['pricing'] ?? [] );
        if ( empty( $pricing['enabled'] ) ) {
            return 0.0;
        }

        return match ( (string) ( $pricing['type'] ?? 'fixed' ) ) {
            'quantity' => max( 0, (float) $value ) * max( 0, (float) ( $pricing['amount'] ?? 0 ) ),
            'choice' => is_array( $value )
                ? array_sum( array_map( static fn ( mixed $selected ): float => (float) ( $pricing['choice_amounts'][ (string) $selected ] ?? 0 ), $value ) )
                : (float) ( $pricing['choice_amounts'][ (string) $value ] ?? 0 ),
            default => self::valueIsEmpty( $value ) ? 0.0 : max( 0, (float) ( $pricing['amount'] ?? 0 ) ),
        };
    }

    private static function extractSubmitterEmail( array $schema, array $payload ): ?string {
        foreach ( $schema as $field ) {
            if ( ! is_array( $field ) || ( $field['type'] ?? '' ) !== 'email' ) {
                continue;
            }
            $key = (string) ( $field['key'] ?? '' );
            $value = trim( (string) ( $payload[ $key ] ?? '' ) );
            if ( $value !== '' ) {
                return $value;
            }
        }
        return null;
    }

    private static function buildAutomationLog( array $form, array $payload, array $totals ): array {
        return [
            'notifications' => (array) ( $form['settings']['notifications'] ?? [] ),
            'confirmation' => (array) ( $form['settings']['confirmation'] ?? [] ),
            'totals' => $totals,
            'submitted_at' => gmdate( 'Y-m-d H:i:s' ),
            'payload_preview' => array_slice( $payload, 0, 5, true ),
        ];
    }

    private static function dispatchNotifications( array $form, array $payload, array $totals, string $submission_key ): void {
        $notifications = (array) ( $form['settings']['notifications'] ?? [] );
        self::sendNotificationProfile(
            (array) ( $notifications['submitter'] ?? [] ),
            $payload,
            $totals,
            $submission_key,
            true
        );
        self::sendNotificationProfile(
            (array) ( $notifications['receiver'] ?? [] ),
            $payload,
            $totals,
            $submission_key,
            false
        );
    }

    private static function sendNotificationProfile( array $profile, array $payload, array $totals, string $submission_key, bool $is_submitter ): void {
        if ( empty( $profile['enabled'] ) ) {
            return;
        }

        $recipients = $is_submitter
            ? self::resolveSubmitterRecipients( $profile, $payload )
            : self::resolveReceiverRecipients( $profile, $payload );

        if ( $recipients === [] ) {
            return;
        }

        $subject = self::renderNotificationTemplate( (string) ( $profile['subject'] ?? '' ), $payload, $totals, $submission_key );
        $message = self::renderNotificationTemplate( (string) ( $profile['message'] ?? '' ), $payload, $totals, $submission_key );

        foreach ( (array) ( $profile['rules'] ?? [] ) as $rule ) {
            if ( ! is_array( $rule ) || ! self::ruleMatchesPayload( $rule, $payload ) ) {
                continue;
            }
            $rule_recipients = self::normalizeEmails( is_array( $rule['emails'] ?? null ) ? implode( ',', (array) $rule['emails'] ) : (string) ( $rule['emails'] ?? '' ) );
            if ( ! $is_submitter && $rule_recipients !== [] ) {
                $recipients = array_values( array_unique( array_merge( $recipients, $rule_recipients ) ) );
            }
            if ( ! empty( $rule['subject'] ) ) {
                $subject = self::renderNotificationTemplate( (string) $rule['subject'], $payload, $totals, $submission_key );
            }
            if ( ! empty( $rule['message'] ) ) {
                $message = self::renderNotificationTemplate( (string) $rule['message'], $payload, $totals, $submission_key );
            }
        }

        if ( $subject === '' || $message === '' ) {
            return;
        }

        \metis_mail( $recipients, $subject, \metis_kses_post( $message ), [ 'Content-Type: text/html; charset=UTF-8' ] );
    }

    private static function resolveSubmitterRecipients( array $profile, array $payload ): array {
        $recipient_field = \sanitize_key( (string) ( $profile['recipient_field'] ?? '' ) );
        if ( $recipient_field !== '' ) {
            $candidate = trim( (string) ( $payload[ $recipient_field ] ?? '' ) );
            return self::normalizeEmails( $candidate );
        }

        foreach ( $payload as $value ) {
            if ( is_string( $value ) && \is_email( $value ) ) {
                return [ strtolower( $value ) ];
            }
        }

        return [];
    }

    private static function resolveReceiverRecipients( array $profile, array $payload ): array {
        $emails = self::normalizeEmails( is_array( $profile['emails'] ?? null ) ? implode( ',', (array) $profile['emails'] ) : (string) ( $profile['emails'] ?? '' ) );
        $recipient_field = \sanitize_key( (string) ( $profile['recipient_field'] ?? '' ) );
        if ( $recipient_field !== '' ) {
            $emails = array_values( array_unique( array_merge( $emails, self::normalizeEmails( (string) ( $payload[ $recipient_field ] ?? '' ) ) ) ) );
        }
        return $emails;
    }

    private static function renderNotificationTemplate( string $template, array $payload, array $totals, string $submission_key ): string {
        $replacements = [
            '{{submission_key}}' => $submission_key,
            '{{amount_total}}' => (string) ( $totals['amount_total'] ?? '0.00' ),
            '{{subtotal}}' => (string) ( $totals['subtotal'] ?? '0.00' ),
        ];
        foreach ( $payload as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( static fn ( mixed $item ): string => is_scalar( $item ) ? (string) $item : '', $value ) );
            }
            $replacements[ '{{' . $key . '}}' ] = is_scalar( $value ) ? (string) $value : '';
        }
        return strtr( $template, $replacements );
    }

    private static function ruleMatchesPayload( array $rule, array $payload ): bool {
        $source = (string) ( $rule['source'] ?? '' );
        $operator = (string) ( $rule['operator'] ?? 'equals' );
        $expected = (string) ( $rule['value'] ?? '' );
        $actual = $payload[ $source ] ?? '';
        $actual_string = is_array( $actual ) ? implode( ',', $actual ) : (string) $actual;

        return match ( $operator ) {
            'not_equals' => $actual_string !== $expected,
            'contains' => str_contains( strtolower( $actual_string ), strtolower( $expected ) ),
            default => $actual_string === $expected,
        };
    }

    private static function validateFieldValue( array $field, mixed $value ): string {
        $label = (string) ( $field['label'] ?? $field['key'] ?? 'Field' );
        $validation = (array) ( $field['validation'] ?? [] );
        $string_value = is_array( $value ) ? implode( ',', $value ) : trim( (string) $value );

        if ( $string_value === '' ) {
            return '';
        }

        $format = (string) ( $field['format'] ?? '' );
        if ( $format === 'phone_us' ) {
            $digits = preg_replace( '/\D+/', '', $string_value );
            if ( ! is_string( $digits ) || strlen( $digits ) !== 10 ) {
                return $label . ' must be a valid 10-digit phone number.';
            }
        } elseif ( $format === 'ssn' ) {
            $digits = preg_replace( '/\D+/', '', $string_value );
            if ( ! is_string( $digits ) || strlen( $digits ) !== 9 ) {
                return $label . ' must be a valid 9-digit SSN.';
            }
        } elseif ( $format === 'zip' ) {
            if ( ! preg_match( '/^\d{5}(?:-\d{4})?$/', $string_value ) ) {
                return $label . ' must be a valid ZIP code.';
            }
        }

        $min_length = (int) ( $validation['min_length'] ?? 0 );
        $max_length = (int) ( $validation['max_length'] ?? 0 );
        if ( $min_length > 0 && strlen( $string_value ) < $min_length ) {
            return $label . ' must be at least ' . $min_length . ' characters.';
        }
        if ( $max_length > 0 && strlen( $string_value ) > $max_length ) {
            return $label . ' must be at most ' . $max_length . ' characters.';
        }

        $pattern = trim( (string) ( $validation['pattern'] ?? '' ) );
        if ( $pattern !== '' ) {
            $wrapped = '#' . str_replace( '#', '\#', $pattern ) . '#';
            if ( @preg_match( $wrapped, $string_value ) !== 1 ) {
                return $label . ' is not in the expected format.';
            }
        }

        return '';
    }

    private static function applyFieldFormatting( array $field, mixed $value ): mixed {
        if ( is_array( $value ) ) {
            return $value;
        }

        $string_value = trim( (string) $value );
        if ( $string_value === '' ) {
            return $value;
        }

        return match ( (string) ( $field['format'] ?? '' ) ) {
            'phone_us' => self::formatPhoneUs( $string_value ),
            'ssn' => self::formatSsn( $string_value ),
            'zip' => self::formatZip( $string_value ),
            'uppercase' => strtoupper( $string_value ),
            default => $value,
        };
    }

    private static function calculateProcessingFees( float $subtotal, float $discount_total, array $config ): float {
        if ( empty( $config['enabled'] ) || ( $config['mode'] ?? 'pass_through' ) !== 'pass_through' ) {
            return 0.0;
        }

        $base = ( $config['apply_to'] ?? 'net' ) === 'subtotal'
            ? $subtotal
            : max( 0, $subtotal - $discount_total );

        return round( ( $base * ( (float) ( $config['percent'] ?? 0 ) / 100 ) ) + (float) ( $config['fixed'] ?? 0 ), 2 );
    }

    private static function formatPhoneUs( string $value ): string {
        $digits = preg_replace( '/\D+/', '', $value );
        if ( ! is_string( $digits ) || strlen( $digits ) !== 10 ) {
            return $value;
        }
        return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6, 4 );
    }

    private static function formatSsn( string $value ): string {
        $digits = preg_replace( '/\D+/', '', $value );
        if ( ! is_string( $digits ) || strlen( $digits ) !== 9 ) {
            return $value;
        }
        return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 2 ) . '-' . substr( $digits, 5, 4 );
    }

    private static function formatZip( string $value ): string {
        $digits = preg_replace( '/\D+/', '', $value );
        if ( ! is_string( $digits ) ) {
            return $value;
        }
        if ( strlen( $digits ) === 9 ) {
            return substr( $digits, 0, 5 ) . '-' . substr( $digits, 5, 4 );
        }
        return strlen( $digits ) === 5 ? $digits : $value;
    }

    private static function maybeCreatePaymentIntent( array $form, int $submission_id, array $totals ): array {
        $amount_total = (float) ( $totals['amount_total'] ?? 0 );
        if ( $amount_total <= 0 || ! self::hasPaymentField( (array) ( $form['schema'] ?? [] ) ) ) {
            return [ 'enabled' => false ];
        }

        if ( ! \function_exists( 'metis_stripe_init' ) ) {
            $stripe_bootstrap = dirname( __DIR__, 4 ) . '/includes/apis/stripe/bootstrap.php';
            if ( is_file( $stripe_bootstrap ) ) {
                require_once $stripe_bootstrap;
            }
        }

        if ( ! class_exists( '\Stripe\PaymentIntent' ) ) {
            return [ 'enabled' => true, 'configured' => false, 'error' => 'Stripe SDK is unavailable.' ];
        }

        \metis_stripe_init();
        if ( \Stripe\Stripe::getApiKey() === null ) {
            return [ 'enabled' => true, 'configured' => false, 'error' => 'Stripe is not configured.' ];
        }

        try {
            $intent = \Stripe\PaymentIntent::create( [
                'amount' => (int) round( $amount_total * 100 ),
                'currency' => strtolower( (string) ( $totals['currency'] ?? 'usd' ) ),
                'automatic_payment_methods' => [ 'enabled' => true ],
                'metadata' => [
                    'metis_form_id' => (string) ( $form['id'] ?? 0 ),
                    'metis_submission_id' => (string) $submission_id,
                    'metis_form_slug' => (string) ( $form['slug'] ?? '' ),
                ],
            ] );

            return [
                'enabled' => true,
                'configured' => true,
                'payment_intent_id' => (string) $intent->id,
                'client_secret' => (string) $intent->client_secret,
                'publishable_key' => (string) \Core_Settings_Service::get( 'stripe_publishable_key', '' ),
            ];
        } catch ( \Throwable $e ) {
            return [ 'enabled' => true, 'configured' => true, 'error' => $e->getMessage() ];
        }
    }

    private static function uniqueSlug( string $candidate, ?int $ignore_id = null ): string {
        global $wpdb;
        $table = \Metis_Tables::get( 'forms' );
        $base = \sanitize_title( $candidate );
        if ( $base === '' ) {
            $base = 'form';
        }
        $slug = $base;
        $suffix = 2;

        while ( true ) {
            $sql = "SELECT id FROM {$table} WHERE slug = %s";
            $params = [ $slug ];
            if ( $ignore_id ) {
                $sql .= ' AND id <> %d';
                $params[] = $ignore_id;
            }
            $exists = (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
            if ( $exists < 1 ) {
                return $slug;
            }
            $slug = $base . '-' . $suffix;
            $suffix++;
        }
    }

    private static function hasPaymentField( array $schema ): bool {
        foreach ( $schema as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            if ( ( $field['type'] ?? '' ) === 'payment' ) {
                return true;
            }
            if ( ! empty( $field['subfields'] ) && self::hasPaymentField( (array) $field['subfields'] ) ) {
                return true;
            }
        }

        return false;
    }

    private static function resolveContactsOptions( int $limit ): array {
        global $wpdb;
        $table = \Metis_Tables::get( 'contacts' );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cid, first_name, last_name, email FROM {$table} ORDER BY last_name ASC, first_name ASC LIMIT %d",
                max( 1, min( 500, $limit ) )
            ),
            ARRAY_A
        ) ?: [];

        return array_map( static function ( array $row ): array {
            $name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
            return [
                'label' => $name !== '' ? $name : (string) ( $row['email'] ?? $row['cid'] ?? '' ),
                'value' => (string) ( $row['cid'] ?? $row['email'] ?? '' ),
            ];
        }, $rows );
    }

    private static function resolveCampaignOptions(): array {
        global $wpdb;
        $table = \Metis_Tables::get( 'campaigns' );
        if ( ! $table ) {
            return [];
        }

        $rows = $wpdb->get_results( "SELECT id, name FROM {$table} ORDER BY name ASC LIMIT 200", ARRAY_A ) ?: [];
        return array_map( static fn ( array $row ): array => [
            'label' => (string) ( $row['name'] ?? '' ),
            'value' => (string) ( $row['id'] ?? '' ),
        ], $rows );
    }

    private static function resolveEventOptions(): array {
        global $wpdb;
        $table = \Metis_Tables::get( 'calendar_events' );
        $rows = $wpdb->get_results( "SELECT id, title, start_at FROM {$table} ORDER BY start_at DESC LIMIT 200", ARRAY_A ) ?: [];
        return array_map( static fn ( array $row ): array => [
            'label' => trim( (string) ( $row['title'] ?? 'Event' ) . ' ' . ( ! empty( $row['start_at'] ) ? '• ' . (string) $row['start_at'] : '' ) ),
            'value' => (string) ( $row['id'] ?? '' ),
        ], $rows );
    }

    private static function decodeJson( mixed $json, mixed $fallback ): mixed {
        if ( ! is_string( $json ) || $json === '' ) {
            return $fallback;
        }
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : $fallback;
    }

    private static function generateCode( string $prefix, string $table, string $column ): string {
        return \metis_generate_code( $prefix, $table, $column, 16 );
    }

    private static function valueIsEmpty( mixed $value ): bool {
        if ( is_array( $value ) ) {
            return $value === [];
        }
        return $value === null || $value === '';
    }

    private static function normalizeIncomingFiles( array $files ): array {
        $normalized = [];
        foreach ( $files as $field => $spec ) {
            if ( ! is_string( $field ) || ! is_array( $spec ) ) {
                continue;
            }
            $normalized[ $field ] = self::normalizeFileSpec( $spec );
        }
        return $normalized;
    }

    private static function normalizeFileSpec( array $spec ): mixed {
        $keys = [ 'name', 'type', 'tmp_name', 'error', 'size' ];
        foreach ( $keys as $key ) {
            if ( ! array_key_exists( $key, $spec ) ) {
                return [];
            }
        }

        if ( ! is_array( $spec['name'] ) ) {
            return $spec;
        }

        return self::expandNestedFileSpec( $spec );
    }

    private static function expandNestedFileSpec( array $spec, array $path = [] ): array {
        $cursor = self::fileValueAtPath( $spec['name'], $path );
        if ( ! is_array( $cursor ) ) {
            return [
                'name' => self::fileValueAtPath( $spec['name'], $path ),
                'type' => self::fileValueAtPath( $spec['type'], $path ),
                'tmp_name' => self::fileValueAtPath( $spec['tmp_name'], $path ),
                'error' => self::fileValueAtPath( $spec['error'], $path ),
                'size' => self::fileValueAtPath( $spec['size'], $path ),
            ];
        }

        $output = [];
        foreach ( array_keys( $cursor ) as $key ) {
            $output[ $key ] = self::expandNestedFileSpec( $spec, array_merge( $path, [ $key ] ) );
        }
        return $output;
    }

    private static function fileValueAtPath( mixed $value, array $path ): mixed {
        foreach ( $path as $segment ) {
            if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
                return null;
            }
            $value = $value[ $segment ];
        }
        return $value;
    }
}
