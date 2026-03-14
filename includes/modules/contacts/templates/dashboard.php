<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! metis_contacts_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view contacts.</div>';
    return;
}

global $wpdb;

$contacts_table = Metis_Tables::get( 'contacts' );
$details_table  = Metis_Tables::get( 'contact_details' );
$transactions_table = Metis_Tables::get( 'transactions' );
$can_manage     = metis_contacts_can_manage();

$rows = [];

if ( metis_contacts_table_exists( $details_table ) && metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
    $rows = $wpdb->get_results( "
        SELECT c.id, c.cid, c.did, c.email, c.first_name, c.last_name, c.created_at, c.updated_at, d.phone
        FROM {$contacts_table} c
        LEFT JOIN {$details_table} d ON d.contact_id = c.id
        ORDER BY c.last_name ASC, c.first_name ASC, c.id ASC
    " );
} else {
    $rows = $wpdb->get_results( "
        SELECT c.id, c.cid, c.did, c.email, c.first_name, c.last_name, c.created_at, c.updated_at, '' AS phone
        FROM {$contacts_table} c
        ORDER BY c.last_name ASC, c.first_name ASC, c.id ASC
    " );
}

$total_contacts = count( $rows );
$with_did       = 0;

foreach ( $rows as $row ) {
    if ( ! empty( $row->did ) ) {
        $with_did++;
    }
}

$without_did = $total_contacts - $with_did;

$duplicate_groups = [];
$duplicate_keys = [];
$dup_buckets = [];
$donation_totals = [];

if ( metis_contacts_table_exists( $transactions_table ) ) {
    $totals_rows = $wpdb->get_results( "
        SELECT did, SUM(amount) AS total_amount
        FROM {$transactions_table}
        WHERE did IS NOT NULL AND did <> ''
        GROUP BY did
    " ) ?: [];
    foreach ( $totals_rows as $tr ) {
        $did_key = (string) ( $tr->did ?? '' );
        if ( $did_key === '' ) continue;
        $donation_totals[ $did_key ] = (float) ( $tr->total_amount ?? 0 );
    }
}

foreach ( $rows as $row ) {
    $email_key = strtolower( trim( (string) ( $row->email ?? '' ) ) );
    if ( $email_key !== '' ) {
        $dup_buckets[ 'email:' . $email_key ][] = $row;
    }

    $name_key = strtolower( trim( (string) $row->first_name . ' ' . (string) $row->last_name ) );
    if ( $name_key !== '' ) {
        $dup_buckets[ 'name:' . $name_key ][] = $row;
    }
}

foreach ( $dup_buckets as $bucket_key => $members ) {
    if ( count( $members ) < 2 ) {
        continue;
    }

    $by_cid = [];
    foreach ( $members as $m ) {
        $m_cid = (string) ( $m->cid ?? '' );
        if ( $m_cid === '' ) {
            continue;
        }
        $by_cid[ $m_cid ] = [
            'cid'        => $m_cid,
            'first_name' => (string) ( $m->first_name ?? '' ),
            'last_name'  => (string) ( $m->last_name ?? '' ),
            'email'      => (string) ( $m->email ?? '' ),
            'did'        => (string) ( $m->did ?? '' ),
            'donation_total' => ! empty( $m->did ) && isset( $donation_totals[ (string) $m->did ] )
                ? (float) $donation_totals[ (string) $m->did ]
                : 0.0,
        ];
    }

    if ( count( $by_cid ) < 2 ) {
        continue;
    }

    $cid_set = array_keys( $by_cid );
    sort( $cid_set );
    $set_key = implode( '|', $cid_set );
    if ( isset( $duplicate_keys[ $set_key ] ) ) {
        continue;
    }
    $duplicate_keys[ $set_key ] = true;

    $members_values = array_values( $by_cid );
    $donor_members = array_values( array_filter( $members_values, static function ( array $member ): bool {
        return (string) ( $member['did'] ?? '' ) !== '';
    } ) );
    $recommended_cid = (string) $members_values[0]['cid'];
    if ( count( $donor_members ) === 1 ) {
        $recommended_cid = (string) $donor_members[0]['cid'];
    } elseif ( count( $donor_members ) > 1 ) {
        usort( $donor_members, static function ( array $a, array $b ): int {
            $a_total = (float) ( $a['donation_total'] ?? 0 );
            $b_total = (float) ( $b['donation_total'] ?? 0 );
            if ( $a_total === $b_total ) {
                return strcmp( (string) ( $a['cid'] ?? '' ), (string) ( $b['cid'] ?? '' ) );
            }
            return $a_total < $b_total ? 1 : -1;
        } );
        $recommended_cid = (string) $donor_members[0]['cid'];
    }

    $duplicate_groups[] = [
        'match'           => strpos( $bucket_key, 'email:' ) === 0 ? 'email' : 'name',
        'recommended_cid' => $recommended_cid,
        'members'         => $members_values,
    ];
}

$duplicate_count = count( $duplicate_groups );
?>

<div class="metis-contacts" data-can-manage="<?php echo esc_attr( $can_manage ? '1' : '0' ); ?>">
    <h1 class="mw-page-title">Contacts</h1>
    <p class="mw-subtitle">Directory of people and organizations across Metis.</p>

    <div class="metis-contacts-stats">
        <div class="metis-contacts-stat">
            <div class="metis-contacts-stat-label">Total Contacts</div>
            <div class="metis-contacts-stat-value"><?php echo esc_html( number_format_i18n( $total_contacts ) ); ?></div>
        </div>
        <div class="metis-contacts-stat">
            <div class="metis-contacts-stat-label">With Donor ID</div>
            <div class="metis-contacts-stat-value"><?php echo esc_html( number_format_i18n( $with_did ) ); ?></div>
        </div>
        <div class="metis-contacts-stat">
            <div class="metis-contacts-stat-label">Without Donor ID</div>
            <div class="metis-contacts-stat-value"><?php echo esc_html( number_format_i18n( $without_did ) ); ?></div>
        </div>
        <div class="metis-contacts-stat">
            <div class="metis-contacts-stat-label">Potential Duplicates</div>
            <div class="metis-contacts-stat-value"><?php echo esc_html( number_format_i18n( $duplicate_count ) ); ?></div>
            <?php if ( $can_manage ) : ?>
                <button type="button" id="metis-review-duplicates-btn" class="mw-btn-xs" style="margin-top:8px;">Review Duplicates</button>
            <?php endif; ?>
        </div>
    </div>

    <div id="metis-contacts-alert" class="mw-alert" style="display:none;"></div>

    <div class="mw-list-layout">

    <!-- Sidebar -->
    <aside class="mw-list-sidebar">
        <div class="mw-list-sidebar-section">
            <div class="mw-list-sidebar-label">Search</div>
            <input id="metis-contacts-search" class="mw-input" type="text" placeholder="Name, email, or DID">
        </div>
        <?php if ( $can_manage ) : ?>
        <div class="mw-list-sidebar-actions">
            <button id="metis-contact-new-btn" type="button" class="mw-btn mw-btn-xs">Add Contact</button>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main content -->
    <div class="mw-list-content">

    <div class="mw-premium-table metis-contacts-table">
        <div class="mw-premium-row mw-premium-header">
            <div class="mw-premium-cell mw-sortable" id="sort-name" data-sort-key="name">Name ▾</div>
            <div class="mw-premium-cell mw-sortable" id="sort-email" data-sort-key="email">Email ▾</div>
            <div class="mw-premium-cell mw-sortable" id="sort-updated" data-sort-key="updated">Updated ▾</div>
        </div>

        <div id="metis-contact-rows">
            <?php if ( ! empty( $rows ) ) : ?>
                <?php foreach ( $rows as $row ) :
                    $full_name = trim( (string) $row->first_name . ' ' . (string) $row->last_name );
                    $full_name = $full_name !== '' ? $full_name : '(No name)';
                    $updated_ts = strtotime( (string) $row->updated_at );
                ?>
                    <div class="mw-premium-row metis-contact-row <?php echo ! empty( $row->did ) ? 'metis-contact-is-donor' : ''; ?>"
                         data-cid="<?php echo esc_attr( (string) $row->cid ); ?>"
                         data-first-name="<?php echo esc_attr( (string) $row->first_name ); ?>"
                         data-last-name="<?php echo esc_attr( (string) $row->last_name ); ?>"
                         data-email="<?php echo esc_attr( (string) $row->email ); ?>"
                         data-phone="<?php echo esc_attr( (string) ( $row->phone ?? '' ) ); ?>"
                         data-did="<?php echo esc_attr( (string) $row->did ); ?>"
                         data-updated-ts="<?php echo esc_attr( $updated_ts ?: 0 ); ?>"
                         data-name-sort="<?php echo esc_attr( strtolower( trim( (string) $row->last_name . ' ' . (string) $row->first_name ) ) ); ?>"
                         data-href="<?php echo esc_url( metis_contacts_detail_url( (string) $row->cid ) ); ?>">

                        <div class="mw-premium-cell metis-contact-name-cell">
                            <div class="metis-contact-name"><?php echo esc_html( $full_name ); ?></div>
                        </div>

                        <div class="mw-premium-cell"><?php echo esc_html( (string) $row->email ); ?></div>
                        <div class="mw-premium-cell"><?php echo esc_html( $updated_ts ? metis_date( 'M j, Y g:i a', $updated_ts ) : '—' ); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <div id="metis-contacts-empty" class="mw-premium-row">
                    <div class="mw-premium-cell mw-muted">No contacts found.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mw-pagination">
        <button id="metis-contacts-prev" type="button" class="mw-btn-xs">Prev</button>
        <span id="metis-contacts-page" class="mw-muted">Page 1 of 1</span>
        <button id="metis-contacts-next" type="button" class="mw-btn-xs">Next</button>
    </div>

    </div><!-- /mw-list-content -->
    </div><!-- /mw-list-layout -->
</div>

<?php if ( $can_manage ) : ?>
<script id="metis-duplicates-json" type="application/json"><?php echo metis_json_encode( $duplicate_groups ); ?></script>

<div id="metis-contacts-modal" class="metis-contacts-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner">
        <h2 id="metis-contacts-modal-title" class="metis-contacts-modal-title">Add Contact</h2>

        <form id="metis-contact-form" class="metis-contact-form">
            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-contact-first-name">First Name</label>
                <input id="metis-contact-first-name" class="mw-input" type="text" maxlength="120" required>
            </div>

            <div class="metis-contact-field metis-contact-field-half">
                <label for="metis-contact-last-name">Last Name</label>
                <input id="metis-contact-last-name" class="mw-input" type="text" maxlength="120" required>
            </div>

            <div class="metis-contact-field metis-contact-field-full">
                <label for="metis-contact-email">Email <span class="metis-required">*</span></label>
                <input id="metis-contact-email" class="mw-input" type="email" maxlength="180" required>
            </div>

            <div class="metis-contact-field metis-contact-field-full">
                <label for="metis-contact-phone">Phone</label>
                <input id="metis-contact-phone" class="mw-input" type="text" maxlength="50" placeholder="Optional">
            </div>

            <div class="metis-contact-actions">
                <button type="button" id="metis-contact-cancel" class="mw-btn mw-btn-ghost">Cancel</button>
                <button type="submit" id="metis-contact-save" class="mw-btn">Save Contact</button>
            </div>
        </form>
    </div>
</div>

<div id="metis-duplicates-modal" class="metis-contacts-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner" style="max-width:960px;">
        <h2 class="metis-contacts-modal-title">Review Duplicates</h2>
        <p class="metis-duplicates-help">Drag rows into <strong>Keep Profile</strong> or <strong>Merge Profiles</strong>. Click <strong>x</strong> on a merge chip to remove it.</p>
        <div id="metis-duplicates-list" class="metis-duplicates-list"></div>
        <div class="metis-contact-actions">
            <button type="button" id="metis-duplicates-run-cleanup" class="mw-btn mw-btn-ghost">Cleanup Merge Notes (One Time)</button>
            <button type="button" id="metis-duplicates-close" class="mw-btn mw-btn-ghost">Close</button>
        </div>
    </div>
</div>

<div id="metis-duplicates-confirm-modal" class="metis-contacts-modal" aria-hidden="true">
    <div class="metis-contacts-modal-inner" style="max-width:720px;">
        <h2 class="metis-contacts-modal-title">Confirm Merge</h2>
        <div id="metis-duplicates-confirm-content" class="metis-duplicates-confirm-content"></div>
        <div class="metis-contact-actions">
            <button type="button" id="metis-duplicates-confirm-cancel" class="mw-btn mw-btn-ghost">Cancel</button>
            <button type="button" id="metis-duplicates-confirm-merge" class="mw-btn">Yes, Merge (Cannot Be Undone)</button>
        </div>
    </div>
</div>
<?php endif; ?>
