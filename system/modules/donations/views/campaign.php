<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$base_url           = metis_donations_base_url();

$cid = metis_donations_request_identifier( 'cid', 'campaign' );

if ( $cid === '' ) : ?>
    <h1 class="metis-page-title">Campaign Not Found</h1>
    <p class="metis-subtitle">No campaign ID was provided.</p>
    <a href="<?php echo metis_escape_url( $base_url . '/campaigns/' ); ?>" class="metis-btn metis-btn-xs">← Back to Campaigns</a>
    <?php return;
endif;
$snapshot = \Metis\Modules\Donations\ReadService::campaignDetailSnapshot( $cid );
$campaign = $snapshot['campaign'] ?? null;

// Decode base64 cdesc if it doesn't look like HTML.
if ( $campaign && ! empty( $campaign->cdesc ) ) {
    $raw = $campaign->cdesc;
    // If the stored value has no HTML tags, try base64 decode.
    if ( strpos( $raw, '<' ) === false ) {
        $decoded = base64_decode( $raw, true );
        if ( $decoded !== false && $decoded !== $raw ) {
            metis_db()->update(
                Metis_Tables::get( 'campaigns' ),
                [ 'cdesc' => $decoded ],
                [ 'cid'   => $cid ]
            );
            $campaign->cdesc = $decoded;
        }
    }
}

if ( $campaign ) {
    $normalized_desc = \Metis\Modules\Donations\CampaignService::normalizeDescriptionHtml( (string) ( $campaign->cdesc ?? '' ) );
    if ( $normalized_desc !== (string) ( $campaign->cdesc ?? '' ) ) {
        metis_db()->update(
            Metis_Tables::get( 'campaigns' ),
            [ 'cdesc' => $normalized_desc !== '' ? $normalized_desc : null ],
            [ 'cid'   => $cid ]
        );
        $campaign->cdesc = $normalized_desc;
    }
}

if ( ! $campaign ) : ?>
    <h1 class="metis-page-title">Campaign Not Found</h1>
    <p class="metis-subtitle">No campaign matched that ID.</p>
    <a href="<?php echo metis_escape_url( $base_url . '/campaigns/' ); ?>" class="metis-btn metis-btn-xs">← Back to Campaigns</a>
    <?php return;
endif;
$agg = $snapshot['agg'] ?? (object) [];
$yearly = $snapshot['yearly'] ?? [];
$transactions = $snapshot['transactions'] ?? [];

// -------------------------------------------------------------------------
// Parse goals
// -------------------------------------------------------------------------
// Use shared metis_parse_goals() from bootstrap, then sort
$goals_raw = metis_parse_goals( $campaign->goals );
krsort( $goals_raw );

$goals        = $goals_raw;
$current_year = (int) date( 'Y' );
$year_goal    = $goals[ $current_year ] ?? null;
$year_raised = (float) ( $snapshot['year_raised'] ?? 0 );
$year_pct    = ( $year_goal && $year_goal > 0 ) ? min( 100, round( ( $year_raised / $year_goal ) * 100, 1 ) ) : null;

