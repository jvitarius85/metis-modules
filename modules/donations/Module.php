<?php
declare(strict_types=1);

namespace Metis\Modules\Donations;

use Metis\Services\DatabaseService;

final class DonationsModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \Metis_Logger::info( 'Donations bootstrap loaded' );

        \metis_on( 'init', [ self::class, 'handleAdminBackfillTriggers' ] );
        \metis_on( 'init', [ self::class, 'ensureRuntimeSchema' ], 5 );
        \metis_on( 'metis_assets_enqueue', [ self::class, 'enqueueReportsAssets' ] );

        if ( function_exists( 'metis_shortcode_register' ) ) {
            \metis_shortcode_register( 'metis_campaign_progress', [ self::class, 'renderCampaignProgressShortcode' ] );
        }

        if ( \class_exists( '\Metis_Cron_Manager' ) ) {
            \Metis_Cron_Manager::register_task(
                'donations_stripe_reconciliation',
                static function (): array {
                    return StripeReconciliationService::runNightly();
                },
                [
                    'label'    => 'Donations Stripe Reconciliation',
                    'interval' => DAY_IN_SECONDS,
                    'lock_ttl' => 45 * MINUTE_IN_SECONDS,
                    'module'   => 'donations',
                ]
            );
        }
    }

    public static function ensureRuntimeSchema(): void {
        if ( function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'donations_runtime_schema',
                [ __FILE__, __DIR__ . '/StripeReconciliationService.php', __DIR__ . '/RecurringDonationsService.php' ],
                static function (): void {
                    self::ensureTransactionPaymentDetailSchema();
                    StripeReconciliationService::ensureSchema();
                    RecurringDonationsService::ensureSchema();
                }
            );
            return;
        }

        self::ensureTransactionPaymentDetailSchema();
        StripeReconciliationService::ensureSchema();
        RecurringDonationsService::ensureSchema();
    }

    public static function baseUrl(): string {
        return rtrim( \metis_portal_url( 'donations' ), '/' );
    }

    public static function platformLabel( string $code ): string {
        $map = [
            'GB' => 'GiveButter',
            'ST' => 'Stripe',
            'OL' => 'Offline',
        ];

        return $map[ $code ] ?? $code;
    }

    public static function paymethodBadge( ?string $method ): string {
        if ( ! $method ) {
            return "<span class='metis-badge gray'>Unknown</span>";
        }

        $map = [
            'card'  => 'Credit Card',
            'cc'    => 'Credit Card',
            'ach'   => 'Bank Transfer',
            'ck'    => 'Check',
            'cash'  => 'Cash',
            'link'  => 'Link',
            'visa'  => 'Credit Card',
            'mastercard' => 'Credit Card',
            'amex'  => 'Credit Card',
            'discover' => 'Credit Card',
            'other' => 'Other',
        ];

        $label = $map[ strtolower( $method ) ] ?? ucfirst( $method );
        return "<span class='metis-badge muted'>" . \metis_escape_html( $label ) . '</span>';
    }

    public static function paymentMethodText( ?string $method, mixed $transaction = null ): string {
        $method = strtolower( trim( (string) $method ) );
        $labels = [
            'card' => 'Credit Card',
            'cc' => 'Credit Card',
            'ach' => 'Bank Transfer',
            'ck' => 'Check',
            'cash' => 'Cash',
            'link' => 'Link',
            'visa' => 'Credit Card',
            'mastercard' => 'Credit Card',
            'amex' => 'Credit Card',
            'discover' => 'Credit Card',
            'other' => 'Other',
        ];
        $label = $labels[ $method ] ?? ( $method !== '' ? ucfirst( $method ) : 'Unknown' );
        $get = static function ( string $key ) use ( $transaction ): string {
            if ( is_array( $transaction ) ) {
                return trim( (string) ( $transaction[ $key ] ?? '' ) );
            }
            if ( is_object( $transaction ) ) {
                return trim( (string) ( $transaction->{$key} ?? '' ) );
            }
            return '';
        };
        if ( in_array( $method, [ 'card', 'cc', 'link', 'visa', 'mastercard', 'amex', 'discover' ], true ) ) {
            $brand = $get( 'card_brand' );
            $last4 = $get( 'card_last4' );
            $bits = [];
            if ( $brand === '' && in_array( $method, [ 'visa', 'mastercard', 'amex', 'discover' ], true ) ) {
                $brand = $method;
            }
            if ( $brand !== '' ) {
                $bits[] = strtoupper( $brand );
            }
            if ( $last4 !== '' ) {
                $bits[] = 'ending ' . $last4;
            }
            if ( $bits !== [] ) {
                return $label . ' (' . implode( ' ', $bits ) . ')';
            }
        }
        if ( $method === 'ach' ) {
            $last4 = $get( 'card_last4' );
            if ( $last4 !== '' ) {
                return $label . ' (ending ' . $last4 . ')';
            }
        }
        if ( $method === 'ck' ) {
            $check = $get( 'chk_num' );
            if ( $check !== '' ) {
                return $label . ' #' . $check;
            }
        }
        return $label;
    }

    public static function paymethodBadgeWithDetails( ?string $method, mixed $transaction = null ): string {
        $text = self::paymentMethodText( $method, $transaction );
        $icon = self::paymentMethodIconMarkup( $method, $transaction );
        $visible = self::paymentMethodVisibleText( $method, $transaction );
        return "<span class='metis-badge muted metis-payment-method-badge' title='" . \metis_escape_attr( $text ) . "'>"
            . $icon
            . "<span class='metis-payment-method-text'>" . \metis_escape_html( $visible ) . '</span>'
            . '</span>';
    }

    private static function paymentMethodVisibleText( ?string $method, mixed $transaction = null ): string {
        $method = strtolower( trim( (string) $method ) );
        $get = static function ( string $key ) use ( $transaction ): string {
            if ( is_array( $transaction ) ) {
                return trim( (string) ( $transaction[ $key ] ?? '' ) );
            }
            if ( is_object( $transaction ) ) {
                return trim( (string) ( $transaction->{$key} ?? '' ) );
            }
            return '';
        };
        $last4 = $get( 'card_last4' );
        if ( in_array( $method, [ 'card', 'cc', 'link', 'visa', 'mastercard', 'amex', 'discover' ], true ) ) {
            return $last4 !== '' ? 'ending ' . $last4 : 'Credit Card';
        }
        if ( $method === 'ach' ) {
            return $last4 !== '' ? 'Bank Transfer ending ' . $last4 : 'Bank Transfer';
        }
        if ( $method === 'ck' ) {
            $check = $get( 'chk_num' );
            return $check !== '' ? 'Check #' . $check : 'Check';
        }
        return self::paymentMethodText( $method, $transaction );
    }

    private static function paymentMethodIconMarkup( ?string $method, mixed $transaction = null ): string {
        $method = strtolower( trim( (string) $method ) );
        $brand = '';
        if ( is_array( $transaction ) ) {
            $brand = strtolower( trim( (string) ( $transaction['card_brand'] ?? '' ) ) );
        } elseif ( is_object( $transaction ) ) {
            $brand = strtolower( trim( (string) ( $transaction->card_brand ?? '' ) ) );
        }
        if ( $brand === '' && in_array( $method, [ 'visa', 'mastercard', 'amex', 'discover' ], true ) ) {
            $brand = $method;
        }

        $icon_slug = match ( $brand ) {
            'visa' => 'visa',
            'mastercard', 'master card' => 'mastercard',
            'amex', 'american express' => 'amex',
            'discover' => 'discover',
            default => match ( $method ) {
                'ck' => 'checkbook',
                'ach' => 'ach-transfer',
                'cash' => 'money',
                'link' => 'link',
                default => 'credit-card',
            },
        };

        if ( ! function_exists( 'metis_navigation_svg_icon_markup' ) ) {
            return '';
        }
        $svg = (string) \metis_navigation_svg_icon_markup( $icon_slug );
        if ( trim( $svg ) === '' ) {
            return '';
        }
        return "<span class='metis-payment-method-icon' aria-hidden='true'>" . $svg . '</span>';
    }

    public static function ensureTransactionPaymentDetailSchema(): void {
        $table = \Metis_Tables::get( 'transactions' );
        if ( $table === '' ) {
            return;
        }
        try {
            $columns = [];
            foreach ( self::db()->fetchAll( "SHOW COLUMNS FROM {$table}" ) as $row ) {
                $columns[ strtolower( (string) ( $row['Field'] ?? '' ) ) ] = true;
            }
            if ( ! isset( $columns['card_brand'] ) ) {
                self::db()->execute( "ALTER TABLE {$table} ADD COLUMN card_brand VARCHAR(64) DEFAULT NULL AFTER chk_num" );
            }
            if ( ! isset( $columns['card_last4'] ) ) {
                self::db()->execute( "ALTER TABLE {$table} ADD COLUMN card_last4 VARCHAR(8) DEFAULT NULL AFTER card_brand" );
            }
        } catch ( \Throwable $e ) {
            \Metis_Logger::warn( 'donations.payment_detail_schema_failed', [ 'error' => $e->getMessage() ] );
        }
    }

    /**
     * @return array{payment_method:string,card_brand:?string,card_last4:?string}
     */
    public static function stripePaymentMethodDetails( mixed $charge ): array {
        $details = is_object( $charge ) && is_object( $charge->payment_method_details ?? null )
            ? $charge->payment_method_details
            : null;
        $type = strtolower( trim( (string) ( $details->type ?? '' ) ) );
        $method = 'cc';
        $brand = null;
        $last4 = null;
        if ( $type === 'us_bank_account' || $type === 'ach_credit_transfer' || $type === 'ach_debit' ) {
            $method = 'ach';
            $bank = is_object( $details->us_bank_account ?? null ) ? $details->us_bank_account : null;
            $last4 = $bank && ! empty( $bank->last4 ) ? (string) $bank->last4 : null;
        } elseif ( $type === 'link' ) {
            $method = 'link';
        } elseif ( $type === 'card' || $type === '' ) {
            $method = 'cc';
            $card = is_object( $details->card ?? null ) ? $details->card : null;
            $brand = $card && ! empty( $card->brand ) ? strtolower( (string) $card->brand ) : null;
            $last4 = $card && ! empty( $card->last4 ) ? (string) $card->last4 : null;
        } else {
            $method = 'other';
        }
        return [
            'payment_method' => $method,
            'card_brand' => $brand,
            'card_last4' => $last4,
        ];
    }

    public static function depositBadge( ?string $date ): string {
        if ( ! $date ) {
            return "<span class='metis-badge gray'>Not Deposited</span>";
        }

        return "<span class='metis-badge green'>Deposited</span>";
    }

    public static function statusBadge( string $status ): string {
        $map = [
            'completed' => [ 'Completed', 'green' ],
            'pending'   => [ 'Pending', 'blue' ],
            'failed'    => [ 'Failed', 'red' ],
            'refunded'  => [ 'Refunded', 'gray' ],
            'voided'    => [ 'Voided', 'gray' ],
        ];

        $status = strtolower( $status );

        if ( ! isset( $map[ $status ] ) ) {
            return "<span class='metis-badge gray'>" . \metis_escape_html( $status ) . '</span>';
        }

        [ $label, $color ] = $map[ $status ];
        return "<span class='metis-badge {$color}'>" . \metis_escape_html( $label ) . '</span>';
    }

    public static function depositSourceBadge( object $deposit ): string {
        $meta = [];
        if ( ! empty( $deposit->meta ) ) {
            $decoded = json_decode( $deposit->meta, true );
            if ( is_array( $decoded ) ) {
                $meta = $decoded;
            }
        }

        if (
            isset( $deposit->provider ) && $deposit->provider === 'stripe'
            && ( ! empty( $meta['payout_id'] ) || ( ! empty( $deposit->provider_ref ) && str_starts_with( $deposit->provider_ref, 'DP' ) ) )
        ) {
            $title = ! empty( $meta['payout_id'] )
                ? 'Stripe payout ' . $meta['payout_id']
                : 'Created from Stripe payout';
            return '<span class="metis-badge metis-badge-stripe" title="' . \metis_escape_attr( $title ) . '">Stripe</span>';
        }

        if ( isset( $deposit->provider ) && $deposit->provider === 'givebutter' ) {
            return '<span class="metis-badge metis-badge-gb" title="Legacy GiveButter batch">GiveButter</span>';
        }

        return '<span class="metis-badge metis-badge-manual" title="Manual or offline deposit">Manual</span>';
    }

    public static function generateBatchCode(): string {
        return \metis_generate_code( 'BT', \Metis_Tables::get( 'batches' ), 'batch_code' );
    }

    private static function db(): DatabaseService {
        /** @var DatabaseService $db */
        $db = \metis_db();
        return $db;
    }

    public static function createDepositBatch( array $tids ): string|MetisError {
        $transactions_table = \Metis_Tables::get( 'transactions' );
        $batches_table      = \Metis_Tables::get( 'batches' );
        $db                 = self::db();

        $tids = array_values( array_unique( array_filter( array_map( 'metis_text_clean', $tids ) ) ) );
        if ( empty( $tids ) ) {
            return new MetisError( 'no_tids', 'No transactions were provided.' );
        }

        $placeholders = implode( ',', array_fill( 0, count( $tids ), '%s' ) );

        $db->execute( "
            UPDATE {$transactions_table}
            SET tran_date = LEFT(tran_date, 19)
            WHERE tran_date IS NOT NULL
              AND tran_date <> ''
              AND CHAR_LENGTH(tran_date) >= 19
        " );

        $db->execute( "
            UPDATE {$transactions_table}
            SET tran_date = STR_TO_DATE(tran_date, '%Y-%m-%d %H:%i:%s')
            WHERE tran_date IS NOT NULL
              AND tran_date <> ''
              AND tran_date <> '0000-00-00 00:00:00'
              AND tran_date NOT LIKE '____-__-__ __:__:__'
        " );

        $summary_sql = "
            SELECT
                COUNT(*) AS txn_count,
                SUM(amount + IFNULL(fee, 0)) AS gross,
                SUM(IFNULL(fee, 0)) AS fees,
                SUM(
                    CASE
                        WHEN payout IS NOT NULL THEN payout
                        ELSE amount - IFNULL(fee, 0)
                    END
                ) AS net
            FROM {$transactions_table}
            WHERE tid IN ({$placeholders})
        ";

        $summary = $db->fetchOne( $summary_sql, $tids );
        if ( ! $summary || ! intval( $summary['txn_count'] ?? 0 ) ) {
            return new MetisError( 'no_rows', 'No matching transactions found for this batch.' );
        }

        $now       = \metis_current_time( 'mysql' );
        $batch_row = [
            'deposit_date' => $now,
            'gross'        => $summary['gross'] ?: 0,
            'fees'         => $summary['fees'] ?: 0,
            'net'          => $summary['net'] ?: 0,
            'txn_count'    => intval( $summary['txn_count'] ?? 0 ),
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
        if ( function_exists( 'metis_entity_id_service' ) ) {
            $batch_row = \metis_entity_id_service()->assignForInsert( 'deposit_batch', $batch_row );
        } else {
            $batch_row['batch_code'] = self::generateBatchCode();
        }
        $batch_code = (string) ( $batch_row['batch_uid'] ?? $batch_row['batch_code'] ?? '' );

        $inserted = $db->insert(
            $batches_table,
            $batch_row,
            [ '%s', '%s', '%s', '%f', '%f', '%f', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return new MetisError( 'insert_failed', 'Failed to create deposit batch record.' );
        }

        $batch_id   = $db->lastInsertId();
        if ( $batch_id > 0 && function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->register( 'deposit_batch', $batch_id, $batch_code );
        }
        $update_sql = "
            UPDATE {$transactions_table}
            SET
                deposit_batch_id = %s,
                deposit_date = %s
            WHERE tid IN ({$placeholders})
        ";

        $result = $db->execute( $db->prepare( $update_sql, ...array_merge( [ $batch_code, $now ], $tids ) ) );
        if ( $result === false ) {
            return new MetisError( 'update_failed', 'Batch created but failed to attach transactions.' );
        }

        \Metis_Logger::info( 'Deposit batch created', [ 'batch_code' => $batch_code, 'txn_count' => $summary['txn_count'] ?? 0 ] );

        if ( \Metis\Core\Application::has_service( 'events' ) ) {
            \Metis\Core\Application::service( 'events' )->publish(
                'donation.batch.created',
                [
                    'batch_id'    => $batch_id,
                    'batch_code'  => $batch_code,
                    'txn_count'   => (int) ( $summary['txn_count'] ?? 0 ),
                    'gross'       => (float) ( $summary['gross'] ?: 0 ),
                    'fees'        => (float) ( $summary['fees'] ?: 0 ),
                    'net'         => (float) ( $summary['net'] ?: 0 ),
                    'created_at'  => $now,
                    'transaction_ids' => $tids,
                ]
            );
        }

        return $batch_code;
    }

    public static function recordOfflineDonation( array $input, int $requestedBy = 0 ): array {
        self::ensureTransactionPaymentDetailSchema();
        $transactions_table = \Metis_Tables::get( 'transactions' );
        $campaigns_table    = \Metis_Tables::get( 'campaigns' );

        if ( $transactions_table === '' ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Donation transactions table is not available.' ];
        }

        $campaign_code = trim( \metis_text_clean( (string) ( $input['campaign_code'] ?? '' ) ) );
        if ( $campaign_code === '' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Choose a campaign for the donation.' ];
        }

        $campaign_exists = (int) self::db()->scalar(
            "SELECT COUNT(*) FROM {$campaigns_table} WHERE cid = %s LIMIT 1",
            [ $campaign_code ]
        );
        if ( $campaign_exists < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'The selected campaign could not be found.' ];
        }

        $amount = round( abs( (float) ( $input['amount'] ?? 0 ) ), 2 );
        if ( $amount <= 0 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Donation amount must be greater than zero.' ];
        }

        $tran_date = self::normalizeOfflineDonationDate( (string) ( $input['tran_date'] ?? '' ) );
        if ( $tran_date === '' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Enter a valid donation date.' ];
        }

        $payment_method = self::normalizeOfflinePaymentMethod( (string) ( $input['payment_method'] ?? '' ) );
        $check_number   = trim( \metis_text_clean( (string) ( $input['chk_num'] ?? '' ) ) );
        if ( $payment_method !== 'ck' ) {
            $check_number = '';
        }

        $donor_did = trim( \metis_text_clean( (string) ( $input['donor_did'] ?? '' ) ) );
        $contact_input = [
            'first_name' => (string) ( $input['first_name'] ?? '' ),
            'last_name'  => (string) ( $input['last_name'] ?? '' ),
            'email'      => (string) ( $input['email'] ?? '' ),
            'phone'      => (string) ( $input['phone'] ?? '' ),
        ];
        $contact = $donor_did !== ''
            ? self::hydrateOfflineDonorContactByDid( $donor_did, $contact_input )
            : self::upsertOfflineDonorContact( $contact_input );

        if ( $donor_did !== '' && ! is_array( $contact ) ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'The selected donor could not be found. Please search again.' ];
        }

        $has_donor_identity = trim(
            (string) ( $contact['did'] ?? '' )
            . (string) ( $input['first_name'] ?? '' )
            . (string) ( $input['last_name'] ?? '' )
            . (string) ( $input['email'] ?? '' )
        ) !== '';
        if ( ! $has_donor_identity ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Enter at least a donor name or email address.' ];
        }

        $notes = trim( \metis_textarea_clean( (string) ( $input['notes'] ?? '' ) ) );
        $now   = \metis_current_time( 'mysql' );
        $payload = [
            'did'                => (string) ( $contact['did'] ?? '' ) !== '' ? (string) $contact['did'] : null,
            'platform'           => 'OL',
            'campaign_code'      => $campaign_code,
            'plan_id'            => null,
            'fund_code'          => null,
            'status'             => 'completed',
            'payment_method'     => $payment_method,
            'chk_num'            => $check_number !== '' ? $check_number : null,
            'amount'             => $amount,
            'fee'                => 0.00,
            'fee_covered'        => 0.00,
            'pl_fee'             => 0.00,
            'payout'             => $amount,
            'tran_date'          => $tran_date,
            'deposit_date'       => null,
            'deposit_batch_id'   => null,
            'giving_space_id'    => null,
            'giving_space_name'  => null,
            'giving_space_msg'   => null,
            'refunded'           => 0,
            'refunded_at'        => null,
            'notes'              => $notes !== '' ? $notes : null,
            'stripe_pay_int'     => null,
            'stripe_charge_id'   => null,
            'stripe_balance_txn' => null,
            'stripe_payout_id'   => null,
            'stripe_refund_id'   => null,
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        if ( function_exists( 'metis_entity_id_service' ) ) {
            $payload = \metis_entity_id_service()->assignForInsert( 'donation_transaction', $payload );
        } else {
            $payload['tid'] = \metis_generate_code( 'TR', $transactions_table, 'tid' );
        }

        $tid = (string) ( $payload['transaction_uid'] ?? $payload['tid'] ?? '' );
        $inserted = self::db()->insert( $transactions_table, $payload );
        if ( ! $inserted ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Could not save the offline donation.' ];
        }

        $transaction_id = self::db()->lastInsertId();
        if ( $transaction_id > 0 && function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->register( 'donation_transaction', $transaction_id, $tid );
        }

        if ( \function_exists( 'metis_finance_record_offline_donation_receipt' ) ) {
            \metis_finance_record_offline_donation_receipt(
                [
                    'event_date'   => substr( $tran_date, 0, 10 ),
                    'reference_id' => $tid,
                    'amount'       => $amount,
                    'description'  => 'Offline donation receipt',
                ],
                $requestedBy
            );
        }

        if ( \Metis\Core\Application::has_service( 'events' ) ) {
            \Metis\Core\Application::service( 'events' )->publish(
                'donation.received',
                [
                    'event_id'      => 0,
                    'reference_id'  => $tid,
                    'amount'        => $amount,
                    'currency'      => 'usd',
                    'transaction_id'=> $transaction_id,
                    'payment_method'=> $payment_method,
                    'source'        => 'offline',
                ]
            );
        }

        \Metis_Logger::info( 'Offline donation recorded', [
            'tid'           => $tid,
            'campaign_code' => $campaign_code,
            'amount'        => $amount,
            'payment_method'=> $payment_method,
        ] );

        return [
            'ok'      => true,
            'status'  => 200,
            'tid'     => $tid,
            'message' => 'Offline donation recorded.',
        ];
    }

    public static function lookupOfflineDonors( string $query, int $limit = 8 ): array {
        $contacts_table     = \Metis_Tables::get( 'contacts' );
        $transactions_table = \Metis_Tables::get( 'transactions' );
        $query = trim( \metis_text_clean( $query ) );
        if ( $contacts_table === '' || $query === '' ) {
            return [];
        }

        $limit = max( 1, min( 15, $limit ) );
        $like  = '%' . $query . '%';
        $rows = self::db()->fetchAll(
            "SELECT
                c.did,
                c.first_name,
                c.last_name,
                c.email,
                MAX(cd.phone) AS phone,
                COUNT(t.id) AS gift_count,
                COALESCE(SUM(t.amount), 0) AS total_raised
             FROM {$contacts_table} c
             LEFT JOIN " . \Metis_Tables::get( 'contact_details' ) . " cd
               ON cd.contact_id = c.id OR cd.did = c.did
             LEFT JOIN {$transactions_table} t
               ON t.did = c.did
             WHERE c.did IS NOT NULL
               AND c.did <> ''
               AND (
                    c.did LIKE %s
                    OR c.email LIKE %s
                    OR c.first_name LIKE %s
                    OR c.last_name LIKE %s
                    OR CONCAT(TRIM(COALESCE(c.first_name, '')), ' ', TRIM(COALESCE(c.last_name, ''))) LIKE %s
               )
             GROUP BY c.did, c.first_name, c.last_name, c.email
             ORDER BY total_raised DESC, gift_count DESC, c.last_name ASC, c.first_name ASC
             LIMIT {$limit}",
            [ $like, $like, $like, $like, $like ]
        );

        if ( ! is_array( $rows ) ) {
            return [];
        }

        return array_map(
            static function ( array $row ): array {
                $name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
                return [
                    'did'         => (string) ( $row['did'] ?? '' ),
                    'first_name'  => (string) ( $row['first_name'] ?? '' ),
                    'last_name'   => (string) ( $row['last_name'] ?? '' ),
                    'name'        => $name !== '' ? $name : (string) ( $row['did'] ?? '' ),
                    'email'       => (string) ( $row['email'] ?? '' ),
                    'phone'       => (string) ( $row['phone'] ?? '' ),
                    'gift_count'  => (int) ( $row['gift_count'] ?? 0 ),
                    'total_raised'=> round( (float) ( $row['total_raised'] ?? 0 ), 2 ),
                ];
            },
            $rows
        );
    }

    public static function addBatchNote( string $batch_code, string $text ): bool|int {
        return self::db()->insert(
            \Metis_Tables::get( 'batch_notes' ),
            [
                'batch_code' => $batch_code,
                'user_id'    => \metis_current_user_id(),
                'note_text'  => $text,
                'created_at' => \metis_current_time( 'mysql' ),
            ]
        );
    }

    public static function updateBatchNote( int $note_id, string $batch_code, string $text ): bool|int {
        return self::db()->update(
            \Metis_Tables::get( 'batch_notes' ),
            [ 'note_text' => $text ],
            [ 'id' => $note_id, 'batch_code' => $batch_code ]
        );
    }

    public static function deleteBatchNote( int $note_id, string $batch_code ): bool|int {
        return self::db()->delete(
            \Metis_Tables::get( 'batch_notes' ),
            [ 'id' => $note_id, 'batch_code' => $batch_code ]
        );
    }

    public static function getBatchNotes( string $batch_code ): array {
        $table = \Metis_Tables::get( 'batch_notes' );
        return self::db()->fetchAll(
            "SELECT * FROM {$table} WHERE batch_code = %s ORDER BY id DESC",
            [ $batch_code ]
        );
    }

    public static function addBatchAudit( string $batch_code, string $type, string $detail = '' ): bool|int {
        return self::db()->insert(
            \Metis_Tables::get( 'batch_audit' ),
            [
                'batch_code'   => $batch_code,
                'user_id'      => \metis_current_user_id(),
                'event_type'   => $type,
                'event_detail' => $detail,
                'created_at'   => \metis_current_time( 'mysql' ),
            ]
        );
    }

    private static function normalizeOfflineDonationDate( string $raw ): string {
        $raw = trim( \metis_text_clean( $raw ) );
        if ( $raw === '' ) {
            return '';
        }

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) === 1 ) {
            return $raw . ' 00:00:00';
        }

        if ( preg_match( '/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(:\d{2})?$/', $raw ) === 1 ) {
            $ts = strtotime( $raw );
            return $ts ? date( 'Y-m-d H:i:s', $ts ) : '';
        }

        $ts = strtotime( $raw );
        return $ts ? date( 'Y-m-d H:i:s', $ts ) : '';
    }

    private static function normalizeOfflinePaymentMethod( string $raw ): string {
        $raw = strtolower( trim( \metis_key_clean( $raw ) ) );
        return in_array( $raw, [ 'cash', 'ck', 'ach', 'other' ], true ) ? $raw : 'other';
    }

    private static function hydrateOfflineDonorContactByDid( string $did, array $input ): array {
        $contacts_table = \Metis_Tables::get( 'contacts' );
        if ( $contacts_table === '' ) {
            return [];
        }

        $did = trim( \metis_text_clean( $did ) );
        if ( $did === '' ) {
            return [];
        }

        $contact = self::db()->fetchOne( "SELECT * FROM {$contacts_table} WHERE did = %s LIMIT 1", [ $did ] );
        if ( ! is_array( $contact ) ) {
            return [];
        }

        $updates = [];
        $first = trim( \metis_text_clean( (string) ( $input['first_name'] ?? '' ) ) );
        $last  = trim( \metis_text_clean( (string) ( $input['last_name'] ?? '' ) ) );
        $email = strtolower( trim( \metis_email_clean( (string) ( $input['email'] ?? '' ) ) ) );

        if ( $first !== '' && (string) ( $contact['first_name'] ?? '' ) === '' ) {
            $updates['first_name'] = $first;
        }
        if ( $last !== '' && (string) ( $contact['last_name'] ?? '' ) === '' ) {
            $updates['last_name'] = $last;
        }
        if ( $email !== '' && (string) ( $contact['email'] ?? '' ) === '' ) {
            $updates['email'] = $email;
        }

        if ( $updates !== [] ) {
            $updates['updated_at'] = \metis_current_time( 'mysql' );
            self::db()->update( $contacts_table, $updates, [ 'id' => (int) $contact['id'] ] );
            $contact = self::db()->fetchOne( "SELECT * FROM {$contacts_table} WHERE id = %d LIMIT 1", [ (int) $contact['id'] ] );
        }

        self::syncOfflineDonorDetails( $contact, $input );
        return is_array( $contact ) ? $contact : [];
    }

    private static function upsertOfflineDonorContact( array $input ): array {
        $contacts_table = \Metis_Tables::get( 'contacts' );
        if ( $contacts_table === '' ) {
            return [];
        }

        $first = trim( \metis_text_clean( (string) ( $input['first_name'] ?? '' ) ) );
        $last  = trim( \metis_text_clean( (string) ( $input['last_name'] ?? '' ) ) );
        $email = strtolower( trim( \metis_email_clean( (string) ( $input['email'] ?? '' ) ) ) );
        $phone = trim( \metis_text_clean( (string) ( $input['phone'] ?? '' ) ) );

        if ( $first === '' && $last === '' && $email === '' && $phone === '' ) {
            return [];
        }

        $db = self::db();
        $existing = null;
        if ( $email !== '' ) {
            $existing = $db->fetchOne( "SELECT * FROM {$contacts_table} WHERE email = %s LIMIT 1", [ $email ] );
        }

        if ( ! is_array( $existing ) && $first !== '' && $last !== '' ) {
            $existing = $db->fetchOne(
                "SELECT * FROM {$contacts_table} WHERE first_name = %s AND last_name = %s ORDER BY id DESC LIMIT 1",
                [ $first, $last ]
            );
        }

        if ( is_array( $existing ) ) {
            $updates = [];
            if ( $first !== '' && (string) ( $existing['first_name'] ?? '' ) === '' ) {
                $updates['first_name'] = $first;
            }
            if ( $last !== '' && (string) ( $existing['last_name'] ?? '' ) === '' ) {
                $updates['last_name'] = $last;
            }
            if ( $email !== '' && (string) ( $existing['email'] ?? '' ) === '' ) {
                $updates['email'] = $email;
            }
            if ( (string) ( $existing['did'] ?? '' ) === '' ) {
                $updates['did'] = \metis_generate_code( 'MW', $contacts_table, 'did' );
            }
            if ( (string) ( $existing['cid'] ?? '' ) === '' ) {
                $updates['cid'] = \metis_generate_code( 'CN', $contacts_table, 'cid' );
            }
            if ( (string) ( $existing['contact_uid'] ?? '' ) === '' ) {
                $updates['contact_uid'] = \metis_generate_code( 'CN', $contacts_table, 'contact_uid' );
            }
            if ( (string) ( $existing['donor_uid'] ?? '' ) === '' ) {
                $updates['donor_uid'] = \metis_generate_code( 'DN', $contacts_table, 'donor_uid' );
            }
            if ( $updates !== [] ) {
                $updates['updated_at'] = \metis_current_time( 'mysql' );
                $db->update( $contacts_table, $updates, [ 'id' => (int) $existing['id'] ] );
                $existing = $db->fetchOne( "SELECT * FROM {$contacts_table} WHERE id = %d LIMIT 1", [ (int) $existing['id'] ] );
            }
        } else {
            $payload = [
                'did'         => \metis_generate_code( 'MW', $contacts_table, 'did' ),
                'email'       => $email !== '' ? $email : null,
                'first_name'  => $first !== '' ? $first : null,
                'last_name'   => $last !== '' ? $last : null,
                'created_at'  => \metis_current_time( 'mysql' ),
                'updated_at'  => \metis_current_time( 'mysql' ),
                'cid'         => \metis_generate_code( 'CN', $contacts_table, 'cid' ),
                'contact_uid' => \metis_generate_code( 'CN', $contacts_table, 'contact_uid' ),
                'donor_uid'   => \metis_generate_code( 'DN', $contacts_table, 'donor_uid' ),
            ];
            $db->insert( $contacts_table, $payload );
            $existing = $db->fetchOne( "SELECT * FROM {$contacts_table} WHERE id = %d LIMIT 1", [ $db->lastInsertId() ] );
        }

        self::syncOfflineDonorDetails( $existing, $input );

        return is_array( $existing ) ? $existing : [];
    }

    private static function syncOfflineDonorDetails( array $contact, array $input ): void {
        $details_table = \Metis_Tables::get( 'contact_details' );
        if ( $details_table === '' || ! is_array( $contact ) ) {
            return;
        }

        $phone = trim( \metis_text_clean( (string) ( $input['phone'] ?? '' ) ) );
        $db = self::db();
        $detail = $db->fetchOne(
            "SELECT * FROM {$details_table} WHERE contact_id = %d OR did = %s LIMIT 1",
            [ (int) ( $contact['id'] ?? 0 ), (string) ( $contact['did'] ?? '' ) ]
        );

        if ( is_array( $detail ) ) {
            $patch = [];
            if ( $phone !== '' && (string) ( $detail['phone'] ?? '' ) === '' ) {
                $patch['phone'] = $phone;
            }
            if ( (int) ( $detail['contact_id'] ?? 0 ) < 1 ) {
                $patch['contact_id'] = (int) ( $contact['id'] ?? 0 );
            }
            if ( (string) ( $detail['contact_cid'] ?? '' ) === '' && (string) ( $contact['cid'] ?? '' ) !== '' ) {
                $patch['contact_cid'] = (string) $contact['cid'];
            }
            if ( $patch !== [] ) {
                $patch['updated_at'] = \metis_current_time( 'mysql' );
                $db->update( $details_table, $patch, [ 'id' => (int) $detail['id'] ] );
            }
            return;
        }

        $db->insert(
            $details_table,
            [
                'did'                      => (string) ( $contact['did'] ?? '' ),
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
                'source_code'              => 'offline_donation',
                'first_contacted'          => \metis_current_time( 'mysql' ),
                'staff_owner'              => null,
                'created_at'               => \metis_current_time( 'mysql' ),
                'updated_at'               => \metis_current_time( 'mysql' ),
                'contact_id'               => (int) ( $contact['id'] ?? 0 ),
                'additional_emails_json'   => null,
                'relationships_json'       => null,
                'contact_cid'              => (string) ( $contact['cid'] ?? '' ),
            ]
        );
    }

    public static function getBatchAudit( string $batch_code ): array {
        $table = \Metis_Tables::get( 'batch_audit' );
        return self::db()->fetchAll(
            "SELECT * FROM {$table} WHERE batch_code = %s ORDER BY id DESC",
            [ $batch_code ]
        );
    }

    public static function getDeposits(): array {
        $table = \Metis_Tables::get( 'deposits' );
        $rows = self::db()->fetchAll( "
            SELECT
                id, provider, source, status,
                provider_ref, deposit_date,
                total_amount, batch_count, meta
            FROM {$table}
            ORDER BY deposit_date DESC, id DESC
        " ) ?: [];

        return array_map(
            static fn ( array $row ): object => (object) $row,
            $rows
        );
    }

    public static function backfillStripePayoutIds( int $limit = 200 ): void {
        if ( ! \metis_current_user_can( 'manage_options' ) ) {
            return;
        }

        $stripe = \function_exists( 'metis_stripe_client' ) ? \metis_stripe_client() : null;
        if ( ! $stripe ) {
            return;
        }

        $table = \Metis_Tables::get( 'transactions' );
        $db    = self::db();

        $rows = $db->fetchAll( "
            SELECT id, stripe_pay_int
            FROM {$table}
            WHERE platform = 'ST'
              AND stripe_pay_int IS NOT NULL
              AND stripe_pay_int <> ''
              AND stripe_payout_id IS NULL
            ORDER BY id ASC
            LIMIT %d
        ", [ $limit ] );

        if ( ! $rows ) {
            \Metis_Logger::info( 'Stripe backfill: no transactions require payout backfill' );
            return;
        }

        foreach ( $rows as $r ) {
            try {
                $pi        = $stripe->retrievePaymentIntent( $r['stripe_pay_int'] );
                $charge_id = $pi->latest_charge ?? ( $pi->charges->data[0]->id ?? null );

                if ( ! $charge_id ) {
                    \Metis_Logger::warn( 'Stripe backfill: no charge resolvable', [ 'pi' => $r['stripe_pay_int'] ] );
                    continue;
                }

                $charge = $stripe->retrieveCharge( (string) $charge_id );
                if ( empty( $charge->balance_transaction ) ) {
                    \Metis_Logger::warn( 'Stripe backfill: no balance transaction', [ 'charge' => $charge_id ] );
                    continue;
                }

                $bt = $stripe->retrieveBalanceTransaction( (string) $charge->balance_transaction );
                if ( empty( $bt->payout ) ) {
                    \Metis_Logger::warn( 'Stripe backfill: no payout on balance txn', [ 'bt' => $bt->id ] );
                    continue;
                }

                $db->update(
                    $table,
                    [ 'stripe_balance_txn' => $bt->id, 'stripe_payout_id' => $bt->payout ],
                    [ 'id' => (int) $r['id'] ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );

                \Metis_Logger::info( 'Stripe backfill: payout linked', [ 'txn' => $r['id'], 'payout' => $bt->payout ] );
            } catch ( \Exception $e ) {
                \Metis_Logger::error( 'Stripe backfill failed', [ 'pi' => $r['stripe_pay_int'], 'error' => $e->getMessage() ] );
            }
        }

        \Metis_Logger::info( 'Stripe payout backfill complete' );
    }

    public static function backfillStripePayoutsFromPayouts( int $limit = 50 ): void {
        if ( ! \metis_current_user_can( 'manage_options' ) ) {
            return;
        }

        $stripe = \function_exists( 'metis_stripe_client' ) ? \metis_stripe_client() : null;
        if ( ! $stripe ) {
            return;
        }

        $table   = \Metis_Tables::get( 'transactions' );
        $payouts = $stripe->listPayouts( [ 'limit' => $limit, 'status' => 'paid' ] );
        $db      = self::db();

        foreach ( $payouts->data as $payout ) {
            $balance_txns = $stripe->listBalanceTransactions( [ 'payout' => $payout->id, 'limit' => 100 ] );

            foreach ( $balance_txns->data as $bt ) {
                $updated = $db->update(
                    $table,
                    [ 'stripe_balance_txn' => $bt->id, 'stripe_payout_id' => $payout->id ],
                    [ 'stripe_balance_txn' => $bt->id ],
                    [ '%s', '%s' ],
                    [ '%s' ]
                );

                if ( $updated ) {
                    \Metis_Logger::info( 'Stripe backfill: txn linked to payout', [ 'bt' => $bt->id, 'payout' => $payout->id ] );
                }
            }
        }

        \Metis_Logger::info( 'Stripe payout-based backfill complete' );
    }

    public static function handleAdminBackfillTriggers(): void {
        if ( ! function_exists( 'metis_is_admin' ) || ! \metis_is_admin() ) {
            return;
        }

        if ( ! \metis_user_logged_in() || ! \metis_current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( metis_request_get()['metis_backfill_payouts'] ) ) {
            self::backfillStripePayoutIds();
            \metis_runtime_die( 'Metis: Stripe payout backfill complete. Check logs.' );
        }

        if ( isset( metis_request_get()['metis_backfill_from_payouts'] ) ) {
            self::backfillStripePayoutsFromPayouts();
            \metis_runtime_die( 'Metis: Payout-based backfill complete. Check logs.' );
        }

        if ( isset( metis_request_get()['metis_backfill_transaction_refs'] ) ) {
            $summary = self::backfillTransactionEntityReferences();
            \metis_runtime_die(
                'Metis: Transaction reference backfill complete. '
                . 'Campaigns updated: ' . (int) ( $summary['campaign_code'] ?? 0 ) . '; '
                . 'Donors updated: ' . (int) ( $summary['did'] ?? 0 ) . '; '
                . 'Batches updated: ' . (int) ( $summary['deposit_batch_id'] ?? 0 ) . '. '
                . 'Check logs for details.'
            );
        }
    }

    /**
     * Backfill transaction foreign-code references after entity UID normalization.
     *
     * @return array{campaign_code:int,did:int,deposit_batch_id:int}
     */
    public static function backfillTransactionEntityReferences(): array {
        $db = self::db();
        $tx_table = \Metis_Tables::get( 'transactions' );
        $campaigns_table = \Metis_Tables::get( 'campaigns' );
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $batches_table = \Metis_Tables::get( 'batches' );
        $registry_table = \Metis_Tables::get( 'entity_registry' );

        $summary = [
            'campaign_code' => 0,
            'did' => 0,
            'deposit_batch_id' => 0,
        ];

        // 1) Campaign references from direct campaign table matches (uid/cid/id).
        $q1 = "
            UPDATE {$tx_table} t
            INNER JOIN {$campaigns_table} c
                ON (
                    t.campaign_code = c.campaign_uid
                    OR t.campaign_code = c.cid
                    OR t.campaign_code = CAST(c.id AS CHAR)
                )
            SET t.campaign_code = COALESCE(NULLIF(c.campaign_uid, ''), c.cid)
            WHERE COALESCE(t.campaign_code, '') <> ''
              AND COALESCE(NULLIF(c.campaign_uid, ''), c.cid) <> ''
              AND t.campaign_code <> COALESCE(NULLIF(c.campaign_uid, ''), c.cid)
        ";
        $res = $db->execute( $q1 );
        $summary['campaign_code'] += is_numeric( $res ) ? (int) $res : 0;

        // 2) Campaign references from registry for legacy values no longer present in campaign columns.
        $q2 = "
            UPDATE {$tx_table} t
            INNER JOIN {$registry_table} r
                ON r.entity_uid = t.campaign_code
               AND r.entity_type = 'donation_campaign'
            INNER JOIN {$campaigns_table} c
                ON c.id = r.entity_id
            SET t.campaign_code = COALESCE(NULLIF(c.campaign_uid, ''), c.cid)
            WHERE COALESCE(t.campaign_code, '') <> ''
              AND COALESCE(NULLIF(c.campaign_uid, ''), c.cid) <> ''
              AND t.campaign_code <> COALESCE(NULLIF(c.campaign_uid, ''), c.cid)
        ";
        $res = $db->execute( $q2 );
        $summary['campaign_code'] += is_numeric( $res ) ? (int) $res : 0;

        // 3) Donor references from contact table matches (donor_uid/did/id/cid/contact_uid).
        $q3 = "
            UPDATE {$tx_table} t
            INNER JOIN {$contacts_table} c
                ON (
                    t.did = c.donor_uid
                    OR t.did = c.did
                    OR t.did = CAST(c.id AS CHAR)
                    OR t.did = c.cid
                    OR t.did = c.contact_uid
                )
            SET t.did = COALESCE(NULLIF(c.donor_uid, ''), c.did)
            WHERE COALESCE(t.did, '') <> ''
              AND COALESCE(NULLIF(c.donor_uid, ''), c.did) <> ''
              AND t.did <> COALESCE(NULLIF(c.donor_uid, ''), c.did)
        ";
        $res = $db->execute( $q3 );
        $summary['did'] += is_numeric( $res ) ? (int) $res : 0;

        // 4) Donor references from registry for legacy values no longer present in contact columns.
        $q4 = "
            UPDATE {$tx_table} t
            INNER JOIN {$registry_table} r
                ON r.entity_uid = t.did
               AND r.entity_type = 'donor'
            INNER JOIN {$contacts_table} c
                ON c.id = r.entity_id
            SET t.did = COALESCE(NULLIF(c.donor_uid, ''), c.did)
            WHERE COALESCE(t.did, '') <> ''
              AND COALESCE(NULLIF(c.donor_uid, ''), c.did) <> ''
              AND t.did <> COALESCE(NULLIF(c.donor_uid, ''), c.did)
        ";
        $res = $db->execute( $q4 );
        $summary['did'] += is_numeric( $res ) ? (int) $res : 0;

        // 5) Deposit batch references from batches table matches (batch_uid/batch_code/id).
        $q5 = "
            UPDATE {$tx_table} t
            INNER JOIN {$batches_table} b
                ON (
                    t.deposit_batch_id = b.batch_uid
                    OR t.deposit_batch_id = b.batch_code
                    OR t.deposit_batch_id = CAST(b.id AS CHAR)
                )
            SET t.deposit_batch_id = COALESCE(NULLIF(b.batch_uid, ''), b.batch_code)
            WHERE COALESCE(t.deposit_batch_id, '') <> ''
              AND COALESCE(NULLIF(b.batch_uid, ''), b.batch_code) <> ''
              AND t.deposit_batch_id <> COALESCE(NULLIF(b.batch_uid, ''), b.batch_code)
        ";
        $res = $db->execute( $q5 );
        $summary['deposit_batch_id'] += is_numeric( $res ) ? (int) $res : 0;

        // 6) Deposit batch references from registry for legacy values no longer present in batch columns.
        $q6 = "
            UPDATE {$tx_table} t
            INNER JOIN {$registry_table} r
                ON r.entity_uid = t.deposit_batch_id
               AND r.entity_type = 'deposit_batch'
            INNER JOIN {$batches_table} b
                ON b.id = r.entity_id
            SET t.deposit_batch_id = COALESCE(NULLIF(b.batch_uid, ''), b.batch_code)
            WHERE COALESCE(t.deposit_batch_id, '') <> ''
              AND COALESCE(NULLIF(b.batch_uid, ''), b.batch_code) <> ''
              AND t.deposit_batch_id <> COALESCE(NULLIF(b.batch_uid, ''), b.batch_code)
        ";
        $res = $db->execute( $q6 );
        $summary['deposit_batch_id'] += is_numeric( $res ) ? (int) $res : 0;

        \Metis_Logger::info( 'Donations transaction reference backfill complete', $summary );
        return $summary;
    }

    public static function parseGoals( ?string $raw ): array {
        if ( ! $raw ) {
            return [];
        }

        $goals = [];
        foreach ( explode( '|', $raw ) as $entry ) {
            $parts = explode( ':', $entry, 2 );
            if ( count( $parts ) === 2 && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) ) {
                $goals[ (int) $parts[0] ] = (float) $parts[1];
            }
        }

        return $goals;
    }

    public static function renderCampaignProgressShortcode( array $atts ): string {
        $atts = \metis_shortcode_defaults( [ 'cid' => '', 'year' => date( 'Y' ) ], $atts, 'metis_campaign_progress' );

        $cid  = \metis_text_clean( $atts['cid'] );
        $year = (int) $atts['year'];

        if ( ! $cid ) {
            return '';
        }

        $campaigns_table    = \Metis_Tables::get( 'campaigns' );
        $transactions_table = \Metis_Tables::get( 'transactions' );
        $db                 = self::db();

        $campaign = $db->fetchOne(
            "SELECT cname, goals FROM {$campaigns_table} WHERE cid = %s AND public = 1 LIMIT 1",
            [ $cid ]
        );

        if ( ! $campaign ) {
            return '';
        }

        $goals    = self::parseGoals( $campaign['goals'] ?? null );
        $goal_amt = $goals[ $year ] ?? null;

        $raised_raw = $db->scalar(
            "SELECT SUM(amount) FROM {$transactions_table} WHERE campaign_code = %s AND YEAR(tran_date) = %d",
            [ $cid, $year ]
        );
        $raised = (float) ( $raised_raw ?? 0 );

        $pct       = ( $goal_amt && $goal_amt > 0 ) ? min( 100, round( ( $raised / $goal_amt ) * 100, 1 ) ) : null;
        $goal_text = $goal_amt ? ' of $' . number_format( $goal_amt, 0 ) . ' goal' : '';

        ob_start();
        ?>
        <div class="metis-shortcode-progress">
            <div class="metis-sc-header">
                <span class="metis-sc-campaign"><?php echo \metis_escape_html( (string) ( $campaign['cname'] ?? '' ) ); ?></span>
                <span class="metis-sc-year"><?php echo \metis_escape_html( (string) $year ); ?></span>
            </div>
            <div class="metis-sc-raised">$<?php echo number_format( $raised, 2 ); ?> raised<?php echo \metis_escape_html( $goal_text ); ?></div>
            <?php if ( $pct !== null ) : ?>
            <div class="metis-sc-progress-wrap">
                <div class="metis-sc-progress-fill" style="width: <?php echo \metis_escape_attr( (string) $pct ); ?>%;"></div>
            </div>
            <div class="metis-sc-pct"><?php echo \metis_escape_html( (string) $pct ); ?>%</div>
            <?php endif; ?>
        </div>
        <style>
        .metis-shortcode-progress { font-family: inherit; max-width: 480px; }
        .metis-sc-header { display: flex; justify-content: space-between; font-size: 15px; font-weight: 600; margin-bottom: 4px; }
        .metis-sc-raised { font-size: 13px; color: #555; margin-bottom: 8px; }
        .metis-sc-progress-wrap { background: #e5e7eb; border-radius: 999px; height: 12px; overflow: hidden; }
        .metis-sc-progress-fill { background: #485bc7; height: 100%; border-radius: 999px; transition: width 0.4s ease; }
        .metis-sc-pct { font-size: 12px; color: #6b7280; margin-top: 4px; }
        </style>
        <?php

        return (string) ob_get_clean();
    }

    public static function enqueueReportsAssets(): void {
        if ( \metis_get_query_var( 'metis_domain' ) !== 'donations' ) {
            return;
        }

        if ( \metis_get_query_var( 'metis_view' ) !== 'reports' ) {
            return;
        }

        \metis_runtime_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );

        \metis_runtime_enqueue_script(
            'jspdf',
            'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
            [],
            '2.5.1',
            true
        );

        \metis_runtime_enqueue_script(
            'metis-donations-reports',
            \metis_module_asset_url( 'donations', 'donations.reports.js' ),
            [ 'chartjs', 'jspdf' ],
            METIS_VERSION,
            true
        );

        \metis_runtime_localize_script( 'metis-donations-reports', 'MWDonationsReports', [
            'ajax_url'      => \metis_ajax_endpoint_url(),
            'nonce'         => \metis_runtime_create_nonce( 'metis_donations_reports' ),
            'action_nonces' => \metis_ajax_action_nonces(),
            'base_url'      => \metis_portal_url( 'donations', 'reports' ),
        ] );

        \Metis_Logger::info( 'Donations reports assets enqueued' );
    }
}
