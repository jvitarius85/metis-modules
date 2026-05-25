<?php
declare(strict_types=1);

namespace Metis\Modules\Forms;

use Metis\Modules\Forms\Concerns\SharedRepositoryLogic;

final class FormSubmissionRepository {
    use SharedRepositoryLogic;

    public static function listSubmissions( int $form_id, int $limit = 200 ): array {
        SchemaManager::ensureSchema();
        if ( $form_id < 1 ) {
            return [];
        }

        $limit = max( 1, min( 1000, $limit ) );
        $table = self::table( 'form_submissions' );
        $rows = self::db()->fetchAll(
            "SELECT *
             FROM {$table}
             WHERE form_id = %d
             ORDER BY created_at DESC, id DESC
             LIMIT %d",
            [ $form_id, $limit ]
        );

        $entries = [];
        foreach ( $rows as $row ) {
            $entries[] = [
                'id'                => (int) ( $row['id'] ?? 0 ),
                'submission_key'    => (string) ( $row['submission_key'] ?? '' ),
                'submission_status' => (string) ( $row['submission_status'] ?? 'submitted' ),
                'payment_status'    => (string) ( $row['payment_status'] ?? 'not_required' ),
                'payment_intent_id' => (string) ( $row['payment_intent_id'] ?? '' ),
                'amount_total'      => (float) ( $row['amount_total'] ?? 0 ),
                'currency'          => (string) ( $row['currency'] ?? 'usd' ),
                'submitter_email'   => (string) ( $row['submitter_email'] ?? '' ),
                'payload'           => self::decodeJson( $row['payload_json'] ?? '' ),
                'normalized'        => self::decodeJson( $row['normalized_json'] ?? '' ),
                'totals'            => self::decodeJson( $row['totals_json'] ?? '' ),
                'created_at'        => (string) ( $row['created_at'] ?? '' ),
            ];
        }

        return $entries;
    }

    public static function summarizeSubmissions( int $form_id ): array {
        SchemaManager::ensureSchema();
        if ( $form_id < 1 ) {
            return [
                'submission_count'      => 0,
                'revenue_total'         => 0.0,
                'payment_pending_count' => 0,
                'last_submission_at'    => '',
            ];
        }

        $table = self::table( 'form_submissions' );
        $row = self::db()->fetchOne(
            "SELECT
                COUNT(*) AS submission_count,
                COALESCE(SUM(amount_total), 0) AS revenue_total,
                SUM(CASE WHEN payment_status IN ('pending', 'requires_payment_method', 'processing') THEN 1 ELSE 0 END) AS payment_pending_count,
                MAX(created_at) AS last_submission_at
             FROM {$table}
             WHERE form_id = %d",
            [ $form_id ]
        );

        return [
            'submission_count'      => (int) ( $row['submission_count'] ?? 0 ),
            'revenue_total'         => (float) ( $row['revenue_total'] ?? 0 ),
            'payment_pending_count' => (int) ( $row['payment_pending_count'] ?? 0 ),
            'last_submission_at'    => (string) ( $row['last_submission_at'] ?? '' ),
        ];
    }

    public static function exportSubmissionsCsv( int $form_id ): string {
        $entries = self::listSubmissions( $form_id, 10000 );
        if ( empty( $entries ) ) {
            return '';
        }

        $headers = [ 'submission_key', 'submitter_email', 'payment_status', 'amount_total', 'created_at' ];
        $dynamic = [];
        foreach ( $entries as $entry ) {
            foreach ( self::flattenForCsv( (array) ( $entry['normalized'] ?? [] ) ) as $key => $value ) {
                unset( $value );
                $dynamic[ $key ] = true;
            }
        }

        $header_row = array_merge( $headers, array_keys( $dynamic ) );
        $stream = fopen( 'php://temp', 'r+' );
        fputcsv( $stream, $header_row );

        foreach ( $entries as $entry ) {
            $flat = self::flattenForCsv( (array) ( $entry['normalized'] ?? [] ) );
            $row = [];
            foreach ( $header_row as $column ) {
                if ( in_array( $column, $headers, true ) ) {
                    $row[] = (string) ( $entry[ $column ] ?? '' );
                } else {
                    $row[] = (string) ( $flat[ $column ] ?? '' );
                }
            }
            fputcsv( $stream, $row );
        }

        rewind( $stream );
        return (string) stream_get_contents( $stream );
    }

