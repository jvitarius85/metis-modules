<?php
declare(strict_types=1);

namespace Metis\Modules\Donations;

use Metis\Core\Services\EmailService;
use Metis\Modules\Finance\FinanceV2Service;

final class RecurringDonationsService {
    private const WINDOW_START_HOUR = 1;
    private const WINDOW_END_HOUR = 5;
    private const MAX_RETRIES = 3;

    public static function ensureSchema(): void {
        $db = \metis_db();
        $charset = $db->get_charset_collate();
        $plans = \Metis_Tables::get( 'recurring_donations' );
        $attempts = \Metis_Tables::get( 'recurring_donation_attempts' );
        $portalTokens = \Metis_Tables::get( 'donor_portal_tokens' );

        \metis_db_delta( "CREATE TABLE {$plans} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recurring_code VARCHAR(32) NOT NULL,
            did VARCHAR(32) DEFAULT NULL,
            donor_email VARCHAR(191) NOT NULL,
            donor_name VARCHAR(191) DEFAULT NULL,
            campaign_code VARCHAR(64) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'usd',
            frequency VARCHAR(24) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            stripe_customer_id VARCHAR(191) NOT NULL,
            stripe_payment_method_id VARCHAR(191) NOT NULL,
            stripe_subscription_id VARCHAR(191) DEFAULT NULL,
            origin_tid VARCHAR(32) DEFAULT NULL,
            next_run_at DATETIME NOT NULL,
            last_run_at DATETIME DEFAULT NULL,
            retry_count INT UNSIGNED NOT NULL DEFAULT 0,
            self_manage_token VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY recurring_code (recurring_code),
            UNIQUE KEY self_manage_token (self_manage_token),
            KEY due_status (status, next_run_at),
            KEY donor_email (donor_email(191)),
            KEY did (did),
            KEY stripe_subscription_id (stripe_subscription_id(191))
        ) {$charset};" );

        $planColumns = $db->column( "SHOW COLUMNS FROM {$plans}" );
        if ( ! in_array( 'stripe_subscription_id', $planColumns, true ) ) {
            $db->execute( "ALTER TABLE {$plans} ADD COLUMN stripe_subscription_id VARCHAR(191) DEFAULT NULL AFTER stripe_payment_method_id" );
            $db->execute( "ALTER TABLE {$plans} ADD KEY stripe_subscription_id (stripe_subscription_id(191))" );
        }

        \metis_db_delta( "CREATE TABLE {$attempts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recurring_id BIGINT UNSIGNED NOT NULL,
            attempt_code VARCHAR(32) NOT NULL,
            status VARCHAR(24) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            currency VARCHAR(8) NOT NULL DEFAULT 'usd',
            stripe_payment_intent_id VARCHAR(191) DEFAULT NULL,
            stripe_charge_id VARCHAR(191) DEFAULT NULL,
            transaction_tid VARCHAR(32) DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY attempt_code (attempt_code),
            KEY recurring_processed (recurring_id, processed_at),
            KEY transaction_tid (transaction_tid)
        ) {$charset};" );

        \metis_db_delta( "CREATE TABLE {$portalTokens} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token_hash VARCHAR(64) NOT NULL,
            donor_email VARCHAR(191) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            KEY donor_email (donor_email(191)),
            KEY expires_at (expires_at)
        ) {$charset};" );
    }

    public static function normalizeFrequency( mixed $value ): string {
        $frequency = \metis_key_clean( (string) $value );
        $aliases = [
            'one-time' => 'one_time',
            'once' => 'one_time',
            'semi_annual' => 'semiannual',
            'semi-annually' => 'semiannual',
            'semi_annually' => 'semiannual',
            'annual' => 'annual',
            'yearly' => 'annual',
        ];
        $frequency = $aliases[ $frequency ] ?? $frequency;
        return \in_array( $frequency, [ 'one_time', 'monthly', 'quarterly', 'semiannual', 'annual' ], true ) ? $frequency : 'one_time';
    }

    public static function isRecurringFrequency( mixed $value ): bool {
        return \in_array( self::normalizeFrequency( $value ), [ 'monthly', 'quarterly', 'semiannual', 'annual' ], true );
    }

    public static function listPlans( int $limit = 200 ): array {
        self::ensureSchema();
        $plans = \Metis_Tables::get( 'recurring_donations' );
        $rows = \metis_db()->fetchAll(
            "SELECT * FROM {$plans} ORDER BY next_run_at ASC, id DESC LIMIT %d",
            [ max( 1, min( 500, $limit ) ) ]
        );
        if ( $rows === [] ) {
            return [];
        }

        $campaignNames = self::campaignNameMap();
        foreach ( $rows as &$row ) {
            $campaign = (string) ( $row['campaign_code'] ?? '' );
            $row['campaign_name'] = $campaignNames[ $campaign ] ?? '';
        }
        unset( $row );
        return $rows;
    }

    private static function campaignNameMap(): array {
        $campaigns = \Metis_Tables::get( 'campaigns' );
        if ( $campaigns === '' ) {
            return [];
        }
        $rows = \metis_db()->fetchAll( "SELECT * FROM {$campaigns} LIMIT 1000" );
        $map = [];
        foreach ( $rows as $row ) {
            $name = trim( (string) ( $row['cname'] ?? $row['name'] ?? '' ) );
            if ( $name === '' ) {
                continue;
            }
            foreach ( [ 'cid', 'campaign_uid', 'id' ] as $key ) {
                $code = trim( (string) ( $row[ $key ] ?? '' ) );
                if ( $code !== '' ) {
                    $map[ $code ] = $name;
                }
            }
        }
        return $map;
    }

    private static function stripeClient(): ?\Metis\Core\Integrations\StripeApiClient {
        return \function_exists( 'metis_stripe_client' ) ? \metis_stripe_client() : null;
    }

    private static function flushMutationCaches(): void {
        if ( \function_exists( 'metis_reports_clear_cache' ) ) {
            \metis_reports_clear_cache();
        }
        if ( \function_exists( 'metis_portal_dashboard_forget_all' ) ) {
            \metis_portal_dashboard_forget_all();
        }
    }

    public static function updateStatus( int $id, string $status ): bool {
        self::ensureSchema();
        $status = \metis_key_clean( $status );
        if ( ! \in_array( $status, [ 'active', 'paused', 'cancelled' ], true ) ) {
            return false;
        }
        return (bool) \metis_db()->update(
            \Metis_Tables::get( 'recurring_donations' ),
            [ 'status' => $status, 'updated_at' => self::now() ],
            [ 'id' => $id ]
        );
    }

    public static function getPlanByToken( string $token ): ?array {
        self::ensureSchema();
        $token = trim( $token );
        if ( $token === '' ) {
            return null;
        }
        $row = \metis_db()->fetchOne(
            'SELECT * FROM ' . \Metis_Tables::get( 'recurring_donations' ) . ' WHERE self_manage_token = %s LIMIT 1',
            [ $token ]
        );
        return is_array( $row ) ? $row : null;
    }

    public static function migrateStripeSubscriptions( bool $cancelStripeSubscriptions = false, int $limit = 100 ): array {
        self::ensureSchema();
        $stripe = self::stripeClient();
        if ( ! $stripe ) {
            return [ 'ok' => false, 'message' => 'Stripe is not configured.' ];
        }

        $plans = \Metis_Tables::get( 'recurring_donations' );
        $created = 0;
        $skipped = 0;
        $cancelled = 0;
        $errors = [];
        $rows = [];

        try {
            $subscriptions = $stripe->listSubscriptions( [
                'status' => 'active',
                'limit' => max( 1, min( 100, $limit ) ),
                'expand' => [ 'data.customer', 'data.default_payment_method', 'data.items.data.price', 'data.latest_invoice' ],
            ] );
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'message' => 'Stripe subscriptions could not be listed: ' . $e->getMessage() ];
        }

