<?php
declare(strict_types=1);

if ( ! defined( "METIS_ROOT" ) ) {
    exit;
}

\Metis\Modules\Donations\DonationsModule::boot();

function metis_donations_base_url(): string {
    return \Metis\Modules\Donations\DonationsModule::baseUrl();
}

function metis_donations_detail_url( string $view, string $identifier ): string {
    $view = metis_slug_clean( $view );
    $identifier = trim( $identifier );
    if ( $view === '' || $identifier === '' ) {
        return metis_donations_base_url() . '/';
    }

    return metis_donations_base_url() . '/' . $view . '/' . rawurlencode( $identifier ) . '/';
}

function metis_donations_request_identifier( string $query_key, string $view = '' ): string {
    $view = $view !== '' ? metis_slug_clean( $view ) : '';
    $path = isset( $_SERVER['REQUEST_URI'] ) ? (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
    $path = trim( rawurldecode( $path ), '/' );

    if ( $path !== '' ) {
        $segments = array_values( array_filter( explode( '/', $path ), static fn ( string $segment ): bool => $segment !== '' ) );
        $count = count( $segments );
        for ( $i = 0; $i < $count; $i++ ) {
            if ( $segments[ $i ] !== 'donations' ) {
                continue;
            }
            $candidate_view = $segments[ $i + 1 ] ?? '';
            $candidate_id = $segments[ $i + 2 ] ?? '';
            if ( $candidate_id !== '' && ( $view === '' || $candidate_view === $view ) ) {
                return metis_text_clean( $candidate_id );
            }
        }
    }

    return isset( metis_request_get()[ $query_key ] )
        ? metis_text_clean( (string) metis_request_get()[ $query_key ] )
        : '';
}

function metis_donations_can( string $action ): bool {
    return function_exists( 'metis_security_user_can' ) && metis_security_user_can( 'donations.' . $action );
}

function metis_donations_can_manage(): bool {
    return metis_donations_can( 'edit' );
}

function metis_donations_can_delete(): bool {
    return metis_donations_can( 'delete' );
}

function metis_donations_can_export(): bool {
    return metis_donations_can( 'export' );
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

function metis_donations_portal_page( string $body, string $title = 'Donor Portal' ): Metis_Http_Response {
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . metis_escape_html( $title ) . '</title><style>body{font-family:system-ui,-apple-system,sans-serif;margin:0;background:#f6f7f9;color:#1f2330}main{max-width:980px;margin:0 auto;padding:34px 18px}.panel{background:#fff;border:1px solid #dde1e8;border-radius:8px;padding:22px;margin-bottom:18px}.muted{color:#687083}label{display:block;font-weight:700;margin-bottom:8px}input{width:100%;box-sizing:border-box;border:1px solid #cfd5df;border-radius:6px;padding:11px 12px;font:inherit}button,.btn{display:inline-block;border:0;border-radius:6px;padding:10px 14px;background:#1f4fd8;color:#fff;text-decoration:none;font-weight:700}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e5e8ef;text-align:left;vertical-align:top;overflow-wrap:anywhere}.actions{margin-top:14px}.danger{background:#a31f34}</style></head><body><main>' . $body . '</main></body></html>';
    return Metis_Http_Response::html( $html, 200 );
}

function metis_donations_handle_donor_portal_route( Metis_Http_Request $request ): Metis_Http_Response {
    \Metis\Modules\Donations\RecurringDonationsService::ensureSchema();
    $message = '';
    if ( strtoupper( $request->method() ) === 'POST' ) {
        $input = $request->parsed_body();
        $email = is_array( $input ) ? (string) ( $input['email'] ?? '' ) : '';
        $result = \Metis\Modules\Donations\RecurringDonationsService::requestPortalAccess( $email );
        $message = '<p class="muted">' . metis_escape_html( (string) ( $result['message'] ?? 'If that email is connected to donor records, an access link will be sent.' ) ) . '</p>';
    }
    $action = metis_escape_url( metis_home_url( '/donor/' ) );
    $body = '<section class="panel"><h1>Donor Portal</h1><p class="muted">Enter the email used for your donations. Metis will send a secure access link; no public account is required.</p>' . $message . '<form method="post" action="' . $action . '"><label for="donor-email">Email address</label><input id="donor-email" name="email" type="email" autocomplete="email" required><div class="actions"><button type="submit">Send Access Link</button></div></form></section>';
    return metis_donations_portal_page( $body );
}

function metis_donations_handle_donor_portal_access_route( Metis_Http_Request $request ): Metis_Http_Response {
    $token = trim( (string) $request->attribute( 'donor_token', '' ) );
    $data = \Metis\Modules\Donations\RecurringDonationsService::consumePortalToken( $token );
    if ( ! is_array( $data ) ) {
        return metis_donations_portal_page( '<section class="panel"><h1>Access Link Expired</h1><p class="muted">This donor portal link is invalid or expired. Request a new link from <a href="' . metis_escape_url( metis_home_url( '/donor/' ) ) . '">the donor portal</a>.</p></section>', 'Donor Portal Access' );
    }

    $txRows = '';
    foreach ( (array) ( $data['transactions'] ?? [] ) as $row ) {
        $txRows .= '<tr><td>' . metis_escape_html( (string) ( $row['tran_date'] ?? '' ) ) . '</td><td>' . metis_escape_html( (string) ( $row['tid'] ?? '' ) ) . '</td><td>$' . metis_escape_html( number_format( (float) ( $row['amount'] ?? 0 ), 2 ) ) . '</td><td>' . metis_escape_html( (string) ( $row['status'] ?? '' ) ) . '</td></tr>';
    }
    if ( $txRows === '' ) {
        $txRows = '<tr><td colspan="4">No donation history was found for this email yet.</td></tr>';
    }

    $recurringRows = '';
    foreach ( (array) ( $data['recurring'] ?? [] ) as $plan ) {
        $manage = metis_home_url( '/donor/recurring/' . rawurlencode( (string) ( $plan['self_manage_token'] ?? '' ) ) . '/' );
        $recurringRows .= '<tr><td>' . metis_escape_html( (string) ( $plan['campaign_code'] ?? '' ) ) . '</td><td>$' . metis_escape_html( number_format( (float) ( $plan['amount'] ?? 0 ), 2 ) ) . '</td><td>' . metis_escape_html( (string) ( $plan['frequency'] ?? '' ) ) . '</td><td>' . metis_escape_html( (string) ( $plan['status'] ?? '' ) ) . '</td><td><a class="btn" href="' . metis_escape_url( $manage ) . '">Manage</a></td></tr>';
    }
    if ( $recurringRows === '' ) {
        $recurringRows = '<tr><td colspan="5">No recurring donations were found for this email.</td></tr>';
    }

    $email = metis_escape_html( (string) ( $data['email'] ?? '' ) );
    $body = '<section class="panel"><h1>Donor Portal</h1><p class="muted">Signed in as ' . $email . '</p></section><section class="panel"><h2>Recurring Donations</h2><table><thead><tr><th>Campaign</th><th>Amount</th><th>Frequency</th><th>Status</th><th>Actions</th></tr></thead><tbody>' . $recurringRows . '</tbody></table></section><section class="panel"><h2>Donation History</h2><table><thead><tr><th>Date</th><th>Receipt</th><th>Amount</th><th>Status</th></tr></thead><tbody>' . $txRows . '</tbody></table></section>';
    return metis_donations_portal_page( $body );
}

function metis_donations_handle_recurring_public_route( Metis_Http_Request $request ): Metis_Http_Response {
    $token = trim( (string) $request->attribute( 'recurring_token', '' ) );
    $plan = \Metis\Modules\Donations\RecurringDonationsService::getPlanByToken( $token );
    if ( ! is_array( $plan ) ) {
        return Metis_Http_Response::html( '<!doctype html><html><head><meta charset="utf-8"><title>Recurring Donation Not Found</title></head><body><main><h1>Recurring Donation Not Found</h1><p>This management link is invalid or expired.</p></main></body></html>', 404 );
    }

    if ( strtoupper( $request->method() ) === 'POST' ) {
        $input = $request->parsed_body();
        $action = metis_key_clean( (string) ( is_array( $input ) ? ( $input['action'] ?? '' ) : '' ) );
        if ( $action === 'cancel' ) {
            \Metis\Modules\Donations\RecurringDonationsService::updateStatus( (int) $plan['id'], 'cancelled' );
            $plan['status'] = 'cancelled';
        } elseif ( $action === 'pause' ) {
            \Metis\Modules\Donations\RecurringDonationsService::updateStatus( (int) $plan['id'], 'paused' );
            $plan['status'] = 'paused';
        } elseif ( $action === 'resume' ) {
            \Metis\Modules\Donations\RecurringDonationsService::updateStatus( (int) $plan['id'], 'active' );
            $plan['status'] = 'active';
        }
    }

    $year = (int) gmdate( 'Y' );
    $history = \Metis\Modules\Donations\RecurringDonationsService::donorHistoryForPlan( $plan, $year );
    $total = array_sum( array_map( static fn ( array $row ): float => strtolower( (string) ( $row['status'] ?? '' ) ) === 'completed' ? (float) ( $row['amount'] ?? 0 ) : 0.0, $history ) );
    $action = metis_escape_url( \metis_home_url( '/donor/recurring/' . rawurlencode( $token ) . '/' ) );
    $status = metis_escape_html( ucfirst( (string) ( $plan['status'] ?? '' ) ) );

    $rows = '';
    foreach ( $history as $row ) {
        $rows .= '<tr><td>' . metis_escape_html( (string) ( $row['tran_date'] ?? '' ) ) . '</td><td>' . metis_escape_html( (string) ( $row['tid'] ?? '' ) ) . '</td><td>$' . metis_escape_html( number_format( (float) ( $row['amount'] ?? 0 ), 2 ) ) . '</td><td>' . metis_escape_html( (string) ( $row['status'] ?? '' ) ) . '</td></tr>';
    }
    if ( $rows === '' ) {
        $rows = '<tr><td colspan="4">No donations found for this year yet.</td></tr>';
    }

    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Manage Recurring Donation</title><style>body{font-family:system-ui,-apple-system,sans-serif;margin:0;background:#f6f7f9;color:#1f2330}main{max-width:920px;margin:0 auto;padding:32px 18px}.panel{background:#fff;border:1px solid #dde1e8;border-radius:8px;padding:22px;margin-bottom:18px}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e5e8ef;text-align:left}button{border:0;border-radius:6px;padding:9px 13px;margin-right:8px;background:#1f4fd8;color:#fff}.danger{background:#a31f34}.muted{color:#687083}</style></head><body><main>';
    $html .= '<section class="panel"><h1>Manage Recurring Donation</h1><p class="muted">Status: ' . $status . '</p><p><strong>$' . metis_escape_html( number_format( (float) $plan['amount'], 2 ) ) . '</strong> ' . metis_escape_html( (string) $plan['frequency'] ) . '</p><p>Next scheduled run: ' . metis_escape_html( (string) $plan['next_run_at'] ) . '</p>';
    $html .= '<form method="post" action="' . $action . '">';
    if ( (string) $plan['status'] === 'active' ) {
        $html .= '<button type="submit" name="action" value="pause">Pause</button>';
    } else {
        $html .= '<button type="submit" name="action" value="resume">Resume</button>';
    }
    $html .= '<button type="submit" class="danger" name="action" value="cancel">Cancel</button></form></section>';
    $html .= '<section class="panel"><h2>' . metis_escape_html( (string) $year ) . ' Giving History</h2><p class="muted">Annual contribution total: $' . metis_escape_html( number_format( $total, 2 ) ) . '</p><table><thead><tr><th>Date</th><th>Receipt</th><th>Amount</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></section>';
    $html .= '</main></body></html>';

    return Metis_Http_Response::html( $html, 200 );
}

if ( class_exists( '\Metis_Cron_Manager' ) ) {
    \Metis_Cron_Manager::register_task(
        'donations_recurring_processor',
        static function (): array {
            if ( ! class_exists( '\Metis\Modules\Donations\RecurringDonationsService' ) ) {
                return [ 'status' => 'skipped', 'message' => 'Recurring donations service is unavailable.' ];
            }
            return \Metis\Modules\Donations\RecurringDonationsService::processDue( false, 25 );
        },
        [
            'label' => 'Recurring Donations Processor',
            'interval' => HOUR_IN_SECONDS,
            'lock_ttl' => 30 * MINUTE_IN_SECONDS,
            'module' => 'donations',
        ]
    );
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
