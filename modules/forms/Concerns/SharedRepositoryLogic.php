<?php
declare(strict_types=1);

namespace Metis\Modules\Forms\Concerns;

use Metis\Modules\Donations\CampaignService;
use Metis\Modules\Donations\DonationsModule;
use Metis\Modules\Finance\FinanceV2Service;
use Metis\Modules\Forms\FormConditionEvaluator;

trait SharedRepositoryLogic {
    private static ?array $campaignOptions = null;
    private static ?array $userOptions = null;
    private static ?array $grandyCatalog = null;

    private static function hydrateFormRow( array $row, bool $published_only ): array {
        $version_id = $published_only
            ? (int) ( $row['published_version_id'] ?? 0 )
            : (int) ( $row['latest_version_id'] ?? 0 );

        if ( $version_id < 1 ) {
            $version_id = (int) ( $row['latest_version_id'] ?? $row['published_version_id'] ?? 0 );
        }

        $version = $version_id > 0 ? self::loadVersion( $version_id ) : null;
        $settings = self::normalizeSettings( self::decodeJson( $row['settings_json'] ?? '' ) );
        $schema = self::normalizeSchema( self::decodeJson( $version['schema_json'] ?? '[]' ), $settings );
        [ $schema, $settings ] = self::canonicalizeConditionalReferences( $schema, $settings );
        $payment_summary = self::paymentSummaryFromSchema( $schema, $settings );
        $settings['payments'] = $payment_summary;

        return [
            'id'                   => (int) ( $row['id'] ?? 0 ),
            'form_uuid'            => (string) ( $row['form_uuid'] ?? '' ),
            'slug'                 => (string) ( $row['slug'] ?? '' ),
            'name'                 => (string) ( $row['name'] ?? '' ),
            'description'          => (string) ( $row['description'] ?? '' ),
            'status'               => (string) ( $row['status'] ?? 'draft' ),
            'latest_version_id'    => (int) ( $row['latest_version_id'] ?? 0 ),
            'published_version_id' => (int) ( $row['published_version_id'] ?? 0 ),
            'version_id'           => $version_id,
            'version_number'       => (int) ( $version['version_number'] ?? 0 ),
            'schema'               => $schema,
            'settings'             => $settings,
            'payment_enabled'      => ! empty( $payment_summary['enabled'] ) ? 1 : 0,
            'payments_enabled'     => ! empty( $payment_summary['enabled'] ),
            'public_url'           => \Metis\Modules\Forms\Support::publicUrl( (string) ( $row['slug'] ?? '' ) ),
            'module_label'         => self::moduleLabel( (string) ( $settings['binding']['module'] ?? '' ) ),
            'versions'             => self::listVersions( (int) ( $row['id'] ?? 0 ) ),
        ];
    }

    private static function normalizeIncomingForm( array $payload ): array {
        $name = trim( metis_text_clean( (string) ( $payload['name'] ?? '' ) ) );
        $slug = metis_slug_clean( (string) ( $payload['slug'] ?? $name ) );
        $description = trim( metis_textarea_clean( (string) ( $payload['description'] ?? '' ) ) );
        $settings = self::normalizeSettings( $payload['settings'] ?? [] );
        $schema = self::normalizeSchema( $payload['schema'] ?? [], $settings );
        [ $schema, $settings ] = self::canonicalizeConditionalReferences( $schema, $settings );
        $payment_summary = self::paymentSummaryFromSchema( $schema, $settings );
        $settings['payments'] = $payment_summary;

        $status = metis_key_clean( (string) ( $payload['status'] ?? 'draft' ) );
        if ( ! in_array( $status, [ 'draft', 'published', 'archived' ], true ) ) {
            $status = 'draft';
        }

        return [
            'id'              => (int) ( $payload['id'] ?? 0 ),
            'name'            => $name,
            'slug'            => $slug,
            'description'     => $description,
            'status'          => $status,
            'settings'        => $settings,
            'schema'          => $schema,
            'payment_enabled' => ! empty( $payment_summary['enabled'] ),
        ];
    }

    public static function normalizeSettings( mixed $settings ): array {
        $settings = is_array( $settings ) ? $settings : [];
        $defaults = self::defaultSettings();
        $email_defaults = self::emailDefaults();

        $normalized = $defaults;
        $normalized['binding']['module'] = metis_key_clean( (string) ( $settings['binding']['module'] ?? $defaults['binding']['module'] ) );
        $normalized['binding']['flow'] = metis_key_clean( (string) ( $settings['binding']['flow'] ?? $defaults['binding']['flow'] ) );
        $normalized['binding']['campaign_code'] = self::normalizeCampaignCode( (string) ( $settings['binding']['campaign_code'] ?? '' ) );
        $normalized['binding']['rules'] = self::normalizeBindingRules(
            $settings['binding']['rules'] ?? [],
            $normalized['binding']['module']
        );

        $normalized['access']['mode'] = metis_key_clean( (string) ( $settings['access']['mode'] ?? $defaults['access']['mode'] ) );
        if ( ! in_array( $normalized['access']['mode'], [ 'public', 'logged_in', 'password', 'role' ], true ) ) {
            $normalized['access']['mode'] = 'public';
        }
        $normalized['access']['password'] = trim( (string) ( $settings['access']['password'] ?? '' ) );
        $normalized['access']['denied_message'] = trim( metis_textarea_clean( (string) ( $settings['access']['denied_message'] ?? $defaults['access']['denied_message'] ) ) );
        $normalized['access']['roles'] = array_values(
            array_filter(
                array_map( static fn ( $role ): string => metis_key_clean( (string) $role ), (array) ( $settings['access']['roles'] ?? [] ) )
            )
        );

        $normalized['schedule']['enabled'] = ! empty( $settings['schedule']['enabled'] );
        $normalized['schedule']['start_at'] = self::normalizeDateTime( (string) ( $settings['schedule']['start_at'] ?? '' ) );
        $normalized['schedule']['end_at'] = self::normalizeDateTime( (string) ( $settings['schedule']['end_at'] ?? '' ) );
        $normalized['schedule']['closed_message'] = trim( metis_textarea_clean( (string) ( $settings['schedule']['closed_message'] ?? $defaults['schedule']['closed_message'] ) ) );

        $normalized['confirmation']['custom_enabled'] = ! empty( $settings['confirmation']['custom_enabled'] );
        $normalized['confirmation']['message'] = trim( metis_textarea_clean( (string) ( $settings['confirmation']['message'] ?? $defaults['confirmation']['message'] ) ) );

        $normalized['notifications']['submitter']['enabled'] = ! empty( $settings['notifications']['submitter']['enabled'] );
        $normalized['notifications']['submitter']['recipient_field'] = metis_key_clean( (string) ( $settings['notifications']['submitter']['recipient_field'] ?? '' ) );
        $normalized['notifications']['submitter']['from_name'] = self::normalizedSenderName(
            $settings['notifications']['submitter']['from_name'] ?? '',
            (string) $email_defaults['from_name']
        );
        $normalized['notifications']['submitter']['from_email'] = self::normalizedSenderEmail(
            $settings['notifications']['submitter']['from_email'] ?? '',
            (string) $email_defaults['from_email']
        );
        $normalized['notifications']['submitter']['include_submission_data'] = ! empty( $settings['notifications']['submitter']['include_submission_data'] );
        $normalized['notifications']['submitter']['subject'] = trim( metis_text_clean( (string) ( $settings['notifications']['submitter']['subject'] ?? $defaults['notifications']['submitter']['subject'] ) ) );
        $normalized['notifications']['submitter']['message'] = self::normalizeNotificationMessageHtml(
            (string) ( $settings['notifications']['submitter']['message'] ?? $defaults['notifications']['submitter']['message'] )
        );

        $normalized['notifications']['internal']['enabled'] = ! empty( $settings['notifications']['internal']['enabled'] );
        $normalized['notifications']['internal']['general_email'] = strtolower( trim( metis_email_clean( (string) ( $settings['notifications']['internal']['general_email'] ?? '' ) ) ) );
        $normalized['notifications']['internal']['from_name'] = self::normalizedSenderName(
            $settings['notifications']['internal']['from_name'] ?? '',
            (string) $email_defaults['from_name']
        );
        $normalized['notifications']['internal']['from_email'] = self::normalizedSenderEmail(
            $settings['notifications']['internal']['from_email'] ?? '',
            (string) $email_defaults['from_email']
        );
        $normalized['notifications']['internal']['include_submission_data'] = ! empty( $settings['notifications']['internal']['include_submission_data'] );
        $normalized['notifications']['internal']['default_user_ids'] = array_values(
            array_filter(
                array_map( static fn ( $id ): int => (int) $id, (array) ( $settings['notifications']['internal']['default_user_ids'] ?? [] ) ),
                static fn ( int $id ): bool => $id > 0
            )
        );
        $normalized['notifications']['internal']['routing_field'] = metis_key_clean( (string) ( $settings['notifications']['internal']['routing_field'] ?? '' ) );
        $normalized['notifications']['internal']['subject'] = trim( metis_text_clean( (string) ( $settings['notifications']['internal']['subject'] ?? $defaults['notifications']['internal']['subject'] ) ) );
        $normalized['notifications']['internal']['message'] = self::normalizeNotificationMessageHtml(
            (string) ( $settings['notifications']['internal']['message'] ?? $defaults['notifications']['internal']['message'] )
        );
        $normalized['notifications']['internal']['routes'] = self::normalizeRoutes( $settings['notifications']['internal']['routes'] ?? [] );

        $normalized['payments'] = self::normalizePaymentSettings( $settings['payments'] ?? [] );

        return $normalized;
    }

    public static function normalizeSchema( mixed $schema, array $settings ): array {
        $legacy_payment = (array) ( $settings['payments'] ?? [] );
        $schema = is_array( $schema ) ? array_values( $schema ) : [];
        $normalized = [];

        foreach ( $schema as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $normalized[] = self::normalizeField( $field, $legacy_payment );
        }

        if ( ! self::containsPaymentField( $normalized ) && ! empty( $legacy_payment['enabled'] ) ) {
            $normalized[] = self::normalizeField(
                [
                    'id'    => 'payment_field',
                    'key'   => 'payment',
                    'type'  => 'payment',
                    'label' => 'Payment',
                ],
                $legacy_payment
            );
        }

        return $normalized;
    }

