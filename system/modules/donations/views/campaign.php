<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$db = metis_db();

$campaigns_table    = Metis_Tables::get( 'campaigns' );
$transactions_table = Metis_Tables::get( 'transactions' );
$contacts_table     = Metis_Tables::get( 'contacts' );
$base_url           = metis_donations_base_url();

$cid = metis_donations_request_identifier( 'cid', 'campaign' );

if ( $cid === '' ) : ?>
    <h1 class="metis-page-title">Campaign Not Found</h1>
    <p class="metis-subtitle">No campaign ID was provided.</p>
    <a href="<?php echo metis_escape_url( $base_url . '/campaigns/' ); ?>" class="metis-btn metis-btn-xs">← Back to Campaigns</a>
    <?php return;
endif;

$campaign = $db->fetchOne( "SELECT * FROM {$campaigns_table} WHERE cid = %s LIMIT 1", [ $cid ] );
$campaign = $campaign ? (object) $campaign : null;

// Decode base64 cdesc if it doesn't look like HTML
if ( $campaign && ! empty( $campaign->cdesc ) ) {
    $raw = $campaign->cdesc;
    // If the stored value has no HTML tags, try base64 decode
    if ( strpos( $raw, '<' ) === false ) {
        $decoded = base64_decode( $raw, true );
        if ( $decoded !== false && $decoded !== $raw ) {
            // Persist decoded HTML back to DB
            $db->update(
                $campaigns_table,
                [ 'cdesc' => $decoded ],
                [ 'cid'   => $cid ]
            );
            $campaign->cdesc = $decoded;
        }
    }
}

if ( ! $campaign ) : ?>
    <h1 class="metis-page-title">Campaign Not Found</h1>
    <p class="metis-subtitle">No campaign matched that ID.</p>
    <a href="<?php echo metis_escape_url( $base_url . '/campaigns/' ); ?>" class="metis-btn metis-btn-xs">← Back to Campaigns</a>
    <?php return;
endif;

