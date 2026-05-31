<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_contacts_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view contacts.</div>';
    return;
}

$can_manage     = metis_contacts_can_manage();

$rows = \Metis\Modules\Contacts\ContactReadService::dashboardRows();

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
$donation_totals = \Metis\Modules\Contacts\ContactReadService::donationTotalsByDid();

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

<div class="metis-contacts" data-can-manage="<?php echo metis_escape_attr( $can_manage ? '1' : '0' ); ?>">
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_label( 'Contacts' ) ); ?></h1>
    <p class="metis-subtitle">Directory of people and organizations across Metis.</p>

    <div class="metis-contacts-stats">
        <div class="metis-contacts-stat">
            <div class="metis-contacts-stat-label">Total Contacts</div>
            <div class="metis-contacts-stat-value"><?php echo metis_escape_html( metis_number_format( $total_contacts ) ); ?></div>
        </div>
        <div class="metis-contacts-stat">
            <div class="metis-contacts-stat-label">With Donor ID</div>
            <div class="metis-contacts-stat-value"><?php echo metis_escape_html( metis_number_format( $with_did ) ); ?></div>
        </div>
        <div class="metis-contacts-stat">
            <div class="metis-contacts-stat-label">Without Donor ID</div>
            <div class="metis-contacts-stat-value"><?php echo metis_escape_html( metis_number_format( $without_did ) ); ?></div>
        </div>
        <div class="metis-contacts-stat">
            <div class="metis-contacts-stat-label">Potential Duplicates</div>
            <div class="metis-contacts-stat-value"><?php echo metis_escape_html( metis_number_format( $duplicate_count ) ); ?></div>
            <?php if ( $can_manage ) : ?>
                <button type="button" id="metis-review-duplicates-btn" class="metis-btn-xs" style="margin-top:8px;">Review Duplicates</button>
            <?php endif; ?>
        </div>
    </div>

    <div id="metis-contacts-alert" class="metis-alert" style="display:none;"></div>

    <div class="metis-list-layout">

    <!-- Sidebar -->
    <aside class="metis-list-sidebar">
        <div class="metis-list-sidebar-section">
            <div class="metis-list-sidebar-label">Search</div>
            <input id="metis-contacts-search" class="metis-input" type="text" placeholder="Name, email, or DID">
        </div>
        <?php if ( $can_manage ) : ?>
        <div class="metis-list-sidebar-actions">
            <button id="metis-contact-new-btn" type="button" class="metis-btn metis-btn-xs">Add Contact</button>
            <button id="metis-contact-batch-btn" type="button" class="metis-btn metis-btn-xs">Batch Add</button>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main content -->
    <div class="metis-list-content">

    <?php if ( $can_manage ) : ?>
    <div id="metis-contact-batch-panel" style="display:none; margin-bottom:14px;">
        <div class="mbe-toolbar">
            <button type="button" class="metis-btn metis-btn-xs" data-batch-action="add">Add Row</button>
            <button type="button" class="metis-btn metis-btn-xs" data-batch-action="save">Save Valid Rows</button>
            <button type="button" id="metis-contact-batch-close" class="metis-btn metis-btn-xs metis-btn-ghost">Close</button>
        </div>
        <div id="metis-contact-batch-entry"></div>
    </div>
    <?php endif; ?>

    <table class="metis-premium-table metis-contacts-table">
        <thead>
            <tr class="metis-premium-row metis-premium-header">
                <th class="metis-premium-cell metis-sortable metis-sort-active metis-sort-asc" id="sort-name" scope="col" data-sort-key="name">Name</th>
                <th class="metis-premium-cell metis-sortable" id="sort-email" scope="col" data-sort-key="email">Email</th>
                <th class="metis-premium-cell metis-sortable" id="sort-updated" scope="col" data-sort-key="updated">Updated</th>
            </tr>
        </thead>

        <tbody id="metis-contact-rows">
            <?php if ( ! empty( $rows ) ) : ?>
                <?php foreach ( $rows as $row ) :
                    $full_name = trim( (string) $row->first_name . ' ' . (string) $row->last_name );
                    $full_name = $full_name !== '' ? $full_name : '(No name)';
                    $updated_ts = strtotime( (string) $row->updated_at );
                ?>
                    <tr class="metis-premium-row metis-contact-row <?php echo ! empty( $row->did ) ? 'metis-contact-is-donor' : ''; ?>"
                         data-cid="<?php echo metis_escape_attr( (string) $row->cid ); ?>"
                         data-first-name="<?php echo metis_escape_attr( (string) $row->first_name ); ?>"
                         data-last-name="<?php echo metis_escape_attr( (string) $row->last_name ); ?>"
                         data-email="<?php echo metis_escape_attr( (string) $row->email ); ?>"
                         data-phone="<?php echo metis_escape_attr( (string) ( $row->phone ?? '' ) ); ?>"
                         data-did="<?php echo metis_escape_attr( (string) $row->did ); ?>"
                         data-updated-ts="<?php echo metis_escape_attr( $updated_ts ?: 0 ); ?>"
                         data-name-sort="<?php echo metis_escape_attr( strtolower( trim( (string) $row->last_name . ' ' . (string) $row->first_name ) ) ); ?>"
                         data-href="<?php echo metis_escape_url( metis_contacts_detail_url( (string) $row->cid ) ); ?>">

                        <td class="metis-premium-cell metis-contact-name-cell">
                            <div class="metis-contact-name"><?php echo metis_escape_html( $full_name ); ?></div>
                        </td>

                        <td class="metis-premium-cell"><?php echo metis_escape_html( (string) $row->email ); ?></td>
                        <td class="metis-premium-cell"><?php echo metis_escape_html( $updated_ts ? metis_runtime_format_datetime( (string) $row->updated_at, null, null, null, '—' ) : '—' ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr id="metis-contacts-empty" class="metis-premium-row">
                    <td class="metis-premium-cell metis-muted" colspan="3">No contacts found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="metis-pagination">
        <button id="metis-contacts-prev" type="button" class="metis-btn-xs">Prev</button>
        <span id="metis-contacts-page" class="metis-muted">Page 1 of 1</span>
        <button id="metis-contacts-next" type="button" class="metis-btn-xs">Next</button>
    </div>

    </div><!-- /metis-list-content -->
    </div><!-- /metis-list-layout -->
</div>

<?php if ( $can_manage ) : ?>
<script id="metis-duplicates-json" type="application/json"><?php echo metis_json_encode( $duplicate_groups ); ?></script>

<div id="metis-modal-backdrop" class="metis-modal-backdrop" aria-hidden="true" hidden>
    <div class="metis-modal">
        <h2 id="metis-modal-title" class="metis-modal-title">Add Contact</h2>

        <form id="metis-form-grid" class="metis-form-grid">
            <div class="metis-field metis-field-half">
                <label for="metis-contact-first-name">First Name</label>
                <input id="metis-contact-first-name" class="metis-input" type="text" maxlength="120" required>
            </div>

            <div class="metis-field metis-field-half">
                <label for="metis-contact-last-name">Last Name</label>
                <input id="metis-contact-last-name" class="metis-input" type="text" maxlength="120" required>
            </div>

            <div class="metis-field metis-field-full">
                <label for="metis-contact-email">Email <span class="metis-required">*</span></label>
                <input id="metis-contact-email" class="metis-input" type="email" maxlength="180" required>
            </div>

            <div class="metis-field metis-field-full">
                <label for="metis-contact-phone">Phone</label>
                <input id="metis-contact-phone" class="metis-input" type="text" maxlength="50" placeholder="Optional">
            </div>

            <div class="metis-form-actions">
                <button type="button" id="metis-contact-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
                <button type="submit" id="metis-contact-save" class="metis-btn">Save Contact</button>
            </div>
        </form>
    </div>
</div>

<div id="metis-duplicates-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
    <div class="metis-modal" style="max-width:960px;">
        <h2 class="metis-modal-title">Review Duplicates</h2>
        <p class="metis-duplicates-help">Drag rows into <strong>Keep Profile</strong> or <strong>Merge Profiles</strong>. Click <strong>x</strong> on a merge chip to remove it.</p>
        <div id="metis-duplicates-list" class="metis-duplicates-list"></div>
        <div class="metis-form-actions">
            <button type="button" id="metis-duplicates-run-cleanup" class="metis-btn metis-btn-ghost">Cleanup Merge Notes (One Time)</button>
            <button type="button" id="metis-duplicates-close" class="metis-btn metis-btn-ghost">Close</button>
        </div>
    </div>
</div>

<div id="metis-duplicates-confirm-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
    <div class="metis-modal" style="max-width:720px;">
        <h2 class="metis-modal-title">Confirm Merge</h2>
        <div id="metis-duplicates-confirm-content" class="metis-duplicates-confirm-content"></div>
        <div class="metis-form-actions">
            <button type="button" id="metis-duplicates-confirm-cancel" class="metis-btn metis-btn-ghost">Cancel</button>
            <button type="button" id="metis-duplicates-confirm-merge" class="metis-btn">Yes, Merge (Cannot Be Undone)</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ( $can_manage ) : ?>
<link rel="stylesheet" href="<?php echo metis_escape_url( metis_home_url( '/assets/runtime/batch-entry.css' ) ); ?>">
<script src="<?php echo metis_escape_url( metis_home_url( '/assets/runtime/batch-entry.js' ) ); ?>" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const batchToggle = document.getElementById('metis-contact-batch-btn');
    const batchPanel = document.getElementById('metis-contact-batch-panel');
    const batchClose = document.getElementById('metis-contact-batch-close');
    const batchContainer = document.getElementById('metis-contact-batch-entry');

    if (!batchToggle || !batchPanel || !batchContainer) {
        return;
    }

    let instance = null;
    batchToggle.addEventListener('click', function () {
        batchPanel.style.display = '';
        if (!instance && window.BatchEntry && typeof window.BatchEntry.init === 'function') {
            instance = window.BatchEntry.init({
                module: 'contacts',
                action: 'create',
                container: '#metis-contact-batch-entry',
                fields: [
                    { key: 'first_name', label: 'First Name', type: 'text', required: true },
                    { key: 'last_name', label: 'Last Name', type: 'text', required: true },
                    { key: 'email', label: 'Email', type: 'email', required: true },
                    { key: 'phone', label: 'Phone', type: 'text', required: false }
                ],
                totals: [
                    { key: 'email', type: 'count', label: 'Rows Entered' }
                ],
                allowAddRow: true,
                allowDeleteRow: true,
                autoAppendRow: true,
                saveMode: 'valid_only',
                                endpointBase: '<?php echo esc_js( metis_home_url( '/api/batch' ) ); ?>',
                nonce: '<?php echo esc_js( metis_runtime_create_nonce( 'metis_batch_api' ) ); ?>'
            });
        }
    });

    if (batchClose) {
        batchClose.addEventListener('click', function () {
            batchPanel.style.display = 'none';
        });
    }
});
</script>
<?php endif; ?>
