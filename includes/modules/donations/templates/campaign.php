<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;

$campaigns_table    = Metis_Tables::get( 'campaigns' );
$transactions_table = Metis_Tables::get( 'transactions' );
$contacts_table     = Metis_Tables::get( 'contacts' );
$base_url           = metis_donations_base_url();

$cid = isset( $_GET['cid'] ) ? sanitize_text_field( $_GET['cid'] ) : '';

if ( $cid === '' ) : ?>
    <h1 class="mw-page-title">Campaign Not Found</h1>
    <p class="mw-subtitle">No campaign ID was provided.</p>
    <a href="<?php echo esc_url( $base_url . '/campaigns/' ); ?>" class="mw-btn mw-btn-xs">← Back to Campaigns</a>
    <?php return;
endif;

$campaign = $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM {$campaigns_table} WHERE cid = %s LIMIT 1", $cid
) );

// Decode base64 cdesc if it doesn't look like HTML
if ( $campaign && ! empty( $campaign->cdesc ) ) {
    $raw = $campaign->cdesc;
    // If the stored value has no HTML tags, try base64 decode
    if ( strpos( $raw, '<' ) === false ) {
        $decoded = base64_decode( $raw, true );
        if ( $decoded !== false && $decoded !== $raw ) {
            // Persist decoded HTML back to DB
            $wpdb->update(
                $campaigns_table,
                [ 'cdesc' => $decoded ],
                [ 'cid'   => $cid ],
                [ '%s' ],
                [ '%s' ]
            );
            $campaign->cdesc = $decoded;
        }
    }
}

if ( ! $campaign ) : ?>
    <h1 class="mw-page-title">Campaign Not Found</h1>
    <p class="mw-subtitle">No campaign matched that ID.</p>
    <a href="<?php echo esc_url( $base_url . '/campaigns/' ); ?>" class="mw-btn mw-btn-xs">← Back to Campaigns</a>
    <?php return;
endif;

