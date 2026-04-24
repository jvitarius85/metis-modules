<?php
declare(strict_types=1);

if ( ! defined( "METIS_ROOT" ) ) {
    exit;
}

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

if ( ! function_exists( "metis_generate_batch_code" ) ) {
    function metis_generate_batch_code(): string {
        return \Metis\Modules\Donations\DonationsModule::generateBatchCode();
    }
}

function metis_create_deposit_batch( array $tids ): string|MetisError {
    return \Metis\Modules\Donations\DonationsModule::createDepositBatch( $tids );
}

function metis_add_batch_note( string $batch_code, string $text ): bool|int {
    return \Metis\Modules\Donations\DonationsModule::addBatchNote( $batch_code, $text );
}

function metis_get_batch_notes( string $batch_code ): array {
    return \Metis\Modules\Donations\DonationsModule::getBatchNotes( $batch_code );
}

function metis_add_batch_audit( string $batch_code, string $type, string $detail = "" ): bool|int {
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

if ( ! function_exists( "metis_parse_goals" ) ) {
    function metis_parse_goals( ?string $raw ): array {
        return \Metis\Modules\Donations\DonationsModule::parseGoals( $raw );
    }
}

if ( ! function_exists( "metis_donations_render_form_embed" ) ) {
    /**
     * Render donation embed using existing public campaign donation flow.
     * Returns empty string when campaign/public URL cannot be resolved.
     */
    function metis_donations_render_form_embed( array $options = [] ): string {
        $campaign_id = trim( metis_text_clean( (string) ( $options["campaign_id"] ?? "" ) ) );
        if ( $campaign_id === "" ) {
            return "";
        }

        if ( ! function_exists( "metis_db" ) || ! class_exists( "\\Metis_Tables" ) ) {
            return "";
        }

        $campaigns_table = \Metis_Tables::get( "campaigns" );
        if ( $campaigns_table === "" ) {
            return "";
        }

        static $campaign_cache = [];
        if ( ! array_key_exists( $campaign_id, $campaign_cache ) ) {
            $db = metis_db();
            $campaign_cache[ $campaign_id ] = $db->fetchOne(
                "SELECT cid, cname, url, public, active FROM {$campaigns_table} WHERE cid = %s LIMIT 1",
                [ $campaign_id ]
            );
        }
        $campaign = $campaign_cache[ $campaign_id ];

        if ( ! is_array( $campaign ) ) {
            return "";
        }

        if ( isset( $campaign["public"] ) && (int) $campaign["public"] !== 1 ) {
            return "";
        }
        if ( isset( $campaign["active"] ) && (int) $campaign["active"] !== 1 ) {
            return "";
        }

        $url = trim( (string) ( $campaign["url"] ?? "" ) );
        if ( $url === "" ) {
            return "";
        }

        if ( preg_match( "#^https?://#i", $url ) === 1 ) {
            $src = metis_escape_url( $url );
        } else {
            if ( $url[0] !== "/" ) {
                $url = "/" . $url;
            }
            $src = metis_escape_url( metis_home_url( $url ) );
        }

        if ( $src === "" ) {
            return "";
        }

        $mode = strtolower( trim( (string) ( $options["mode"] ?? "both" ) ) );
        if ( ! in_array( $mode, [ "one_time", "monthly", "both" ], true ) ) {
            $mode = "both";
        }
        $show_name = ! array_key_exists( "show_name", $options ) || ! empty( $options["show_name"] );
        $show_email = ! array_key_exists( "show_email", $options ) || ! empty( $options["show_email"] );
        $show_phone = ! empty( $options["show_phone"] );

        $amount = isset( $options["default_amount"] ) ? max( 1, (int) $options["default_amount"] ) : 50;
        $submit = trim( (string) ( $options["submit_label"] ?? "Continue to Donate" ) );
        if ( $submit === "" ) {
            $submit = "Continue to Donate";
        }

        $html = "<form class=\"metis-block-donation-form metis-block-donation-form-inline\" method=\"get\" action=\"" . $src . "\">";
        $html .= "<input type=\"hidden\" name=\"mode\" value=\"" . metis_escape_attr( $mode ) . "\">";
        $html .= "<div class=\"metis-donation-fields\">";
        $html .= "<input class=\"metis-input\" type=\"number\" min=\"1\" step=\"1\" name=\"amount\" value=\"" . metis_escape_attr( (string) $amount ) . "\" placeholder=\"Amount\">";
        if ( $show_name ) {
            $html .= "<input class=\"metis-input\" type=\"text\" name=\"name\" placeholder=\"Full name\">";
        }
        if ( $show_email ) {
            $html .= "<input class=\"metis-input\" type=\"email\" name=\"email\" placeholder=\"Email\">";
        }
        if ( $show_phone ) {
            $html .= "<input class=\"metis-input\" type=\"tel\" name=\"phone\" placeholder=\"Phone\">";
        }
        $html .= "</div>";
        $html .= "<button type=\"submit\" class=\"metis-btn\">" . metis_escape_html( $submit ) . "</button>";
        $html .= "</form>";

        return $html;
    }
}