    public static function resolveDynamicOptions( array $source, ?string $parent_value = null ): array {
        $type = \metis_key_clean( (string) ( $source['type'] ?? '' ) );
        $parent_value = trim( (string) $parent_value );

        if ( $type === '' || $type === 'static' ) {
            return self::normalizeOptions( $source['items'] ?? [] );
        }

        if ( $type === 'grandys_categories' ) {
            return self::grandyCategoryOptions();
        }

        if ( $type === 'grandys_items' ) {
            $items = self::grandyItemOptions();
            if ( $parent_value === '' && ! empty( $source['parent_field'] ) ) {
                return [];
            }
            if ( $parent_value === '' ) {
                return $items;
            }

            return array_values(
                array_filter(
                    $items,
                    static fn ( array $item ): bool => (string) ( $item['category'] ?? '' ) === $parent_value
                )
            );
        }

        if ( $type === 'campaigns' ) {
            return self::campaignOptions();
        }

        return self::normalizeOptions( $source['items'] ?? [] );
    }

    public static function publicAvailability( array $form, array $input = [] ): array {
        $settings = self::normalizeSettings( $form['settings'] ?? [] );
        $access = (array) ( $settings['access'] ?? [] );
        $schedule = (array) ( $settings['schedule'] ?? [] );
        $now = (int) \metis_current_time( 'timestamp' );

        if ( ! empty( $schedule['enabled'] ) ) {
            $start = self::parseTimestamp( (string) ( $schedule['start_at'] ?? '' ) );
            $end = self::parseTimestamp( (string) ( $schedule['end_at'] ?? '' ) );
            if ( ( $start > 0 && $now < $start ) || ( $end > 0 && $now > $end ) ) {
                return [
                    'ok'      => false,
                    'blocked' => true,
                    'status'  => 403,
                    'message' => (string) ( $schedule['closed_message'] ?? 'This form is not accepting submissions right now.' ),
                ];
            }
        }

        $mode = \metis_key_clean( (string) ( $access['mode'] ?? 'public' ) );
        if ( $mode === 'public' ) {
            return [ 'ok' => true ];
        }

        if ( $mode === 'logged_in' && ! \metis_user_logged_in() ) {
            return [
                'ok'      => false,
                'blocked' => true,
                'status'  => 401,
                'message' => (string) ( $access['denied_message'] ?? 'You must be signed in to access this form.' ),
            ];
        }

        if ( $mode === 'password' ) {
            $candidate = trim( (string) ( $input['_access_password'] ?? '' ) );
            if ( $candidate === '' || $candidate !== (string) ( $access['password'] ?? '' ) ) {
                return [
                    'ok'                => false,
                    'blocked'           => true,
                    'status'            => 401,
                    'message'           => (string) ( $access['denied_message'] ?? 'A valid password is required for this form.' ),
                    'requires_password' => true,
                ];
            }
        }

        if ( $mode === 'role' ) {
            if ( ! \metis_user_logged_in() ) {
                return [
                    'ok'      => false,
                    'blocked' => true,
                    'status'  => 401,
                    'message' => (string) ( $access['denied_message'] ?? 'You must be signed in to access this form.' ),
                ];
            }

            $allowed = array_values(
                array_filter(
                    array_map( static fn ( $role ): string => \metis_key_clean( (string) $role ), (array) ( $access['roles'] ?? [] ) )
                )
            );
            $current = [];
            if ( \function_exists( 'metis_runtime_current_user' ) ) {
                $current = array_map( 'strval', (array) ( \metis_runtime_current_user()->roles ?? [] ) );
            } elseif ( \function_exists( 'metis_auth_user_row' ) ) {
                $auth = \metis_auth_user_row( 'id', \metis_current_user_id() );
                if ( is_array( $auth ) ) {
                    $current = array_map( 'strval', (array) ( json_decode( (string) ( $auth['roles_json'] ?? '[]' ), true ) ?: [] ) );
                    $person_id = (int) ( $auth['person_id'] ?? 0 );
                    if ( $person_id > 0 && \function_exists( 'metis_auth_person_roles' ) ) {
                        $current = array_values( array_unique( array_merge( $current, \metis_auth_person_roles( $person_id ) ) ) );
                    }
                }
            }
            if ( empty( array_intersect( $allowed, $current ) ) ) {
                return [
                    'ok'      => false,
                    'blocked' => true,
                    'status'  => 403,
                    'message' => (string) ( $access['denied_message'] ?? 'You do not have access to this form.' ),
                ];
            }
        }

        return [ 'ok' => true ];
    }

