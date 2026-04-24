<?php
declare(strict_types=1);

namespace Metis\Modules\Donations;

use Metis\Services\DatabaseService;

final class DonationsModule {
    public static function boot(): void {
        \Metis_Logger::info( 'Donations bootstrap loaded' );

        \metis_on( 'init', [ self::class, 'handleAdminBackfillTriggers' ] );
        \metis_on( 'metis_assets_enqueue', [ self::class, 'enqueueReportsAssets' ] );

        if ( function_exists( 'metis_shortcode_register' ) ) {
            \metis_shortcode_register( 'mw_campaign_progress', [ self::class, 'renderCampaignProgressShortcode' ] );
        }
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
            return "<span class='mw-badge gray'>Unknown</span>";
        }

        $map = [
            'cc'    => 'Credit Card',
            'ach'   => 'Bank Transfer',
            'ck'    => 'Check',
            'cash'  => 'Cash',
            'other' => 'Other',
        ];

        $label = $map[ strtolower( $method ) ] ?? ucfirst( $method );
        return "<span class='mw-badge muted'>" . \metis_escape_html( $label ) . '</span>';
    }

    public static function depositBadge( ?string $date ): string {
        if ( ! $date ) {
            return "<span class='mw-badge gray'>Not Deposited</span>";
        }

        return "<span class='mw-badge green'>Deposited</span>";
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
            return "<span class='mw-badge gray'>" . \metis_escape_html( $status ) . '</span>';
        }

        [ $label, $color ] = $map[ $status ];
        return "<span class='mw-badge {$color}'>" . \metis_escape_html( $label ) . '</span>';
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
            return '<span class="mw-badge mw-badge-stripe" title="' . \metis_escape_attr( $title ) . '">Stripe</span>';
        }

        if ( isset( $deposit->provider ) && $deposit->provider === 'givebutter' ) {
            return '<span class="mw-badge mw-badge-gb" title="Legacy GiveButter batch">GiveButter</span>';
        }

        return '<span class="mw-badge mw-badge-manual" title="Manual or offline deposit">Manual</span>';
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

        if ( ! class_exists( '\Stripe\Stripe' ) ) {
            return;
        }

        $secret = \Metis\Core\Services\CredentialService::getBySetting( 'stripe_secret' );
        if ( ! $secret ) {
            \Metis_Logger::error( 'Stripe backfill: secret key not set' );
            return;
        }

        \Stripe\Stripe::setApiKey( $secret );

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
                $pi        = \Stripe\PaymentIntent::retrieve( $r['stripe_pay_int'] );
                $charge_id = $pi->latest_charge ?? ( $pi->charges->data[0]->id ?? null );

                if ( ! $charge_id ) {
                    \Metis_Logger::warn( 'Stripe backfill: no charge resolvable', [ 'pi' => $r['stripe_pay_int'] ] );
                    continue;
                }

                $charge = \Stripe\Charge::retrieve( $charge_id );
                if ( empty( $charge->balance_transaction ) ) {
                    \Metis_Logger::warn( 'Stripe backfill: no balance transaction', [ 'charge' => $charge_id ] );
                    continue;
                }

                $bt = \Stripe\BalanceTransaction::retrieve( $charge->balance_transaction );
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

        if ( ! class_exists( '\Stripe\Stripe' ) ) {
            return;
        }

        $secret = \Metis\Core\Services\CredentialService::getBySetting( 'stripe_secret' );
        if ( ! $secret ) {
            \Metis_Logger::error( 'Stripe backfill: secret key not set' );
            return;
        }

        \Stripe\Stripe::setApiKey( $secret );

        $table   = \Metis_Tables::get( 'transactions' );
        $payouts = \Stripe\Payout::all( [ 'limit' => $limit, 'status' => 'paid' ] );
        $db      = self::db();

        foreach ( $payouts->data as $payout ) {
            $balance_txns = \Stripe\BalanceTransaction::all( [ 'payout' => $payout->id, 'limit' => 100 ] );

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

        if ( isset( $_GET['metis_backfill_payouts'] ) ) {
            self::backfillStripePayoutIds();
            \metis_runtime_die( 'Metis: Stripe payout backfill complete. Check logs.' );
        }

        if ( isset( $_GET['metis_backfill_from_payouts'] ) ) {
            self::backfillStripePayoutsFromPayouts();
            \metis_runtime_die( 'Metis: Payout-based backfill complete. Check logs.' );
        }

        if ( isset( $_GET['metis_backfill_transaction_refs'] ) ) {
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
        $atts = \metis_shortcode_defaults( [ 'cid' => '', 'year' => date( 'Y' ) ], $atts, 'mw_campaign_progress' );

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
        <div class="mw-shortcode-progress">
            <div class="mw-sc-header">
                <span class="mw-sc-campaign"><?php echo \metis_escape_html( (string) ( $campaign['cname'] ?? '' ) ); ?></span>
                <span class="mw-sc-year"><?php echo \metis_escape_html( (string) $year ); ?></span>
            </div>
            <div class="mw-sc-raised">$<?php echo number_format( $raised, 2 ); ?> raised<?php echo \metis_escape_html( $goal_text ); ?></div>
            <?php if ( $pct !== null ) : ?>
            <div class="mw-sc-progress-wrap">
                <div class="mw-sc-progress-fill" style="width: <?php echo \metis_escape_attr( (string) $pct ); ?>%;"></div>
            </div>
            <div class="mw-sc-pct"><?php echo \metis_escape_html( (string) $pct ); ?>%</div>
            <?php endif; ?>
        </div>
        <style>
        .mw-shortcode-progress { font-family: inherit; max-width: 480px; }
        .mw-sc-header { display: flex; justify-content: space-between; font-size: 15px; font-weight: 600; margin-bottom: 4px; }
        .mw-sc-raised { font-size: 13px; color: #555; margin-bottom: 8px; }
        .mw-sc-progress-wrap { background: #e5e7eb; border-radius: 999px; height: 12px; overflow: hidden; }
        .mw-sc-progress-fill { background: #485bc7; height: 100%; border-radius: 999px; transition: width 0.4s ease; }
        .mw-sc-pct { font-size: 12px; color: #6b7280; margin-top: 4px; }
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