    private static function normalizeField( array $field, array $legacy_payment ): array {
        $type = metis_key_clean( (string) ( $field['type'] ?? 'text' ) );
        if ( ! in_array( $type, [ 'text', 'email', 'number', 'textarea', 'select', 'radio', 'checkbox', 'date', 'repeater', 'payment' ], true ) ) {
            $type = 'text';
        }

        $id = metis_key_clean( (string) ( $field['id'] ?? $field['key'] ?? 'field_' . self::randomFieldToken() ) );
        $key = metis_key_clean( (string) ( $field['key'] ?? $id ) );
        if ( $key === '' ) {
            $key = $id !== '' ? $id : 'field_' . self::randomFieldToken();
        }

        $normalized = [
            'id'             => $id !== '' ? $id : $key,
            'key'            => $key,
            'type'           => $type,
            'label'          => trim( metis_text_clean( (string) ( $field['label'] ?? ucwords( str_replace( '_', ' ', $key ) ) ) ) ),
            'help'           => trim( metis_textarea_clean( (string) ( $field['help'] ?? '' ) ) ),
            'placeholder'    => trim( metis_text_clean( (string) ( $field['placeholder'] ?? '' ) ) ),
            'mask'           => self::normalizeMask( $field['mask'] ?? '' ),
            'required'       => ! empty( $field['required'] ),
            'width'          => in_array( (string) ( $field['width'] ?? 'full' ), [ 'full', 'half', 'narrow' ], true )
                ? (string) ( $field['width'] ?? 'full' )
                : 'full',
            'searchable'     => ! empty( $field['searchable'] ),
            'validation'     => self::normalizeValidation( $field['validation'] ?? [] ),
            'conditions'     => self::normalizeConditions( $field['conditions'] ?? [] ),
            'options'        => [],
            'options_source' => [
                'type'         => '',
                'parent_field' => '',
                'items'        => [],
            ],
            'repeat_limit'   => max( 1, min( 25, (int) ( $field['repeat_limit'] ?? 5 ) ) ),
            'subfields'      => [],
            'payment'        => [],
        ];

        if ( in_array( $type, [ 'select', 'radio', 'checkbox' ], true ) ) {
            $source = is_array( $field['options_source'] ?? null ) ? $field['options_source'] : [];
            $source_type = metis_key_clean( (string) ( $source['type'] ?? '' ) );
            if ( ! in_array( $source_type, [ '', 'static', 'grandys_categories', 'grandys_items', 'campaigns' ], true ) ) {
                $source_type = '';
            }

            $normalized['options_source']['type'] = $source_type;
            $normalized['options_source']['parent_field'] = metis_key_clean( (string) ( $source['parent_field'] ?? '' ) );
            $normalized['options_source']['items'] = self::normalizeOptions( $source['items'] ?? [] );
            $normalized['options'] = self::normalizeOptions( $field['options'] ?? [] );

            if ( is_string( $field['options_text'] ?? null ) && $normalized['options'] === [] ) {
                $normalized['options'] = self::parseOptionsText( (string) $field['options_text'] );
            }

            if ( $source_type === 'grandys_categories' ) {
                $normalized['options_source']['items'] = self::grandyCategoryOptions();
                $normalized['options'] = self::grandyCategoryOptions();
            } elseif ( $source_type === 'grandys_items' ) {
                $normalized['options_source']['items'] = self::grandyItemOptions();
                $normalized['options'] = $normalized['options_source']['parent_field'] !== ''
                    ? []
                    : self::grandyItemOptions();
            } elseif ( $source_type === 'campaigns' ) {
                $normalized['options_source']['items'] = self::campaignOptions();
                $normalized['options'] = self::campaignOptions();
            }
        }

        if ( $type === 'repeater' ) {
            $normalized['subfields'] = [];
            foreach ( (array) ( $field['subfields'] ?? [] ) as $subfield ) {
                if ( ! is_array( $subfield ) ) {
                    continue;
                }
                $normalized['subfields'][] = self::normalizeField( $subfield, $legacy_payment );
            }
        }

        if ( $type === 'payment' ) {
            $normalized['width'] = 'full';
            $normalized['payment'] = self::normalizePaymentSettings( $field['payment'] ?? [], $legacy_payment );
        }

        return $normalized;
    }

    private static function normalizeValidation( mixed $validation ): array {
        $validation = is_array( $validation ) ? $validation : [];

        return [
            'min_length' => max( 0, (int) ( $validation['min_length'] ?? 0 ) ),
            'max_length' => max( 0, (int) ( $validation['max_length'] ?? 0 ) ),
            'min_value'  => self::nullableNumber( $validation['min_value'] ?? null ),
            'max_value'  => self::nullableNumber( $validation['max_value'] ?? null ),
        ];
    }

    private static function normalizeMask( mixed $mask ): string {
        $normalized = metis_key_clean( (string) $mask );

        return in_array( $normalized, [ '', 'phone_us', 'zip_us', 'zip_plus4_us' ], true )
            ? $normalized
            : '';
    }

    private static function randomFieldToken(): string {
        try {
            return substr( bin2hex( random_bytes( 8 ) ), 0, 8 );
        } catch ( \Throwable ) {
            return substr( md5( uniqid( 'field_', true ) ), 0, 8 );
        }
    }

    private static function normalizeConditions( mixed $conditions ): array {
        $conditions = is_array( $conditions ) ? $conditions : [];
        $normalized = [];

        foreach ( $conditions as $condition ) {
            if ( ! is_array( $condition ) ) {
                continue;
            }

            $field = metis_key_clean( (string) ( $condition['field'] ?? '' ) );
            $operator = metis_key_clean( (string) ( $condition['operator'] ?? 'equals' ) );
            if ( $field === '' || ! in_array( $operator, [ 'equals', 'not_equals', 'contains', 'empty', 'not_empty' ], true ) ) {
                continue;
            }

            $normalized[] = [
                'field'    => $field,
                'operator' => $operator,
                'value'    => is_array( $condition['value'] ?? null ) ? array_values( $condition['value'] ) : trim( (string) ( $condition['value'] ?? '' ) ),
            ];
        }

        return $normalized;
    }

    private static function normalizeBindingRules( mixed $rules, string $module ): array {
        $rules = is_array( $rules ) ? $rules : [];
        $normalized = [];
        $allowed_flows = array_values(
            array_filter(
                array_map(
                    static fn ( mixed $flow ): string => metis_key_clean( (string) ( is_array( $flow ) ? ( $flow['value'] ?? '' ) : '' ) ),
                    (array) ( \Metis\Modules\Forms\Support::moduleFlows()[ $module ] ?? [] )
                )
            )
        );

        foreach ( $rules as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }

            $conditions = self::normalizeConditions(
                [
                    [
                        'field'    => $rule['field'] ?? '',
                        'operator' => $rule['operator'] ?? 'equals',
                        'value'    => $rule['value'] ?? '',
                    ],
                ]
            );
            if ( $conditions === [] ) {
                continue;
            }

            $flow = metis_key_clean( (string) ( $rule['flow'] ?? '' ) );
            if ( $flow !== '' && ! in_array( $flow, $allowed_flows, true ) ) {
                $flow = '';
            }

            $normalized[] = [
                'field'    => (string) $conditions[0]['field'],
                'operator' => (string) $conditions[0]['operator'],
                'value'    => $conditions[0]['value'],
                'flow'     => $flow,
            ];
        }