// -------------------------------------------------------------------------
// Transaction aggregates
// -------------------------------------------------------------------------
$agg = (object) ( $db->fetchOne( "
    SELECT
        COUNT(*)         AS gift_count,
        SUM(amount)      AS total_raised,
        SUM(amount + IFNULL(fee, 0)) AS total_gross,
        MIN(tran_date)   AS first_gift,
        MAX(tran_date)   AS last_gift,
        COUNT(DISTINCT did) AS donor_count
    FROM {$transactions_table}
    WHERE campaign_code = %s
", [ $cid ] ) ?: [] );

// Annual breakdown
$yearly = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll( "
    SELECT
        YEAR(tran_date)  AS year,
        COUNT(*)         AS gift_count,
        SUM(amount)      AS raised,
        COUNT(DISTINCT did) AS donors
    FROM {$transactions_table}
    WHERE campaign_code = %s
    GROUP BY YEAR(tran_date)
    ORDER BY year DESC
", [ $cid ] ) ?: [] );

// Recent transactions
$transactions = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll( "
    SELECT t.*, c.first_name, c.last_name, c.email
    FROM {$transactions_table} t
    LEFT JOIN {$contacts_table} c ON c.did = t.did
    WHERE t.campaign_code = %s
    ORDER BY t.tran_date DESC, t.id DESC
    LIMIT 100
", [ $cid ] ) ?: [] );

// -------------------------------------------------------------------------
// Parse goals
// -------------------------------------------------------------------------
// Use shared metis_parse_goals() from bootstrap, then sort
$goals_raw = metis_parse_goals( $campaign->goals );
krsort( $goals_raw );

$goals        = $goals_raw;
$current_year = (int) date( 'Y' );
$year_goal    = $goals[ $current_year ] ?? null;

// Current-year raised
$year_raised_raw = $db->scalar(
    "SELECT SUM(amount) FROM {$transactions_table} WHERE campaign_code = %s AND YEAR(tran_date) = %d",
    [ $cid, $current_year ]
);
$year_raised = (float) ( $year_raised_raw ?? 0 );
$year_pct    = ( $year_goal && $year_goal > 0 ) ? min( 100, round( ( $year_raised / $year_goal ) * 100, 1 ) ) : null;

$nonce = metis_runtime_create_nonce( 'metis_campaign_edit' );
metis_set_page_title( $campaign->cname );
?>

<p><a href="<?php echo metis_escape_url( $base_url . '/campaigns/' ); ?>" class="metis-btn metis-btn-xs metis-btn-ghost">← All Campaigns</a></p>

<!-- PAGE HEADER -->
<div class="metis-space-between metis-campaign-detail-header">
    <div>
        <h1 class="metis-page-title metis-campaign-title"><?php echo metis_escape_html( $campaign->cname ); ?></h1>
        <div class="metis-flex metis-campaign-header-badges">
            <span class="metis-badge <?php echo strtolower( $campaign->type ?? '' ) === 'ongoing' ? 'blue' : 'muted'; ?>">
                <?php echo metis_escape_html( ucfirst( $campaign->type ?? 'Unknown' ) ); ?>
            </span>
            <span class="metis-badge <?php echo $campaign->active ? 'green' : 'gray'; ?>">
                <?php echo $campaign->active ? 'Active' : 'Inactive'; ?>
            </span>
            <?php if ( $campaign->public ) : ?>
                <span class="metis-badge muted">Public</span>
            <?php endif; ?>
            <span class="metis-muted metis-campaign-cid"><?php echo metis_escape_html( $cid ); ?></span>
        </div>
    </div>
    <div class="metis-flex metis-campaign-header-actions">
        <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-edit-campaign-btn">Edit Campaign</button>
    </div>
</div>

<!-- KPI SUMMARY CARDS -->
<div class="metis-report-kpis metis-campaign-kpis">
    <div class="metis-kpi-card">
        <div class="metis-kpi-value">$<?php echo number_format( (float) ( $agg->total_raised ?? 0 ), 2 ); ?></div>
        <div class="metis-kpi-label">Total Net Raised</div>
    </div>
    <div class="metis-kpi-card">
        <div class="metis-kpi-value"><?php echo number_format( (int) ( $agg->gift_count ?? 0 ) ); ?></div>
        <div class="metis-kpi-label">Total Gifts</div>
    </div>
    <div class="metis-kpi-card">
        <div class="metis-kpi-value"><?php echo number_format( (int) ( $agg->donor_count ?? 0 ) ); ?></div>
        <div class="metis-kpi-label">Unique Donors</div>
    </div>
    <div class="metis-kpi-card">
        <?php
        $avg = $agg->gift_count > 0 ? ( $agg->total_raised / $agg->gift_count ) : 0;
        ?>
        <div class="metis-kpi-value">$<?php echo number_format( $avg, 2 ); ?></div>
        <div class="metis-kpi-label">Avg Gift</div>
    </div>
    <?php if ( $year_goal ) : ?>
    <div class="metis-kpi-card">
        <div class="metis-kpi-value"><?php echo $year_pct; ?>%</div>
        <div class="metis-kpi-label"><?php echo $current_year; ?> Goal Progress</div>
        <div class="metis-progress-bar-wrap metis-campaign-progress-wrap">
            <div class="metis-progress-bar-fill <?php echo $year_pct >= 100 ? 'metis-progress-complete' : ( $year_pct >= 50 ? 'metis-progress-mid' : 'metis-progress-low' ); ?>"
                 style="width: <?php echo $year_pct; ?>%"></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- TWO-COLUMN LAYOUT: Goals + Info -->
<div class="metis-campaign-detail-grid">

    <!-- ANNUAL GOALS -->
    <div class="metis-campaign-detail-card">
        <div class="metis-campaign-card-header">
            <h2 class="metis-section-title metis-campaign-card-title">Annual Goals</h2>
            <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-add-goal-btn">+ Set Goal</button>
        </div>

        <div id="metis-goals-list">
            <?php if ( ! empty( $yearly ) ) : ?>
                <?php foreach ( $yearly as $yr ) :
                    $yr_num    = (int) $yr->year;
                    $yr_raised = (float) $yr->raised;
                    $yr_goal   = $goals[ $yr_num ] ?? null;
                    $yr_pct    = ( $yr_goal && $yr_goal > 0 ) ? min( 100, round( ( $yr_raised / $yr_goal ) * 100, 1 ) ) : null;
                ?>
                    <div class="metis-goal-row" data-year="<?php echo $yr_num; ?>">
                        <div class="metis-goal-year-label">
                            <?php echo $yr_num; ?>
                            <?php if ( $yr_num === $current_year ) echo '<span class="metis-badge blue metis-campaign-current-badge">Current</span>'; ?>
                        </div>
                        <div class="metis-goal-stats">
                            <span class="metis-goal-raised">$<?php echo number_format( $yr_raised, 2 ); ?> raised</span>
                            <span class="metis-muted"> · <?php echo $yr->gift_count; ?> gifts</span>
                            <?php if ( $yr_goal ) : ?>
                                <span class="metis-muted"> · Goal: $<?php echo number_format( $yr_goal, 0 ); ?></span>
                            <?php else : ?>
                                <button type="button" class="metis-goal-set-link" data-year="<?php echo $yr_num; ?>">Set goal</button>
                            <?php endif; ?>
                        </div>
                        <?php if ( $yr_pct !== null ) : ?>
                            <div class="metis-progress-bar-wrap">
                                <div class="metis-progress-bar-fill <?php echo $yr_pct >= 100 ? 'metis-progress-complete' : ( $yr_pct >= 50 ? 'metis-progress-mid' : 'metis-progress-low' ); ?>"
                                     style="width: <?php echo $yr_pct; ?>%"></div>
                            </div>
                            <div class="metis-progress-label"><?php echo $yr_pct; ?>% of goal</div>
                        <?php endif; ?>
                        <?php if ( $yr_goal ) : ?>
                            <button type="button" class="metis-goal-edit-btn metis-muted" data-year="<?php echo $yr_num; ?>" data-amount="<?php echo metis_escape_attr( $yr_goal ); ?>">Edit</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php // Show goals set for years with no transactions yet
                foreach ( $goals as $yr_num => $yr_goal ) :
                    $has_activity = false;
                    foreach ( $yearly as $yr ) { if ( (int) $yr->year === $yr_num ) { $has_activity = true; break; } }
                    if ( $has_activity ) continue;
                ?>
                    <div class="metis-goal-row metis-goal-row--no-activity" data-year="<?php echo $yr_num; ?>">
                        <div class="metis-goal-year-label">
                            <?php echo $yr_num; ?>
                            <?php if ( $yr_num === $current_year ) echo '<span class="metis-badge blue metis-campaign-current-badge">Current</span>'; ?>
                        </div>
                        <div class="metis-goal-stats">
                            <span class="metis-muted">No activity</span>
                            <span class="metis-muted"> · Goal: $<?php echo number_format( $yr_goal, 0 ); ?></span>
                        </div>
                        <div class="metis-progress-bar-wrap">
                            <div class="metis-progress-bar-fill metis-progress-low" style="width: 0%"></div>
                        </div>
                        <div class="metis-progress-label">0% of goal</div>
                        <button type="button" class="metis-goal-edit-btn metis-muted" data-year="<?php echo $yr_num; ?>" data-amount="<?php echo metis_escape_attr( $yr_goal ); ?>">Edit</button>
                    </div>
                <?php endforeach; ?>

            <?php else : ?>
                <p class="metis-muted metis-campaign-empty-history">No giving history yet for this campaign.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- CAMPAIGN INFO -->
    <div class="metis-campaign-detail-card">
        <h2 class="metis-section-title">Campaign Info</h2>
        <div class="metis-campaign-info-grid">
            <div class="metis-info-row">
                <div class="small-label">Campaign ID</div>
                <div><?php echo metis_escape_html( $campaign->cid ); ?></div>
            </div>
            <div class="metis-info-row">
                <div class="small-label">Type</div>
                <div><?php echo metis_escape_html( ucfirst( $campaign->type ?? '—' ) ); ?></div>
            </div>
            <div class="metis-info-row">
                <div class="small-label">Status</div>
                <div><?php echo $campaign->active ? 'Active' : 'Inactive'; ?></div>
            </div>
            <div class="metis-info-row">
                <div class="small-label">Public</div>
                <div><?php echo $campaign->public ? 'Yes' : 'No'; ?></div>
            </div>
            <?php if ( $campaign->url ) : ?>
            <div class="metis-info-row">
                <div class="small-label">Donation URL</div>
                <div>
                    <a href="<?php echo metis_escape_url( 'https://mobilizewaco.org' . $campaign->url ); ?>" target="_blank" class="metis-batch-link metis-campaign-donation-link">
                        mobilizewaco.org<?php echo metis_escape_html( $campaign->url ); ?>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <div class="metis-info-row">
                <div class="small-label">First Gift</div>
                <div><?php echo $agg->first_gift ? metis_escape_html( metis_runtime_format_date( (string) $agg->first_gift, null, null, null, '—' ) ) : '—'; ?></div>
            </div>
            <div class="metis-info-row">
                <div class="small-label">Last Gift</div>
                <div><?php echo $agg->last_gift ? metis_escape_html( metis_runtime_format_date( (string) $agg->last_gift, null, null, null, '—' ) ) : '—'; ?></div>
            </div>
        </div>

        <!-- SHORTCODE -->
        <div class="metis-campaign-shortcode-wrap">
            <div class="small-label metis-campaign-shortcode-label">Shortcode</div>
            <div class="metis-shortcode-wrap">
                <code class="metis-shortcode" id="metis-shortcode-val">[metis_campaign_progress cid="<?php echo metis_escape_attr( $cid ); ?>"]</code>
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-copy-shortcode">Copy</button>
            </div>
            <p class="metis-muted metis-campaign-shortcode-help">
                Embeds a live donation progress bar for this campaign on any page or post.
            </p>
        </div>
    </div>

</div>

<!-- DESCRIPTION -->
<div class="metis-campaign-detail-card metis-campaign-detail-card-spaced">
    <div class="metis-campaign-card-header">
        <h2 class="metis-section-title metis-campaign-card-title">Campaign Description</h2>
        <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-edit-desc-btn">Edit</button>
    </div>
    <div id="metis-desc-display">
        <?php if ( $campaign->cdesc ) : ?>
            <div class="metis-campaign-desc-body"><?php echo metis_runtime_kses_post( $campaign->cdesc ); ?></div>
        <?php else : ?>
            <p class="metis-muted">No description set. Click Edit to add one.</p>
        <?php endif; ?>
    </div>
    <!-- Editor wrapper: hidden until Edit clicked. Quill WYSIWYG. -->
    <div id="metis-desc-editor" class="metis-campaign-desc-editor">
        <div id="metis-quill-editor" class="metis-campaign-quill-host"></div>
        <div class="metis-flex metis-campaign-desc-actions">
            <button type="button" class="metis-btn metis-btn-xs" id="metis-save-desc-btn">Save Description</button>
            <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-cancel-desc-btn">Cancel</button>
        </div>
    </div>
</div>

<!-- RECENT TRANSACTIONS -->
<div class="metis-campaign-detail-card metis-campaign-detail-card-spaced">
    <h2 class="metis-section-title">Recent Transactions</h2>

    <table class="metis-premium-table metis-batch-table metis-campaign-tx-table">
        <thead>
            <tr class="metis-premium-row metis-premium-header metis-batch-row">
                <th class="metis-premium-cell" scope="col">Date</th>
                <th class="metis-premium-cell" scope="col">Donor</th>
                <th class="metis-premium-cell metis-col-numeric" scope="col">Amount</th>
                <th class="metis-premium-cell" scope="col">Status</th>
            </tr>
        </thead>
        <tbody>

        <?php if ( ! empty( $transactions ) ) : ?>
            <?php foreach ( $transactions as $t ) :
                $donor_name  = trim( ( $t->first_name ?: '' ) . ' ' . ( $t->last_name ?: '' ) );
                $tx_url      = metis_donations_detail_url( 'transaction', (string) $t->tid );
                $display_date = $t->tran_date ? date( 'm/d/Y', strtotime( $t->tran_date ) ) : '—';
            ?>
                <tr class="metis-premium-row metis-batch-row metis-clickable-row"
                     data-href="<?php echo metis_escape_url( $tx_url ); ?>">
                    <td class="metis-premium-cell"><?php echo metis_escape_html( $display_date ); ?></td>
                    <td class="metis-premium-cell">
                        <div><?php echo metis_escape_html( $donor_name ?: 'Unknown' ); ?></div>
                        <div class="metis-muted metis-campaign-tx-did"><?php echo metis_escape_html( $t->did ?: '' ); ?></div>
                    </td>
                    <td class="metis-premium-cell metis-col-numeric">$<?php echo number_format( (float) $t->amount, 2 ); ?></td>
                    <td class="metis-premium-cell metis-tx-flags">
                        <?php echo metis_status_badge( $t->status ); ?>
                        <?php echo metis_deposit_badge( $t->deposit_date ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else : ?>
            <tr class="metis-premium-row">
                <td class="metis-premium-cell metis-muted" colspan="4">No transactions found for this campaign.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- GOAL MODAL -->
<div id="metis-goal-modal" class="metis-modal-overlay metis-campaign-modal-hidden">
    <div class="metis-modal">
        <div class="metis-modal-header">
            <h3 id="metis-goal-modal-title">Set Annual Goal</h3>
            <button type="button" class="metis-modal-close" id="metis-goal-modal-close">×</button>
        </div>
        <div class="metis-modal-body">
            <div class="metis-report-field metis-campaign-goal-field-first">
                <label>Year</label>
                <input type="number" id="metis-goal-year" class="metis-input" value="<?php echo $current_year; ?>" min="2015" max="2099">
            </div>
            <div class="metis-report-field">
                <label>Goal Amount ($)</label>
                <input type="number" id="metis-goal-amount" class="metis-input" placeholder="e.g. 10000" step="0.01" min="0">
            </div>
        </div>
        <div class="metis-modal-footer">
            <button type="button" class="metis-btn metis-btn-xs" id="metis-goal-save-btn">Save Goal</button>
            <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-goal-modal-cancel">Cancel</button>
            <button type="button" class="metis-btn metis-btn-xs metis-btn-danger metis-campaign-goal-delete" id="metis-goal-delete-btn">Remove Goal</button>
        </div>
        <div id="metis-goal-status" class="metis-report-status metis-campaign-status"></div>
    </div>
</div>

<!-- EDIT CAMPAIGN MODAL -->
<div id="metis-edit-campaign-modal" class="metis-modal-overlay metis-campaign-modal-hidden">
    <div class="metis-modal metis-modal--wide">
        <div class="metis-modal-header">
            <h3>Edit Campaign</h3>
            <button type="button" class="metis-modal-close" id="metis-edit-modal-close">×</button>
        </div>
        <div class="metis-modal-body">
            <div class="metis-edit-form-grid">
                <div class="metis-report-field">
                    <label>Campaign Name</label>
                    <input type="text" id="metis-edit-cname" class="metis-input" value="<?php echo metis_escape_attr( $campaign->cname ); ?>">
                </div>
                <div class="metis-report-field">
                    <label>Type</label>
                    <select id="metis-edit-type" class="metis-select">
                        <option value="Ongoing" <?php metis_attr_selected( $campaign->type, 'Ongoing' ); ?>>Ongoing</option>
                        <option value="Project" <?php metis_attr_selected( $campaign->type, 'Project' ); ?>>Project</option>
                    </select>
                </div>
                <div class="metis-report-field">
                    <label>Donation URL (path only)</label>
                    <input type="text" id="metis-edit-url" class="metis-input" value="<?php echo metis_escape_attr( $campaign->url ?? '' ); ?>" placeholder="/join/donate/">
                </div>
                <div class="metis-report-field">
                    <label>Status</label>
                    <select id="metis-edit-active" class="metis-select">
                        <option value="1" <?php metis_attr_selected( (int) $campaign->active, 1 ); ?>>Active</option>
                        <option value="0" <?php metis_attr_selected( (int) $campaign->active, 0 ); ?>>Inactive</option>
                    </select>
                </div>
                <div class="metis-report-field">
                    <label>Public</label>
                    <select id="metis-edit-public" class="metis-select">
                        <option value="1" <?php metis_attr_selected( (int) $campaign->public, 1 ); ?>>Yes</option>
                        <option value="0" <?php metis_attr_selected( (int) $campaign->public, 0 ); ?>>No</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="metis-modal-footer">
            <button type="button" class="metis-btn metis-btn-xs" id="metis-edit-save-btn">Save Changes</button>
            <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-edit-modal-cancel">Cancel</button>
        </div>
        <div id="metis-edit-status" class="metis-report-status metis-campaign-status"></div>
    </div>
</div>

<script>
(function () {
    const notify = (message, type) => Metis.util.notify(message, type || 'info');
    const CID   = <?php echo json_encode( $cid ); ?>;
    const NONCE = <?php echo json_encode( $nonce ); ?>;
    const AJAX  = <?php echo json_encode( metis_ajax_endpoint_url() ); ?>;

    function postPayload(payload) {
        const body = new URLSearchParams(payload);
        const action = body.get('action') || '';
        if (action) {
            body.set('metis_action_nonce', Metis.ajax.nonceFor(action, body.get('metis_action_nonce') || body.get('nonce') || NONCE));
            body.set('nonce', body.get('nonce') || NONCE);
        }
        return fetch(AJAX, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body
        }).then(r => Metis.ajax.parseJson(r));
    }

    // -------------------------------------------------------------------------
    // Row click — transactions
    // -------------------------------------------------------------------------
    document.querySelectorAll('.metis-batch-row[data-href]').forEach(row => {
        row.addEventListener('click', () => {
            if (window.Metis && Metis.navigation && typeof Metis.navigation.go === 'function') {
                Metis.navigation.go(row.dataset.href);
                return;
            }
            window.location.assign(row.dataset.href);
        });
    });

    // -------------------------------------------------------------------------
    // Copy shortcode
    // -------------------------------------------------------------------------
    document.getElementById('metis-copy-shortcode')?.addEventListener('click', function () {
        const val = document.getElementById('metis-shortcode-val')?.textContent || '';
        navigator.clipboard.writeText(val).then(() => {
            this.textContent = 'Copied!';
            setTimeout(() => { this.textContent = 'Copy'; }, 2000);
        });
    });

    // -------------------------------------------------------------------------
    // Description WYSIWYG editor — Quill
    // -------------------------------------------------------------------------
    let quillEditor = null;
    const initialDesc = <?php echo json_encode( $campaign->cdesc ?? '' ); ?>;

    function loadQuill(callback) {
        if (window.Quill) { callback(); return; }
        // Load Quill CSS
        const link = document.createElement('link');
        link.rel  = 'stylesheet';
        link.href = 'https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.min.css';
        document.head.appendChild(link);
        // Load Quill JS
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js';
        script.onload = callback;
        document.head.appendChild(script);
    }

    document.getElementById('metis-edit-desc-btn')?.addEventListener('click', () => {
        document.getElementById('metis-desc-display').style.display = 'none';
        document.getElementById('metis-desc-editor').style.display  = 'block';

        if (quillEditor) return; // already initialized

        loadQuill(() => {
            quillEditor = new Quill('#metis-quill-editor', {
                theme:   'snow',
                modules: {
                    toolbar: [
                        [{ header: [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ color: [] }, { background: [] }],
                        [{ list: 'ordered' }, { list: 'bullet' }, { indent: '-1' }, { indent: '+1' }],
                        [{ align: [] }],
                        ['link', 'blockquote', 'code-block'],
                        ['clean']
                    ]
                }
            });
            // Load existing content as HTML
            if (initialDesc) {
                quillEditor.clipboard.dangerouslyPasteHTML(initialDesc);
            }
            quillEditor.focus();
        });
    });

    document.getElementById('metis-cancel-desc-btn')?.addEventListener('click', () => {
        document.getElementById('metis-desc-editor').style.display  = 'none';
        document.getElementById('metis-desc-display').style.display = 'block';
    });

    document.getElementById('metis-save-desc-btn')?.addEventListener('click', async function () {
        const content = quillEditor ? quillEditor.root.innerHTML : '';

        const btn = this;
        btn.disabled = true; btn.textContent = 'Saving…';
        const data = await postPayload({ action: 'metis_campaign_save_desc', nonce: NONCE, cid: CID, desc: content });
        btn.disabled = false; btn.textContent = 'Save Description';
        if (data.success) {
            const display = document.getElementById('metis-desc-display');
            display.innerHTML = content
                ? `<div class="metis-campaign-desc-body">${content}</div>`
                : `<p class="metis-muted">No description set. Click Edit to add one.</p>`;
            document.getElementById('metis-desc-editor').style.display  = 'none';
            display.style.display = 'block';
        } else {
            notify('Save failed. Please try again.', 'error');
        }
    });

    // -------------------------------------------------------------------------
    // Goal modal helpers
    // -------------------------------------------------------------------------
    const goalModal      = document.getElementById('metis-goal-modal');
    const goalYearInput  = document.getElementById('metis-goal-year');
    const goalAmtInput   = document.getElementById('metis-goal-amount');
    const goalStatus     = document.getElementById('metis-goal-status');
    const goalDeleteBtn  = document.getElementById('metis-goal-delete-btn');
    const goalModalTitle = document.getElementById('metis-goal-modal-title');

    function openGoalModal(year, amount) {
        goalYearInput.value  = year || new Date().getFullYear();
        goalAmtInput.value   = amount || '';
        goalModalTitle.textContent = amount ? 'Edit Annual Goal' : 'Set Annual Goal';
        goalDeleteBtn.style.display = amount ? 'inline-flex' : 'none';
        goalStatus.style.display = 'none';
        goalModal.style.display  = 'flex';
        goalAmtInput.focus();
    }

    document.getElementById('metis-add-goal-btn')?.addEventListener('click', () => openGoalModal(new Date().getFullYear(), null));
    document.getElementById('metis-goal-modal-close')?.addEventListener('click', () => goalModal.style.display = 'none');
    document.getElementById('metis-goal-modal-cancel')?.addEventListener('click', () => goalModal.style.display = 'none');
    goalModal?.addEventListener('click', e => { if (e.target === goalModal) goalModal.style.display = 'none'; });

    document.querySelectorAll('.metis-goal-edit-btn, .metis-goal-set-link').forEach(btn => {
        btn.addEventListener('click', () => openGoalModal(btn.dataset.year, btn.dataset.amount || null));
    });

    async function saveGoal(year, amount) {
        goalStatus.style.display = 'block';
        goalStatus.dataset.type  = 'busy';
        goalStatus.textContent   = 'Saving…';
        const data = await postPayload({ action: 'metis_campaign_save_goal', nonce: NONCE, cid: CID, year, amount });
        if (data.success) {
            goalStatus.dataset.type = 'ok';
            goalStatus.textContent  = 'Goal saved.';
            setTimeout(() => { goalModal.style.display = 'none'; }, 500);
        } else {
            goalStatus.dataset.type = 'error';
            goalStatus.textContent  = data.data || 'Save failed.';
        }
    }

    document.getElementById('metis-goal-save-btn')?.addEventListener('click', () => {
        const year   = parseInt(goalYearInput.value);
        const amount = parseFloat(goalAmtInput.value);
        if (!year || isNaN(amount) || amount < 0) { notify('Please enter a valid year and amount.', 'warning'); return; }
        saveGoal(year, amount);
    });

    document.getElementById('metis-goal-delete-btn')?.addEventListener('click', () => {
        const year = parseInt(goalYearInput.value);
        if (!(window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function')) return;
        Metis.confirm.open({
            title: 'Remove Campaign Goal',
            message: `Remove the ${year} goal for this campaign?`,
            confirmLabel: 'Remove Goal',
            tone: 'danger'
        }).then((confirmed) => {
            if (!confirmed) return;
            saveGoal(year, 0);
        });
    });

    // -------------------------------------------------------------------------
    // Edit campaign modal
    // -------------------------------------------------------------------------
    const editModal  = document.getElementById('metis-edit-campaign-modal');
    const editStatus = document.getElementById('metis-edit-status');

    document.getElementById('metis-edit-campaign-btn')?.addEventListener('click', () => {
        editStatus.style.display = 'none';
        editModal.style.display  = 'flex';
    });
    document.getElementById('metis-edit-modal-close')?.addEventListener('click',  () => editModal.style.display = 'none');
    document.getElementById('metis-edit-modal-cancel')?.addEventListener('click', () => editModal.style.display = 'none');
    editModal?.addEventListener('click', e => { if (e.target === editModal) editModal.style.display = 'none'; });

    document.getElementById('metis-edit-save-btn')?.addEventListener('click', async function () {
        const btn = this;
        btn.disabled = true; btn.textContent = 'Saving…';
        editStatus.style.display = 'block';
        editStatus.dataset.type  = 'busy';
        editStatus.textContent   = 'Saving…';

        const data = await postPayload({
            action: 'metis_campaign_save_info',
            nonce:  NONCE,
            cid:    CID,
            cname:  document.getElementById('metis-edit-cname')?.value  || '',
            type:   document.getElementById('metis-edit-type')?.value   || '',
            url:    document.getElementById('metis-edit-url')?.value    || '',
            active: document.getElementById('metis-edit-active')?.value || '1',
            public: document.getElementById('metis-edit-public')?.value || '1',
        });
        btn.disabled = false; btn.textContent = 'Save Changes';
        if (data.success) {
            editStatus.dataset.type = 'ok';
            editStatus.textContent  = 'Saved.';
            const nextName = document.getElementById('metis-edit-cname')?.value || '';
            const titleEl = document.querySelector('.metis-campaign-title');
            if (titleEl && nextName) {
                titleEl.textContent = nextName;
            }
            setTimeout(() => { editModal.style.display = 'none'; }, 500);
        } else {
            editStatus.dataset.type = 'error';
            editStatus.textContent  = data.data || 'Save failed.';
        }
    });

})();
</script>