        foreach ( (array) ( $subscriptions->data ?? [] ) as $subscription ) {
            $subscriptionId = (string) ( $subscription->id ?? '' );
            if ( $subscriptionId === '' ) {
                $skipped++;
                continue;
            }

            $existing = (int) \metis_db()->scalar( "SELECT id FROM {$plans} WHERE stripe_subscription_id = %s LIMIT 1", [ $subscriptionId ] );
            if ( $existing > 0 ) {
                $skipped++;
                $rows[] = [ 'subscription' => $subscriptionId, 'status' => 'skipped', 'message' => 'Already imported.' ];
                continue;
            }

            $customer = self::resolveStripeCustomer( $subscription );
            $customerId = is_object( $customer ) ? (string) ( $customer->id ?? '' ) : self::subscriptionCustomerId( $subscription );
            $paymentMethodId = self::subscriptionPaymentMethodId( $subscription, $customer );
            $item = $subscription->items->data[0] ?? null;
            $price = is_object( $item ) && is_object( $item->price ?? null ) ? $item->price : null;
            $unitAmount = is_object( $price ) ? (int) ( $price->unit_amount ?? 0 ) : 0;
            $currency = is_object( $price ) ? strtolower( (string) ( $price->currency ?? 'usd' ) ) : 'usd';
            $frequency = self::frequencyFromStripePrice( $price );
            $campaignCode = self::campaignFromSubscription( $subscription, $price );
            if ( $campaignCode === '' ) {
                $campaignCode = self::campaignFromSubscription( $subscription, $price, self::productFromStripePrice( $price ) );
            }
            $email = self::subscriptionEmail( $subscription, $customer );
            $name = is_object( $customer ) ? trim( (string) ( $customer->name ?? '' ) ) : '';

            $missing = [];
            if ( $customerId === '' ) {
                $missing[] = 'customer';
            }
            if ( $paymentMethodId === '' ) {
                $missing[] = 'payment method';
            }
            if ( $unitAmount < 1 ) {
                $missing[] = 'amount';
            }
            if ( $campaignCode === '' ) {
                $missing[] = 'campaign';
            }
            if ( $email === '' ) {
                $missing[] = 'email';
            }
            if ( $missing !== [] ) {
                $skipped++;
                $rows[] = [
                    'subscription' => $subscriptionId,
                    'status' => 'needs_review',
                    'message' => 'Missing ' . implode( ', ', $missing ) . '. Customer ID: ' . ( $customerId !== '' ? $customerId : 'unavailable' ) . '.',
                    'editable' => true,
                    'missing' => $missing,
                    'customer_id' => $customerId,
                    'payment_method_id' => $paymentMethodId,
                    'donor_email' => $email,
                    'donor_name' => $name,
                    'campaign_code' => $campaignCode,
                    'amount' => round( $unitAmount / 100, 2 ),
                    'currency' => $currency,
                    'frequency' => $frequency,
                ];
                continue;
            }

            $contact = self::upsertContact( [ 'email' => $email, 'first_name' => $name, 'last_name' => '' ] );
            $now = self::now();
            $code = \metis_generate_code( 'RD', $plans, 'recurring_code' );
            $token = hash( 'sha256', $code . '|' . $email . '|' . \random_int( 100000, 999999 ) );
            $nextRun = ! empty( $subscription->current_period_end )
                ? gmdate( 'Y-m-d H:i:s', (int) $subscription->current_period_end )
                : self::nextRunAt( $frequency, $now );

            $ok = \metis_db()->insert( $plans, [
                'recurring_code' => $code,
                'did' => (string) ( $contact['did'] ?? '' ) ?: null,
                'donor_email' => $email,
                'donor_name' => $name !== '' ? $name : null,
                'campaign_code' => $campaignCode,
                'amount' => round( $unitAmount / 100, 2 ),
                'currency' => $currency,
                'frequency' => $frequency,
                'status' => 'active',
                'stripe_customer_id' => $customerId,
                'stripe_payment_method_id' => $paymentMethodId,
                'stripe_subscription_id' => $subscriptionId,
                'next_run_at' => $nextRun,
                'self_manage_token' => $token,
                'created_at' => $now,
                'updated_at' => $now,
            ] );

            if ( ! $ok ) {
                $errors[] = 'Could not save ' . $subscriptionId;
                continue;
            }

            $created++;
            self::flushMutationCaches();
            if ( $cancelStripeSubscriptions ) {
                try {
                    $stripe->cancelSubscription( $subscriptionId );
                    $cancelled++;
                } catch ( \Throwable $e ) {
                    $errors[] = 'Imported but could not cancel ' . $subscriptionId . ': ' . $e->getMessage();
                }
            }
            $message = $cancelStripeSubscriptions ? 'Imported and Stripe subscription cancelled. Payment method remains stored by Stripe.' : 'Imported; Stripe subscription left active.';
            $rows[] = [ 'subscription' => $subscriptionId, 'status' => 'imported', 'message' => $message ];
        }