$nonce = metis_runtime_create_nonce( 'metis_campaign_edit' );
metis_set_page_title( $campaign->cname );
$campaign_desc_html = \Metis\Modules\Donations\CampaignService::normalizeDescriptionHtml( (string) ( $campaign->cdesc ?? '' ) );
$editor_icon_base = function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/svg' ) : '/svg';
$editor_icon_fallback_base = function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/assets/Images/icons' ) : '/assets/Images/icons';
$action_nonces = [
    'metis_campaign_save_desc' => metis_runtime_create_nonce( metis_ajax_nonce_action( 'metis_campaign_save_desc' ) ),
    'metis_campaign_save_goal' => metis_runtime_create_nonce( metis_ajax_nonce_action( 'metis_campaign_save_goal' ) ),
    'metis_campaign_save_info' => metis_runtime_create_nonce( metis_ajax_nonce_action( 'metis_campaign_save_info' ) ),
];
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
        <?php if ( $campaign_desc_html !== '' ) : ?>
            <div class="metis-campaign-desc-body"><?php echo metis_runtime_kses_post( $campaign_desc_html ); ?></div>
        <?php else : ?>
            <p class="metis-muted">No description set. Click Edit to add one.</p>
        <?php endif; ?>
    </div>
    <div id="metis-desc-editor" class="metis-campaign-desc-editor" hidden>
        <div class="metis-rich-text metis-campaign-rich-text" data-metis-rich-root="campaign-description">
            <div class="metis-se-rich-toolbar">
                <div class="metis-se-rich-group">
                    <div class="metis-se-rich-dropdown">
                        <button type="button" class="metis-se-toolbtn metis-se-rich-menu-trigger" data-rich-toggle="menu" aria-label="Text style">
                            <img src="<?php echo metis_escape_attr( $editor_icon_base . '/text-scale' ); ?>" data-icon-fallback="<?php echo metis_escape_attr( $editor_icon_fallback_base . '/text-scale.svg' ); ?>" alt="" aria-hidden="true">
                        </button>
                        <div class="metis-se-rich-menu">
                            <button type="button" class="metis-se-toolbtn" data-rich-action="block" data-rich-value="P">Paragraph</button>
                            <button type="button" class="metis-se-toolbtn" data-rich-action="block" data-rich-value="H2">Heading 2</button>
                            <button type="button" class="metis-se-toolbtn" data-rich-action="block" data-rich-value="H3">Heading 3</button>
                            <button type="button" class="metis-se-toolbtn" data-rich-action="block" data-rich-value="BLOCKQUOTE">Quote</button>
                            <button type="button" class="metis-se-toolbtn" data-rich-action="block" data-rich-value="PRE">Code block</button>
                        </div>
                    </div>
                    <button type="button" class="metis-se-toolbtn metis-se-rich-icon-btn" data-rich-cmd="bold" aria-label="Bold">
                        <img src="<?php echo metis_escape_attr( $editor_icon_base . '/text-bold' ); ?>" data-icon-fallback="<?php echo metis_escape_attr( $editor_icon_fallback_base . '/text-bold.svg' ); ?>" alt="" aria-hidden="true">
                    </button>
                    <button type="button" class="metis-se-toolbtn metis-se-rich-icon-btn" data-rich-cmd="italic" aria-label="Italic">
                        <img src="<?php echo metis_escape_attr( $editor_icon_base . '/italic' ); ?>" data-icon-fallback="<?php echo metis_escape_attr( $editor_icon_fallback_base . '/italic.svg' ); ?>" alt="" aria-hidden="true">
                    </button>
                    <button type="button" class="metis-se-toolbtn metis-se-rich-icon-btn" data-rich-cmd="underline" aria-label="Underline">
                        <img src="<?php echo metis_escape_attr( $editor_icon_base . '/text-underline' ); ?>" data-icon-fallback="<?php echo metis_escape_attr( $editor_icon_fallback_base . '/text-underline.svg' ); ?>" alt="" aria-hidden="true">
                    </button>
                    <button type="button" class="metis-se-toolbtn metis-se-rich-icon-btn" data-rich-cmd="strikeThrough" aria-label="Strikethrough">
                        <img src="<?php echo metis_escape_attr( $editor_icon_base . '/text-strikethrough' ); ?>" data-icon-fallback="<?php echo metis_escape_attr( $editor_icon_fallback_base . '/text-strikethrough.svg' ); ?>" alt="" aria-hidden="true">
                    </button>
                </div>
                <div class="metis-se-rich-group">
                    <button type="button" class="metis-se-toolbtn metis-se-rich-icon-btn" data-rich-cmd="insertOrderedList" aria-label="Numbered list">
                        <img src="<?php echo metis_escape_attr( $editor_icon_base . '/list-ordered' ); ?>" data-icon-fallback="<?php echo metis_escape_attr( $editor_icon_fallback_base . '/list-ordered.svg' ); ?>" alt="" aria-hidden="true">
                    </button>
                    <button type="button" class="metis-se-toolbtn metis-se-rich-icon-btn" data-rich-cmd="insertUnorderedList" aria-label="Bulleted list">
                        <img src="<?php echo metis_escape_attr( $editor_icon_base . '/list-unordered' ); ?>" data-icon-fallback="<?php echo metis_escape_attr( $editor_icon_fallback_base . '/list-unordered.svg' ); ?>" alt="" aria-hidden="true">
                    </button>
                    <button type="button" class="metis-se-toolbtn metis-se-rich-icon-btn" data-rich-cmd="createLink" aria-label="Insert link">
                        <img src="<?php echo metis_escape_attr( $editor_icon_base . '/link' ); ?>" data-icon-fallback="<?php echo metis_escape_attr( $editor_icon_fallback_base . '/link.svg' ); ?>" alt="" aria-hidden="true">
                    </button>
                    <button type="button" class="metis-se-toolbtn metis-se-rich-icon-btn" data-rich-cmd="removeFormat" aria-label="Clear formatting">
                        <img src="<?php echo metis_escape_attr( $editor_icon_base . '/clear-formatting' ); ?>" data-icon-fallback="<?php echo metis_escape_attr( $editor_icon_fallback_base . '/clear-formatting.svg' ); ?>" alt="" aria-hidden="true">
                    </button>
                </div>
                <div class="metis-se-rich-group">
                    <button type="button" class="metis-se-toolbtn metis-se-rich-icon-btn" data-rich-cmd="justifyLeft" aria-label="Align left">
                        <img src="<?php echo metis_escape_attr( $editor_icon_base . '/text-align-left' ); ?>" data-icon-fallback="<?php echo metis_escape_attr( $editor_icon_fallback_base . '/text-align-left.svg' ); ?>" alt="" aria-hidden="true">
                    </button>
                    <button type="button" class="metis-se-toolbtn metis-se-rich-icon-btn" data-rich-cmd="justifyCenter" aria-label="Align center">
                        <img src="<?php echo metis_escape_attr( $editor_icon_base . '/text-align-center' ); ?>" data-icon-fallback="<?php echo metis_escape_attr( $editor_icon_fallback_base . '/text-align-center.svg' ); ?>" alt="" aria-hidden="true">
                    </button>
                    <button type="button" class="metis-se-toolbtn metis-se-rich-icon-btn" data-rich-cmd="justifyRight" aria-label="Align right">
                        <img src="<?php echo metis_escape_attr( $editor_icon_base . '/text-align-right' ); ?>" data-icon-fallback="<?php echo metis_escape_attr( $editor_icon_fallback_base . '/text-align-right.svg' ); ?>" alt="" aria-hidden="true">
                    </button>
                </div>
            </div>
            <div id="metis-campaign-desc-rich" class="metis-se-rich-editor metis-campaign-desc-rich" contenteditable="true" spellcheck="true" data-placeholder="Add campaign description..." data-metis-rich-editor="campaign-description"></div>
        </div>
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
<div id="metis-goal-modal" class="metis-modal-backdrop metis-campaign-modal-hidden" aria-hidden="true">
    <div class="metis-modal">
        <div class="metis-modal-header">
            <h3 id="metis-goal-modal-title">Set Annual Goal</h3>
            <button type="button" class="metis-modal-close" id="metis-goal-modal-close" data-modal-close="metis-goal-modal">×</button>
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
            <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-goal-modal-cancel" data-modal-close="metis-goal-modal">Cancel</button>
            <button type="button" class="metis-btn metis-btn-xs metis-btn-danger metis-campaign-goal-delete" id="metis-goal-delete-btn">Remove Goal</button>
        </div>
        <div id="metis-goal-status" class="metis-report-status metis-campaign-status"></div>
    </div>