    public static function formSupportsPayments( array $form ): bool {
        foreach ( (array) ( $form['schema'] ?? [] ) as $field ) {
            if ( is_array( $field ) && ( $field['type'] ?? '' ) === 'payment' ) {
                return true;
            }
        }

        return false;
    }

    public static function submitForm( array $form, array $payload, array $files = [], string $source_url = '' ): array {
        unset( $files );
        $normalized = self::normalizeSubmission( $form, $payload );
        if ( ! empty( $normalized['errors'] ) ) {
            return [
                'ok'      => false,
                'status'  => 422,
                'message' => 'Please review the highlighted fields and try again.',
                'errors'  => $normalized['errors'],
            ];
        }

        if ( self::formSupportsPayments( $form ) ) {
            return [
                'ok'      => false,
                'status'  => 422,
                'message' => 'This form requires payment. Use the payment flow to submit it.',
            ];
        }

        $submission = self::insertSubmission(
            $form,
            $normalized['normalized'],
            $normalized['raw'],
            [
                'base_amount'  => 0.0,
                'fee_amount'   => 0.0,
                'covered_fee'  => 0.0,
                'grand_total'  => 0.0,
                'currency'     => 'usd',
                'cover_fees'   => false,
                'payment_mode' => 'not_required',
            ],
            null,
            $source_url
        );
        if ( empty( $submission['ok'] ) ) {
            return $submission;
        }

        $binding_context = self::dispatchBindings( $form, $submission['submission'], $normalized['normalized'], [], null, null );
        self::sendNotifications( $form, $submission['submission'], $normalized['normalized'], $binding_context );

        return [
            'ok'      => true,
            'status'  => 200,
            'message' => (string) ( $form['settings']['confirmation']['message'] ?? 'Thanks, your submission has been received.' ),
        ];
    }

    public static function preparePublicPayment( array $form, array $payload, array $files = [], string $source_url = '' ): array {
        unset( $files );
        $payment_field = self::paymentField( (array) ( $form['schema'] ?? [] ) );
        if ( ! is_array( $payment_field ) ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'This form is not configured for payments.' ];
        }

        $normalized = self::normalizeSubmission( $form, $payload );
        if ( ! empty( $normalized['errors'] ) ) {
            return [
                'ok'      => false,
                'status'  => 422,
                'message' => 'Please review the highlighted fields and try again.',
                'errors'  => $normalized['errors'],
            ];
        }

        $totals = self::calculatePaymentTotals( (array) ( $payment_field['payment'] ?? [] ), $payload );
        if ( empty( $totals['ok'] ) ) {
            return $totals;
        }

        $session_key = \metis_generate_code( 'FPS', self::table( 'form_payment_sessions' ), 'session_key' );
        $stripe = self::createPaymentIntent( $form, $session_key, $totals, $normalized['normalized'] );
        if ( empty( $stripe['ok'] ) ) {
            return $stripe;
        }

        $saved = self::persistPaymentSession(
            $session_key,
            (int) ( $form['id'] ?? 0 ),
            (string) ( $stripe['payment_intent_id'] ?? '' ),
            $payload,
            $normalized['normalized'],
            $totals,
            $source_url
        );
        if ( empty( $saved['ok'] ) ) {
            return $saved;
        }

