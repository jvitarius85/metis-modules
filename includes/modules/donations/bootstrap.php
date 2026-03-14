<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __DIR__, 2 ) . '/core/autoload.php';

\Metis\Modules\Donations\DonationsModule::boot();

function metis_donations_base_url(): string {
    return \Metis\Modules\Donations\DonationsModule::baseUrl();
}

function metis_platform_label( string $code ): string {
    return \Metis\Modules\Donations\DonationsModule::platformLabel( $code );
}

function metis_paymethod_badge( ?string $method ): string {
    return \Metis\Modules\Donations\DonationsModule::paymethodBadge( $method );
}

function metis_deposit_badge( ?string $date ): string {
    return \Metis\Modules\Donations\DonationsModule::depositBadge( $date );
}

function metis_status_badge( string $status ): string {
    return \Metis\Modules\Donations\DonationsModule::statusBadge( $status );
}

function metis_deposit_source_badge( object $deposit ): string {
    return \Metis\Modules\Donations\DonationsModule::depositSourceBadge( $deposit );
}

if ( ! function_exists( 'metis_generate_batch_code' ) ) {
    function metis_generate_batch_code(): string {
        return \Metis\Modules\Donations\DonationsModule::generateBatchCode();
    }
}

function metis_create_deposit_batch( array $tids ): string|\WP_Error {
    return \Metis\Modules\Donations\DonationsModule::createDepositBatch( $tids );
}

function metis_add_batch_note( string $batch_code, string $text ): bool|int {
    return \Metis\Modules\Donations\DonationsModule::addBatchNote( $batch_code, $text );
}

function metis_get_batch_notes( string $batch_code ): array {
    return \Metis\Modules\Donations\DonationsModule::getBatchNotes( $batch_code );
}

function metis_add_batch_audit( string $batch_code, string $type, string $detail = '' ): bool|int {
    return \Metis\Modules\Donations\DonationsModule::addBatchAudit( $batch_code, $type, $detail );
}

function metis_get_batch_audit( string $batch_code ): array {
    return \Metis\Modules\Donations\DonationsModule::getBatchAudit( $batch_code );
}

function metis_get_deposits(): array {
    return \Metis\Modules\Donations\DonationsModule::getDeposits();
}

function metis_backfill_stripe_payout_ids( int $limit = 200 ): void {
    \Metis\Modules\Donations\DonationsModule::backfillStripePayoutIds( $limit );
}

function metis_backfill_stripe_payouts_from_payouts( int $limit = 50 ): void {
    \Metis\Modules\Donations\DonationsModule::backfillStripePayoutsFromPayouts( $limit );
}

if ( ! function_exists( 'metis_parse_goals' ) ) {
    function metis_parse_goals( ?string $raw ): array {
        return \Metis\Modules\Donations\DonationsModule::parseGoals( $raw );
    }
}
