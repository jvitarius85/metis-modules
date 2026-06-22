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

function metis_paymethod_badge_with_details( ?string $method, mixed $transaction = null ): string {
    return \Metis\Modules\Donations\DonationsModule::paymethodBadgeWithDetails( $method, $transaction );
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

function metis_update_batch_note( int $note_id, string $batch_code, string $text ): bool|int {
    return \Metis\Modules\Donations\DonationsModule::updateBatchNote( $note_id, $batch_code, $text );
}

function metis_delete_batch_note( int $note_id, string $batch_code ): bool|int {
    return \Metis\Modules\Donations\DonationsModule::deleteBatchNote( $note_id, $batch_code );
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

function metis_donations_portal_styles(): string {
    return '<style>.metis-donor-public{--ink:#172033;--muted:#606b80;--line:#dfe5f1;--soft:#f6f8fc;--brand:#2754d8;color:var(--ink);font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.metis-donor-public *{box-sizing:border-box}.metis-donor-shell{width:min(1120px,calc(100% - 32px));margin:0 auto;padding:36px 0 54px}.metis-donor-hero{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:20px;align-items:end;padding:0 0 22px}.metis-donor-kicker{margin:0 0 8px;color:#31449d;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.12em}.metis-donor-logo{margin:0 0 22px}.metis-donor-logo img{display:block;max-width:240px;max-height:92px;width:auto;height:auto;object-fit:contain}.metis-donor-title{margin:0;font-size:clamp(32px,4vw,52px);line-height:1.03;letter-spacing:0}.metis-donor-copy{margin:12px 0 0;color:var(--muted);font-size:17px;line-height:1.58}.metis-donor-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:18px}.metis-donor-panel{grid-column:span 12;background:#fff;border:1px solid var(--line);border-radius:8px;padding:22px;box-shadow:0 1px 2px rgba(12,22,44,.04)}.metis-donor-panel.half{grid-column:span 6}.metis-donor-panel.third{grid-column:span 4}.metis-donor-panel h2{margin:0 0 16px;font-size:23px;line-height:1.2}.metis-donor-stat{display:grid;gap:6px}.metis-donor-stat span{color:var(--muted);font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.1em}.metis-donor-stat strong{font-size:28px;line-height:1.1}.metis-donor-form{display:grid;gap:14px}.metis-donor-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.metis-donor-field{display:grid;gap:7px}.metis-donor-field.full{grid-column:1/-1}.metis-donor-field label{font-weight:800}.metis-donor-field input,.metis-donor-field select,.metis-donor-field textarea{width:100%;min-width:0;border:1px solid #cfd6e3;border-radius:6px;padding:11px 12px;background:#fff;color:var(--ink);font:inherit}.metis-donor-field textarea{min-height:108px;resize:vertical}.metis-donor-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.metis-donor-btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 15px;border-radius:6px;border:1px solid var(--brand);background:var(--brand);color:#fff;text-decoration:none;font-weight:800;cursor:pointer}.metis-donor-btn.secondary{background:#fff;color:var(--brand)}.metis-donor-btn.danger{border-color:#a31f34;background:#a31f34}.metis-donor-message{margin:0 0 16px;padding:12px 14px;border:1px solid #bfd1ff;border-radius:8px;background:#f3f6ff;color:#253c8f}.metis-donor-message.error{border-color:#fac6cc;background:#fff5f6;color:#8c1b2b}.metis-donor-table-wrap{width:100%;overflow-x:auto;border:1px solid var(--line);border-radius:8px}.metis-donor-table{width:100%;border-collapse:collapse;table-layout:auto}.metis-donor-table th,.metis-donor-table td{padding:12px 14px;border-bottom:1px solid #e8ecf4;text-align:left;vertical-align:top;overflow-wrap:anywhere;word-break:normal}.metis-donor-table th{background:#eef1ff;color:#243d9d;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.08em}.metis-donor-table tr:last-child td{border-bottom:0}.metis-donor-muted{color:var(--muted)}.metis-donor-note{font-size:13px;color:var(--muted);line-height:1.5}.metis-donor-profile-line{display:flex;gap:10px;flex-wrap:wrap;color:var(--muted)}.metis-donor-layout{display:grid;grid-template-columns:260px minmax(0,1fr);gap:22px;align-items:start}.metis-donor-sidebar{position:sticky;top:22px;background:#fff;border:1px solid var(--line);border-radius:12px;padding:22px;box-shadow:0 1px 2px rgba(12,22,44,.04)}.metis-donor-sidebar-title{margin:0 0 20px;color:#6f788b;font-size:13px;font-weight:900;text-transform:uppercase;letter-spacing:.12em}.metis-donor-nav{display:grid;gap:8px}.metis-donor-nav a{display:block;border-radius:8px;padding:12px 14px;color:#6f788b;text-decoration:none;font-weight:800}.metis-donor-nav a:hover,.metis-donor-nav a:focus{background:#f1f3f9;color:#172033;outline:0}.metis-donor-nav a.is-active{background:#f1f3f9;color:#172033}.metis-donor-content{display:grid;gap:18px;min-width:0}.metis-donor-section{display:grid;gap:18px;scroll-margin-top:24px}.metis-donor-section-head{display:grid;gap:4px;margin:0 0 -2px}.metis-donor-section-head h2{margin:0;font-size:28px;line-height:1.15}.metis-donor-section-head p{margin:0;color:var(--muted);font-size:15px;line-height:1.5}.metis-donor-stat-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}@media(max-width:900px){.metis-donor-layout{grid-template-columns:1fr}.metis-donor-sidebar{position:static}.metis-donor-nav{grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.metis-donor-nav a{text-align:center}}@media(max-width:780px){.metis-donor-shell{width:min(100% - 24px,1120px);padding-top:24px}.metis-donor-hero{grid-template-columns:1fr}.metis-donor-panel.half,.metis-donor-panel.third{grid-column:span 12}.metis-donor-fields{grid-template-columns:1fr}.metis-donor-title{font-size:34px}.metis-donor-table{min-width:680px}.metis-donor-stat-row{grid-template-columns:1fr}.metis-donor-nav{grid-template-columns:1fr}.metis-donor-nav a{text-align:left}}</style>';
}

function metis_donations_portal_page( string $body, string $title = 'Manage Profile' ): Metis_Http_Response {
    $content = metis_donations_portal_styles() . '<div class="metis-donor-public"><main class="metis-donor-shell">' . $body . '</main></div>';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . metis_escape_html( $title ) . '</title></head><body>' . $content . '</body></html>';
    return Metis_Http_Response::html( $html, 200 );
}

function metis_donations_portal_datetime( string $value ): string {
    if ( $value === '' ) {
        return '—';
    }
    return function_exists( 'metis_runtime_format_datetime' )
        ? metis_runtime_format_datetime( $value, null, null, null, $value )
        : $value;
}

function metis_donations_portal_date( string $value ): string {
    if ( $value === '' ) {
        return '—';
    }
    return function_exists( 'metis_runtime_format_date' )
        ? metis_runtime_format_date( $value, null, null, null, $value )
        : $value;
}

function metis_donations_portal_logo_html(): string {
    $logo = function_exists( 'metis_portal_logo_url' ) ? trim( (string) metis_portal_logo_url() ) : '';
    if ( $logo === '' ) {
        return '';
    }
    return '<div class="metis-donor-logo"><img src="' . metis_escape_url( $logo ) . '" alt="Mobilize Waco"></div>';
}

function metis_donations_handle_manage_profile_route( Metis_Http_Request $request ): Metis_Http_Response {
    \Metis\Modules\Donations\RecurringDonationsService::ensureSchema();
    $message = '';
    if ( strtoupper( $request->method() ) === 'POST' ) {
        $input = $request->parsed_body();
        $email = is_array( $input ) ? (string) ( $input['email'] ?? '' ) : '';
        $result = \Metis\Modules\Donations\RecurringDonationsService::requestPortalAccess( $email );
        $class = empty( $result['ok'] ) ? 'metis-donor-message error' : 'metis-donor-message';
        $message = '<p class="' . $class . '">' . metis_escape_html( (string) ( $result['message'] ?? 'If that email is connected to donor records, an access link will be sent.' ) ) . '</p>';
    }
    $action = metis_escape_url( metis_home_url( '/manage/' ) );
    $body = '<section class="metis-donor-hero"><div><p class="metis-donor-kicker">Secure Access</p><h1 class="metis-donor-title">Manage Profile</h1><p class="metis-donor-copy">Enter the email connected to your Metis profile. We will send a secure access link that expires in 15 minutes. No public account is required.</p></div></section>';
    $body .= '<section class="metis-donor-panel"><form class="metis-donor-form" method="post" action="' . $action . '">' . $message . '<div class="metis-donor-field"><label for="donor-email">Email address</label><input id="donor-email" name="email" type="email" autocomplete="email" required></div><div class="metis-donor-actions"><button class="metis-donor-btn" type="submit">Send Access Link</button></div></form></section>';
    return metis_donations_portal_page( $body );
}

function metis_donations_handle_manage_access_route( Metis_Http_Request $request ): Metis_Http_Response {
    $token = trim( (string) $request->attribute( 'donor_token', '' ) );
    $data = \Metis\Modules\Donations\RecurringDonationsService::consumePortalToken( $token );
    if ( ! is_array( $data ) ) {
        return metis_donations_portal_page( '<section class="metis-donor-panel"><h1>Access Link Expired</h1><p class="metis-donor-copy">This manage profile link is invalid or expired. Request a new link from <a href="' . metis_escape_url( metis_home_url( '/manage/' ) ) . '">manage profile</a>.</p></section>', 'Manage Profile Access' );
    }

    $input = $request->parsed_body();
    $notice = '';
    if ( strtoupper( $request->method() ) === 'POST' && is_array( $input ) ) {
        $action = metis_key_clean( (string) ( $input['portal_action'] ?? '' ) );
        if ( $action === 'update_profile' ) {
            $result = \Metis\Modules\Donations\RecurringDonationsService::updateDonorProfile( (string) ( $data['email'] ?? '' ), $input );
            $notice = '<p class="' . ( empty( $result['ok'] ) ? 'metis-donor-message error' : 'metis-donor-message' ) . '">' . metis_escape_html( (string) ( $result['message'] ?? '' ) ) . '</p>';
            $data = \Metis\Modules\Donations\RecurringDonationsService::consumePortalToken( $token ) ?: $data;
        } elseif ( $action === 'send_inquiry' ) {
            $result = \Metis\Modules\Donations\RecurringDonationsService::sendDonorInquiry( (string) ( $data['email'] ?? '' ), (string) ( $input['message'] ?? '' ), (string) ( $input['tid'] ?? '' ) );
            $notice = '<p class="' . ( empty( $result['ok'] ) ? 'metis-donor-message error' : 'metis-donor-message' ) . '">' . metis_escape_html( (string) ( $result['message'] ?? '' ) ) . '</p>';
        } elseif ( $action === 'newsletter_toggle' ) {
            $result = \Metis\Modules\Donations\RecurringDonationsService::toggleNewsletterSubscription( (string) ( $data['email'] ?? '' ), (int) ( $input['list_id'] ?? 0 ) );
            $notice = '<p class="' . ( empty( $result['ok'] ) ? 'metis-donor-message error' : 'metis-donor-message' ) . '">' . metis_escape_html( (string) ( $result['message'] ?? '' ) ) . '</p>';
            $data = \Metis\Modules\Donations\RecurringDonationsService::consumePortalToken( $token ) ?: $data;
        }
    }

    $transactions = (array) ( $data['transactions'] ?? [] );
    $completedTotal = 0.0;
    foreach ( $transactions as $row ) {
        if ( strtolower( (string) ( $row['status'] ?? '' ) ) === 'completed' ) {
            $completedTotal += (float) ( $row['amount'] ?? 0 );
        }
    }

    $txRows = '';
    foreach ( $transactions as $row ) {
        $tid = (string) ( $row['tid'] ?? '' );
        $questionForm = '<details class="metis-donor-row-question"><summary>Ask a question</summary><form class="metis-donor-form" method="post" action="' . metis_escape_url( metis_home_url( '/manage/access/' . rawurlencode( $token ) . '/' ) ) . '"><input type="hidden" name="portal_action" value="send_inquiry"><input type="hidden" name="tid" value="' . metis_escape_attr( $tid ) . '"><div class="metis-donor-field"><label for="message-' . metis_escape_attr( $tid ) . '">Question about this donation</label><textarea id="message-' . metis_escape_attr( $tid ) . '" name="message" required></textarea></div><div class="metis-donor-actions"><button class="metis-donor-btn" type="submit">Send Question</button></div></form></details>';
        $txRows .= '<tr><td>' . metis_escape_html( metis_donations_portal_datetime( (string) ( $row['tran_date'] ?? '' ) ) ) . '</td><td>' . metis_escape_html( $tid ) . '</td><td>$' . metis_escape_html( number_format( (float) ( $row['amount'] ?? 0 ), 2 ) ) . '</td><td>' . metis_escape_html( (string) ( $row['status'] ?? '' ) ) . '</td><td>' . $questionForm . '</td></tr>';
    }
    if ( $txRows === '' ) {
        $txRows = '<tr><td colspan="5">No donation history was found for this email yet.</td></tr>';
    }

    $recurringRows = '';
    foreach ( (array) ( $data['recurring'] ?? [] ) as $plan ) {
        $manage = metis_home_url( '/manage/recurring/' . rawurlencode( (string) ( $plan['self_manage_token'] ?? '' ) ) . '/' );
        $recurringRows .= '<tr><td>' . metis_escape_html( (string) ( $plan['campaign_code'] ?? '' ) ) . '</td><td>$' . metis_escape_html( number_format( (float) ( $plan['amount'] ?? 0 ), 2 ) ) . '</td><td>' . metis_escape_html( (string) ( $plan['frequency'] ?? '' ) ) . '</td><td>' . metis_escape_html( metis_donations_portal_datetime( (string) ( $plan['next_run_at'] ?? '' ) ) ) . '</td><td>' . metis_escape_html( (string) ( $plan['status'] ?? '' ) ) . '</td><td><a class="metis-donor-btn secondary" href="' . metis_escape_url( $manage ) . '">Manage</a></td></tr>';
    }
    if ( $recurringRows === '' ) {
        $recurringRows = '<tr><td colspan="6">No recurring donations were found for this email.</td></tr>';
    }

    $contact = is_array( $data['contact'] ?? null ) ? $data['contact'] : [];
    $detail = is_array( $data['detail'] ?? null ) ? $data['detail'] : [];
    $email = (string) ( $data['email'] ?? '' );
    $name = trim( (string) ( $contact['first_name'] ?? '' ) . ' ' . (string) ( $contact['last_name'] ?? '' ) );
    if ( $name === '' ) { $name = $email; }
    $years = (array) ( $data['statement_years'] ?? [ gmdate( 'Y' ) ] );
    $statementOptions = '';
    foreach ( $years as $year ) {
        $year = preg_match( '/^\d{4}$/', (string) $year ) ? (string) $year : gmdate( 'Y' );
        $statementOptions .= '<option value="' . metis_escape_attr( $year ) . '">' . metis_escape_html( $year ) . '</option>';
    }
    $statementBase = metis_escape_url( metis_home_url( '/manage/access/' . rawurlencode( $token ) . '/statement/' ) );

    $body = '<section class="metis-donor-hero"><div><p class="metis-donor-kicker">Manage Profile</p><h1 class="metis-donor-title">Welcome, ' . metis_escape_html( $name ) . '</h1><p class="metis-donor-copy">Manage your profile, donations, recurring giving, contribution statements, and newsletter subscriptions.</p></div></section>' . $notice;
    $body .= '<section class="metis-donor-layout"><aside class="metis-donor-sidebar" aria-label="Manage profile sections"><p class="metis-donor-sidebar-title">Manage</p><nav class="metis-donor-nav"><a class="is-active" href="#profile">Profile</a><a href="#donations">Donations</a><a href="#newsletter">Newsletter</a></nav></aside><div class="metis-donor-content">';
    $body .= '<section class="metis-donor-section" id="profile"><div class="metis-donor-section-head"><h2>Profile</h2><p>Review your account details and update the contact information connected to your profile.</p></div><div class="metis-donor-panel"><div class="metis-donor-stat"><span>Signed In</span><strong>' . metis_escape_html( $email ) . '</strong></div></div>';
    $body .= '<div class="metis-donor-panel"><h2>Profile Details</h2><form class="metis-donor-form" method="post" action="' . metis_escape_url( metis_home_url( '/manage/access/' . rawurlencode( $token ) . '/' ) ) . '"><input type="hidden" name="portal_action" value="update_profile"><div class="metis-donor-fields"><div class="metis-donor-field"><label for="first-name">First name</label><input id="first-name" name="first_name" value="' . metis_escape_attr( (string) ( $contact['first_name'] ?? '' ) ) . '"></div><div class="metis-donor-field"><label for="last-name">Last name</label><input id="last-name" name="last_name" value="' . metis_escape_attr( (string) ( $contact['last_name'] ?? '' ) ) . '"></div><div class="metis-donor-field"><label for="phone">Phone</label><input id="phone" name="phone" value="' . metis_escape_attr( (string) ( $detail['phone'] ?? '' ) ) . '"></div><div class="metis-donor-field"><label for="zip">ZIP</label><input id="zip" name="zip" value="' . metis_escape_attr( (string) ( $detail['zip'] ?? '' ) ) . '"></div><div class="metis-donor-field full"><label for="address">Address</label><input id="address" name="address" value="' . metis_escape_attr( (string) ( $detail['address'] ?? '' ) ) . '"></div><div class="metis-donor-field"><label for="city">City</label><input id="city" name="city" value="' . metis_escape_attr( (string) ( $detail['city'] ?? '' ) ) . '"></div><div class="metis-donor-field"><label for="state">State</label><input id="state" name="state" value="' . metis_escape_attr( (string) ( $detail['state'] ?? '' ) ) . '"></div></div><div class="metis-donor-actions"><button class="metis-donor-btn" type="submit">Update Profile</button></div></form></div></section>';
    $body .= '<section class="metis-donor-section" id="donations"><div class="metis-donor-section-head"><h2>Donations</h2><p>View giving history, manage recurring donations, and download annual contribution statements.</p></div><div class="metis-donor-stat-row"><div class="metis-donor-panel"><div class="metis-donor-stat"><span>Completed Giving</span><strong>$' . metis_escape_html( number_format( $completedTotal, 2 ) ) . '</strong></div></div><div class="metis-donor-panel"><div class="metis-donor-stat"><span>History Count</span><strong>' . metis_escape_html( (string) count( $transactions ) ) . '</strong></div></div></div>';
    $body .= '<div class="metis-donor-panel"><h2>Recurring Donations</h2><div class="metis-donor-table-wrap"><table class="metis-donor-table"><thead><tr><th>Campaign</th><th>Amount</th><th>Frequency</th><th>Next Run</th><th>Status</th><th>Actions</th></tr></thead><tbody>' . $recurringRows . '</tbody></table></div></div>';
    $body .= '<div class="metis-donor-panel"><h2>Contribution Statement</h2><p class="metis-donor-muted">Choose a year and download a generated statement for completed donations.</p><form class="metis-donor-form" method="get" onsubmit="var y=this.elements.statement_year.value; if(y){ window.location.href=\'' . $statementBase . '\' + encodeURIComponent(y) + \'/\'; } return false;"><div class="metis-donor-field"><label for="statement-year">Year</label><select id="statement-year" name="statement_year">' . $statementOptions . '</select></div><div class="metis-donor-actions"><button class="metis-donor-btn" type="submit">Download Statement</button></div></form></div>';
    $body .= '<div class="metis-donor-panel"><h2>Donation History</h2><div class="metis-donor-table-wrap"><table class="metis-donor-table"><thead><tr><th>Date</th><th>Receipt</th><th>Amount</th><th>Status</th><th>Question</th></tr></thead><tbody>' . $txRows . '</tbody></table></div></div></section>';
    $newsletterRows = '';
    foreach ( (array) ( $data['newsletter'] ?? [] ) as $subscription ) {
        $listId = (int) ( $subscription['id'] ?? 0 );
        $status = (string) ( $subscription['status'] ?? 'unsubscribed' );
        $newsletterRows .= '<tr><td>' . metis_escape_html( (string) ( $subscription['name'] ?? 'Newsletter' ) ) . '</td><td>' . metis_escape_html( ucfirst( $status ) ) . '</td><td><form method="post" action="' . metis_escape_url( metis_home_url( '/manage/access/' . rawurlencode( $token ) . '/' ) ) . '"><input type="hidden" name="portal_action" value="newsletter_toggle"><input type="hidden" name="list_id" value="' . metis_escape_attr( (string) $listId ) . '"><button class="metis-donor-btn secondary" type="submit">' . metis_escape_html( $status === 'subscribed' ? 'Unsubscribe' : 'Subscribe' ) . '</button></form></td></tr>';
    }
    if ( $newsletterRows === '' ) {
        $newsletterRows = '<tr><td colspan="3">No active newsletter lists are available.</td></tr>';
    }
    $body .= '<section class="metis-donor-section" id="newsletter"><div class="metis-donor-section-head"><h2>Newsletter</h2><p>Manage the newsletter lists connected to your profile.</p></div><div class="metis-donor-panel"><h2>Newsletter Subscriptions</h2><div class="metis-donor-table-wrap"><table class="metis-donor-table"><thead><tr><th>List</th><th>Status</th><th>Action</th></tr></thead><tbody>' . $newsletterRows . '</tbody></table></div></div></section></div></section>';
    return metis_donations_portal_page( $body );
}

function metis_donations_handle_manage_statement_route( Metis_Http_Request $request ): Metis_Http_Response {
    $token = trim( (string) $request->attribute( 'donor_token', '' ) );
    $year = (int) $request->attribute( 'statement_year', gmdate( 'Y' ) );
    $data = \Metis\Modules\Donations\RecurringDonationsService::consumePortalToken( $token );
    if ( ! is_array( $data ) ) {
        return metis_donations_portal_page( '<section class="metis-donor-panel"><h1>Access Link Expired</h1><p class="metis-donor-copy">This statement link is invalid or expired. Request a new link from <a href="' . metis_escape_url( metis_home_url( '/manage/' ) ) . '">manage profile</a>.</p></section>', 'Contribution Statement' );
    }
    $statement = \Metis\Modules\Donations\RecurringDonationsService::contributionStatementData( (string) ( $data['email'] ?? '' ), $year );
    $contact = (array) ( $statement['contact'] ?? [] );
    $name = trim( (string) ( $contact['first_name'] ?? '' ) . ' ' . (string) ( $contact['last_name'] ?? '' ) );
    if ( $name === '' ) { $name = (string) ( $statement['email'] ?? '' ); }
    $rows = '';
    foreach ( (array) ( $statement['transactions'] ?? [] ) as $row ) {
        $rows .= '<tr><td>' . metis_escape_html( metis_donations_portal_date( (string) ( $row['tran_date'] ?? '' ) ) ) . '</td><td>' . metis_escape_html( (string) ( $row['tid'] ?? '' ) ) . '</td><td>$' . metis_escape_html( number_format( (float) ( $row['amount'] ?? 0 ), 2 ) ) . '</td></tr>';
    }
    if ( $rows === '' ) {
        $rows = '<tr><td colspan="3">No completed contributions were found for this year.</td></tr>';
    }
    $logo = function_exists( 'metis_portal_logo_url' ) ? trim( (string) metis_portal_logo_url() ) : '';
    $logoHtml = $logo !== '' ? '<img src="' . metis_escape_url( $logo ) . '" alt="Mobilize Waco" style="max-width:220px;max-height:90px;width:auto;height:auto;margin-bottom:22px">' : '';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Contribution Statement ' . metis_escape_html( (string) $year ) . '</title><style>body{font-family:DejaVu Sans,Arial,sans-serif;color:#172033;margin:34px;line-height:1.5}h1{margin:0 0 8px;font-size:28px}table{width:100%;border-collapse:collapse;margin-top:24px}th,td{padding:10px;border-bottom:1px solid #dfe5f1;text-align:left}th{background:#eef1ff;color:#243d9d}.total{font-size:20px;font-weight:700;margin-top:22px}.muted{color:#606b80}.meta{margin:18px 0;padding:14px;border:1px solid #dfe5f1;background:#f8fafc}</style></head><body>' . $logoHtml . '<h1>Contribution Statement</h1><p class="muted">Year: ' . metis_escape_html( (string) $year ) . '</p><div class="meta"><strong>Donor:</strong> ' . metis_escape_html( $name ) . '<br><strong>Email:</strong> ' . metis_escape_html( (string) ( $statement['email'] ?? '' ) ) . '</div><table><thead><tr><th>Date</th><th>Receipt</th><th>Amount</th></tr></thead><tbody>' . $rows . '</tbody></table><p class="total">Total completed contributions: $' . metis_escape_html( number_format( (float) ( $statement['total'] ?? 0 ), 2 ) ) . '</p><p class="muted">Generated by Metis on ' . metis_escape_html( metis_donations_portal_datetime( gmdate( 'Y-m-d H:i:s' ) ) ) . '.</p></body></html>';
    if ( ! class_exists( 'Core_PDF_Service' ) ) {
        return Metis_Http_Response::html( '<p>PDF service is unavailable.</p>', 500 );
    }
    $pdf = new Core_PDF_Service( [ 'defaultFont' => 'DejaVu Sans' ] );
    $bytes = $pdf->render_with_footer( $html, 'Mobilize Waco - Contribution Statement', [ 'paper' => 'letter', 'orientation' => 'portrait' ] );
    $filename = 'contribution-statement-' . $year . '.pdf';
    return new Metis_Http_Response( 200, [ 'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . metis_filename_clean( $filename ) . '"', 'Content-Length' => (string) strlen( $bytes ) ], $bytes );
}

function metis_donations_handle_recurring_public_route( Metis_Http_Request $request ): Metis_Http_Response {
    $token = trim( (string) $request->attribute( 'recurring_token', '' ) );
    $plan = \Metis\Modules\Donations\RecurringDonationsService::getPlanByToken( $token );
    if ( ! is_array( $plan ) ) {
        return metis_donations_portal_page( '<section class="metis-donor-panel"><h1>Recurring Donation Not Found</h1><p class="metis-donor-copy">This management link is invalid or expired.</p></section>', 'Recurring Donation Not Found' );
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
    $action = metis_escape_url( \metis_home_url( '/manage/recurring/' . rawurlencode( $token ) . '/' ) );
    $status = metis_escape_html( ucfirst( (string) ( $plan['status'] ?? '' ) ) );

    $rows = '';
    foreach ( $history as $row ) {
        $rows .= '<tr><td>' . metis_escape_html( metis_donations_portal_datetime( (string) ( $row['tran_date'] ?? '' ) ) ) . '</td><td>' . metis_escape_html( (string) ( $row['tid'] ?? '' ) ) . '</td><td>$' . metis_escape_html( number_format( (float) ( $row['amount'] ?? 0 ), 2 ) ) . '</td><td>' . metis_escape_html( (string) ( $row['status'] ?? '' ) ) . '</td></tr>';
    }
    if ( $rows === '' ) {
        $rows = '<tr><td colspan="4">No donations found for this year yet.</td></tr>';
    }

    $body = '<section class="metis-donor-hero"><div>' . metis_donations_portal_logo_html() . '<p class="metis-donor-kicker">Recurring Donation</p><h1 class="metis-donor-title">Manage Recurring Donation</h1><p class="metis-donor-copy">Review this schedule, pause or resume processing, or cancel future payments.</p></div></section>';
    $body .= '<section class="metis-donor-panel"><p class="metis-donor-muted">Status: ' . $status . '</p><p><strong>$' . metis_escape_html( number_format( (float) $plan['amount'], 2 ) ) . '</strong> ' . metis_escape_html( (string) $plan['frequency'] ) . '</p><p>Next scheduled run: ' . metis_escape_html( metis_donations_portal_datetime( (string) $plan['next_run_at'] ) ) . '</p>';
    $body .= '<form class="metis-donor-actions" method="post" action="' . $action . '">';
    if ( (string) $plan['status'] === 'active' ) {
        $body .= '<button class="metis-donor-btn" type="submit" name="action" value="pause">Pause</button>';
    } else {
        $body .= '<button class="metis-donor-btn" type="submit" name="action" value="resume">Resume</button>';
    }
    $body .= '<button class="metis-donor-btn danger" type="submit" name="action" value="cancel">Cancel</button></form></section>';
    $body .= '<section class="metis-donor-panel"><h2>' . metis_escape_html( (string) $year ) . ' Giving History</h2><p class="metis-donor-muted">Annual contribution total: $' . metis_escape_html( number_format( $total, 2 ) ) . '</p><div class="metis-donor-table-wrap"><table class="metis-donor-table"><thead><tr><th>Date</th><th>Receipt</th><th>Amount</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';

    return metis_donations_portal_page( $body, 'Manage Recurring Donation' );
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
    function metis_donations_form_embed_ref_for_campaign( array $campaign ): string {
        if ( ! function_exists( 'metis_forms_find_published_payment_form_ref' ) ) {
            return '';
        }

        $candidate_ids = array_values( array_filter( array_unique( [
            trim( (string) ( $campaign['campaign_uid'] ?? '' ) ),
            trim( (string) ( $campaign['cid'] ?? '' ) ),
            trim( (string) ( $campaign['campaign_code'] ?? '' ) ),
            trim( (string) ( $campaign['code'] ?? '' ) ),
            isset( $campaign['id'] ) ? trim( (string) $campaign['id'] ) : '',
        ] ) ) );
        if ( $candidate_ids === [] ) {
            return '';
        }

        return (string) metis_forms_find_published_payment_form_ref( $candidate_ids );
    }

    /**
     * Render a canonical published Metis form for the donation campaign.
     * Returns empty string when no published form is linked to the campaign.
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
            $campaign = $db->fetchOne(
                "SELECT id, cid, campaign_uid, campaign_code, code, cname, url, public, active FROM {$campaigns_table} WHERE cid = %s LIMIT 1",
                [ $campaign_id ]
            );
            if ( ! is_array( $campaign ) ) {
                $campaign = $db->fetchOne(
                    "SELECT id, cid, campaign_uid, campaign_code, code, cname, url, public, active FROM {$campaigns_table} WHERE campaign_code = %s LIMIT 1",
                    [ $campaign_id ]
                );
            }
            if ( ! is_array( $campaign ) ) {
                $campaign = $db->fetchOne(
                    "SELECT id, cid, campaign_uid, campaign_code, code, cname, url, public, active FROM {$campaigns_table} WHERE code = %s LIMIT 1",
                    [ $campaign_id ]
                );
            }
            if ( ! is_array( $campaign ) && ctype_digit( $campaign_id ) ) {
                $campaign = $db->fetchOne(
                    "SELECT id, cid, campaign_uid, campaign_code, code, cname, url, public, active FROM {$campaigns_table} WHERE id = %d LIMIT 1",
                    [ (int) $campaign_id ]
                );
            }
            $campaign_cache[ $campaign_id ] = $campaign;
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

        $form_ref = metis_donations_form_embed_ref_for_campaign( $campaign );
        if ( $form_ref !== '' && function_exists( 'metis_forms_render_embed' ) ) {
            return (string) metis_forms_render_embed( $form_ref, $options );
        }
        return "";
    }
}