        return [
            'ok' => true,
            'created' => $created,
            'skipped' => $skipped,
            'cancelled' => $cancelled,
            'errors' => $errors,
            'rows' => $rows,
            'message' => sprintf( '%d imported, %d skipped%s.', $created, $skipped, $cancelStripeSubscriptions ? ', ' . $cancelled . ' Stripe subscriptions cancelled' : '' ),
        ];
    }

    public static function importReviewedStripeSubscription( string $subscriptionId, array $overrides, bool $cancelStripeSubscription = false ): array {
        self::ensureSchema();
        $stripe = self::stripeClient();
        if ( ! $stripe ) {
            return [ 'ok' => false, 'message' => 'Stripe is not configured.' ];
        }

        $subscriptionId = trim( \metis_text_clean( $subscriptionId ) );
        if ( $subscriptionId === '' ) {
            return [ 'ok' => false, 'message' => 'Stripe subscription is required.' ];
        }

        $plans = \Metis_Tables::get( 'recurring_donations' );
        $existing = (int) \metis_db()->scalar( "SELECT id FROM {$plans} WHERE stripe_subscription_id = %s LIMIT 1", [ $subscriptionId ] );
        if ( $existing > 0 ) {
            return [ 'ok' => false, 'message' => 'This Stripe subscription has already been imported.' ];
        }

        try {
            $subscription = $stripe->retrieveSubscription(
                $subscriptionId,
                [ 'expand' => [ 'customer', 'default_payment_method', 'items.data.price', 'latest_invoice' ] ]
            );
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'message' => 'Stripe subscription could not be retrieved: ' . $e->getMessage() ];
        }

        $customer = self::resolveStripeCustomer( $subscription );
        $customerId = is_object( $customer ) ? (string) ( $customer->id ?? '' ) : self::subscriptionCustomerId( $subscription );
        $paymentMethodId = self::subscriptionPaymentMethodId( $subscription, $customer );
        $item = $subscription->items->data[0] ?? null;
        $price = is_object( $item ) && is_object( $item->price ?? null ) ? $item->price : null;
        $unitAmount = is_object( $price ) ? (int) ( $price->unit_amount ?? 0 ) : 0;
        $currency = is_object( $price ) ? strtolower( (string) ( $price->currency ?? 'usd' ) ) : 'usd';
        $frequency = self::frequencyFromStripePrice( $price );
        $campaignCode = self::normalizeCampaignCode( (string) ( $overrides['campaign_code'] ?? '' ) );
        if ( $campaignCode === '' ) {
            $campaignCode = self::campaignFromSubscription( $subscription, $price );
        }
        if ( $campaignCode === '' ) {
            $campaignCode = self::campaignFromSubscription( $subscription, $price, self::productFromStripePrice( $price ) );
        }
        $email = strtolower( trim( \metis_email_clean( (string) ( $overrides['donor_email'] ?? '' ) ) ) );
        if ( $email === '' ) {
            $email = self::subscriptionEmail( $subscription, $customer );
        }
        $name = trim( \metis_text_clean( (string) ( $overrides['donor_name'] ?? '' ) ) );
        if ( $name === '' && is_object( $customer ) ) {
            $name = trim( (string) ( $customer->name ?? '' ) );
        }

        $missing = [];
        if ( $customerId === '' ) $missing[] = 'customer';
        if ( $paymentMethodId === '' ) $missing[] = 'payment method';
        if ( $unitAmount < 1 ) $missing[] = 'amount';
        if ( $campaignCode === '' ) $missing[] = 'campaign';
        if ( $email === '' || ! \metis_email_is_valid( $email ) ) $missing[] = 'valid donor email';
        if ( $missing !== [] ) {
            return [ 'ok' => false, 'message' => 'Missing ' . implode( ', ', $missing ) . '.' ];
        }

        $contact = self::upsertContact( [ 'email' => $email, 'first_name' => $name, 'last_name' => '' ] );
        $now = self::now();
        $code = \metis_generate_code( 'RD', $plans, 'recurring_code' );
        $token = hash( 'sha256', $code . '|' . $email . '|' . \random_int( 100000, 999999 ) );
        $nextRun = ! empty( $subscription->current_period_end )
            ? gmdate( 'Y-m-d H:i:s', (int) $subscription->current_period_end )
            : self::nextRunAt( $frequency, $now );

        $ok = \metis_db()->insert( $plans, [
            'recurring_code' => $code,
            'did' => (string) ( $contact['did'] ?? '' ) ?: null,
            'donor_email' => $email,
            'donor_name' => $name !== '' ? $name : null,
            'campaign_code' => $campaignCode,
            'amount' => round( $unitAmount / 100, 2 ),
            'currency' => $currency,
            'frequency' => $frequency,
            'status' => 'active',
            'stripe_customer_id' => $customerId,
            'stripe_payment_method_id' => $paymentMethodId,
            'stripe_subscription_id' => $subscriptionId,
            'next_run_at' => $nextRun,
            'self_manage_token' => $token,
            'created_at' => $now,
            'updated_at' => $now,
        ] );
        if ( ! $ok ) {
            return [ 'ok' => false, 'message' => 'Could not save recurring donation.' ];
        }

        self::flushMutationCaches();

        $cancelled = false;
        if ( $cancelStripeSubscription ) {
            try {
                $stripe->cancelSubscription( $subscriptionId );
                $cancelled = true;
            } catch ( \Throwable $e ) {
                return [ 'ok' => true, 'message' => 'Imported, but Stripe subscription could not be cancelled: ' . $e->getMessage(), 'cancelled' => false ];
            }
        }

        return [ 'ok' => true, 'message' => $cancelled ? 'Imported and Stripe subscription cancelled.' : 'Imported; Stripe subscription left active.', 'cancelled' => $cancelled ];
    }

    public static function donorHistoryForPlan( array $plan, int $year = 0 ): array {
        $transactions = \Metis_Tables::get( 'transactions' );
        $params = [];
        $where = [];
        $did = trim( (string) ( $plan['did'] ?? '' ) );
        if ( $did !== '' ) {
            $where[] = 'did = %s';
            $params[] = $did;
        }
        if ( $year > 0 ) {
            $where[] = 'tran_date >= %s AND tran_date < %s';
            $params[] = sprintf( '%04d-01-01 00:00:00', $year );
            $params[] = sprintf( '%04d-01-01 00:00:00', $year + 1 );
        }
        if ( $where === [] ) {
            return [];
        }
        return \metis_db()->fetchAll(
            "SELECT tid, amount, tran_date, campaign_code, payment_method, status
             FROM {$transactions}
             WHERE " . implode( ' AND ', $where ) . "
             ORDER BY tran_date DESC, id DESC
             LIMIT 500",
            $params
        );
    }

    public static function createFromSuccessfulPayment( array $form, array $normalized, array $totals, object $intent, ?string $originTid = null ): array {
        self::ensureSchema();
        $frequency = self::normalizeFrequency( $normalized['_donation_frequency'] ?? 'one_time' );
        if ( ! self::isRecurringFrequency( $frequency ) ) {
            return [ 'ok' => true, 'created' => false ];
        }

        $customerId = trim( (string) ( $intent->customer ?? '' ) );
        $paymentMethodId = trim( (string) ( $intent->payment_method ?? '' ) );
        if ( $customerId === '' || $paymentMethodId === '' ) {
            return [ 'ok' => false, 'created' => false, 'error' => 'Stripe did not return a reusable customer/payment method for this recurring donation.' ];
        }

        $paymentField = self::paymentField( (array) ( $form['schema'] ?? [] ) );
        $payment = is_array( $paymentField ) ? (array) ( $paymentField['payment'] ?? [] ) : [];
        $campaignCode = self::normalizeCampaignCode( (string) ( $payment['campaign_code'] ?? '' ) );
        if ( $campaignCode === '' ) {
            return [ 'ok' => false, 'created' => false, 'error' => 'Recurring donations require a campaign.' ];
        }

        $contact = self::upsertContact( $normalized );
        $email = strtolower( trim( \metis_email_clean( (string) ( $normalized['email'] ?? '' ) ) ) );
        if ( $email === '' || ! \metis_email_is_valid( $email ) ) {
            return [ 'ok' => false, 'created' => false, 'error' => 'Recurring donations require a valid donor email.' ];
        }

        $now = self::now();
        $plans = \Metis_Tables::get( 'recurring_donations' );
        $nextRunAt = self::nextRunAt( $frequency, $now );
        $code = \metis_generate_code( 'RD', $plans, 'recurring_code' );
        $token = hash( 'sha256', $code . '|' . $email . '|' . \random_int( 100000, 999999 ) );
        $name = trim( (string) ( ( $normalized['first_name'] ?? '' ) . ' ' . ( $normalized['last_name'] ?? '' ) ) );

        $ok = \metis_db()->insert( $plans, [
            'recurring_code' => $code,
            'did' => (string) ( $contact['did'] ?? '' ) ?: null,
            'donor_email' => $email,
            'donor_name' => $name !== '' ? $name : null,
            'campaign_code' => $campaignCode,
            'amount' => round( (float) ( $totals['grand_total'] ?? 0 ), 2 ),
            'currency' => strtolower( (string) ( $totals['currency'] ?? 'usd' ) ),
            'frequency' => $frequency,
            'status' => 'active',
            'stripe_customer_id' => $customerId,
            'stripe_payment_method_id' => $paymentMethodId,
            'origin_tid' => $originTid ?: null,
            'next_run_at' => $nextRunAt,
            'self_manage_token' => $token,
            'created_at' => $now,
            'updated_at' => $now,
        ] );

        if ( ! $ok ) {
            return [ 'ok' => false, 'created' => false, 'error' => 'Recurring donation could not be saved.' ];
        }

        self::sendSetupEmail( $email, $name, $code, $frequency, (float) ( $totals['grand_total'] ?? 0 ), $token );
        return [ 'ok' => true, 'created' => true, 'recurring_code' => $code ];
    }

    public static function processDue( bool $force = false, int $limit = 25 ): array {
        self::ensureSchema();
        if ( ! $force && ! self::insideProcessingWindow() ) {
            return [ 'status' => 'skipped', 'message' => 'Recurring donations only process between 1:00 AM and 5:00 AM America/Chicago.' ];
        }
        if ( ! self::stripeClient() ) {
            return [ 'status' => 'failed', 'message' => 'Stripe is not configured.' ];
        }

        $plansTable = \Metis_Tables::get( 'recurring_donations' );
        $now = self::now();
        $plans = \metis_db()->fetchAll(
            "SELECT * FROM {$plansTable}
             WHERE status = 'active' AND next_run_at <= %s
             ORDER BY next_run_at ASC
             LIMIT %d",
            [ $now, max( 1, min( 100, $limit ) ) ]
        );

        $processed = 0;
        $failed = 0;
        foreach ( $plans as $plan ) {
            $result = self::processPlan( $plan );
            if ( ( $result['status'] ?? '' ) === 'paid' ) {
                $processed++;
            } else {
                $failed++;
            }
        }

        return [
            'status' => $failed > 0 ? 'partial' : 'ok',
            'message' => sprintf( 'Recurring donations processed: %d paid, %d failed.', $processed, $failed ),
            'processed' => $processed,
            'failed' => $failed,
        ];
    }

    private static function processPlan( array $plan ): array {
        $db = \metis_db();
        $plansTable = \Metis_Tables::get( 'recurring_donations' );
        $attemptsTable = \Metis_Tables::get( 'recurring_donation_attempts' );
        $now = self::now();
        $attemptCode = \metis_generate_code( 'RDA', $attemptsTable, 'attempt_code' );
        $amount = round( (float) ( $plan['amount'] ?? 0 ), 2 );
        $currency = strtolower( (string) ( $plan['currency'] ?? 'usd' ) );
        $stripe = self::stripeClient();
        if ( ! $stripe ) {
            return [ 'status' => 'failed' ];
        }

        try {
            $intent = $stripe->createPaymentIntent(
                [
                    'amount' => (int) round( $amount * 100 ),
                    'currency' => $currency,
                    'customer' => (string) $plan['stripe_customer_id'],
                    'payment_method' => (string) $plan['stripe_payment_method_id'],
                    'off_session' => true,
                    'confirm' => true,
                    'metadata' => [
                        'recurring_code' => (string) $plan['recurring_code'],
                        'campaign_code' => (string) $plan['campaign_code'],
                    ],
                    'expand' => [ 'latest_charge.balance_transaction' ],
                ],
                [ 'idempotency_key' => 'metis-recurring-' . (string) $plan['recurring_code'] . '-' . substr( (string) $plan['next_run_at'], 0, 10 ) ]
            );
        } catch ( \Throwable $e ) {
            $retryCount = (int) ( $plan['retry_count'] ?? 0 ) + 1;
            $status = $retryCount >= self::MAX_RETRIES ? 'paused' : 'active';
            $db->insert( $attemptsTable, [
                'recurring_id' => (int) $plan['id'],
                'attempt_code' => $attemptCode,
                'status' => 'failed',
                'amount' => $amount,
                'currency' => $currency,
                'error_message' => $e->getMessage(),
                'processed_at' => $now,
            ] );
            $db->update( $plansTable, [
                'status' => $status,
                'retry_count' => $retryCount,
                'next_run_at' => $status === 'active' ? gmdate( 'Y-m-d H:i:s', strtotime( '+3 days' ) ) : (string) $plan['next_run_at'],
                'updated_at' => $now,
            ], [ 'id' => (int) $plan['id'] ] );
            self::sendFailureEmail( $plan, $e->getMessage(), $status === 'paused' );
            return [ 'status' => 'failed' ];
        }

        $charge = self::resolveChargeWithBalanceTransaction( $intent->latest_charge ?? null );
        $tid = self::recordTransaction( $plan, $intent, $charge );
        $db->insert( $attemptsTable, [
            'recurring_id' => (int) $plan['id'],
            'attempt_code' => $attemptCode,
            'status' => 'paid',
            'amount' => $amount,
            'currency' => $currency,
            'stripe_payment_intent_id' => (string) ( $intent->id ?? '' ),
            'stripe_charge_id' => is_object( $charge ) ? (string) ( $charge->id ?? '' ) : null,
            'transaction_tid' => $tid,
            'processed_at' => $now,
        ] );
        $db->update( $plansTable, [
            'last_run_at' => $now,
            'next_run_at' => self::nextRunAt( (string) $plan['frequency'], $now ),
            'retry_count' => 0,
            'updated_at' => $now,
        ], [ 'id' => (int) $plan['id'] ] );
        self::sendReceiptEmail( $plan, $tid, $amount, $currency );
        return [ 'status' => 'paid', 'tid' => $tid ];
    }

    private static function recordTransaction( array $plan, object $intent, mixed $charge ): string {
        $transactions = \Metis_Tables::get( 'transactions' );
        if ( class_exists( DonationsModule::class ) ) {
            DonationsModule::ensureTransactionPaymentDetailSchema();
        }
        $amount = round( (float) $plan['amount'], 2 );
        $fee = self::estimatedStripeProcessingFee( $amount );
        $payout = round( $amount - $fee, 2 );
        $balance = is_object( $charge ) ? self::resolveBalanceTransaction( $charge->balance_transaction ?? null ) : null;
        if ( is_object( $balance ) ) {
            $fee = isset( $balance->fee ) ? round( ( (float) $balance->fee ) / 100, 2 ) : 0.0;
            $payout = isset( $balance->net ) ? round( ( (float) $balance->net ) / 100, 2 ) : round( $amount - $fee, 2 );
        }
        $now = self::now();
        $tid = \metis_generate_code( 'TR', $transactions, 'tid' );
        $method_details = class_exists( DonationsModule::class )
            ? DonationsModule::stripePaymentMethodDetails( $charge )
            : [ 'payment_method' => 'cc', 'card_brand' => null, 'card_last4' => null ];
        \metis_db()->insert( $transactions, [
            'tid' => $tid,
            'did' => (string) ( $plan['did'] ?? '' ) ?: null,
            'platform' => 'stripe',
            'campaign_code' => (string) $plan['campaign_code'],
            'plan_id' => null,
            'fund_code' => null,
            'status' => 'completed',
            'payment_method' => (string) ( $method_details['payment_method'] ?? 'cc' ),
            'chk_num' => null,
            'card_brand' => $method_details['card_brand'] ?? null,
            'card_last4' => $method_details['card_last4'] ?? null,
            'amount' => $amount,
            'fee' => max( 0, $fee ),
            'fee_covered' => 0,
            'pl_fee' => 0,
            'payout' => $payout,
            'tran_date' => $now,
            'deposit_date' => null,
            'deposit_batch_id' => null,
            'giving_space_id' => null,
            'giving_space_name' => null,
            'giving_space_msg' => null,
            'notes' => 'Recurring donation ' . (string) $plan['recurring_code'],
            'stripe_pay_int' => (string) ( $intent->id ?? '' ),
            'stripe_charge_id' => is_object( $charge ) ? (string) ( $charge->id ?? '' ) : null,
            'stripe_balance_txn' => is_object( $balance ) ? (string) ( $balance->id ?? '' ) : ( is_object( $charge ) && is_string( $charge->balance_transaction ?? null ) ? (string) $charge->balance_transaction : null ),
            'stripe_payout_id' => is_object( $balance ) ? (string) ( $balance->payout ?? '' ) : null,
            'refunded' => 0,
            'stripe_refund_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'transaction_uid' => \metis_generate_code( 'DTX', $transactions, 'transaction_uid' ),
        ] );

        if ( \class_exists( FinanceV2Service::class ) ) {
            FinanceV2Service::recordStripeClearingEvent( [
                'event_type' => 'donation',
                'event_date' => substr( $now, 0, 10 ),
                'reference_id' => (string) ( $intent->id ?? $tid ),
                'amount' => $amount,
                'description' => 'Recurring donation: ' . (string) $plan['recurring_code'],
            ] );
        }

        return $tid;
    }

    private static function resolveChargeWithBalanceTransaction( mixed $charge ): ?object {
        if ( is_object( $charge ) ) {
            if ( is_object( $charge->balance_transaction ?? null ) ) {
                return $charge;
            }
            $chargeId = trim( (string) ( $charge->id ?? '' ) );
        } else {
            $chargeId = trim( (string) $charge );
        }

        $stripe = self::stripeClient();
        if ( $chargeId === '' || ! $stripe ) {
            return is_object( $charge ) ? $charge : null;
        }

        try {
            return $stripe->retrieveCharge( $chargeId, [ 'expand' => [ 'balance_transaction' ] ] );
        } catch ( \Throwable $e ) {
            if ( \class_exists( '\Metis_Logger', false ) ) {
                \Metis_Logger::warn( 'donations.recurring_charge_lookup_failed', [
                    'charge_id' => $chargeId,
                    'error' => $e->getMessage(),
                ] );
            }
            return is_object( $charge ) ? $charge : null;
        }
    }

    private static function resolveBalanceTransaction( mixed $balanceTransaction ): ?object {
        if ( is_object( $balanceTransaction ) ) {
            return $balanceTransaction;
        }
        $balanceTransactionId = trim( (string) $balanceTransaction );
        $stripe = self::stripeClient();
        if ( $balanceTransactionId === '' || ! $stripe ) {
            return null;
        }
        try {
            return $stripe->retrieveBalanceTransaction( $balanceTransactionId );
        } catch ( \Throwable $e ) {
            if ( \class_exists( '\Metis_Logger', false ) ) {
                \Metis_Logger::warn( 'donations.recurring_balance_transaction_lookup_failed', [
                    'balance_transaction_id' => $balanceTransactionId,
                    'error' => $e->getMessage(),
                ] );
            }
            return null;
        }
    }

    private static function estimatedStripeProcessingFee( float $amount ): float {
        $percent = 2.9;
        $fixed = 0.30;
        if ( \class_exists( '\Core_Settings_Service', false ) ) {
            $percent = (float) \Core_Settings_Service::get( 'stripe_platform_fee_percent', $percent );
            $fixed = (float) \Core_Settings_Service::get( 'stripe_platform_fee_fixed', $fixed );
        }
        $percent = max( 0.0, $percent ) / 100;
        $fixed = max( 0.0, $fixed );
        return round( max( 0.0, ( $amount * $percent ) + $fixed ), 2 );
    }

    private static function insideProcessingWindow(): bool {
        $tz = new \DateTimeZone( 'America/Chicago' );
        $now = new \DateTimeImmutable( 'now', $tz );
        $hour = (int) $now->format( 'G' );
        return $hour >= self::WINDOW_START_HOUR && $hour < self::WINDOW_END_HOUR;
    }

    private static function nextRunAt( string $frequency, string $from ): string {
        $modifier = match ( self::normalizeFrequency( $frequency ) ) {
            'quarterly' => '+3 months',
            'semiannual' => '+6 months',
            'annual' => '+1 year',
            default => '+1 month',
        };
        $base = new \DateTimeImmutable( $from, new \DateTimeZone( 'UTC' ) );
        return $base->modify( $modifier )->setTime( 7, 0, 0 )->format( 'Y-m-d H:i:s' );
    }

    private static function sendSetupEmail( string $email, string $name, string $code, string $frequency, float $amount, string $token ): void {
        $manageUrl = \metis_home_url( '/manage/recurring/' . rawurlencode( $token ) . '/' );
        $body = '<p>Thank you for setting up a recurring donation.</p>'
            . '<p><strong>Amount:</strong> $' . \number_format( $amount, 2 ) . '<br>'
            . '<strong>Frequency:</strong> ' . \metis_escape_html( ucfirst( $frequency ) ) . '</p>'
            . '<p>You can manage this recurring donation here: <a href="' . \metis_escape_url( $manageUrl ) . '">' . \metis_escape_html( $manageUrl ) . '</a></p>';
        EmailService::sendHtml( $email, 'Recurring donation set up', $body, [
            'module' => 'donations',
            'internal_reference' => $code,
        ] );
    }

    private static function sendReceiptEmail( array $plan, string $tid, float $amount, string $currency ): void {
        $body = '<p>Thank you for your recurring donation.</p>'
            . '<p><strong>Receipt:</strong> ' . \metis_escape_html( $tid ) . '<br>'
            . '<strong>Amount:</strong> ' . \metis_escape_html( strtoupper( $currency ) ) . ' ' . \number_format( $amount, 2 ) . '</p>';
        EmailService::sendHtml( (string) $plan['donor_email'], 'Donation receipt ' . $tid, $body, [
            'module' => 'donations',
            'internal_reference' => $tid,
        ] );
    }

    private static function sendFailureEmail( array $plan, string $error, bool $paused ): void {
        $body = '<p>We could not process your recurring donation.</p><p>' . \metis_escape_html( $paused ? 'The schedule has been paused after repeated failures.' : 'We will retry automatically.' ) . '</p>';
        EmailService::sendHtml( (string) $plan['donor_email'], 'Recurring donation payment issue', $body, [
            'module' => 'donations',
            'internal_reference' => (string) ( $plan['recurring_code'] ?? '' ),
        ] );
        unset( $error );
    }

    private static function upsertContact( array $normalized ): array {
        $contacts = \Metis_Tables::get( 'contacts' );
        $email = strtolower( trim( \metis_email_clean( (string) ( $normalized['email'] ?? '' ) ) ) );
        if ( $contacts === '' || $email === '' ) {
            return [];
        }
        $db = \metis_db();
        $existing = $db->fetchOne( "SELECT * FROM {$contacts} WHERE LOWER(email) = LOWER(%s) LIMIT 1", [ $email ] );
        if ( is_array( $existing ) ) {
            return $existing;
        }
        $did = \metis_generate_code( 'DNR', $contacts, 'did' );
        $db->insert( $contacts, [
            'did' => $did,
            'first_name' => \metis_text_clean( (string) ( $normalized['first_name'] ?? '' ) ),
            'last_name' => \metis_text_clean( (string) ( $normalized['last_name'] ?? '' ) ),
            'email' => $email,
            'created_at' => self::now(),
            'updated_at' => self::now(),
        ] );
        return $db->fetchOne( "SELECT * FROM {$contacts} WHERE did = %s LIMIT 1", [ $did ] ) ?: [ 'did' => $did, 'email' => $email ];
    }

    private static function subscriptionCustomerId( object $subscription ): string {
        $customer = $subscription->customer ?? null;
        if ( is_object( $customer ) ) {
            return trim( (string) ( $customer->id ?? '' ) );
        }
        return trim( (string) $customer );
    }

    private static function resolveStripeCustomer( object $subscription ): ?object {
        $customer = $subscription->customer ?? null;
        if ( is_object( $customer ) && empty( $customer->deleted ) ) {
            return $customer;
        }

        $customerId = self::subscriptionCustomerId( $subscription );
        $stripe = self::stripeClient();
        if ( $customerId === '' || ! $stripe ) {
            return is_object( $customer ) ? $customer : null;
        }

        try {
            $retrieved = $stripe->retrieveCustomer( $customerId );
            return is_object( $retrieved ) ? $retrieved : ( is_object( $customer ) ? $customer : null );
        } catch ( \Throwable $e ) {
            if ( class_exists( '\\Metis_Logger' ) ) {
                \Metis_Logger::warn( 'donations.recurring_migration_customer_lookup_failed', [
                    'customer' => $customerId,
                    'error' => $e->getMessage(),
                ] );
            }
            return is_object( $customer ) ? $customer : null;
        }
    }

    private static function subscriptionPaymentMethodId( object $subscription, ?object $customer ): string {
        $candidates = [
            $subscription->default_payment_method ?? null,
            $customer->invoice_settings->default_payment_method ?? null,
            $customer->default_source ?? null,
        ];
        foreach ( $candidates as $candidate ) {
            if ( is_object( $candidate ) ) {
                $id = trim( (string) ( $candidate->id ?? '' ) );
            } else {
                $id = trim( (string) $candidate );
            }
            if ( $id !== '' ) {
                return $id;
            }
        }
        return '';
    }

    private static function frequencyFromStripePrice( ?object $price ): string {
        if ( ! is_object( $price ) || ! is_object( $price->recurring ?? null ) ) {
            return 'monthly';
        }
        $interval = (string) ( $price->recurring->interval ?? 'month' );
        $count = max( 1, (int) ( $price->recurring->interval_count ?? 1 ) );
        if ( $interval === 'year' ) {
            return 'annual';
        }
        if ( $interval === 'month' && $count >= 12 ) {
            return 'annual';
        }
        if ( $interval === 'month' && $count >= 6 ) {
            return 'semiannual';
        }
        if ( $interval === 'month' && $count >= 3 ) {
            return 'quarterly';
        }
        return 'monthly';
    }

    private static function productFromStripePrice( ?object $price ): ?object {
        if ( ! is_object( $price ) ) {
            return null;
        }

        $product = $price->product ?? null;
        if ( is_object( $product ) ) {
            return $product;
        }

        $productId = trim( (string) $product );
        $stripe = self::stripeClient();
        if ( $productId === '' || ! $stripe ) {
            return null;
        }

        try {
            $retrieved = $stripe->retrieveProduct( $productId );
            return is_object( $retrieved ) ? $retrieved : null;
        } catch ( \Throwable $e ) {
            if ( class_exists( '\Metis_Logger' ) ) {
                \Metis_Logger::warn( 'donations.recurring_migration_product_lookup_failed', [
                    'product' => $productId,
                    'error' => $e->getMessage(),
                ] );
            }
            return null;
        }
    }

    private static function ensureLegacyMigrationCampaign(): string {
        $campaigns = \Metis_Tables::get( 'campaigns' );
        if ( $campaigns === '' ) {
            return '';
        }

        $db = \metis_db();
        $existing = $db->fetchOne(
            "SELECT cid, campaign_uid FROM {$campaigns} WHERE cid = %s OR campaign_uid = %s OR cname = %s LIMIT 1",
            [ 'LEGACY-STRIPE-RECURRING', 'LEGACY-STRIPE-RECURRING', 'Legacy Stripe Subscriptions' ]
        );
        if ( is_array( $existing ) ) {
            $code = trim( (string) ( $existing['campaign_uid'] ?? '' ) );
            return $code !== '' ? $code : trim( (string) ( $existing['cid'] ?? '' ) );
        }

        $columns = array_flip( array_map( 'strval', $db->column( "SHOW COLUMNS FROM {$campaigns}" ) ) );
        $payload = [];
        $put = static function ( string $column, mixed $value ) use ( &$payload, $columns ): void {
            if ( isset( $columns[ $column ] ) ) {
                $payload[ $column ] = $value;
            }
        };

        $put( 'cid', 'LEGACY-STRIPE-RECURRING' );
        $put( 'campaign_uid', 'LEGACY-STRIPE-RECURRING' );
        $put( 'cname', 'Legacy Stripe Subscriptions' );
        $put( 'name', 'Legacy Stripe Subscriptions' );
        $put( 'slug', 'legacy-stripe-subscriptions' );
        $put( 'type', 'Ongoing' );
        $put( 'status', 'active' );
        $put( 'active', 1 );
        $put( 'public', 0 );
        $put( 'created_at', self::now() );
        $put( 'updated_at', self::now() );

        if ( $payload === [] || ! $db->insert( $campaigns, $payload ) ) {
            return '';
        }

        return isset( $columns['campaign_uid'] ) ? 'LEGACY-STRIPE-RECURRING' : ( isset( $columns['cid'] ) ? 'LEGACY-STRIPE-RECURRING' : '' );
    }

    private static function campaignFromSubscription( object $subscription, ?object $price, ?object $product = null ): string {
        $candidates = [
            $subscription->metadata->campaign_code ?? null,
            $subscription->metadata->campaign ?? null,
            $price->metadata->campaign_code ?? null,
            $price->metadata->campaign ?? null,
            $product->metadata->campaign_code ?? null,
            $product->metadata->campaign ?? null,
        ];
        foreach ( $candidates as $candidate ) {
            $campaign = self::normalizeCampaignCode( (string) $candidate );
            if ( $campaign !== '' ) {
                return $campaign;
            }
        }
        return '';
    }

    private static function subscriptionEmail( object $subscription, ?object $customer ): string {
        $candidates = [
            $customer->email ?? null,
            $subscription->latest_invoice->customer_email ?? null,
            $subscription->metadata->donor_email ?? null,
            $subscription->metadata->email ?? null,
        ];
        foreach ( $candidates as $candidate ) {
            $email = strtolower( trim( \metis_email_clean( (string) $candidate ) ) );
            if ( $email !== '' && \metis_email_is_valid( $email ) ) {
                return $email;
            }
        }
        return '';
    }

    public static function requestPortalAccess( string $email ): array {
        self::ensureSchema();
        $email = strtolower( trim( \metis_email_clean( $email ) ) );
        if ( $email === '' || ! \metis_email_is_valid( $email ) ) {
            return [ 'ok' => false, 'message' => 'Enter a valid email address.' ];
        }

        $transactions = \Metis_Tables::get( 'transactions' );
        $contacts = \Metis_Tables::get( 'contacts' );
        $recurring = \Metis_Tables::get( 'recurring_donations' );
        $newsletterSubs = \Metis_Tables::get( 'newsletter_subs' );
        $hasDonor = (int) \metis_db()->scalar(
            "SELECT COUNT(*) FROM {$contacts} c WHERE LOWER(c.email) = LOWER(%s)",
            [ $email ]
        );
        $hasTransactions = (int) \metis_db()->scalar(
            "SELECT COUNT(*) FROM {$transactions} t INNER JOIN {$contacts} c ON c.did = t.did WHERE LOWER(c.email) = LOWER(%s)",
            [ $email ]
        );
        $hasRecurring = (int) \metis_db()->scalar(
            "SELECT COUNT(*) FROM {$recurring} WHERE LOWER(donor_email) = LOWER(%s)",
            [ $email ]
        );
        $hasNewsletter = 0;
        if ( $newsletterSubs !== '' && self::tableExists( $newsletterSubs ) ) {
            $hasNewsletter = (int) \metis_db()->scalar(
                "SELECT COUNT(*) FROM {$newsletterSubs} ns INNER JOIN {$contacts} c ON c.id = ns.contact_id WHERE LOWER(c.email) = LOWER(%s)",
                [ $email ]
            );
        }

        if ( $hasDonor < 1 && $hasTransactions < 1 && $hasRecurring < 1 && $hasNewsletter < 1 ) {
            return [ 'ok' => true, 'message' => 'If that email is connected to donor records, an access link will be sent.' ];
        }

        $rawToken = bin2hex( random_bytes( 32 ) );
        $tokenHash = hash( 'sha256', $rawToken );
        $expiresAt = gmdate( 'Y-m-d H:i:s', time() + 900 );
        $table = \Metis_Tables::get( 'donor_portal_tokens' );
        \metis_db()->insert( $table, [
            'token_hash' => $tokenHash,
            'donor_email' => $email,
            'expires_at' => $expiresAt,
            'created_at' => self::now(),
        ] );

        $url = \metis_home_url( '/manage/access/' . rawurlencode( $rawToken ) . '/' );
        $orgName = self::profileAccessOrganizationName();
        EmailService::sendHtml( $email, 'Your ' . $orgName . ' profile access link', self::portalAccessEmailHtml( $url ), [
            'module' => 'donations',
            'reply_to' => 'donations@mobilizewaco.org',
        ] );

        return [ 'ok' => true, 'message' => 'If that email is connected to donor records, an access link will be sent.' ];
    }

    private static function profileAccessOrganizationName(): string {
        $name = '';
        if ( class_exists( '\Core_Settings_Service' ) ) {
            $name = trim( (string) \Core_Settings_Service::get( 'login_organization_name', '' ) );
            if ( $name === '' ) {
                $name = trim( (string) \Core_Settings_Service::get( 'portal_name', '' ) );
            }
        }
        if ( $name === '' && function_exists( 'metis_portal_name' ) ) {
            $name = trim( (string) \metis_portal_name() );
        }
        return $name !== '' ? $name : 'Metis';
    }

    public static function consumePortalToken( string $token ): ?array {
        self::ensureSchema();
        $token = trim( $token );
        if ( $token === '' ) {
            return null;
        }

        $table = \Metis_Tables::get( 'donor_portal_tokens' );
        $hash = hash( 'sha256', $token );
        $row = \metis_db()->fetchOne(
            "SELECT * FROM {$table} WHERE token_hash = %s AND expires_at >= %s LIMIT 1",
            [ $hash, self::now() ]
        );
        if ( ! is_array( $row ) ) {
            return null;
        }
        return self::donorPortalData( (string) $row['donor_email'] );
    }

    public static function donorPortalData( string $email ): array {
        self::ensureSchema();
        $email = strtolower( trim( \metis_email_clean( $email ) ) );
        $contacts = \Metis_Tables::get( 'contacts' );
        $transactions = \Metis_Tables::get( 'transactions' );
        $recurring = \Metis_Tables::get( 'recurring_donations' );
        $details = \Metis_Tables::get( 'contact_details' );
        $contact = \metis_db()->fetchOne( "SELECT * FROM {$contacts} WHERE LOWER(email) = LOWER(%s) LIMIT 1", [ $email ] ) ?: [];
        $detail = [];
        $did = trim( (string) ( $contact['did'] ?? '' ) );
        if ( is_array( $contact ) && $details !== '' && self::tableExists( $details ) ) {
            $detail = \metis_db()->fetchOne(
                "SELECT * FROM {$details} WHERE contact_id = %d OR did = %s LIMIT 1",
                [ (int) ( $contact['id'] ?? 0 ), $did ]
            ) ?: [];
        }
        $tx = [];
        if ( $did !== '' ) {
            $tx = \metis_db()->fetchAll(
                "SELECT tid, tran_date, amount, status FROM {$transactions} WHERE did = %s ORDER BY tran_date DESC LIMIT 100",
                [ $did ]
            );
        }
        $plans = \metis_db()->fetchAll(
            "SELECT recurring_code, campaign_code, amount, frequency, status, next_run_at, self_manage_token FROM {$recurring} WHERE LOWER(donor_email) = LOWER(%s) ORDER BY next_run_at ASC",
            [ $email ]
        );
        $years = [];
        foreach ( $tx as $row ) {
            $year = substr( (string) ( $row['tran_date'] ?? '' ), 0, 4 );
            if ( preg_match( '/^\d{4}$/', $year ) ) {
                $years[ $year ] = $year;
            }
        }
        if ( $years === [] ) {
            $years[ gmdate( 'Y' ) ] = gmdate( 'Y' );
        }
        rsort( $years );

        return [ 'email' => $email, 'contact' => $contact, 'detail' => $detail, 'transactions' => $tx, 'recurring' => $plans, 'newsletter' => self::newsletterSubscriptionsForEmail( $email ), 'statement_years' => array_values( $years ) ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function newsletterSubscriptionsForEmail( string $email ): array {
        $email = strtolower( trim( \metis_email_clean( $email ) ) );
        if ( $email === '' || ! \metis_email_is_valid( $email ) ) {
            return [];
        }
        $lists = \Metis_Tables::get( 'newsletter_lists' );
        $subs = \Metis_Tables::get( 'newsletter_subs' );
        $contacts = \Metis_Tables::get( 'contacts' );
        if ( $lists === '' || $subs === '' || $contacts === '' || ! self::tableExists( $lists ) || ! self::tableExists( $subs ) || ! self::tableExists( $contacts ) ) {
            return [];
        }
        $contactId = (int) \metis_db()->scalar( "SELECT id FROM {$contacts} WHERE LOWER(email) = LOWER(%s) LIMIT 1", [ $email ] );
        if ( $contactId < 1 ) {
            return [];
        }
        return \metis_db()->fetchAll(
            "SELECT l.id, l.name, COALESCE(s.status, 'unsubscribed') AS status
             FROM {$lists} l
             LEFT JOIN {$subs} s ON s.list_id = l.id AND s.contact_id = %d
             WHERE l.is_active = 1
             ORDER BY l.name ASC",
            [ $contactId ]
        );
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public static function toggleNewsletterSubscription( string $email, int $listId ): array {
        $email = strtolower( trim( \metis_email_clean( $email ) ) );
        if ( $email === '' || ! \metis_email_is_valid( $email ) || $listId < 1 ) {
            return [ 'ok' => false, 'message' => 'Newsletter subscription could not be updated.' ];
        }
        $lists = \Metis_Tables::get( 'newsletter_lists' );
        $subs = \Metis_Tables::get( 'newsletter_subs' );
        if ( $lists === '' || $subs === '' || ! self::tableExists( $lists ) || ! self::tableExists( $subs ) ) {
            return [ 'ok' => false, 'message' => 'Newsletter subscriptions are unavailable.' ];
        }
        $list = \metis_db()->fetchOne( "SELECT id FROM {$lists} WHERE id = %d AND is_active = 1 LIMIT 1", [ $listId ] );
        if ( ! is_array( $list ) ) {
            return [ 'ok' => false, 'message' => 'Newsletter list was not found.' ];
        }
        $contacts = \Metis_Tables::get( 'contacts' );
        $contact = $contacts !== '' && self::tableExists( $contacts )
            ? ( \metis_db()->fetchOne( "SELECT id FROM {$contacts} WHERE LOWER(email) = LOWER(%s) LIMIT 1", [ $email ] ) ?: [] )
            : [];
        $contactId = (int) ( $contact['id'] ?? 0 );
        if ( $contactId < 1 ) {
            return [ 'ok' => false, 'message' => 'No profile record was found for this email.' ];
        }
        $now = \metis_current_time( 'mysql' );
        $row = \metis_db()->fetchOne(
            "SELECT id, status FROM {$subs} WHERE list_id = %d AND contact_id = %d LIMIT 1",
            [ $listId, $contactId ]
        );
        if ( is_array( $row ) ) {
            $newStatus = (string) ( $row['status'] ?? '' ) === 'subscribed' ? 'unsubscribed' : 'subscribed';
            \metis_db()->update(
                $subs,
                [
                    'status' => $newStatus,
                    'unsubscribed_at' => $newStatus === 'subscribed' ? null : $now,
                    'subscribed_at' => $newStatus === 'subscribed' ? $now : null,
                    'last_event_at' => $now,
                    'updated_at' => $now,
                ],
                [ 'id' => (int) ( $row['id'] ?? 0 ) ]
            );
            return [ 'ok' => true, 'message' => 'Newsletter subscription updated.' ];
        }
        $payload = [
            'list_id' => $listId,
            'contact_id' => $contactId,
            'status' => 'subscribed',
            'subscribed_at' => $now,
            'last_event_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        \metis_db()->insert( $subs, $payload );
        return [ 'ok' => true, 'message' => 'Newsletter subscription updated.' ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool,message:string}
     */
    public static function updateDonorProfile( string $email, array $input ): array {
        self::ensureSchema();
        $email = strtolower( trim( \metis_email_clean( $email ) ) );
        if ( $email === '' || ! \metis_email_is_valid( $email ) ) {
            return [ 'ok' => false, 'message' => 'The portal access link is no longer valid.' ];
        }

        $contacts = \Metis_Tables::get( 'contacts' );
        $details = \Metis_Tables::get( 'contact_details' );
        $contact = \metis_db()->fetchOne( "SELECT * FROM {$contacts} WHERE LOWER(email) = LOWER(%s) LIMIT 1", [ $email ] );
        if ( ! is_array( $contact ) ) {
            return [ 'ok' => false, 'message' => 'No editable donor profile was found for this email.' ];
        }

        $first = trim( \metis_text_clean( (string) ( $input['first_name'] ?? '' ) ) );
        $last = trim( \metis_text_clean( (string) ( $input['last_name'] ?? '' ) ) );
        $phone = trim( \metis_text_clean( (string) ( $input['phone'] ?? '' ) ) );
        $address = trim( \metis_text_clean( (string) ( $input['address'] ?? '' ) ) );
        $city = trim( \metis_text_clean( (string) ( $input['city'] ?? '' ) ) );
        $state = trim( \metis_text_clean( (string) ( $input['state'] ?? '' ) ) );
        $zip = trim( \metis_text_clean( (string) ( $input['zip'] ?? '' ) ) );

        $contactColumns = self::tableColumns( $contacts );
        $contactPatch = [];
        foreach ( [ 'first_name' => $first, 'last_name' => $last ] as $column => $value ) {
            if ( isset( $contactColumns[ $column ] ) ) {
                $contactPatch[ $column ] = $value;
            }
        }
        if ( isset( $contactColumns['updated_at'] ) ) {
            $contactPatch['updated_at'] = \metis_current_time( 'mysql' );
        }
        if ( $contactPatch !== [] ) {
            \metis_db()->update( $contacts, $contactPatch, [ 'id' => (int) ( $contact['id'] ?? 0 ) ] );
        }

        if ( $details !== '' && self::tableExists( $details ) ) {
            $detailColumns = self::tableColumns( $details );
            $detail = \metis_db()->fetchOne(
                "SELECT * FROM {$details} WHERE contact_id = %d OR did = %s LIMIT 1",
                [ (int) ( $contact['id'] ?? 0 ), (string) ( $contact['did'] ?? '' ) ]
            );
            $detailPatch = [];
            foreach ( [ 'phone' => $phone, 'address' => $address, 'city' => $city, 'state' => $state, 'zip' => $zip ] as $column => $value ) {
                if ( isset( $detailColumns[ $column ] ) ) {
                    $detailPatch[ $column ] = $value;
                }
            }
            if ( isset( $detailColumns['updated_at'] ) ) {
                $detailPatch['updated_at'] = \metis_current_time( 'mysql' );
            }
            if ( is_array( $detail ) && $detailPatch !== [] ) {
                \metis_db()->update( $details, $detailPatch, [ 'id' => (int) ( $detail['id'] ?? 0 ) ] );
            } elseif ( ! is_array( $detail ) && $detailPatch !== [] ) {
                if ( isset( $detailColumns['contact_id'] ) ) {
                    $detailPatch['contact_id'] = (int) ( $contact['id'] ?? 0 );
                }
                if ( isset( $detailColumns['did'] ) ) {
                    $detailPatch['did'] = (string) ( $contact['did'] ?? '' );
                }
                if ( isset( $detailColumns['contact_cid'] ) ) {
                    $detailPatch['contact_cid'] = (string) ( $contact['cid'] ?? '' );
                }
                if ( isset( $detailColumns['created_at'] ) ) {
                    $detailPatch['created_at'] = \metis_current_time( 'mysql' );
                }
                \metis_db()->insert( $details, $detailPatch );
            }
        }

        return [ 'ok' => true, 'message' => 'Your profile was updated.' ];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public static function sendDonorInquiry( string $email, string $message, string $transactionId = '' ): array {
        self::ensureSchema();
        $email = strtolower( trim( \metis_email_clean( $email ) ) );
        $message = trim( \metis_text_clean( $message ) );
        $transactionId = trim( \metis_text_clean( $transactionId ) );
        if ( $email === '' || ! \metis_email_is_valid( $email ) ) {
            return [ 'ok' => false, 'message' => 'The portal access link is no longer valid.' ];
        }
        if ( $message === '' ) {
            return [ 'ok' => false, 'message' => 'Enter your question before sending.' ];
        }

        $transaction = [];
        if ( $transactionId !== '' ) {
            $transactions = \Metis_Tables::get( 'transactions' );
            $contacts = \Metis_Tables::get( 'contacts' );
            $transaction = \metis_db()->fetchOne(
                "SELECT t.tid, t.tran_date, t.amount, t.status FROM {$transactions} t INNER JOIN {$contacts} c ON c.did = t.did WHERE t.tid = %s AND LOWER(c.email) = LOWER(%s) LIMIT 1",
                [ $transactionId, $email ]
            ) ?: [];
            if ( ! is_array( $transaction ) || $transaction === [] ) {
                return [ 'ok' => false, 'message' => 'That donation could not be verified for this portal link.' ];
            }
        }

        $to = 'donations@mobilizewaco.org';
        $subject = $transactionId !== '' ? ( 'Donor question about ' . $transactionId ) : 'Donor portal inquiry';
        $detailHtml = '';
        if ( $transaction !== [] ) {
            $detailHtml = '<p><strong>Receipt:</strong> ' . \metis_escape_html( (string) ( $transaction['tid'] ?? '' ) ) . '<br>'
                . '<strong>Date:</strong> ' . \metis_escape_html( (string) ( $transaction['tran_date'] ?? '' ) ) . '<br>'
                . '<strong>Amount:</strong> $' . \metis_escape_html( number_format( (float) ( $transaction['amount'] ?? 0 ), 2 ) ) . '<br>'
                . '<strong>Status:</strong> ' . \metis_escape_html( (string) ( $transaction['status'] ?? '' ) ) . '</p>';
        }
        $body = '<div style="font-family:Arial,sans-serif;color:#111827;line-height:1.55">'
            . '<h2 style="margin:0 0 12px">Donor portal inquiry</h2>'
            . '<p><strong>Donor email:</strong> ' . \metis_escape_html( $email ) . '</p>'
            . $detailHtml
            . '<div style="padding:14px;border:1px solid #d8deea;border-radius:8px;background:#f8fafc">' . nl2br( \metis_escape_html( $message ) ) . '</div>'
            . '</div>';
        $send = EmailService::sendHtml( $to, $subject, $body, [
            'module' => 'donations',
            'reply_to' => $email,
        ] );

        return ! empty( $send['ok'] )
            ? [ 'ok' => true, 'message' => 'Your question was sent.' ]
            : [ 'ok' => false, 'message' => 'The question could not be sent. Please try again later.' ];
    }

    /**
     * @return array{email:string,contact:array<string,mixed>,detail:array<string,mixed>,transactions:array<int,array<string,mixed>>,year:int,total:float}
     */
    public static function contributionStatementData( string $email, int $year ): array {
        self::ensureSchema();
        $email = strtolower( trim( \metis_email_clean( $email ) ) );
        $year = max( 2000, min( 2100, $year ) );
        $data = self::donorPortalData( $email );
        $transactions = [];
        $total = 0.0;
        foreach ( (array) ( $data['transactions'] ?? [] ) as $row ) {
            if ( substr( (string) ( $row['tran_date'] ?? '' ), 0, 4 ) !== (string) $year ) {
                continue;
            }
            if ( strtolower( (string) ( $row['status'] ?? '' ) ) !== 'completed' ) {
                continue;
            }
            $transactions[] = $row;
            $total += (float) ( $row['amount'] ?? 0 );
        }

        return [
            'email' => $email,
            'contact' => is_array( $data['contact'] ?? null ) ? $data['contact'] : [],
            'detail' => is_array( $data['detail'] ?? null ) ? $data['detail'] : [],
            'transactions' => $transactions,
            'year' => $year,
            'total' => $total,
        ];
    }

    private static function portalAccessEmailHtml( string $url ): string {
        $safeUrl = \metis_escape_url( $url );
        $logo = function_exists( 'metis_portal_logo_url' ) ? trim( (string) \metis_portal_logo_url() ) : '';
        $logoHtml = $logo !== '' ? '<tr><td style="padding:26px 30px 0"><img src="' . \metis_escape_url( $logo ) . '" alt="Mobilize Waco" style="display:block;max-width:210px;max-height:82px;width:auto;height:auto;border:0"></td></tr>' : '';
        return '<div style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,sans-serif;color:#172033">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;background:#f5f7fb"><tr><td align="center" style="padding:32px 16px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;border-collapse:collapse;background:#ffffff;border:1px solid #dfe5f1;border-radius:10px;overflow:hidden">'
            . $logoHtml
            . '<tr><td style="padding:28px 30px 10px"><h1 style="margin:0;color:#172033;font-size:24px;line-height:1.2">Your profile access link</h1></td></tr>'
            . '<tr><td style="padding:0 30px 22px;color:#596579;font-size:15px;line-height:1.6">Use this secure link to manage your profile, newsletter subscriptions, donation history, recurring gifts, and contribution statements. This link expires in 15 minutes.</td></tr>'
            . '<tr><td style="padding:0 30px 28px"><a href="' . $safeUrl . '" style="display:inline-block;background:#2754d8;color:#ffffff;text-decoration:none;font-weight:700;border-radius:6px;padding:12px 18px">Open manage profile</a></td></tr>'
            . '<tr><td style="padding:0 30px 28px;color:#596579;font-size:13px;line-height:1.5">If the button does not work, copy and paste this link into your browser:<br><a href="' . $safeUrl . '" style="color:#2754d8;word-break:break-all">' . \metis_escape_html( $url ) . '</a></td></tr>'
            . '</table></td></tr></table></div>';
    }

    /**
     * @return array{from_name:string,from_email:string,reply_to:string}
     */
    private static function emailDefaults(): array {
        $fromName = \class_exists( '\Core_Settings_Service' ) ? trim( (string) \Core_Settings_Service::get( 'newsletter_default_from_name', '' ) ) : '';
        $fromEmail = \class_exists( '\Core_Settings_Service' ) ? strtolower( trim( \metis_email_clean( (string) \Core_Settings_Service::get( 'newsletter_default_from_email', '' ) ) ) ) : '';
        $replyTo = \class_exists( '\Core_Settings_Service' ) ? strtolower( trim( \metis_email_clean( (string) \Core_Settings_Service::get( 'newsletter_default_reply_to', '' ) ) ) ) : '';
        return [
            'from_name' => $fromName,
            'from_email' => \metis_email_is_valid( $fromEmail ) ? $fromEmail : '',
            'reply_to' => \metis_email_is_valid( $replyTo ) ? $replyTo : '',
        ];
    }

    /**
     * @return array<string,bool>
     */
    private static function tableColumns( string $table ): array {
        if ( $table === '' ) {
            return [];
        }
        if ( ! self::tableExists( $table ) ) {
            return [];
        }
        $columns = [];
        foreach ( \metis_db()->fetchAll( "SHOW COLUMNS FROM {$table}" ) as $row ) {
            $field = (string) ( $row['Field'] ?? '' );
            if ( $field !== '' ) {
                $columns[ $field ] = true;
            }
        }
        return $columns;
    }

    private static function tableExists( string $table ): bool {
        if ( $table === '' ) {
            return false;
        }
        return \metis_db()->scalar( 'SHOW TABLES LIKE %s', [ $table ] ) === $table;
    }

    private static function paymentField( array $schema ): ?array {
        foreach ( $schema as $field ) {
            if ( is_array( $field ) && ( $field['type'] ?? '' ) === 'payment' ) {
                return $field;
            }
        }
        return null;
    }

    private static function normalizeCampaignCode( string $value ): string {
        return trim( \metis_text_clean( $value ) );
    }

    private static function now(): string {
        return \gmdate( 'Y-m-d H:i:s' );
    }
}