        return [
            'ok'                => true,
            'status'            => 200,
            'payment_session'   => $session_key,
            'payment_intent_id' => (string) ( $stripe['payment_intent_id'] ?? '' ),
            'client_secret'     => (string) ( $stripe['client_secret'] ?? '' ),
            'publishable_key'   => (string) ( $stripe['publishable_key'] ?? '' ),
            'totals'            => $totals,
        ];
    }

    public static function finalizePaymentSession( string $session_key, ?string $payment_intent_id = null ): array {
        $session_key = trim( $session_key );
        if ( $session_key === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Payment session is required.' ];
        }

        $session = self::getPaymentSession( $session_key );
        if ( ! is_array( $session ) ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Payment session not found.' ];
        }

        $expected_payment_intent_id = trim( (string) ( $session['payment_intent_id'] ?? '' ) );
        $payment_intent_id = trim( (string) ( $payment_intent_id ?: $expected_payment_intent_id ) );
        if ( $payment_intent_id === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Payment intent is required.' ];
        }

        if ( $expected_payment_intent_id === '' || ! hash_equals( $expected_payment_intent_id, $payment_intent_id ) ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Payment session does not match the submitted payment.' ];
        }

        $existing = self::getSubmissionByPaymentIntent( $payment_intent_id );
        if ( is_array( $existing ) ) {
            return [
                'ok'      => true,
                'status'  => 200,
                'message' => 'Payment already finalized.',
            ];
        }

        $stripe = \function_exists( 'metis_stripe_client' ) ? \metis_stripe_client() : null;
        if ( ! $stripe ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Stripe is not configured.' ];
        }

        try {
            $intent = $stripe->retrievePaymentIntent(
                $payment_intent_id,
                [ 'expand' => [ 'latest_charge.balance_transaction' ] ]
            );
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Payment intent could not be retrieved.' ];
        }

        if ( ! is_object( $intent ) || (string) ( $intent->status ?? '' ) !== 'succeeded' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Payment has not completed successfully.' ];
        }

        $raw = self::decodeJson( $session['payload_json'] ?? '' );
        $normalized = self::decodeJson( $session['normalized_json'] ?? '' );
        $totals = self::decodeJson( $session['totals_json'] ?? '' );

        $expected_amount = (int) ( $totals['amount_cents'] ?? 0 );
        $actual_amount = (int) ( $intent->amount ?? 0 );
        if ( $expected_amount > 0 && $actual_amount !== $expected_amount ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Payment amount does not match the original session.' ];
        }

        $expected_currency = strtolower( trim( (string) ( $totals['currency'] ?? '' ) ) );
        $actual_currency = strtolower( trim( (string) ( $intent->currency ?? '' ) ) );
        if ( $expected_currency !== '' && $actual_currency !== '' && $actual_currency !== $expected_currency ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Payment currency does not match the original session.' ];
        }

        $form = FormDefinitionRepository::getFormById( (int) ( $session['form_id'] ?? 0 ), false );
        if ( ! is_array( $form ) ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Form not found.' ];
        }

        $charge = is_object( $intent->latest_charge ?? null ) ? $intent->latest_charge : null;

        $submission = self::insertSubmission(
            $form,
            $normalized,
            $raw,
            $totals,
            $payment_intent_id,
            (string) ( $session['source_url'] ?? '' )
        );
        if ( empty( $submission['ok'] ) ) {
            return $submission;
        }

        $binding_context = self::dispatchBindings( $form, $submission['submission'], $normalized, $totals, $intent, $charge );
        if ( \class_exists( \Metis\Modules\Donations\RecurringDonationsService::class ) ) {
            \Metis\Modules\Donations\RecurringDonationsService::createFromSuccessfulPayment(
                $form,
                $normalized,
                $totals,
                $intent,
                (string) ( $binding_context['transaction_tid'] ?? '' )
            );
        }
        self::sendNotifications( $form, $submission['submission'], $normalized, $binding_context );
        self::deletePaymentSession( $session_key );

        return [
            'ok'      => true,
            'status'  => 200,
            'message' => (string) ( $form['settings']['confirmation']['message'] ?? 'Payment complete.' ),
        ];
    }
}