// -------------------------------------------------------------------------
// Transaction aggregates
// -------------------------------------------------------------------------
$agg = $wpdb->get_row( $wpdb->prepare( "
    SELECT
        COUNT(*)         AS gift_count,
        SUM(amount)      AS total_raised,
        SUM(amount + IFNULL(fee, 0)) AS total_gross,
        MIN(tran_date)   AS first_gift,
        MAX(tran_date)   AS last_gift,
        COUNT(DISTINCT did) AS donor_count
    FROM {$transactions_table}
    WHERE campaign_code = %s
", $cid ) );

// Annual breakdown
$yearly = $wpdb->get_results( $wpdb->prepare( "
    SELECT
        YEAR(tran_date)  AS year,
        COUNT(*)         AS gift_count,
        SUM(amount)      AS raised,
        COUNT(DISTINCT did) AS donors
    FROM {$transactions_table}
    WHERE campaign_code = %s
    GROUP BY YEAR(tran_date)
    ORDER BY year DESC
", $cid ) );

// Recent transactions
$transactions = $wpdb->get_results( $wpdb->prepare( "
    SELECT t.*, c.first_name, c.last_name, c.email
    FROM {$transactions_table} t
    LEFT JOIN {$contacts_table} c ON c.did = t.did
    WHERE t.campaign_code = %s
    ORDER BY t.tran_date DESC, t.id DESC
    LIMIT 100
", $cid ) );

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
$year_raised_raw = $wpdb->get_var( $wpdb->prepare(
    "SELECT SUM(amount) FROM {$transactions_table} WHERE campaign_code = %s AND YEAR(tran_date) = %d",
    $cid, $current_year
) );
$year_raised = (float) ( $year_raised_raw ?? 0 );
$year_pct    = ( $year_goal && $year_goal > 0 ) ? min( 100, round( ( $year_raised / $year_goal ) * 100, 1 ) ) : null;

$nonce = metis_create_nonce( 'metis_campaign_edit' );
metis_set_page_title( $campaign->cname );
?>

<p><a href="<?php echo esc_url( $base_url . '/campaigns/' ); ?>" class="mw-btn mw-btn-xs mw-btn-ghost">← All Campaigns</a></p>

<!-- PAGE HEADER -->
<div class="mw-space-between mw-campaign-detail-header">
    <div>
        <h1 class="mw-page-title" style="margin-bottom: 4px;"><?php echo esc_html( $campaign->cname ); ?></h1>
        <div class="mw-flex" style="gap: 8px; flex-wrap: wrap;">
            <span class="mw-badge <?php echo strtolower( $campaign->type ?? '' ) === 'ongoing' ? 'blue' : 'muted'; ?>">
                <?php echo esc_html( ucfirst( $campaign->type ?? 'Unknown' ) ); ?>
            </span>
            <span class="mw-badge <?php echo $campaign->active ? 'green' : 'gray'; ?>">
                <?php echo $campaign->active ? 'Active' : 'Inactive'; ?>
            </span>
            <?php if ( $campaign->public ) : ?>
                <span class="mw-badge muted">Public</span>
            <?php endif; ?>
            <span class="mw-muted" style="font-size: 13px; align-self: center;"><?php echo esc_html( $cid ); ?></span>
        </div>
    </div>
    <div class="mw-flex" style="gap: 8px;">
        <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="mw-edit-campaign-btn">Edit Campaign</button>
    </div>
</div>

<!-- KPI SUMMARY CARDS -->
<div class="mw-report-kpis" style="margin: 20px 0;">
    <div class="mw-kpi-card">
        <div class="mw-kpi-value">$<?php echo number_format( (float) ( $agg->total_raised ?? 0 ), 2 ); ?></div>
        <div class="mw-kpi-label">Total Net Raised</div>
    </div>
    <div class="mw-kpi-card">
        <div class="mw-kpi-value"><?php echo number_format( (int) ( $agg->gift_count ?? 0 ) ); ?></div>
        <div class="mw-kpi-label">Total Gifts</div>
    </div>
    <div class="mw-kpi-card">
        <div class="mw-kpi-value"><?php echo number_format( (int) ( $agg->donor_count ?? 0 ) ); ?></div>
        <div class="mw-kpi-label">Unique Donors</div>
    </div>
    <div class="mw-kpi-card">
        <?php
        $avg = $agg->gift_count > 0 ? ( $agg->total_raised / $agg->gift_count ) : 0;
        ?>
        <div class="mw-kpi-value">$<?php echo number_format( $avg, 2 ); ?></div>
        <div class="mw-kpi-label">Avg Gift</div>
    </div>
    <?php if ( $year_goal ) : ?>
    <div class="mw-kpi-card">
        <div class="mw-kpi-value"><?php echo $year_pct; ?>%</div>
        <div class="mw-kpi-label"><?php echo $current_year; ?> Goal Progress</div>
        <div class="mw-progress-bar-wrap" style="margin-top: 8px;">
            <div class="mw-progress-bar-fill <?php echo $year_pct >= 100 ? 'mw-progress-complete' : ( $year_pct >= 50 ? 'mw-progress-mid' : 'mw-progress-low' ); ?>"
                 style="width: <?php echo $year_pct; ?>%"></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- TWO-COLUMN LAYOUT: Goals + Info -->
<div class="mw-campaign-detail-grid">

    <!-- ANNUAL GOALS -->
    <div class="mw-campaign-detail-card">
        <div class="mw-campaign-card-header">
            <h2 class="mw-section-title" style="margin: 0;">Annual Goals</h2>
            <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="mw-add-goal-btn">+ Set Goal</button>
        </div>

        <div id="mw-goals-list">
            <?php if ( ! empty( $yearly ) ) : ?>
                <?php foreach ( $yearly as $yr ) :
                    $yr_num    = (int) $yr->year;
                    $yr_raised = (float) $yr->raised;
                    $yr_goal   = $goals[ $yr_num ] ?? null;
                    $yr_pct    = ( $yr_goal && $yr_goal > 0 ) ? min( 100, round( ( $yr_raised / $yr_goal ) * 100, 1 ) ) : null;
                ?>
                    <div class="mw-goal-row" data-year="<?php echo $yr_num; ?>">
                        <div class="mw-goal-year-label">
                            <?php echo $yr_num; ?>
                            <?php if ( $yr_num === $current_year ) echo '<span class="mw-badge blue" style="font-size:10px; margin-left:6px;">Current</span>'; ?>
                        </div>
                        <div class="mw-goal-stats">
                            <span class="mw-goal-raised">$<?php echo number_format( $yr_raised, 2 ); ?> raised</span>
                            <span class="mw-muted"> · <?php echo $yr->gift_count; ?> gifts</span>
                            <?php if ( $yr_goal ) : ?>
                                <span class="mw-muted"> · Goal: $<?php echo number_format( $yr_goal, 0 ); ?></span>
                            <?php else : ?>
                                <button type="button" class="mw-goal-set-link" data-year="<?php echo $yr_num; ?>">Set goal</button>
                            <?php endif; ?>
                        </div>
                        <?php if ( $yr_pct !== null ) : ?>
                            <div class="mw-progress-bar-wrap">
                                <div class="mw-progress-bar-fill <?php echo $yr_pct >= 100 ? 'mw-progress-complete' : ( $yr_pct >= 50 ? 'mw-progress-mid' : 'mw-progress-low' ); ?>"
                                     style="width: <?php echo $yr_pct; ?>%"></div>
                            </div>
                            <div class="mw-progress-label"><?php echo $yr_pct; ?>% of goal</div>
                        <?php endif; ?>
                        <?php if ( $yr_goal ) : ?>
                            <button type="button" class="mw-goal-edit-btn mw-muted" data-year="<?php echo $yr_num; ?>" data-amount="<?php echo esc_attr( $yr_goal ); ?>">Edit</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php // Show goals set for years with no transactions yet
                foreach ( $goals as $yr_num => $yr_goal ) :
                    $has_activity = false;
                    foreach ( $yearly as $yr ) { if ( (int) $yr->year === $yr_num ) { $has_activity = true; break; } }
                    if ( $has_activity ) continue;
                ?>
                    <div class="mw-goal-row mw-goal-row--no-activity" data-year="<?php echo $yr_num; ?>">
                        <div class="mw-goal-year-label">
                            <?php echo $yr_num; ?>
                            <?php if ( $yr_num === $current_year ) echo '<span class="mw-badge blue" style="font-size:10px; margin-left:6px;">Current</span>'; ?>
                        </div>
                        <div class="mw-goal-stats">
                            <span class="mw-muted">No activity</span>
                            <span class="mw-muted"> · Goal: $<?php echo number_format( $yr_goal, 0 ); ?></span>
                        </div>
                        <div class="mw-progress-bar-wrap">
                            <div class="mw-progress-bar-fill mw-progress-low" style="width: 0%"></div>
                        </div>
                        <div class="mw-progress-label">0% of goal</div>
                        <button type="button" class="mw-goal-edit-btn mw-muted" data-year="<?php echo $yr_num; ?>" data-amount="<?php echo esc_attr( $yr_goal ); ?>">Edit</button>
                    </div>
                <?php endforeach; ?>

            <?php else : ?>
                <p class="mw-muted" style="padding: 12px 0;">No giving history yet for this campaign.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- CAMPAIGN INFO -->
    <div class="mw-campaign-detail-card">
        <h2 class="mw-section-title">Campaign Info</h2>
        <div class="mw-campaign-info-grid">
            <div class="mw-info-row">
                <div class="small-label">Campaign ID</div>
                <div><?php echo esc_html( $campaign->cid ); ?></div>
            </div>
            <div class="mw-info-row">
                <div class="small-label">Type</div>
                <div><?php echo esc_html( ucfirst( $campaign->type ?? '—' ) ); ?></div>
            </div>
            <div class="mw-info-row">
                <div class="small-label">Status</div>
                <div><?php echo $campaign->active ? 'Active' : 'Inactive'; ?></div>
            </div>
            <div class="mw-info-row">
                <div class="small-label">Public</div>
                <div><?php echo $campaign->public ? 'Yes' : 'No'; ?></div>
            </div>
            <?php if ( $campaign->url ) : ?>
            <div class="mw-info-row">
                <div class="small-label">Donation URL</div>
                <div>
                    <a href="<?php echo esc_url( 'https://mobilizewaco.org' . $campaign->url ); ?>" target="_blank" class="mw-batch-link" style="font-size: 13px; word-break: break-all;">
                        mobilizewaco.org<?php echo esc_html( $campaign->url ); ?>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <div class="mw-info-row">
                <div class="small-label">First Gift</div>
                <div><?php echo $agg->first_gift ? esc_html( date( 'M j, Y', strtotime( $agg->first_gift ) ) ) : '—'; ?></div>
            </div>
            <div class="mw-info-row">
                <div class="small-label">Last Gift</div>
                <div><?php echo $agg->last_gift ? esc_html( date( 'M j, Y', strtotime( $agg->last_gift ) ) ) : '—'; ?></div>
            </div>
        </div>

        <!-- SHORTCODE -->
        <div style="margin-top: 20px;">
            <div class="small-label" style="margin-bottom: 6px;">Shortcode</div>
            <div class="mw-shortcode-wrap">
                <code class="mw-shortcode" id="mw-shortcode-val">[mw_campaign_progress cid="<?php echo esc_attr( $cid ); ?>"]</code>
                <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="mw-copy-shortcode">Copy</button>
            </div>
            <p class="mw-muted" style="font-size: 12px; margin-top: 6px;">
                Embeds a live donation progress bar for this campaign on any page or post.
            </p>
        </div>
    </div>

</div>

<!-- DESCRIPTION -->
<div class="mw-campaign-detail-card" style="margin-top: 16px;">
    <div class="mw-campaign-card-header">
        <h2 class="mw-section-title" style="margin: 0;">Campaign Description</h2>
        <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="mw-edit-desc-btn">Edit</button>
    </div>
    <div id="mw-desc-display">
        <?php if ( $campaign->cdesc ) : ?>
            <div class="mw-campaign-desc-body"><?php echo metis_kses_post( $campaign->cdesc ); ?></div>
        <?php else : ?>
            <p class="mw-muted">No description set. Click Edit to add one.</p>
        <?php endif; ?>
    </div>
    <!-- Editor wrapper: hidden until Edit clicked. Quill WYSIWYG. -->
    <div id="mw-desc-editor" style="display: none;">
        <div id="mw-quill-editor" style="min-height: 300px; font-size: 14px; line-height: 1.7;"></div>
        <div class="mw-flex" style="gap: 8px; margin-top: 12px;">
            <button type="button" class="mw-btn mw-btn-xs" id="mw-save-desc-btn">Save Description</button>
            <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="mw-cancel-desc-btn">Cancel</button>
        </div>
    </div>
</div>

<!-- RECENT TRANSACTIONS -->
<div class="mw-campaign-detail-card" style="margin-top: 16px;">
    <h2 class="mw-section-title">Recent Transactions</h2>

    <div class="mw-premium-table mw-batch-table">
        <div class="mw-premium-row mw-premium-header mw-batch-row">
            <div>Date</div>
            <div>Donor</div>
            <div>Amount</div>
            <div>Status</div>
        </div>

        <?php if ( ! empty( $transactions ) ) : ?>
            <?php foreach ( $transactions as $t ) :
                $donor_name  = trim( ( $t->first_name ?: '' ) . ' ' . ( $t->last_name ?: '' ) );
                $tx_url      = $base_url . '/transaction/?tid=' . urlencode( $t->tid );
                $display_date = $t->tran_date ? date( 'm/d/Y', strtotime( $t->tran_date ) ) : '—';
            ?>
                <div class="mw-premium-row mw-batch-row"
                     data-href="<?php echo esc_url( $tx_url ); ?>"
                     style="cursor: pointer;">
                    <div><?php echo esc_html( $display_date ); ?></div>
                    <div>
                        <div><?php echo esc_html( $donor_name ?: 'Unknown' ); ?></div>
                        <div class="mw-muted" style="font-size: 12px;"><?php echo esc_html( $t->did ?: '' ); ?></div>
                    </div>
                    <div class="mw-col-numeric">$<?php echo number_format( (float) $t->amount, 2 ); ?></div>
                    <div class="mw-tx-flags">
                        <?php echo metis_status_badge( $t->status ); ?>
                        <?php echo metis_deposit_badge( $t->deposit_date ); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="mw-premium-row">
                <div class="mw-muted">No transactions found for this campaign.</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- GOAL MODAL -->
<div id="mw-goal-modal" class="mw-modal-overlay" style="display: none;">
    <div class="mw-modal">
        <div class="mw-modal-header">
            <h3 id="mw-goal-modal-title">Set Annual Goal</h3>
            <button type="button" class="mw-modal-close" id="mw-goal-modal-close">×</button>
        </div>
        <div class="mw-modal-body">
            <div class="mw-report-field" style="margin-bottom: 16px;">
                <label>Year</label>
                <input type="number" id="mw-goal-year" class="mw-input" value="<?php echo $current_year; ?>" min="2015" max="2099">
            </div>
            <div class="mw-report-field">
                <label>Goal Amount ($)</label>
                <input type="number" id="mw-goal-amount" class="mw-input" placeholder="e.g. 10000" step="0.01" min="0">
            </div>
        </div>
        <div class="mw-modal-footer">
            <button type="button" class="mw-btn mw-btn-xs" id="mw-goal-save-btn">Save Goal</button>
            <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="mw-goal-modal-cancel">Cancel</button>
            <button type="button" class="mw-btn mw-btn-xs mw-btn-danger" id="mw-goal-delete-btn" style="display: none; margin-left: auto;">Remove Goal</button>
        </div>
        <div id="mw-goal-status" class="mw-report-status" style="display: none; margin-top: 12px;"></div>
    </div>
</div>

<!-- EDIT CAMPAIGN MODAL -->
<div id="mw-edit-campaign-modal" class="mw-modal-overlay" style="display: none;">
    <div class="mw-modal mw-modal--wide">
        <div class="mw-modal-header">
            <h3>Edit Campaign</h3>
            <button type="button" class="mw-modal-close" id="mw-edit-modal-close">×</button>
        </div>
        <div class="mw-modal-body">
            <div class="mw-edit-form-grid">
                <div class="mw-report-field">
                    <label>Campaign Name</label>
                    <input type="text" id="mw-edit-cname" class="mw-input" value="<?php echo esc_attr( $campaign->cname ); ?>">
                </div>
                <div class="mw-report-field">
                    <label>Type</label>
                    <select id="mw-edit-type" class="mw-select">
                        <option value="Ongoing" <?php selected( $campaign->type, 'Ongoing' ); ?>>Ongoing</option>
                        <option value="Project" <?php selected( $campaign->type, 'Project' ); ?>>Project</option>
                    </select>
                </div>
                <div class="mw-report-field">
                    <label>Donation URL (path only)</label>
                    <input type="text" id="mw-edit-url" class="mw-input" value="<?php echo esc_attr( $campaign->url ?? '' ); ?>" placeholder="/join/donate/">
                </div>
                <div class="mw-report-field">
                    <label>Status</label>
                    <select id="mw-edit-active" class="mw-select">
                        <option value="1" <?php selected( (int) $campaign->active, 1 ); ?>>Active</option>
                        <option value="0" <?php selected( (int) $campaign->active, 0 ); ?>>Inactive</option>
                    </select>
                </div>
                <div class="mw-report-field">
                    <label>Public</label>
                    <select id="mw-edit-public" class="mw-select">
                        <option value="1" <?php selected( (int) $campaign->public, 1 ); ?>>Yes</option>
                        <option value="0" <?php selected( (int) $campaign->public, 0 ); ?>>No</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="mw-modal-footer">
            <button type="button" class="mw-btn mw-btn-xs" id="mw-edit-save-btn">Save Changes</button>
            <button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" id="mw-edit-modal-cancel">Cancel</button>
        </div>
        <div id="mw-edit-status" class="mw-report-status" style="display: none; margin-top: 12px;"></div>
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
    document.querySelectorAll('.mw-batch-row[data-href]').forEach(row => {
        row.addEventListener('click', () => { window.location.href = row.dataset.href; });
    });

    // -------------------------------------------------------------------------
    // Copy shortcode
    // -------------------------------------------------------------------------
    document.getElementById('mw-copy-shortcode')?.addEventListener('click', function () {
        const val = document.getElementById('mw-shortcode-val')?.textContent || '';
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

    document.getElementById('mw-edit-desc-btn')?.addEventListener('click', () => {
        document.getElementById('mw-desc-display').style.display = 'none';
        document.getElementById('mw-desc-editor').style.display  = 'block';

        if (quillEditor) return; // already initialized

        loadQuill(() => {
            quillEditor = new Quill('#mw-quill-editor', {
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

    document.getElementById('mw-cancel-desc-btn')?.addEventListener('click', () => {
        document.getElementById('mw-desc-editor').style.display  = 'none';
        document.getElementById('mw-desc-display').style.display = 'block';
    });

    document.getElementById('mw-save-desc-btn')?.addEventListener('click', async function () {
        const content = quillEditor ? quillEditor.root.innerHTML : '';

        const btn = this;
        btn.disabled = true; btn.textContent = 'Saving…';
        const data = await postPayload({ action: 'metis_campaign_save_desc', nonce: NONCE, cid: CID, desc: content });
        btn.disabled = false; btn.textContent = 'Save Description';
        if (data.success) {
            const display = document.getElementById('mw-desc-display');
            display.innerHTML = content
                ? `<div class="mw-campaign-desc-body">${content}</div>`
                : `<p class="mw-muted">No description set. Click Edit to add one.</p>`;
            document.getElementById('mw-desc-editor').style.display  = 'none';
            display.style.display = 'block';
        } else {
            notify('Save failed. Please try again.', 'error');
        }
    });

    // -------------------------------------------------------------------------
    // Goal modal helpers
    // -------------------------------------------------------------------------
    const goalModal      = document.getElementById('mw-goal-modal');
    const goalYearInput  = document.getElementById('mw-goal-year');
    const goalAmtInput   = document.getElementById('mw-goal-amount');
    const goalStatus     = document.getElementById('mw-goal-status');
    const goalDeleteBtn  = document.getElementById('mw-goal-delete-btn');
    const goalModalTitle = document.getElementById('mw-goal-modal-title');

    function openGoalModal(year, amount) {
        goalYearInput.value  = year || new Date().getFullYear();
        goalAmtInput.value   = amount || '';
        goalModalTitle.textContent = amount ? 'Edit Annual Goal' : 'Set Annual Goal';
        goalDeleteBtn.style.display = amount ? 'inline-flex' : 'none';
        goalStatus.style.display = 'none';
        goalModal.style.display  = 'flex';
        goalAmtInput.focus();
    }

    document.getElementById('mw-add-goal-btn')?.addEventListener('click', () => openGoalModal(new Date().getFullYear(), null));
    document.getElementById('mw-goal-modal-close')?.addEventListener('click', () => goalModal.style.display = 'none');
    document.getElementById('mw-goal-modal-cancel')?.addEventListener('click', () => goalModal.style.display = 'none');
    goalModal?.addEventListener('click', e => { if (e.target === goalModal) goalModal.style.display = 'none'; });

    document.querySelectorAll('.mw-goal-edit-btn, .mw-goal-set-link').forEach(btn => {
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
            setTimeout(() => { goalModal.style.display = 'none'; location.reload(); }, 800);
        } else {
            goalStatus.dataset.type = 'error';
            goalStatus.textContent  = data.data || 'Save failed.';
        }
    }

    document.getElementById('mw-goal-save-btn')?.addEventListener('click', () => {
        const year   = parseInt(goalYearInput.value);
        const amount = parseFloat(goalAmtInput.value);
        if (!year || isNaN(amount) || amount < 0) { notify('Please enter a valid year and amount.', 'warning'); return; }
        saveGoal(year, amount);
    });

    document.getElementById('mw-goal-delete-btn')?.addEventListener('click', () => {
        const year = parseInt(goalYearInput.value);
        if (!confirm(`Remove the ${year} goal for this campaign?`)) return;
        saveGoal(year, 0); // 0 = remove
    });

    // -------------------------------------------------------------------------
    // Edit campaign modal
    // -------------------------------------------------------------------------
    const editModal  = document.getElementById('mw-edit-campaign-modal');
    const editStatus = document.getElementById('mw-edit-status');

    document.getElementById('mw-edit-campaign-btn')?.addEventListener('click', () => {
        editStatus.style.display = 'none';
        editModal.style.display  = 'flex';
    });
    document.getElementById('mw-edit-modal-close')?.addEventListener('click',  () => editModal.style.display = 'none');
    document.getElementById('mw-edit-modal-cancel')?.addEventListener('click', () => editModal.style.display = 'none');
    editModal?.addEventListener('click', e => { if (e.target === editModal) editModal.style.display = 'none'; });

    document.getElementById('mw-edit-save-btn')?.addEventListener('click', async function () {
        const btn = this;
        btn.disabled = true; btn.textContent = 'Saving…';
        editStatus.style.display = 'block';
        editStatus.dataset.type  = 'busy';
        editStatus.textContent   = 'Saving…';

        const data = await postPayload({
            action: 'metis_campaign_save_info',
            nonce:  NONCE,
            cid:    CID,
            cname:  document.getElementById('mw-edit-cname')?.value  || '',
            type:   document.getElementById('mw-edit-type')?.value   || '',
            url:    document.getElementById('mw-edit-url')?.value    || '',
            active: document.getElementById('mw-edit-active')?.value || '1',
            public: document.getElementById('mw-edit-public')?.value || '1',
        });
        btn.disabled = false; btn.textContent = 'Save Changes';
        if (data.success) {
            editStatus.dataset.type = 'ok';
            editStatus.textContent  = 'Saved. Reloading…';
            setTimeout(() => location.reload(), 800);
        } else {
            editStatus.dataset.type = 'error';
            editStatus.textContent  = data.data || 'Save failed.';
        }
    });

})();
</script>
