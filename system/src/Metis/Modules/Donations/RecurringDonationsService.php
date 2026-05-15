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
        $campaigns = \Metis_Tables::get( 'campaigns' );
        return \metis_db()->fetchAll(
            "SELECT r.*, c.cname AS campaign_name
             FROM {$plans} r
             LEFT JOIN {$campaigns} c ON c.cid = r.campaign_code
             ORDER BY r.next_run_at ASC, r.id DESC
             LIMIT %d",
            [ max( 1, min( 500, $limit ) ) ]
        );
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
        if ( ! \function_exists( 'metis_stripe_init' ) || ! \class_exists( '\Stripe\Subscription' ) ) {
            return [ 'ok' => false, 'message' => 'Stripe subscriptions are unavailable.' ];
        }
        \metis_stripe_init();
        if ( \Stripe\Stripe::getApiKey() === null ) {
            return [ 'ok' => false, 'message' => 'Stripe is not configured.' ];
        }

        $plans = \Metis_Tables::get( 'recurring_donations' );
        $created = 0;
        $skipped = 0;
        $cancelled = 0;
        $errors = [];
        $rows = [];

        try {
            $subscriptions = \Stripe\Subscription::all( [
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
            if ( $cancelStripeSubscriptions ) {
                try {
                    $subscription->cancel();
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
        if ( ! \function_exists( 'metis_stripe_init' ) || ! \class_exists( '\\Stripe\\Subscription' ) ) {
            return [ 'ok' => false, 'message' => 'Stripe subscriptions are unavailable.' ];
        }
        \metis_stripe_init();
        if ( \Stripe\Stripe::getApiKey() === null ) {
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
            $subscription = \Stripe\Subscription::retrieve( [
                'id' => $subscriptionId,
                'expand' => [ 'customer', 'default_payment_method', 'items.data.price', 'latest_invoice' ],
            ] );
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

        $cancelled = false;
        if ( $cancelStripeSubscription ) {
            try {
                $subscription->cancel();
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
        if ( ! \function_exists( 'metis_stripe_init' ) || ! \class_exists( '\Stripe\PaymentIntent' ) ) {
            return [ 'status' => 'failed', 'message' => 'Stripe SDK is unavailable.' ];
        }
        \metis_stripe_init();
        if ( \Stripe\Stripe::getApiKey() === null ) {
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

        try {
            $intent = \Stripe\PaymentIntent::create(
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

        $charge = is_object( $intent->latest_charge ?? null ) ? $intent->latest_charge : null;
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
        $amount = round( (float) $plan['amount'], 2 );
        $fee = 0.0;
        $payout = $amount;
        if ( is_object( $charge ) && is_object( $charge->balance_transaction ?? null ) ) {
            $balance = $charge->balance_transaction;
            $fee = isset( $balance->fee ) ? round( ( (float) $balance->fee ) / 100, 2 ) : 0.0;
            $payout = isset( $balance->net ) ? round( ( (float) $balance->net ) / 100, 2 ) : round( $amount - $fee, 2 );
        }
        $now = self::now();
        $tid = \metis_generate_code( 'TR', $transactions, 'tid' );
        \metis_db()->insert( $transactions, [
            'tid' => $tid,
            'did' => (string) ( $plan['did'] ?? '' ) ?: null,
            'platform' => 'stripe',
            'campaign_code' => (string) $plan['campaign_code'],
            'plan_id' => null,
            'fund_code' => null,
            'status' => 'completed',
            'payment_method' => 'stripe',
            'chk_num' => null,
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
            'stripe_balance_txn' => is_object( $charge ) && is_object( $charge->balance_transaction ?? null ) ? (string) ( $charge->balance_transaction->id ?? '' ) : null,
            'stripe_payout_id' => is_object( $charge ) && is_object( $charge->balance_transaction ?? null ) ? (string) ( $charge->balance_transaction->payout ?? '' ) : null,
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
        $manageUrl = \metis_home_url( '/donor/recurring/' . rawurlencode( $token ) . '/' );
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
        if ( $customerId === '' || ! class_exists( '\\Stripe\\Customer' ) ) {
            return is_object( $customer ) ? $customer : null;
        }

        try {
            $retrieved = \Stripe\Customer::retrieve( $customerId );
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
        if ( $productId === '' || ! class_exists( '\Stripe\Product' ) ) {
            return null;
        }

        try {
            $retrieved = \Stripe\Product::retrieve( $productId );
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

        if ( $hasDonor < 1 && $hasTransactions < 1 && $hasRecurring < 1 ) {
            return [ 'ok' => true, 'message' => 'If that email is connected to donor records, an access link will be sent.' ];
        }

        $rawToken = bin2hex( random_bytes( 32 ) );
        $tokenHash = hash( 'sha256', $rawToken );
        $expiresAt = gmdate( 'Y-m-d H:i:s', time() + 1800 );
        $table = \Metis_Tables::get( 'donor_portal_tokens' );
        \metis_db()->insert( $table, [
            'token_hash' => $tokenHash,
            'donor_email' => $email,
            'expires_at' => $expiresAt,
            'created_at' => self::now(),
        ] );

        $url = \metis_home_url( '/donor/access/' . rawurlencode( $rawToken ) . '/' );
        EmailService::sendHtml( $email, 'Your donor portal access link', '<p>Use this secure link to view your donor portal. It expires in 30 minutes.</p><p><a href="' . \metis_escape_url( $url ) . '">' . \metis_escape_html( $url ) . '</a></p>', [
            'module' => 'donations',
            'internal_reference' => 'DONOR_PORTAL',
        ] );

        return [ 'ok' => true, 'message' => 'If that email is connected to donor records, an access link will be sent.' ];
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
        $contact = \metis_db()->fetchOne( "SELECT * FROM {$contacts} WHERE LOWER(email) = LOWER(%s) LIMIT 1", [ $email ] ) ?: [];
        $did = trim( (string) ( $contact['did'] ?? '' ) );
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
        return [ 'email' => $email, 'contact' => $contact, 'transactions' => $tx, 'recurring' => $plans ];
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