        return $normalized;
    }

    private static function normalizeRoutes( mixed $routes ): array {
        $routes = is_array( $routes ) ? $routes : [];
        $normalized = [];

        foreach ( $routes as $route ) {
            if ( ! is_array( $route ) ) {
                continue;
            }

            $value = trim( metis_text_clean( (string) ( $route['value'] ?? '' ) ) );
            $user_id = (int) ( $route['user_id'] ?? 0 );
            if ( $value === '' || $user_id < 1 ) {
                continue;
            }

            $normalized[] = [
                'value'   => $value,
                'user_id' => $user_id,
            ];
        }

        return $normalized;
    }

    private static function normalizePaymentSettings( mixed $payment, array $fallback = [] ): array {
        $payment = is_array( $payment ) ? $payment : [];
        $defaults = array_merge( self::paymentDefaults(), is_array( $fallback ) ? $fallback : [] );
        $currency = strtolower( trim( metis_text_clean( (string) ( $payment['currency'] ?? $fallback['currency'] ?? 'usd' ) ) ) );
        if ( $currency === '' ) {
            $currency = 'usd';
        }

        return [
            'enabled'             => ! empty( $payment['enabled'] ) || ! empty( $fallback['enabled'] ),
            'mode'                => 'donation',
            'campaign_code'       => self::normalizeCampaignCode( (string) ( $payment['campaign_code'] ?? $fallback['campaign_code'] ?? '' ) ),
            'currency'            => $currency,
            'donation_amounts'    => self::normalizeMoneyChoices( $payment['donation_amounts'] ?? $fallback['donation_amounts'] ?? [ 25, 50, 100 ] ),
            'allow_custom_amount' => ! empty( $payment['allow_custom_amount'] ) || ! empty( $fallback['allow_custom_amount'] ),
            'custom_amount_label' => trim( metis_text_clean( (string) ( $payment['custom_amount_label'] ?? $fallback['custom_amount_label'] ?? 'Other amount' ) ) ),
            'cover_fees_enabled'  => ! empty( $payment['cover_fees_enabled'] ) || ! empty( $fallback['cover_fees_enabled'] ),
            'cover_fees_label'    => trim( metis_text_clean( (string) ( $payment['cover_fees_label'] ?? $fallback['cover_fees_label'] ?? $defaults['cover_fees_label'] ) ) ),
            'fee_percent'         => max( 0.0, (float) ( $payment['fee_percent'] ?? $fallback['fee_percent'] ?? $defaults['fee_percent'] ) ),
            'fee_fixed'           => max( 0.0, (float) ( $payment['fee_fixed'] ?? $fallback['fee_fixed'] ?? $defaults['fee_fixed'] ) ),
            'summary_label'       => trim( metis_text_clean( (string) ( $payment['summary_label'] ?? $fallback['summary_label'] ?? 'Total' ) ) ),
            'success_message'     => trim( metis_textarea_clean( (string) ( $payment['success_message'] ?? $fallback['success_message'] ?? 'Thanks, your submission has been received.' ) ) ),
        ];
    }

    private static function normalizeOptions( mixed $options ): array {
        $options = is_array( $options ) ? $options : [];
        $normalized = [];
        foreach ( $options as $option ) {
            if ( ! is_array( $option ) ) {
                continue;
            }
            $label = trim( metis_text_clean( (string) ( $option['label'] ?? '' ) ) );
            $value = trim( metis_slug_clean( (string) ( $option['value'] ?? $label ) ) );
            if ( $label === '' || $value === '' ) {
                continue;
            }
            $normalized[] = [
                'label'    => $label,
                'value'    => $value,
                'category' => trim( metis_slug_clean( (string) ( $option['category'] ?? '' ) ) ),
            ];
        }

        return $normalized;
    }

    private static function parseOptionsText( string $raw ): array {
        $lines = preg_split( '/\r\n|\r|\n/', $raw ) ?: [];
        $options = [];
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            $parts = array_map( 'trim', explode( '|', $line ) );
            $label = $parts[0] ?? '';
            $value = $parts[1] ?? $label;
            $category = $parts[2] ?? '';
            $options[] = [
                'label'    => metis_text_clean( $label ),
                'value'    => metis_slug_clean( $value ),
                'category' => metis_slug_clean( $category ),
            ];
        }

        return self::normalizeOptions( $options );
    }

    private static function normalizeSubmission( array $form, array $payload ): array {
        $schema = (array) ( $form['schema'] ?? [] );
        $normalized = [];
        $errors = [];

        foreach ( $schema as $field ) {
            if ( ! is_array( $field ) || ( $field['type'] ?? '' ) === 'payment' ) {
                continue;
            }

            if ( ! self::fieldIsVisible( $field, $payload ) ) {
                continue;
            }

            $result = self::normalizeFieldSubmission( $field, $payload[ (string) $field['key'] ] ?? null, $payload );
            if ( $result['error'] !== '' ) {
                $errors[ (string) $field['key'] ] = $result['error'];
                continue;
            }

            $normalized[ (string) $field['key'] ] = $result['value'];
        }

        if ( self::paymentField( $schema ) !== null ) {
            $normalized['_donation_frequency'] = \class_exists( \Metis\Modules\Donations\RecurringDonationsService::class )
                ? \Metis\Modules\Donations\RecurringDonationsService::normalizeFrequency( $payload['_donation_frequency'] ?? 'one_time' )
                : 'one_time';
        }

        return [
            'normalized' => $normalized,
            'errors'     => $errors,
            'raw'        => $payload,
        ];
    }

    private static function normalizeFieldSubmission( array $field, mixed $value, array $context ): array {
        $type = (string) ( $field['type'] ?? 'text' );
        $required = ! empty( $field['required'] );
        $key = (string) ( $field['key'] ?? '' );
        $validation = (array) ( $field['validation'] ?? [] );

        if ( $type === 'repeater' ) {
            $rows = is_array( $value ) ? array_values( $value ) : [];
            $normalized_rows = [];
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $normalized_row = [];
                foreach ( (array) ( $field['subfields'] ?? [] ) as $subfield ) {
                    if ( ! is_array( $subfield ) ) {
                        continue;
                    }
                    if ( ! self::fieldIsVisible( $subfield, $row ) ) {
                        continue;
                    }
                    $result = self::normalizeFieldSubmission( $subfield, $row[ (string) $subfield['key'] ] ?? null, $row );
                    if ( $result['error'] !== '' ) {
                        return [ 'value' => [], 'error' => $result['error'] ];
                    }
                    $normalized_row[ (string) $subfield['key'] ] = $result['value'];
                }
                if ( $normalized_row !== [] ) {
                    $normalized_rows[] = $normalized_row;
                }
            }

            if ( $required && $normalized_rows === [] ) {
                return [ 'value' => [], 'error' => (string) ( $field['label'] ?? $key ) . ' is required.' ];
            }

            return [ 'value' => $normalized_rows, 'error' => '' ];
        }

        if ( $type === 'checkbox' ) {
            $values = is_array( $value ) ? array_values( array_filter( array_map( static fn ( $item ): string => trim( metis_text_clean( (string) $item ) ), $value ) ) ) : [];
            if ( $required && $values === [] ) {
                return [ 'value' => [], 'error' => (string) ( $field['label'] ?? $key ) . ' is required.' ];
            }
            return [ 'value' => $values, 'error' => '' ];
        }

        $scalar = is_scalar( $value ) ? trim( (string) $value ) : '';

        if ( $type === 'email' ) {
            $scalar = strtolower( trim( metis_email_clean( $scalar ) ) );
            if ( $required && $scalar === '' ) {
                return [ 'value' => '', 'error' => (string) ( $field['label'] ?? $key ) . ' is required.' ];
            }
            if ( $scalar !== '' && ! metis_email_is_valid( $scalar ) ) {
                return [ 'value' => '', 'error' => 'Enter a valid email address.' ];
            }
            return [ 'value' => $scalar, 'error' => '' ];
        }

        if ( $type === 'number' ) {
            if ( $required && $scalar === '' ) {
                return [ 'value' => '', 'error' => (string) ( $field['label'] ?? $key ) . ' is required.' ];
            }
            if ( $scalar === '' ) {
                return [ 'value' => '', 'error' => '' ];
            }
            if ( ! is_numeric( $scalar ) ) {
                return [ 'value' => '', 'error' => 'Enter a valid number.' ];
            }
            $number = (float) $scalar;
            if ( ( $validation['min_value'] ?? null ) !== null && $number < (float) $validation['min_value'] ) {
                return [ 'value' => '', 'error' => 'The value is below the minimum allowed.' ];
            }
            if ( ( $validation['max_value'] ?? null ) !== null && $number > (float) $validation['max_value'] ) {
                return [ 'value' => '', 'error' => 'The value is above the maximum allowed.' ];
            }
            return [ 'value' => $number, 'error' => '' ];
        }

        if ( $type === 'date' ) {
            if ( $required && $scalar === '' ) {
                return [ 'value' => '', 'error' => (string) ( $field['label'] ?? $key ) . ' is required.' ];
            }
            if ( $scalar !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $scalar ) ) {
                return [ 'value' => '', 'error' => 'Choose a valid date.' ];
            }
            return [ 'value' => $scalar, 'error' => '' ];
        }

        if ( in_array( $type, [ 'select', 'radio' ], true ) ) {
            if ( $required && $scalar === '' ) {
                return [ 'value' => '', 'error' => (string) ( $field['label'] ?? $key ) . ' is required.' ];
            }
            $options = self::fieldOptionsForSubmission( $field, $context );
            if ( $scalar !== '' && $options !== [] ) {
                $allowed = array_column( $options, 'value' );
                if ( ! in_array( $scalar, $allowed, true ) ) {
                    return [ 'value' => '', 'error' => 'Choose a valid option.' ];
                }
            }
            return [ 'value' => $scalar, 'error' => '' ];
        }

        $scalar = in_array( $type, [ 'text', 'textarea' ], true )
            ? trim( metis_textarea_clean( $scalar ) )
            : trim( metis_text_clean( $scalar ) );

        if ( $required && $scalar === '' ) {
            return [ 'value' => '', 'error' => (string) ( $field['label'] ?? $key ) . ' is required.' ];
        }
        $mask = self::normalizeMask( $field['mask'] ?? '' );
        if ( $scalar !== '' && $mask !== '' ) {
            $mask_result = self::normalizeMaskedValue( $scalar, $mask );
            if ( $mask_result['error'] !== '' ) {
                return $mask_result;
            }
            $scalar = $mask_result['value'];
        }
        if ( (int) ( $validation['min_length'] ?? 0 ) > 0 && strlen( $scalar ) < (int) $validation['min_length'] && $scalar !== '' ) {
            return [ 'value' => '', 'error' => 'The entry is shorter than allowed.' ];
        }
        if ( (int) ( $validation['max_length'] ?? 0 ) > 0 && strlen( $scalar ) > (int) $validation['max_length'] ) {
            return [ 'value' => '', 'error' => 'The entry is longer than allowed.' ];
        }

        return [ 'value' => $scalar, 'error' => '' ];
    }

    private static function normalizeMaskedValue( string $value, string $mask ): array {
        $digits = preg_replace( '/\D+/', '', $value ) ?? '';

        if ( $mask === 'phone_us' ) {
            if ( strlen( $digits ) !== 10 ) {
                return [ 'value' => '', 'error' => 'Enter a valid 10-digit phone number.' ];
            }

            return [
                'value' => sprintf( '(%s) %s-%s', substr( $digits, 0, 3 ), substr( $digits, 3, 3 ), substr( $digits, 6, 4 ) ),
                'error' => '',
            ];
        }

        if ( $mask === 'zip_us' ) {
            if ( strlen( $digits ) !== 5 ) {
                return [ 'value' => '', 'error' => 'Enter a valid 5-digit ZIP code.' ];
            }

            return [ 'value' => $digits, 'error' => '' ];
        }

        if ( $mask === 'zip_plus4_us' ) {
            if ( strlen( $digits ) !== 9 ) {
                return [ 'value' => '', 'error' => 'Enter a valid ZIP+4 code.' ];
            }

            return [ 'value' => substr( $digits, 0, 5 ) . '-' . substr( $digits, 5, 4 ), 'error' => '' ];
        }

        return [ 'value' => $value, 'error' => '' ];
    }

    private static function fieldOptionsForSubmission( array $field, array $context ): array {
        $source = (array) ( $field['options_source'] ?? [] );
        $parent_key = (string) ( $source['parent_field'] ?? '' );
        $parent_value = $parent_key !== '' && isset( $context[ $parent_key ] ) ? (string) $context[ $parent_key ] : '';

        if ( ! empty( $source['type'] ) ) {
            return self::resolveDynamicOptions( $source, $parent_value );
        }

        return self::normalizeOptions( $field['options'] ?? [] );
    }

    private static function fieldIsVisible( array $field, array $context ): bool {
        $conditions = (array) ( $field['conditions'] ?? [] );
        return FormConditionEvaluator::conditionsPass( $conditions, $context );
    }

    private static function ruleMatches( string $field, string $operator, mixed $value, array $context ): bool {
        return FormConditionEvaluator::conditionPasses(
            [
                'field'    => $field,
                'operator' => $operator,
                'value'    => $value,
            ],
            $context
        );
    }

    /**
     * @param array<int,array<string,mixed>> $schema
     * @param array<string,mixed> $settings
     * @return array{0:array<int,array<string,mixed>>,1:array<string,mixed>}
     */
    private static function canonicalizeConditionalReferences( array $schema, array $settings ): array {
        $fields_by_key = self::fieldLookupByKey( $schema );
        $schema = self::canonicalizeFieldConditionTree( $schema, $fields_by_key );

        $binding_rules = is_array( $settings['binding']['rules'] ?? null ) ? $settings['binding']['rules'] : [];
        foreach ( $binding_rules as $index => $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }
            $field_key = (string) ( $rule['field'] ?? '' );
            if ( $field_key === '' || ! isset( $fields_by_key[ $field_key ] ) ) {
                continue;
            }
            $binding_rules[ $index ]['value'] = self::canonicalOptionValue(
                $fields_by_key[ $field_key ],
                is_scalar( $rule['value'] ?? null ) ? (string) $rule['value'] : ''
            );
        }
        $settings['binding']['rules'] = $binding_rules;

        $routing_field = (string) ( $settings['notifications']['internal']['routing_field'] ?? '' );
        $routes = is_array( $settings['notifications']['internal']['routes'] ?? null ) ? $settings['notifications']['internal']['routes'] : [];
        if ( $routing_field !== '' && isset( $fields_by_key[ $routing_field ] ) ) {
            foreach ( $routes as $index => $route ) {
                if ( ! is_array( $route ) ) {
                    continue;
                }
                $routes[ $index ]['value'] = self::canonicalOptionValue(
                    $fields_by_key[ $routing_field ],
                    is_scalar( $route['value'] ?? null ) ? (string) $route['value'] : ''
                );
            }
        }
        $settings['notifications']['internal']['routes'] = $routes;

        return [ $schema, $settings ];
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @param array<string,array<string,mixed>> $fields_by_key
     * @return array<int,array<string,mixed>>
     */
    private static function canonicalizeFieldConditionTree( array $fields, array $fields_by_key ): array {
        foreach ( $fields as $index => $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            $conditions = is_array( $field['conditions'] ?? null ) ? $field['conditions'] : [];
            foreach ( $conditions as $condition_index => $condition ) {
                if ( ! is_array( $condition ) ) {
                    continue;
                }
                $field_key = (string) ( $condition['field'] ?? '' );
                if ( $field_key === '' || ! isset( $fields_by_key[ $field_key ] ) ) {
                    continue;
                }
                $conditions[ $condition_index ]['value'] = self::canonicalOptionValue(
                    $fields_by_key[ $field_key ],
                    is_scalar( $condition['value'] ?? null ) ? (string) $condition['value'] : ''
                );
            }
            $field['conditions'] = $conditions;

            if ( ( $field['type'] ?? '' ) === 'repeater' ) {
                $subfields = is_array( $field['subfields'] ?? null ) ? $field['subfields'] : [];
                $field['subfields'] = self::canonicalizeFieldConditionTree( $subfields, self::fieldLookupByKey( $subfields ) );
            }

            $fields[ $index ] = $field;
        }

        return $fields;
    }

    /**
     * @param array<int,array<string,mixed>> $fields
     * @return array<string,array<string,mixed>>
     */
    private static function fieldLookupByKey( array $fields ): array {
        $lookup = [];
        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $key = (string) ( $field['key'] ?? '' );
            if ( $key !== '' ) {
                $lookup[ $key ] = $field;
            }
        }
        return $lookup;
    }

    private static function canonicalOptionValue( array $field, string $raw_value ): string {
        $value = trim( $raw_value );
        if ( $value === '' ) {
            return '';
        }

        foreach ( self::normalizeOptions( $field['options_source']['items'] ?? $field['options'] ?? [] ) as $option ) {
            $option_value = (string) ( $option['value'] ?? '' );
            $option_label = trim( (string) ( $option['label'] ?? '' ) );
            if ( $option_value === $value || $option_label === $value ) {
                return $option_value !== '' ? $option_value : $value;
            }
        }

        $normalized_value = self::normalizeTemplateAlias( $value );
        if ( $normalized_value === '' ) {
            return $value;
        }

        foreach ( self::normalizeOptions( $field['options_source']['items'] ?? $field['options'] ?? [] ) as $option ) {
            $option_value = (string) ( $option['value'] ?? '' );
            $option_label = trim( (string) ( $option['label'] ?? '' ) );
            if (
                self::normalizeTemplateAlias( $option_value ) === $normalized_value
                || self::normalizeTemplateAlias( $option_label ) === $normalized_value
            ) {
                return $option_value !== '' ? $option_value : $value;
            }
        }

        return $value;
    }

    private static function calculatePaymentTotals( array $payment, array $payload ): array {
        $currency = strtolower( (string) ( $payment['currency'] ?? 'usd' ) );
        $selected = trim( (string) ( $payload['_donation_amount'] ?? '' ) );
        $custom = trim( (string) ( $payload['_donation_amount_custom'] ?? '' ) );
        $amount = $selected !== '' ? (float) $selected : 0.0;
        if ( $amount <= 0 && ! empty( $payment['allow_custom_amount'] ) && $custom !== '' && is_numeric( $custom ) ) {
            $amount = (float) $custom;
        }
        if ( $amount <= 0 ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Choose a donation amount.' ];
        }

        $cover_fees = ! empty( $payment['cover_fees_enabled'] ) && ! empty( $payload['_cover_fees'] );
        $percent_decimal = max( 0.0, (float) ( $payment['fee_percent'] ?? 0 ) ) / 100;
        $fixed_fee = max( 0.0, (float) ( $payment['fee_fixed'] ?? 0 ) );
        $grand_total = round( $amount, 2 );
        $covered_fee = 0.0;

        if ( $cover_fees ) {
            $denominator = 1 - $percent_decimal;
            if ( $denominator <= 0 ) {
                return [ 'ok' => false, 'status' => 422, 'error' => 'Payment fee configuration is invalid.' ];
            }
            $gross = ( $amount + $fixed_fee ) / $denominator;
            $grand_total = ceil( $gross * 100 ) / 100;
            $covered_fee = round( $grand_total - $amount, 2 );
        }

        $fee_amount = round( $grand_total - $amount, 2 );

        return [
            'ok'           => true,
            'status'       => 200,
            'currency'     => $currency,
            'base_amount'  => round( $amount, 2 ),
            'fee_amount'   => $fee_amount,
            'covered_fee'  => $covered_fee,
            'grand_total'  => round( $grand_total, 2 ),
            'cover_fees'   => $cover_fees,
            'payment_mode' => 'stripe',
            'amount_cents' => (int) round( $grand_total * 100 ),
        ];
    }

    private static function createPaymentIntent( array $form, string $session_key, array $totals, array $normalized = [] ): array {
        $stripe = \function_exists( 'metis_stripe_client' ) ? \metis_stripe_client() : null;
        if ( ! $stripe ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Stripe is not configured.' ];
        }

        $publishable = \function_exists( 'metis_stripe_publishable_key' ) ? \metis_stripe_publishable_key() : self::stripePublishableKey();
        if ( $publishable === '' ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Stripe publishable key is not configured.' ];
        }

        $payment_field = self::paymentField( (array) ( $form['schema'] ?? [] ) );
        $campaign_code = is_array( $payment_field ) ? (string) ( $payment_field['payment']['campaign_code'] ?? '' ) : '';
        $frequency = \class_exists( \Metis\Modules\Donations\RecurringDonationsService::class )
            ? \Metis\Modules\Donations\RecurringDonationsService::normalizeFrequency( $normalized['_donation_frequency'] ?? 'one_time' )
            : 'one_time';
        $email = strtolower( trim( metis_email_clean( (string) ( $normalized['email'] ?? '' ) ) ) );
        $name = trim( (string) ( ( $normalized['first_name'] ?? '' ) . ' ' . ( $normalized['last_name'] ?? '' ) ) );

        $intent_payload = [
            'amount'                    => (int) ( $totals['amount_cents'] ?? 0 ),
            'currency'                  => (string) ( $totals['currency'] ?? 'usd' ),
            'automatic_payment_methods' => [ 'enabled' => true ],
            'metadata'                  => [
                'form_id'            => (string) ( $form['id'] ?? 0 ),
                'form_name'          => (string) ( $form['name'] ?? '' ),
                'session_key'        => $session_key,
                'campaign_code'      => $campaign_code,
                'donation_frequency' => $frequency,
            ],
        ];

        if ( $frequency !== 'one_time' ) {
            if ( $email === '' || ! metis_email_is_valid( $email ) ) {
                return [ 'ok' => false, 'status' => 422, 'error' => 'Recurring donations require a valid donor email.' ];
            }
            $customer_payload = [
                'email' => $email !== '' ? $email : null,
                'name' => $name !== '' ? $name : null,
                'metadata' => [
                    'metis_form_id' => (string) ( $form['id'] ?? 0 ),
                    'metis_session_key' => $session_key,
                ],
            ];
            $customer_payload = array_filter( $customer_payload, static fn ( mixed $value ): bool => $value !== null );
            try {
                $customer = $stripe->createCustomer( $customer_payload );
                $intent_payload['customer'] = (string) ( $customer->id ?? '' );
                $intent_payload['setup_future_usage'] = 'off_session';
            } catch ( \Throwable $e ) {
                return [ 'ok' => false, 'status' => 500, 'error' => 'Stripe customer could not be created for recurring donation.' ];
            }
        }

        try {
            $intent = $stripe->createPaymentIntent( $intent_payload );
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Stripe payment intent could not be created.' ];
        }

        return [
            'ok'                => true,
            'status'            => 200,
            'payment_intent_id' => (string) $intent->id,
            'client_secret'     => (string) $intent->client_secret,
            'publishable_key'   => $publishable,
        ];
    }

    private static function stripePublishableKey(): string {
        foreach ( [ 'stripe_publishable_key', 'stripe_public_key', 'stripe_pk', 'stripe_publishable' ] as $key ) {
            $value = trim( (string) self::setting( $key, '' ) );
            if ( str_starts_with( $value, 'pk_' ) ) {
                return $value;
            }
        }

        return '';
    }

    private static function persistPaymentSession( string $session_key, int $form_id, string $payment_intent_id, array $payload, array $normalized, array $totals, string $source_url ): array {
        $table = self::table( 'form_payment_sessions' );
        $now = self::now();
        $inserted = self::db()->insert(
            $table,
            [
                'session_key'       => $session_key,
                'form_id'           => $form_id,
                'payment_intent_id' => $payment_intent_id,
                'source_url'        => $source_url !== '' ? $source_url : null,
                'payload_json'      => self::encodeJson( $payload ),
                'normalized_json'   => self::encodeJson( $normalized ),
                'totals_json'       => self::encodeJson( $totals ),
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Payment session could not be saved.' ];
        }

        return [ 'ok' => true ];
    }

    private static function getPaymentSession( string $session_key ): ?array {
        $table = self::table( 'form_payment_sessions' );
        $row = self::db()->fetchOne( "SELECT * FROM {$table} WHERE session_key = %s LIMIT 1", [ $session_key ] );
        return is_array( $row ) ? $row : null;
    }

    private static function deletePaymentSession( string $session_key ): void {
        self::db()->delete( self::table( 'form_payment_sessions' ), [ 'session_key' => $session_key ], [ '%s' ] );
    }

    private static function insertSubmission( array $form, array $normalized, array $payload, array $totals, ?string $payment_intent_id, string $source_url ): array {
        $table = self::table( 'form_submissions' );
        $submission_key = \metis_generate_code( 'ENY', $table, 'submission_key' );
        $now = self::now();

        $payment_status = $payment_intent_id ? 'paid' : 'not_required';
        $submitter_email = self::extractEmail( $normalized );

        $inserted = self::db()->insert(
            $table,
            [
                'form_id'           => (int) ( $form['id'] ?? 0 ),
                'version_id'        => (int) ( $form['version_id'] ?? 0 ) ?: null,
                'submission_key'    => $submission_key,
                'submission_status' => 'submitted',
                'payment_status'    => $payment_status,
                'payment_intent_id' => $payment_intent_id ?: null,
                'amount_total'      => (float) ( $totals['grand_total'] ?? 0 ),
                'currency'          => (string) ( $totals['currency'] ?? 'usd' ),
                'submitter_email'   => $submitter_email !== '' ? $submitter_email : null,
                'source_url'        => $source_url !== '' ? $source_url : null,
                'payload_json'      => self::encodeJson( $payload ),
                'normalized_json'   => self::encodeJson( $normalized ),
                'totals_json'       => self::encodeJson( $totals ),
                'automation_json'   => null,
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Submission could not be saved.' ];
        }

        $submission_id = (int) self::db()->lastInsertId();
        $submission = self::db()->fetchOne( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", [ $submission_id ] );

        return [ 'ok' => true, 'submission' => is_array( $submission ) ? $submission : [ 'id' => $submission_id, 'submission_key' => $submission_key ] ];
    }

    private static function getSubmissionByPaymentIntent( string $payment_intent_id ): ?array {
        $table = self::table( 'form_submissions' );
        $row = self::db()->fetchOne( "SELECT * FROM {$table} WHERE payment_intent_id = %s LIMIT 1", [ $payment_intent_id ] );
        return is_array( $row ) ? $row : null;
    }

    private static function sendNotifications( array $form, array $submission, array $normalized, array $binding_context = [] ): void {
        if ( ! \class_exists( '\Metis\Core\Services\EmailService' ) ) {
            return;
        }

        $settings = self::normalizeSettings( $form['settings'] ?? [] );
        $vars = self::notificationVars( $form, $submission, $normalized, $binding_context );
        $aliases = self::notificationTemplateAliases( $form );

        $submitter = (array) ( $settings['notifications']['submitter'] ?? [] );
        if ( ! empty( $submitter['enabled'] ) ) {
            $recipient_key = metis_key_clean( (string) ( $submitter['recipient_field'] ?? '' ) );
            $recipient = strtolower( trim( metis_email_clean( (string) ( $normalized[ $recipient_key ] ?? '' ) ) ) );
            if ( $recipient === '' ) {
                $recipient = (string) ( $vars['submitter_email'] ?? '' );
            }
            if ( $recipient !== '' && metis_email_is_valid( $recipient ) ) {
                $submitter_subject = self::mergeTemplate( (string) ( $submitter['subject'] ?? '' ), $vars, $aliases );
                $submitter_body = self::renderNotificationBody(
                    $form,
                    (string) ( $submitter['message'] ?? '' ),
                    $vars,
                    $normalized,
                    ! empty( $submitter['include_submission_data'] ),
                    $binding_context,
                    $aliases
                );
                $submitter_options = [ 'module' => 'forms' ];
                $submitter_from_name = trim( (string) ( $submitter['from_name'] ?? '' ) );
                $submitter_from_email = strtolower( trim( metis_email_clean( (string) ( $submitter['from_email'] ?? '' ) ) ) );
                if ( $submitter_from_name !== '' ) {
                    $submitter_options['from_name'] = $submitter_from_name;
                }
                if ( $submitter_from_email !== '' && metis_email_is_valid( $submitter_from_email ) ) {
                    $submitter_options['from_email'] = $submitter_from_email;
                    $submitter_options['reply_to'] = $submitter_from_email;
                }
                self::applyConversationNotificationRouting( $submitter_subject, $submitter_options, $binding_context );
                $submitter_reference = self::notificationInternalReference( $form, $submission, $binding_context );
                if ( $submitter_reference !== '' ) {
                    $submitter_options['internal_reference'] = $submitter_reference;
                }
                $send_result = \Metis\Core\Services\EmailService::sendHtml(
                    $recipient,
                    $submitter_subject,
                    $submitter_body,
                    $submitter_options
                );
                self::recordConversationNotification(
                    (int) ( $binding_context['ticket_id'] ?? 0 ),
                    $recipient,
                    $submitter_subject,
                    $submitter_body,
                    $submitter_options,
                    is_array( $send_result ) ? $send_result : []
                );
            }
        }

        $internal = (array) ( $settings['notifications']['internal'] ?? [] );
        if ( empty( $internal['enabled'] ) ) {
            return;
        }

        $emails = [];
        $default_recipient_emails = [];
        $routed_recipient_emails = [];
        $general = strtolower( trim( metis_email_clean( (string) ( $internal['general_email'] ?? '' ) ) ) );

        foreach ( (array) ( $internal['default_user_ids'] ?? [] ) as $user_id ) {
            $user_email = self::userEmailById( (int) $user_id );
            if ( $user_email !== '' ) {
                $emails[] = $user_email;
                $default_recipient_emails[] = $user_email;
            }
        }

        $routing_field = (string) ( $internal['routing_field'] ?? '' );
        $matched_route = false;
        foreach ( (array) ( $internal['routes'] ?? [] ) as $route ) {
            if ( ! is_array( $route ) || $routing_field === '' ) {
                continue;
            }
            if ( ! self::ruleMatches( $routing_field, 'equals', $route['value'] ?? '', $normalized ) ) {
                continue;
            }
            $matched_route = true;
            $user_email = self::userEmailById( (int) ( $route['user_id'] ?? 0 ) );
            if ( $user_email !== '' ) {
                $emails[] = $user_email;
                $routed_recipient_emails[] = $user_email;
            }
        }

        if ( ! $matched_route && $general !== '' && metis_email_is_valid( $general ) ) {
            $emails[] = $general;
        }

        $emails = array_values( array_unique( array_filter( $emails ) ) );
        if ( $emails === [] ) {
            return;
        }

        $subject = self::mergeTemplate( (string) ( $internal['subject'] ?? '' ), $vars, $aliases );
        $html = self::renderNotificationBody(
            $form,
            (string) ( $internal['message'] ?? '' ),
            $vars,
            $normalized,
            ! empty( $internal['include_submission_data'] ),
            $binding_context,
            $aliases
        );
        $internal_options = [ 'module' => 'forms' ];
        $internal_from_name = trim( (string) ( $internal['from_name'] ?? '' ) );
        $internal_from_email = strtolower( trim( metis_email_clean( (string) ( $internal['from_email'] ?? '' ) ) ) );
        if ( $internal_from_name !== '' ) {
            $internal_options['from_name'] = $internal_from_name;
        }
        if ( $internal_from_email !== '' && metis_email_is_valid( $internal_from_email ) ) {
            $internal_options['from_email'] = $internal_from_email;
        }
        $reply_to = [];
        if ( $routed_recipient_emails !== [] ) {
            $reply_to = array_merge( $reply_to, $routed_recipient_emails );
        } elseif ( $general !== '' && metis_email_is_valid( $general ) ) {
            $reply_to[] = $general;
        }
        if ( $default_recipient_emails !== [] ) {
            $reply_to = array_merge( $reply_to, $default_recipient_emails );
        }
        $reply_to = array_values( array_unique( array_filter( $reply_to ) ) );
        if ( $reply_to !== [] ) {
            $internal_options['reply_to'] = $reply_to;
        } elseif ( $internal_from_email !== '' && metis_email_is_valid( $internal_from_email ) ) {
            $internal_options['reply_to'] = [ $internal_from_email ];
        }
        self::applyConversationNotificationRouting( $subject, $internal_options, $binding_context );
        $internal_reference = self::notificationInternalReference( $form, $submission, $binding_context );
        if ( $internal_reference !== '' ) {
            $internal_options['internal_reference'] = $internal_reference;
        }
        foreach ( $emails as $email ) {
            \Metis\Core\Services\EmailService::sendHtml(
                (string) $email,
                $subject,
                $html,
                $internal_options
            );
        }
    }

    private static function dispatchBindings( array $form, array $submission, array $normalized, array $totals, mixed $intent, mixed $charge ): array {
        $settings = self::normalizeSettings( $form['settings'] ?? [] );
        $binding = (array) ( $settings['binding'] ?? [] );
        $module = (string) ( $binding['module'] ?? '' );
        $flow = self::resolveBindingFlow( $binding, $normalized );
        $context = [];

        if ( $module === 'grandys_stash' && \class_exists( '\Metis\Modules\GrandyStash\GrandyStashRepository' ) ) {
            $result = \Metis\Modules\GrandyStash\GrandyStashRepository::createTicketFromFormSubmission(
                $flow !== '' ? $flow : 'request',
                $normalized,
                (int) ( $form['id'] ?? 0 ),
                (int) ( $submission['id'] ?? 0 )
            );
            $ticket_id = (int) ( is_array( $result ) ? ( $result['ticket_id'] ?? 0 ) : 0 );
            if ( $ticket_id > 0 ) {
                $context['ticket_id'] = (string) $ticket_id;
                $ticket = \Metis\Modules\GrandyStash\GrandyStashRepository::getTicket( $ticket_id );
                if ( is_array( $ticket ) ) {
                    $context['ticket_code'] = (string) ( $ticket['code'] ?? '' );
                    $context['internal_reference'] = (string) ( $ticket['code'] ?? '' );
                }
            }
        }

        if ( $intent && self::formSupportsPayments( $form ) ) {
            $tid = self::recordDonationTransaction( $form, $normalized, $totals, $intent, $charge );
            if ( $tid !== '' ) {
                $context['transaction_tid'] = $tid;
                $context['internal_reference'] = $tid;
            }
        }

        return $context;
    }

    private static function resolveBindingFlow( array $binding, array $context ): string {
        $default_flow = metis_key_clean( (string) ( $binding['flow'] ?? '' ) );
        $rules = is_array( $binding['rules'] ?? null ) ? $binding['rules'] : [];

        foreach ( $rules as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }

            if ( ! self::ruleMatches(
                (string) ( $rule['field'] ?? '' ),
                (string) ( $rule['operator'] ?? 'equals' ),
                $rule['value'] ?? '',
                $context
            ) ) {
                continue;
            }

            $flow = metis_key_clean( (string) ( $rule['flow'] ?? '' ) );
            if ( $flow !== '' ) {
                return $flow;
            }
        }

        return $default_flow;
    }

    private static function recordDonationTransaction( array $form, array $normalized, array $totals, object $intent, mixed $charge ): string {
        $payment_field = self::paymentField( (array) ( $form['schema'] ?? [] ) );
        $payment = is_array( $payment_field ) ? (array) ( $payment_field['payment'] ?? [] ) : [];
        $campaign_code = self::normalizeCampaignCode( (string) ( $payment['campaign_code'] ?? '' ) );
        if ( $campaign_code === '' ) {
            return '';
        }

        $contact = self::upsertDonorContact( $normalized );
        $did = (string) ( $contact['did'] ?? '' );
        $transactions = \Metis_Tables::get( 'transactions' );
        if ( $transactions === '' ) {
            return '';
        }
        if ( class_exists( DonationsModule::class ) ) {
            DonationsModule::ensureTransactionPaymentDetailSchema();
        }

        $payment_intent_id = (string) ( $intent->id ?? '' );
        if ( $payment_intent_id !== '' ) {
            $existing = (int) self::db()->scalar(
                "SELECT id FROM {$transactions} WHERE stripe_pay_int = %s LIMIT 1",
                [ $payment_intent_id ]
            );
            if ( $existing > 0 ) {
                return '';
            }
        }

        $amount = round( (float) ( $totals['grand_total'] ?? 0 ), 2 );
        $fee = round( (float) ( $totals['fee_amount'] ?? 0 ), 2 );
        $payout = round( $amount - $fee, 2 );

        if ( is_object( $charge ) && is_object( $charge->balance_transaction ?? null ) ) {
            $balance = $charge->balance_transaction;
            $stripe_fee = isset( $balance->fee ) ? round( ( (float) $balance->fee ) / 100, 2 ) : null;
            $stripe_net = isset( $balance->net ) ? round( ( (float) $balance->net ) / 100, 2 ) : null;
            if ( $stripe_fee !== null ) {
                $fee = $stripe_fee;
            }
            if ( $stripe_net !== null ) {
                $payout = $stripe_net;
            }
        }

        $tran_date = self::now();
        $method_details = class_exists( DonationsModule::class )
            ? DonationsModule::stripePaymentMethodDetails( $charge )
            : [ 'payment_method' => 'cc', 'card_brand' => null, 'card_last4' => null ];
        $payload = [
            'tid'                => \metis_generate_code( 'TR', $transactions, 'tid' ),
            'did'                => $did !== '' ? $did : null,
            'platform'           => 'stripe',
            'campaign_code'      => $campaign_code,
            'plan_id'            => null,
            'fund_code'          => null,
            'status'             => 'completed',
            'payment_method'     => (string) ( $method_details['payment_method'] ?? 'cc' ),
            'chk_num'            => null,
            'card_brand'         => $method_details['card_brand'] ?? null,
            'card_last4'         => $method_details['card_last4'] ?? null,
            'amount'             => $amount,
            'fee'                => max( 0, $fee ),
            'fee_covered'        => round( (float) ( $totals['covered_fee'] ?? 0 ), 2 ),
            'pl_fee'             => 0.00,
            'payout'             => $payout,
            'tran_date'          => $tran_date,
            'deposit_date'       => null,
            'deposit_batch_id'   => null,
            'giving_space_id'    => null,
            'giving_space_name'  => null,
            'giving_space_msg'   => null,
            'refunded'           => 0,
            'refunded_at'        => null,
            'notes'              => self::buildTransactionNotes( $form, $normalized ),
            'stripe_pay_int'     => $payment_intent_id !== '' ? $payment_intent_id : null,
            'stripe_charge_id'   => is_object( $charge ) ? (string) ( $charge->id ?? '' ) : null,
            'stripe_balance_txn' => is_object( $charge ) && is_object( $charge->balance_transaction ?? null ) ? (string) ( $charge->balance_transaction->id ?? '' ) : null,
            'stripe_payout_id'   => is_object( $charge ) && is_object( $charge->balance_transaction ?? null ) ? (string) ( $charge->balance_transaction->payout ?? '' ) : null,
            'stripe_refund_id'   => null,
            'created_at'         => self::now(),
            'updated_at'         => self::now(),
            'transaction_uid'    => \metis_generate_code( 'DTX', $transactions, 'transaction_uid' ),
        ];

        self::db()->insert(
            $transactions,
            $payload,
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( \class_exists( FinanceV2Service::class ) ) {
            FinanceV2Service::recordStripeClearingEvent(
                [
                    'event_type' => 'donation',
                    'event_date' => substr( $tran_date, 0, 10 ),
                    'reference_id' => $payment_intent_id !== '' ? $payment_intent_id : (string) $payload['tid'],
                    'amount' => $amount,
                    'description' => 'Forms payment: ' . (string) ( $form['name'] ?? 'Form' ),
                ]
            );
        }
        return (string) $payload['tid'];
    }

    private static function upsertDonorContact( array $normalized ): array {
        $contacts = \Metis_Tables::get( 'contacts' );
        $details = \Metis_Tables::get( 'contact_details' );
        if ( $contacts === '' ) {
            return [];
        }

        $email = strtolower( trim( metis_email_clean( (string) ( $normalized['email'] ?? '' ) ) ) );
        if ( $email === '' ) {
            return [];
        }

        $first = trim( metis_text_clean( (string) ( $normalized['first_name'] ?? '' ) ) );
        $last = trim( metis_text_clean( (string) ( $normalized['last_name'] ?? '' ) ) );
        $phone = trim( metis_text_clean( (string) ( $normalized['phone'] ?? '' ) ) );
        $db = self::db();
        $existing = $db->fetchOne( "SELECT * FROM {$contacts} WHERE email = %s LIMIT 1", [ $email ] );

        if ( is_array( $existing ) ) {
            $updates = [];
            $formats = [];
            if ( $first !== '' && (string) ( $existing['first_name'] ?? '' ) === '' ) {
                $updates['first_name'] = $first;
                $formats[] = '%s';
            }
            if ( $last !== '' && (string) ( $existing['last_name'] ?? '' ) === '' ) {
                $updates['last_name'] = $last;
                $formats[] = '%s';
            }
            if ( (string) ( $existing['did'] ?? '' ) === '' ) {
                $updates['did'] = \metis_generate_code( 'MW', $contacts, 'did' );
                $formats[] = '%s';
            }
            if ( (string) ( $existing['cid'] ?? '' ) === '' ) {
                $updates['cid'] = \metis_generate_code( 'CN', $contacts, 'cid' );
                $formats[] = '%s';
            }
            if ( (string) ( $existing['contact_uid'] ?? '' ) === '' ) {
                $updates['contact_uid'] = \metis_generate_code( 'CN', $contacts, 'contact_uid' );
                $formats[] = '%s';
            }
            if ( (string) ( $existing['donor_uid'] ?? '' ) === '' ) {
                $updates['donor_uid'] = \metis_generate_code( 'DN', $contacts, 'donor_uid' );
                $formats[] = '%s';
            }
            if ( array_key_exists( 'updated_at', $existing ) ) {
                $updates['updated_at'] = self::now();
                $formats[] = '%s';
            }
            if ( $updates !== [] ) {
                $db->update( $contacts, $updates, [ 'id' => (int) $existing['id'] ], $formats, [ '%d' ] );
                $existing = $db->fetchOne( "SELECT * FROM {$contacts} WHERE id = %d LIMIT 1", [ (int) $existing['id'] ] );
            }
        } else {
            $payload = [
                'did'         => \metis_generate_code( 'MW', $contacts, 'did' ),
                'email'       => $email,
                'first_name'  => $first !== '' ? $first : null,
                'last_name'   => $last !== '' ? $last : null,
                'created_at'  => self::now(),
                'updated_at'  => self::now(),
                'cid'         => \metis_generate_code( 'CN', $contacts, 'cid' ),
                'contact_uid' => \metis_generate_code( 'CN', $contacts, 'contact_uid' ),
                'donor_uid'   => \metis_generate_code( 'DN', $contacts, 'donor_uid' ),
            ];
            $db->insert( $contacts, $payload, [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
            $contact_id = (int) $db->lastInsertId();
            $existing = $db->fetchOne( "SELECT * FROM {$contacts} WHERE id = %d LIMIT 1", [ $contact_id ] );
        }

        if ( is_array( $existing ) && $details !== '' ) {
            $detail_row = $db->fetchOne(
                "SELECT * FROM {$details} WHERE contact_id = %d OR did = %s LIMIT 1",
                [ (int) ( $existing['id'] ?? 0 ), (string) ( $existing['did'] ?? '' ) ]
            );
            if ( is_array( $detail_row ) ) {
                $patch = [];
                $formats = [];
                if ( $phone !== '' && (string) ( $detail_row['phone'] ?? '' ) === '' ) {
                    $patch['phone'] = $phone;
                    $formats[] = '%s';
                }
                if ( (string) ( $detail_row['contact_cid'] ?? '' ) === '' && (string) ( $existing['cid'] ?? '' ) !== '' ) {
                    $patch['contact_cid'] = (string) $existing['cid'];
                    $formats[] = '%s';
                }
                if ( (int) ( $detail_row['contact_id'] ?? 0 ) < 1 ) {
                    $patch['contact_id'] = (int) ( $existing['id'] ?? 0 );
                    $formats[] = '%d';
                }
                if ( $patch !== [] ) {
                    if ( array_key_exists( 'updated_at', $detail_row ) ) {
                        $patch['updated_at'] = self::now();
                        $formats[] = '%s';
                    }
                    $db->update( $details, $patch, [ 'id' => (int) $detail_row['id'] ], $formats, [ '%d' ] );
                }
            } else {
                $db->insert(
                    $details,
                    [
                        'did'                      => (string) ( $existing['did'] ?? '' ),
                        'phone'                    => $phone !== '' ? $phone : null,
                        'address'                  => null,
                        'city'                     => null,
                        'state'                    => null,
                        'zip'                      => null,
                        'birthday'                 => null,
                        'spouse_name'              => null,
                        'household_id'             => null,
                        'preferred_contact_method' => null,
                        'preferred_name'           => null,
                        'do_not_contact'           => 0,
                        'volunteer_status'         => 0,
                        'anonymous_donor'          => 0,
                        'source_code'              => 'forms',
                        'first_contacted'          => self::now(),
                        'staff_owner'              => null,
                        'created_at'               => self::now(),
                        'updated_at'               => self::now(),
                        'contact_id'               => (int) ( $existing['id'] ?? 0 ),
                        'additional_emails_json'   => null,
                        'relationships_json'       => null,
                        'contact_cid'              => (string) ( $existing['cid'] ?? '' ),
                    ],
                    [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
                );
            }
        }

        return is_array( $existing ) ? $existing : [];
    }

    private static function paymentSummaryFromSchema( array $schema, array $settings ): array {
        foreach ( $schema as $field ) {
            if ( is_array( $field ) && ( $field['type'] ?? '' ) === 'payment' ) {
                return self::normalizePaymentSettings( $field['payment'] ?? [], $settings['payments'] ?? [] );
            }
        }

        return self::normalizePaymentSettings( [], $settings['payments'] ?? [] );
    }

    private static function paymentField( array $schema ): ?array {
        foreach ( $schema as $field ) {
            if ( is_array( $field ) && ( $field['type'] ?? '' ) === 'payment' ) {
                return $field;
            }
        }

        return null;
    }

    private static function containsPaymentField( array $schema ): bool {
        return self::paymentField( $schema ) !== null;
    }

    private static function loadVersion( int $version_id ): ?array {
        $versions = self::table( 'form_versions' );
        $row = self::db()->fetchOne( "SELECT * FROM {$versions} WHERE id = %d LIMIT 1", [ $version_id ] );
        return is_array( $row ) ? $row : null;
    }

    private static function listVersions( int $form_id ): array {
        if ( $form_id < 1 ) {
            return [];
        }

        $versions = self::table( 'form_versions' );
        $rows = self::db()->fetchAll(
            "SELECT id, version_number, is_published, created_at
             FROM {$versions}
             WHERE form_id = %d
             ORDER BY version_number DESC
             LIMIT 20",
            [ $form_id ]
        );

        $versions_out = [];
        foreach ( $rows as $row ) {
            $versions_out[] = [
                'id'             => (int) ( $row['id'] ?? 0 ),
                'version_number' => (int) ( $row['version_number'] ?? 0 ),
                'is_published'   => ! empty( $row['is_published'] ),
                'created_at'     => (string) ( $row['created_at'] ?? '' ),
            ];
        }

        return $versions_out;
    }

    private static function uniqueSlug( string $slug, int $ignore_form_id = 0 ): string {
        $slug = metis_slug_clean( $slug );
        if ( $slug === '' ) {
            $slug = 'form-' . strtolower( \metis_generate_code( 'FRM' ) );
        }

        $forms = self::table( 'forms' );
        $candidate = $slug;
        $suffix = 2;
        while ( true ) {
            $existing_id = (int) self::db()->scalar( "SELECT id FROM {$forms} WHERE slug = %s LIMIT 1", [ $candidate ] );
            if ( $existing_id < 1 || $existing_id === $ignore_form_id ) {
                return $candidate;
            }
            $candidate = $slug . '-' . $suffix;
            $suffix++;
        }
    }

    private static function normalizeMoneyChoices( mixed $choices ): array {
        $choices = is_array( $choices ) ? $choices : [];
        $normalized = [];
        foreach ( $choices as $choice ) {
            if ( ! is_numeric( $choice ) ) {
                continue;
            }
            $value = round( abs( (float) $choice ), 2 );
            if ( $value <= 0 || in_array( $value, $normalized, true ) ) {
                continue;
            }
            $normalized[] = $value;
        }
        sort( $normalized );
        return $normalized;
    }

    private static function nullableNumber( mixed $value ): ?float {
        if ( $value === null || $value === '' ) {
            return null;
        }

        return is_numeric( $value ) ? (float) $value : null;
    }

    private static function parseTimestamp( string $value ): int {
        $value = trim( $value );
        if ( $value === '' ) {
            return 0;
        }
        $timestamp = strtotime( $value );
        return $timestamp === false ? 0 : $timestamp;
    }

    private static function normalizeDateTime( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }
        $timestamp = strtotime( $value );
        return $timestamp === false ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
    }

    private static function normalizeCampaignCode( string $value ): string {
        return trim( metis_text_clean( $value ) );
    }

    private static function decodeJson( mixed $json ): array {
        if ( is_array( $json ) ) {
            return $json;
        }
        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            return [];
        }
        $decoded = json_decode( $json, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private static function encodeJson( mixed $value ): string {
        $encoded = \metis_json_encode( $value );
        return is_string( $encoded ) ? $encoded : '{}';
    }

    private static function paymentDefaults(): array {
        return [
            'fee_percent'      => max( 0.0, (float) self::setting( 'stripe_platform_fee_percent', 2.9 ) ),
            'fee_fixed'        => max( 0.0, (float) self::setting( 'stripe_platform_fee_fixed', 0.30 ) ),
            'cover_fees_label' => trim( metis_text_clean( (string) self::setting( 'stripe_platform_cover_fees_label', 'I would like to cover the processing fees.' ) ) ),
        ];
    }

    private static function emailDefaults(): array {
        $from_name = trim( metis_text_clean( (string) self::setting( 'newsletter_default_from_name', '' ) ) );
        $from_email = strtolower( trim( metis_email_clean( (string) self::setting( 'newsletter_default_from_email', '' ) ) ) );

        return [
            'from_name'  => $from_name,
            'from_email' => $from_email,
        ];
    }

    private static function setting( string $key, mixed $default = null ): mixed {
        if ( \class_exists( '\Core_Settings_Service', false ) ) {
            return \Core_Settings_Service::get( $key, $default );
        }

        if (
            \class_exists( '\Metis\Core\Application', false )
            && \Metis\Core\Application::has_service( 'settings' )
        ) {
            try {
                return \Metis\Core\Application::service( 'settings' )->get( $key, $default );
            } catch ( \Throwable ) {
                return $default;
            }
        }

        return $default;
    }

    private static function defaultSettings(): array {
        $payment_defaults = self::paymentDefaults();
        $email_defaults = self::emailDefaults();

        return [
            'binding' => [
                'module'        => '',
                'flow'          => '',
                'campaign_code' => '',
                'rules'         => [],
            ],
            'access' => [
                'mode'           => 'public',
                'password'       => '',
                'roles'          => [],
                'denied_message' => 'This form is not currently available.',
            ],
            'schedule' => [
                'enabled'        => false,
                'start_at'       => '',
                'end_at'         => '',
                'closed_message' => 'This form is not accepting submissions right now.',
            ],
            'confirmation' => [
                'custom_enabled' => false,
                'message' => 'Thanks, your submission has been received.',
            ],
            'notifications' => [
                'submitter' => [
                    'enabled'         => false,
                    'recipient_field' => 'email',
                    'from_name'       => $email_defaults['from_name'],
                    'from_email'      => $email_defaults['from_email'],
                    'include_submission_data' => false,
                    'subject'         => 'We received your submission',
                    'message'         => '<p>Thank you for your submission.</p>',
                ],
                'internal' => [
                    'enabled'          => false,
                    'general_email'    => '',
                    'from_name'        => $email_defaults['from_name'],
                    'from_email'       => $email_defaults['from_email'],
                    'include_submission_data' => true,
                    'default_user_ids' => [],
                    'routing_field'    => '',
                    'routes'           => [],
                    'subject'          => 'New form submission received',
                    'message'          => '<p>A new submission was received.</p>',
                ],
            ],
            'payments' => [
                'enabled'             => false,
                'mode'                => 'donation',
                'campaign_code'       => '',
                'currency'            => 'usd',
                'donation_amounts'    => [ 25, 50, 100 ],
                'allow_custom_amount' => true,
                'custom_amount_label' => 'Other amount',
                'cover_fees_enabled'  => false,
                'cover_fees_label'    => $payment_defaults['cover_fees_label'],
                'fee_percent'         => $payment_defaults['fee_percent'],
                'fee_fixed'           => $payment_defaults['fee_fixed'],
                'summary_label'       => 'Total',
                'success_message'     => 'Thanks, your payment has been received.',
            ],
        ];
    }

    private static function normalizedSenderName( mixed $value, string $default = '' ): string {
        $clean = trim( metis_text_clean( (string) $value ) );
        return $clean !== '' ? $clean : $default;
    }

    private static function normalizedSenderEmail( mixed $value, string $default = '' ): string {
        $clean = strtolower( trim( metis_email_clean( (string) $value ) ) );
        return $clean !== '' ? $clean : $default;
    }

    private static function campaignOptions(): array {
        if ( self::$campaignOptions !== null ) {
            return self::$campaignOptions;
        }

        self::$campaignOptions = CampaignService::getActiveCampaignOptions();
        return self::$campaignOptions;
    }

    private static function userOptions(): array {
        if ( self::$userOptions !== null ) {
            return self::$userOptions;
        }

        $options = [];
        $table = \Metis_Tables::has( 'people' ) ? (string) \Metis_Tables::get( 'people' ) : '';
        if ( $table === '' ) {
            self::$userOptions = $options;
            return self::$userOptions;
        }

        $rows = self::db()->fetchAll(
            "SELECT id, display_name, first_name, last_name, email, workspace_email
             FROM {$table}
             WHERE COALESCE(NULLIF(workspace_email, ''), NULLIF(email, '')) IS NOT NULL
             ORDER BY COALESCE(NULLIF(display_name, ''), NULLIF(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')), ''), COALESCE(workspace_email, email)) ASC
             LIMIT 500"
        ) ?: [];

        foreach ( $rows as $person ) {
            $email = strtolower(
                trim(
                    metis_email_clean(
                        (string) ( $person['workspace_email'] ?? $person['email'] ?? '' )
                    )
                )
            );
            if ( ! metis_email_is_valid( $email ) ) {
                $email = strtolower( trim( metis_email_clean( (string) ( $person['email'] ?? '' ) ) ) );
            }
            if ( ! metis_email_is_valid( $email ) ) {
                continue;
            }

            $label = trim( (string) ( $person['display_name'] ?? '' ) );
            if ( $label === '' ) {
                $label = trim( (string) ( $person['first_name'] ?? '' ) . ' ' . (string) ( $person['last_name'] ?? '' ) );
            }
            if ( $label === '' ) {
                $label = $email;
            }

            $id = (int) ( $person['id'] ?? 0 );
            if ( $id < 1 ) {
                continue;
            }

            $options[] = [
                'id'    => $id,
                'value' => $id,
                'label' => $label,
                'email' => $email,
            ];
        }

        self::$userOptions = $options;
        return self::$userOptions;
    }

    private static function grandyCatalog(): array {
        if ( self::$grandyCatalog !== null ) {
            return self::$grandyCatalog;
        }

        if ( \class_exists( '\Metis\Modules\GrandyStash\GrandyStashRepository' ) ) {
            $summary = \Metis\Modules\GrandyStash\GrandyStashRepository::catalogSummary();
            if ( is_array( $summary ) ) {
                self::$grandyCatalog = $summary;
                return self::$grandyCatalog;
            }
        }

        self::$grandyCatalog = [ 'categories' => [], 'items' => [] ];
        return self::$grandyCatalog;
    }

    private static function grandyCategoryOptions(): array {
        $catalog = self::grandyCatalog();
        $options = [];
        foreach ( (array) ( $catalog['categories'] ?? [] ) as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $label = trim( (string) ( $row['category_name'] ?? '' ) );
            $value = trim( (string) ( $row['category_slug'] ?? '' ) );
            if ( $label === '' || $value === '' ) {
                continue;
            }
            $options[] = [
                'label'    => $label,
                'value'    => $value,
                'category' => '',
            ];
        }

        return $options;
    }

    private static function grandyItemOptions(): array {
        $catalog = self::grandyCatalog();
        $options = [];
        foreach ( (array) ( $catalog['items'] ?? [] ) as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $label = trim( (string) ( $row['item_name'] ?? '' ) );
            $value = trim( (string) ( $row['item_slug'] ?? '' ) );
            $category = trim( (string) ( $row['category_slug'] ?? '' ) );
            if ( $label === '' || $value === '' ) {
                continue;
            }
            $options[] = [
                'label'    => $label,
                'value'    => $value,
                'category' => $category,
            ];
        }

        return $options;
    }

    private static function flattenForCsv( array $value, string $prefix = '' ): array {
        $flat = [];
        foreach ( $value as $key => $item ) {
            $name = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            if ( is_array( $item ) ) {
                if ( array_is_list( $item ) ) {
                    $flat[ $name ] = self::stringifyList( $item );
                } else {
                    $flat += self::flattenForCsv( $item, $name );
                }
            } else {
                $flat[ $name ] = is_scalar( $item ) ? (string) $item : '';
            }
        }

        return $flat;
    }

    private static function stringifyList( array $list ): string {
        $parts = [];
        foreach ( $list as $item ) {
            if ( is_array( $item ) ) {
                $parts[] = self::encodeJson( $item );
            } else {
                $parts[] = (string) $item;
            }
        }

        return implode( '; ', array_filter( $parts, static fn ( string $part ): bool => trim( $part ) !== '' ) );
    }

    private static function renderPlainSubmission( array $normalized ): string {
        $lines = [];
        foreach ( self::flattenForCsv( $normalized ) as $key => $value ) {
            $lines[] = $key . ': ' . $value;
        }
        return implode( "\n", $lines );
    }

    private static function mergeTemplate( string $template, array $vars, array $aliases = [] ): string {
        $output = self::canonicalizeTemplateAliases( $template, $aliases );
        foreach ( $vars as $key => $value ) {
            $output = str_replace( '{{' . $key . '}}', (string) $value, $output );
        }
        return $output;
    }

    private static function mergeTemplateHtml( string $template, array $vars, array $aliases = [] ): string {
        $output = self::canonicalizeTemplateAliases( $template, $aliases );
        foreach ( $vars as $key => $value ) {
            $output = str_replace( '{{' . $key . '}}', self::escapeHtmlValue( (string) $value ), $output );
        }
        return $output;
    }

    private static function normalizeNotificationMessageHtml( string $html ): string {
        $html = str_replace( "\xC2\xA0", ' ', $html );
        $html = str_replace( "\u{00A0}", ' ', $html );
        $html = preg_replace( '/Â(?=\s|<|$)/u', '', $html ) ?? $html;
        $html = function_exists( 'metis_runtime_kses_post' )
            ? (string) \metis_runtime_kses_post( $html )
            : $html;
        return trim( $html );
    }

    private static function plainTextToHtml( string $message ): string {
        $escaped = \function_exists( 'metis_escape_html' )
            ? metis_escape_html( trim( $message ) )
            : htmlspecialchars( trim( $message ), ENT_QUOTES, 'UTF-8' );

        return nl2br( $escaped );
    }

    private static function renderNotificationBody( array $form, string $template, array $vars, array $normalized, bool $include_submission_data, array $binding_context = [], array $aliases = [] ): string {
        $body = trim( self::mergeTemplateHtml( $template, $vars, $aliases ) );
        if ( $body === '' ) {
            $body = '<p>&nbsp;</p>';
        }

        $extras = [];
        $ticket_code = trim( (string) ( $binding_context['ticket_code'] ?? '' ) );
        $ticket_id = trim( (string) ( $binding_context['ticket_id'] ?? '' ) );
        if ( $ticket_code !== '' || $ticket_id !== '' ) {
            $extras[] = '<div><strong>Ticket:</strong> ' . self::escapeHtmlValue( $ticket_code !== '' ? $ticket_code : 'Ticket #' . $ticket_id ) . '</div>';
        }
        if ( $include_submission_data ) {
            $submission_html = self::renderSubmissionHtml( (array) ( $form['schema'] ?? [] ), $normalized );
            if ( $submission_html !== '' ) {
                $extras[] = $submission_html;
            }
        }
        if ( $extras !== [] ) {
            $body .= '<hr><div class="metis-form-email-meta">' . implode( '', $extras ) . '</div>';
        }

        return function_exists( 'metis_runtime_kses_post' )
            ? (string) \metis_runtime_kses_post( $body )
            : $body;
    }

    private static function renderSubmissionHtml( array $schema, array $normalized ): string {
        $rows = self::renderSubmissionRows( $schema, $normalized );
        if ( $rows === [] ) {
            foreach ( self::flattenForCsv( $normalized ) as $key => $value ) {
                $label = ucwords( str_replace( [ '_', '.' ], ' ', (string) $key ) );
                $rows[] = '<tr>'
                    . '<th style="padding:6px 10px;text-align:left;border:1px solid #d0d5dd;background:#f8fafc;vertical-align:top;">' . self::escapeHtmlValue( $label ) . '</th>'
                    . '<td style="padding:6px 10px;border:1px solid #d0d5dd;">' . self::escapeHtmlValue( (string) $value ) . '</td>'
                    . '</tr>';
            }
        }
        if ( $rows === [] ) {
            return '';
        }

        return '<div style="margin-top:16px;">'
            . '<div style="font-weight:600;margin-bottom:8px;">Submitted Information</div>'
            . '<table style="width:100%;border-collapse:collapse;">'
            . implode( '', $rows )
            . '</table>'
            . '</div>';
    }

    private static function notificationVars( array $form, array $submission, array $normalized, array $binding_context = [] ): array {
        $vars = [
            'form_name'       => (string) ( $form['name'] ?? '' ),
            'submission_key'  => (string) ( $submission['submission_key'] ?? '' ),
            'submitter_email' => self::extractEmail( $normalized ),
            'ticket_id'       => (string) ( $binding_context['ticket_id'] ?? '' ),
            'ticket_code'     => (string) ( $binding_context['ticket_code'] ?? '' ),
        ];

        foreach ( (array) ( $form['schema'] ?? [] ) as $field ) {
            self::appendFieldNotificationVars( $vars, is_array( $field ) ? $field : [], $normalized );
        }

        return $vars;
    }

    private static function notificationTemplateAliases( array $form ): array {
        $aliases = [];
        self::registerTemplateAlias( $aliases, 'Form name', 'form_name' );
        self::registerTemplateAlias( $aliases, 'Submission key', 'submission_key' );
        self::registerTemplateAlias( $aliases, 'Submitter email', 'submitter_email' );
        self::registerTemplateAlias( $aliases, 'Ticket code', 'ticket_code' );
        self::registerTemplateAlias( $aliases, 'Ticket ID', 'ticket_id' );

        foreach ( (array) ( $form['schema'] ?? [] ) as $field ) {
            if ( is_array( $field ) ) {
                self::appendFieldTemplateAliases( $aliases, $field );
            }
        }

        return $aliases;
    }

    private static function notificationInternalReference( array $form, array $submission, array $binding_context = [] ): string {
        if ( \class_exists( '\Metis\Core\Services\EmailService' ) ) {
            $explicit = \Metis\Core\Services\EmailService::normalizeInternalReference( $binding_context['internal_reference'] ?? '' );
            if ( $explicit !== '' ) {
                return $explicit;
            }

            $ticket_code = \Metis\Core\Services\EmailService::normalizeInternalReference( $binding_context['ticket_code'] ?? '' );
            if ( $ticket_code !== '' ) {
                return $ticket_code;
            }
        }

        $submission_key = strtoupper( trim( metis_text_clean( (string) ( $submission['submission_key'] ?? '' ) ) ) );
        if ( $submission_key === '' ) {
            return '';
        }

        $form_id = (int) ( $form['id'] ?? 0 );
        if ( $form_id > 0 ) {
            return 'FORM-' . $form_id . '-' . $submission_key;
        }

        return 'FORM-' . $submission_key;
    }

    private static function applyConversationNotificationRouting( string &$subject, array &$options, array $binding_context = [] ): void {
        $ticket_code = strtoupper( trim( metis_text_clean( (string) ( $binding_context['ticket_code'] ?? '' ) ) ) );
        if ( $ticket_code !== '' ) {
            if ( \class_exists( '\Metis\Modules\GrandyStash\ConversationSupport' ) ) {
                $subject = \Metis\Modules\GrandyStash\ConversationSupport::ensureTicketCodeInSubject( $subject, $ticket_code );
            } elseif ( stripos( $subject, $ticket_code ) === false ) {
                $subject = '[' . $ticket_code . '] ' . trim( $subject );
            }
        }

        $ticket_id = (int) ( $binding_context['ticket_id'] ?? 0 );
        if ( $ticket_id <= 0 || ! \class_exists( '\Metis\Modules\GrandyStash\GrandyStashRepository' ) ) {
            return;
        }

        $mailbox = \Metis\Modules\GrandyStash\GrandyStashRepository::conversationMailboxForTicket( $ticket_id );
        if ( ! \is_array( $mailbox ) || empty( $mailbox['enabled'] ) ) {
            return;
        }

        $mailbox_email = strtolower( trim( metis_email_clean( (string) ( $mailbox['mailbox_email'] ?? '' ) ) ) );
        $mailbox_name = trim( metis_text_clean( (string) ( $mailbox['display_name'] ?? '' ) ) );

        if ( $mailbox_email !== '' && metis_email_is_valid( $mailbox_email ) ) {
            if ( empty( $options['from_email'] ) ) {
                $options['from_email'] = $mailbox_email;
            }
            $options['reply_to'] = $mailbox_email;
        }

        if ( $mailbox_name !== '' && empty( $options['from_name'] ) ) {
            $options['from_name'] = $mailbox_name;
        }
    }

    private static function recordConversationNotification(
        int $ticket_id,
        string $recipient,
        string $subject,
        string $html_body,
        array $options,
        array $send_result
    ): void {
        if ( $ticket_id < 1 || empty( $send_result['ok'] ) || ! \class_exists( '\Metis\Modules\GrandyStash\GrandyStashRepository' ) ) {
            return;
        }

        $reply_to_option = $options['reply_to'] ?? $options['from_email'] ?? '';
        if ( is_array( $reply_to_option ) ) {
            $reply_to_option = (string) ( array_values( $reply_to_option )[0] ?? '' );
        }
        $mailbox_email = strtolower( trim( metis_email_clean( (string) $reply_to_option ) ) );
        $sender_email = strtolower( trim( metis_email_clean( (string) ( $options['from_email'] ?? $mailbox_email ) ) ) );
        $recipient = strtolower( trim( metis_email_clean( $recipient ) ) );
        if ( $mailbox_email === '' || ! metis_email_is_valid( $mailbox_email ) || $recipient === '' || ! metis_email_is_valid( $recipient ) ) {
            return;
        }

        $text_body = '';
        if ( \class_exists( '\Metis\Modules\Newsletter\Support' ) ) {
            $text_body = \Metis\Modules\Newsletter\Support::plainTextFromHtml( $html_body );
        }
        if ( $text_body === '' ) {
            $text_body = trim( preg_replace( '/\s+/', ' ', strip_tags( $html_body ) ) ?? '' );
        }

        \Metis\Modules\GrandyStash\GrandyStashRepository::recordOutboundNotificationMessage(
            $ticket_id,
            [
                'provider_message_id' => (string) ( $send_result['gmail_id'] ?? '' ),
                'provider_thread_id'  => (string) ( $send_result['thread_id'] ?? '' ),
                'mailbox_email'       => $mailbox_email,
                'subject'             => $subject,
                'sender_email'        => $sender_email !== '' ? $sender_email : $mailbox_email,
                'sender_name'         => (string) ( $options['from_name'] ?? '' ),
                'sender_user_id'      => (int) \metis_current_user_id(),
                'recipient_email'     => $recipient,
                'recipients_json'     => [ $recipient ],
                'text_body'           => $text_body,
                'html_body'           => $html_body,
                'delivery_status'     => 'sent',
                'error_message'       => '',
            ]
        );
    }

    private static function appendFieldNotificationVars( array &$vars, array $field, array $normalized, string $prefix = '' ): void {
        $key = (string) ( $field['key'] ?? '' );
        if ( $key === '' ) {
            return;
        }
        $value_key = $prefix !== '' ? $prefix . '.' . $key : $key;
        if ( array_key_exists( $key, $normalized ) ) {
            $vars[ $value_key ] = self::fieldMergeValue( $field, $normalized[ $key ] );
        }
        if ( (string) ( $field['type'] ?? '' ) !== 'repeater' || ! array_key_exists( $key, $normalized ) || ! is_array( $normalized[ $key ] ) ) {
            return;
        }
        foreach ( (array) ( $field['subfields'] ?? [] ) as $subfield ) {
            if ( ! is_array( $subfield ) ) {
                continue;
            }
            $subkey = (string) ( $subfield['key'] ?? '' );
            if ( $subkey === '' ) {
                continue;
            }
            $parts = [];
            foreach ( $normalized[ $key ] as $row ) {
                if ( ! is_array( $row ) || ! array_key_exists( $subkey, $row ) ) {
                    continue;
                }
                $display = self::fieldDisplayValue( $subfield, $row[ $subkey ] );
                if ( trim( $display ) !== '' ) {
                    $parts[] = $display;
                }
            }
            if ( $parts !== [] ) {
                $vars[ $value_key . '.' . $subkey ] = implode( '; ', $parts );
            }
        }
    }

    private static function appendFieldTemplateAliases( array &$aliases, array $field, string $prefix_label = '', string $prefix_key = '' ): void {
        $key = (string) ( $field['key'] ?? '' );
        if ( $key === '' ) {
            return;
        }

        $label = trim( (string) ( $field['label'] ?? $key ) );
        $value_key = $prefix_key !== '' ? $prefix_key . '.' . $key : $key;
        $value_label = $prefix_label !== '' ? $prefix_label . ' - ' . $label : $label;

        self::registerTemplateAlias( $aliases, $value_key, $value_key );
        self::registerTemplateAlias( $aliases, $value_label, $value_key );

        if ( (string) ( $field['type'] ?? '' ) !== 'repeater' ) {
            return;
        }

        foreach ( (array) ( $field['subfields'] ?? [] ) as $subfield ) {
            if ( is_array( $subfield ) ) {
                self::appendFieldTemplateAliases( $aliases, $subfield, $value_label, $value_key );
            }
        }
    }

    private static function registerTemplateAlias( array &$aliases, string $alias, string $canonical_key ): void {
        $normalized_alias = self::normalizeTemplateAlias( $alias );
        $canonical = trim( $canonical_key );
        if ( $normalized_alias === '' || $canonical === '' ) {
            return;
        }
        $aliases[ $normalized_alias ] = $canonical;
    }

    private static function canonicalizeTemplateAliases( string $template, array $aliases ): string {
        if ( $template === '' || $aliases === [] ) {
            return $template;
        }

        return preg_replace_callback(
            '/\{\{\s*([^{}]+?)\s*\}\}/',
            static function ( array $matches ) use ( $aliases ): string {
                $inner = trim( (string) ( $matches[1] ?? '' ) );
                $normalized = self::normalizeTemplateAlias( $inner );
                if ( $normalized === '' || ! isset( $aliases[ $normalized ] ) ) {
                    return '{{' . $inner . '}}';
                }

                return '{{' . (string) $aliases[ $normalized ] . '}}';
            },
            $template
        ) ?? $template;
    }

    private static function normalizeTemplateAlias( string $value ): string {
        $normalized = strtolower( trim( $value ) );
        $normalized = preg_replace( '/[^a-z0-9]+/', ' ', $normalized ) ?? $normalized;
        return trim( $normalized );
    }

    private static function renderSubmissionRows( array $schema, array $normalized ): array {
        $rows = [];
        foreach ( $schema as $field ) {
            if ( ! is_array( $field ) || (string) ( $field['type'] ?? '' ) === 'payment' ) {
                continue;
            }
            $key = (string) ( $field['key'] ?? '' );
            if ( $key === '' || ! array_key_exists( $key, $normalized ) ) {
                continue;
            }
            $label = trim( (string) ( $field['label'] ?? $key ) );
            $value = $normalized[ $key ];
            if ( (string) ( $field['type'] ?? '' ) === 'repeater' ) {
                $repeater_html = self::renderRepeaterValueHtml( $field, is_array( $value ) ? $value : [] );
                if ( $repeater_html === '' ) {
                    continue;
                }
                $rows[] = '<tr>'
                    . '<th style="padding:6px 10px;text-align:left;border:1px solid #d0d5dd;background:#f8fafc;vertical-align:top;">' . self::escapeHtmlValue( $label ) . '</th>'
                    . '<td style="padding:6px 10px;border:1px solid #d0d5dd;">' . $repeater_html . '</td>'
                    . '</tr>';
                continue;
            }
            $display = self::fieldDisplayValue( $field, $value );
            if ( trim( $display ) === '' ) {
                continue;
            }
            $rows[] = '<tr>'
                . '<th style="padding:6px 10px;text-align:left;border:1px solid #d0d5dd;background:#f8fafc;vertical-align:top;">' . self::escapeHtmlValue( $label ) . '</th>'
                . '<td style="padding:6px 10px;border:1px solid #d0d5dd;">' . self::escapeHtmlValue( $display ) . '</td>'
                . '</tr>';
        }

        return $rows;
    }

    private static function renderRepeaterValueHtml( array $field, array $rows ): string {
        if ( $rows === [] ) {
            return '';
        }

        $blocks = [];
        foreach ( $rows as $index => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $parts = [];
            foreach ( (array) ( $field['subfields'] ?? [] ) as $subfield ) {
                if ( ! is_array( $subfield ) ) {
                    continue;
                }
                $key = (string) ( $subfield['key'] ?? '' );
                if ( $key === '' || ! array_key_exists( $key, $row ) ) {
                    continue;
                }
                $value = self::fieldDisplayValue( $subfield, $row[ $key ] );
                if ( trim( $value ) === '' ) {
                    continue;
                }
                $parts[] = '<tr>'
                    . '<th style="padding:4px 8px;text-align:left;border:1px solid #e2e8f0;background:#f8fafc;vertical-align:top;">' . self::escapeHtmlValue( (string) ( $subfield['label'] ?? $key ) ) . '</th>'
                    . '<td style="padding:4px 8px;border:1px solid #e2e8f0;">' . self::escapeHtmlValue( $value ) . '</td>'
                    . '</tr>';
            }
            if ( $parts === [] ) {
                continue;
            }
            $blocks[] = '<div style="margin:0 0 12px;">'
                . '<div style="font-weight:600;margin:0 0 6px;">Row ' . self::escapeHtmlValue( (string) ( $index + 1 ) ) . '</div>'
                . '<table style="width:100%;border-collapse:collapse;">' . implode( '', $parts ) . '</table>'
                . '</div>';
        }

        return implode( '', $blocks );
    }

    private static function fieldMergeValue( array $field, mixed $value ): string {
        return self::fieldDisplayValue( $field, $value );
    }

    private static function fieldDisplayValue( array $field, mixed $value ): string {
        $type = (string) ( $field['type'] ?? 'text' );
        if ( $type === 'repeater' ) {
            $rows = is_array( $value ) ? $value : [];
            $lines = [];
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $parts = [];
                foreach ( (array) ( $field['subfields'] ?? [] ) as $subfield ) {
                    if ( ! is_array( $subfield ) ) {
                        continue;
                    }
                    $key = (string) ( $subfield['key'] ?? '' );
                    if ( $key === '' || ! array_key_exists( $key, $row ) ) {
                        continue;
                    }
                    $display = self::fieldDisplayValue( $subfield, $row[ $key ] );
                    if ( trim( $display ) === '' ) {
                        continue;
                    }
                    $parts[] = (string) ( $subfield['label'] ?? $key ) . ': ' . $display;
                }
                if ( $parts !== [] ) {
                    $lines[] = implode( ' | ', $parts );
                }
            }
            return implode( "\n", $lines );
        }

        if ( $type === 'checkbox' ) {
            $labels = [];
            foreach ( is_array( $value ) ? $value : [] as $item ) {
                $labels[] = self::optionDisplayLabel( $field, (string) $item );
            }
            return implode( ', ', array_filter( array_map( 'trim', $labels ) ) );
        }

        if ( is_array( $value ) ) {
            return self::stringifyList( $value );
        }

        $scalar = trim( (string) $value );
        if ( $scalar === '' ) {
            return '';
        }

        if ( in_array( $type, [ 'select', 'radio' ], true ) ) {
            return self::optionDisplayLabel( $field, $scalar );
        }

        return $scalar;
    }

    private static function optionDisplayLabel( array $field, string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }

        $options = [];
        foreach ( (array) ( $field['options'] ?? [] ) as $option ) {
            if ( is_array( $option ) ) {
                $options[] = $option;
            }
        }
        foreach ( (array) ( $field['options_source']['items'] ?? [] ) as $option ) {
            if ( is_array( $option ) ) {
                $options[] = $option;
            }
        }

        foreach ( $options as $option ) {
            if ( trim( (string) ( $option['value'] ?? '' ) ) === $value ) {
                $label = trim( (string) ( $option['label'] ?? '' ) );
                if ( $label !== '' ) {
                    return $label;
                }
            }
        }

        return $value;
    }

    private static function escapeHtmlValue( string $value ): string {
        return \function_exists( 'metis_escape_html' )
            ? metis_escape_html( $value )
            : htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
    }

    private static function extractEmail( array $normalized ): string {
        foreach ( [ 'email', 'submitter_email' ] as $key ) {
            $email = strtolower( trim( metis_email_clean( (string) ( $normalized[ $key ] ?? '' ) ) ) );
            if ( $email !== '' && metis_email_is_valid( $email ) ) {
                return $email;
            }
        }

        return '';
    }

    private static function buildTransactionNotes( array $form, array $normalized ): string {
        $name = trim(
            trim( (string) ( $normalized['first_name'] ?? '' ) ) . ' ' . trim( (string) ( $normalized['last_name'] ?? '' ) )
        );
        $parts = [ 'Forms payment' ];
        if ( (string) ( $form['name'] ?? '' ) !== '' ) {
            $parts[] = 'Form: ' . (string) $form['name'];
        }
        if ( $name !== '' ) {
            $parts[] = 'Donor: ' . $name;
        } elseif ( (string) ( $normalized['email'] ?? '' ) !== '' ) {
            $parts[] = 'Donor: ' . (string) $normalized['email'];
        }
        return implode( ' | ', $parts );
    }

    private static function userEmailById( int $user_id ): string {
        if ( $user_id < 1 || ! \Metis_Tables::has( 'people' ) ) {
            return '';
        }

        $table = (string) \Metis_Tables::get( 'people' );
        if ( $table === '' ) {
            return '';
        }

        $row = self::db()->fetchOne(
            "SELECT workspace_email, email
             FROM {$table}
             WHERE id = %d
             LIMIT 1",
            [ $user_id ]
        );

        $email = strtolower( trim( metis_email_clean( (string) ( $row['workspace_email'] ?? '' ) ) ) );
        if ( ! metis_email_is_valid( $email ) ) {
            $email = strtolower( trim( metis_email_clean( (string) ( $row['email'] ?? '' ) ) ) );
        }
        return $email !== '' && metis_email_is_valid( $email ) ? $email : '';
    }

    private static function moduleLabel( string $module_key ): string {
        foreach ( \Metis\Modules\Forms\Support::moduleOptions() as $option ) {
            if ( (string) ( $option['value'] ?? '' ) === $module_key ) {
                return (string) ( $option['label'] ?? 'Unassigned' );
            }
        }

        return 'Unassigned';
    }

    private static function table( string $key ): string {
        return (string) \Metis_Tables::get( $key );
    }

    private static function db(): \Metis\Services\DatabaseService {
        return \metis_db();
    }

    private static function now(): string {
        return (string) \metis_current_time( 'mysql' );
    }
}