</div>

<!-- EDIT CAMPAIGN MODAL -->
<div id="metis-edit-campaign-modal" class="metis-modal-backdrop metis-campaign-modal-hidden" aria-hidden="true">
    <div class="metis-modal metis-modal--wide">
        <div class="metis-modal-header">
            <h3>Edit Campaign</h3>
            <button type="button" class="metis-modal-close" id="metis-edit-modal-close" data-modal-close="metis-edit-campaign-modal">×</button>
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
            <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-edit-modal-cancel" data-modal-close="metis-edit-campaign-modal">Cancel</button>
        </div>
        <div id="metis-edit-status" class="metis-report-status metis-campaign-status"></div>
    </div>
</div>

<script>
(function () {
    const notify = (message, type) => Metis.util.notify(message, type || 'info');
    const CID   = <?php echo json_encode( $cid ); ?>;
    const NONCE = <?php echo json_encode( $nonce ); ?>;
    const ACTION_NONCES = <?php echo json_encode( $action_nonces ); ?>;
    const AJAX  = <?php echo json_encode( metis_ajax_endpoint_url() ); ?>;

    function postPayload(payload) {
        const body = new URLSearchParams(payload);
        const action = body.get('action') || '';
        if (action) {
            const localNonce = ACTION_NONCES[action] || '';
            body.set('metis_action_nonce', Metis.ajax.nonceFor(action, body.get('metis_action_nonce') || localNonce || body.get('nonce') || NONCE));
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

    const descDisplay = document.getElementById('metis-desc-display');
    const descEditorWrap = document.getElementById('metis-desc-editor');
    const descEditor = document.getElementById('metis-campaign-desc-rich');
    const descToolbar = descEditorWrap ? descEditorWrap.querySelector('[data-metis-rich-root="campaign-description"]') : null;
    let currentDescHtml = <?php echo json_encode( $campaign_desc_html ); ?>;
    let descEditorReady = false;

    function normalizeDescHtml(value) {
        if (window.Metis && Metis.ui && Metis.ui.richText) {
            return Metis.ui.richText.normalizeHtml(value || '');
        }
        return String(value || '');
    }

    function closeDescMenus() {
        if (window.Metis && Metis.ui && Metis.ui.richText) {
            Metis.ui.richText.closeMenus(descEditorWrap || document);
        }
    }

    function prepareDescEditor() {
        if (!descEditor || descEditorReady) {
            return;
        }
        descEditorReady = true;
        if (window.Metis && Metis.ui && Metis.ui.richText) {
            Metis.ui.richText.bindIconFallbacks(descEditorWrap || document);
        }
        descEditor.innerHTML = normalizeDescHtml(currentDescHtml || '<p></p>');
        descEditor.addEventListener('focus', () => {
            if (window.Metis && Metis.ui && Metis.ui.richText) {
                Metis.ui.richText.saveSelection(descEditor);
            }
        });
        ['mouseup', 'keyup', 'blur'].forEach((eventName) => {
            descEditor.addEventListener(eventName, () => {
                if (window.Metis && Metis.ui && Metis.ui.richText) {
                    Metis.ui.richText.saveSelection(descEditor);
                }
            });
        });
        descEditor.addEventListener('input', () => {
            if (window.Metis && Metis.ui && Metis.ui.richText) {
                Metis.ui.richText.normalizeEditor(descEditor);
                Metis.ui.richText.saveSelection(descEditor);
            }
        });
        descToolbar?.addEventListener('click', async (event) => {
            const toggle = event.target.closest('[data-rich-toggle="menu"]');
            if (toggle) {
                event.preventDefault();
                const dropdown = toggle.closest('.metis-se-rich-dropdown');
                descToolbar.querySelectorAll('.metis-se-rich-dropdown.is-open').forEach((node) => {
                    if (node !== dropdown) {
                        node.classList.remove('is-open');
                    }
                });
                if (dropdown) {
                    dropdown.classList.toggle('is-open');
                }
                return;
            }

            const action = event.target.closest('[data-rich-action]');
            if (action && window.Metis && Metis.ui && Metis.ui.richText) {
                event.preventDefault();
                Metis.ui.richText.applyAction(
                    descEditor,
                    String(action.getAttribute('data-rich-action') || ''),
                    String(action.getAttribute('data-rich-value') || ''),
                    String(action.getAttribute('data-rich-color') || '')
                );
                closeDescMenus();
                return;
            }

            const command = event.target.closest('[data-rich-cmd]');
            if (command && window.Metis && Metis.ui && Metis.ui.richText) {
                event.preventDefault();
                await Metis.ui.richText.applyCommand(descEditor, String(command.getAttribute('data-rich-cmd') || ''), '');
                closeDescMenus();
            }
        });
        document.addEventListener('click', (event) => {
            if (!descToolbar || descToolbar.contains(event.target)) {
                return;
            }
            closeDescMenus();
        });
    }

    document.getElementById('metis-edit-desc-btn')?.addEventListener('click', () => {
        if (!descDisplay || !descEditorWrap || !descEditor) {
            return;
        }
        prepareDescEditor();
        descDisplay.hidden = true;
        descEditorWrap.hidden = false;
        descEditor.innerHTML = normalizeDescHtml(currentDescHtml || '<p></p>');
        if (window.Metis && Metis.ui && Metis.ui.richText) {
            Metis.ui.richText.placeCaretAtEnd(descEditor);
        } else {
            descEditor.focus();
        }
    });

    document.getElementById('metis-cancel-desc-btn')?.addEventListener('click', () => {
        if (!descDisplay || !descEditorWrap) {
            return;
        }
        descEditorWrap.hidden = true;
        descDisplay.hidden = false;
        closeDescMenus();
    });

    document.getElementById('metis-save-desc-btn')?.addEventListener('click', async function () {
        const content = normalizeDescHtml(descEditor ? descEditor.innerHTML : '');

        const btn = this;
        btn.disabled = true; btn.textContent = 'Saving…';
        const data = await postPayload({
            action: 'metis_campaign_save_desc',
            nonce: NONCE,
            metis_action_nonce: ACTION_NONCES.metis_campaign_save_desc || '',
            cid: CID,
            desc: content
        });
        btn.disabled = false; btn.textContent = 'Save Description';
        if (data.success) {
            const savedDesc = normalizeDescHtml((data.data && data.data.desc) ? data.data.desc : content);
            currentDescHtml = savedDesc;
            descDisplay.innerHTML = savedDesc
                ? `<div class="metis-campaign-desc-body">${savedDesc}</div>`
                : `<p class="metis-muted">No description set. Click Edit to add one.</p>`;
            descEditorWrap.hidden = true;
            descDisplay.hidden = false;
            closeDescMenus();
            notify('Campaign description saved.', 'success');
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

    if (window.Metis && Metis.ui && Metis.ui.modal) {
        Metis.ui.modal.init(document);
    }

    function openGoalModal(year, amount) {
        goalYearInput.value  = year || new Date().getFullYear();
        goalAmtInput.value   = amount || '';
        goalModalTitle.textContent = amount ? 'Edit Annual Goal' : 'Set Annual Goal';
        goalDeleteBtn.style.display = amount ? 'inline-flex' : 'none';
        goalStatus.style.display = 'none';
        if (window.Metis && Metis.ui && Metis.ui.modal) {
            Metis.ui.modal.form('metis-goal-modal');
        }
        goalAmtInput.focus();
    }

    document.getElementById('metis-add-goal-btn')?.addEventListener('click', () => openGoalModal(new Date().getFullYear(), null));

    document.querySelectorAll('.metis-goal-edit-btn, .metis-goal-set-link').forEach(btn => {
        btn.addEventListener('click', () => openGoalModal(btn.dataset.year, btn.dataset.amount || null));
    });

    async function saveGoal(year, amount) {
        goalStatus.style.display = 'block';
        goalStatus.dataset.type  = 'busy';
        goalStatus.textContent   = 'Saving…';
        const data = await postPayload({
            action: 'metis_campaign_save_goal',
            nonce: NONCE,
            metis_action_nonce: ACTION_NONCES.metis_campaign_save_goal || '',
            cid: CID,
            year,
            amount
        });
        if (data.success) {
            goalStatus.dataset.type = 'ok';
            goalStatus.textContent  = 'Goal saved.';
            setTimeout(() => {
                if (window.Metis && Metis.ui && Metis.ui.modal) {
                    Metis.ui.modal.close('metis-goal-modal');
                }
            }, 500);
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
        if (window.Metis && Metis.ui && Metis.ui.modal) {
            Metis.ui.modal.form('metis-edit-campaign-modal');
        }
    });

    document.getElementById('metis-edit-save-btn')?.addEventListener('click', async function () {
        const btn = this;
        btn.disabled = true; btn.textContent = 'Saving…';
        editStatus.style.display = 'block';
        editStatus.dataset.type  = 'busy';
        editStatus.textContent   = 'Saving…';

        const data = await postPayload({
            action: 'metis_campaign_save_info',
            nonce:  NONCE,
            metis_action_nonce: ACTION_NONCES.metis_campaign_save_info || '',
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
            setTimeout(() => {
                if (window.Metis && Metis.ui && Metis.ui.modal) {
                    Metis.ui.modal.close('metis-edit-campaign-modal');
                }
            }, 500);
        } else {
            editStatus.dataset.type = 'error';
            editStatus.textContent  = data.data || 'Save failed.';
        }
    });

})();
</script>
