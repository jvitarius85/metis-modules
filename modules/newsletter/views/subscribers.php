<?php
if (!defined('METIS_ROOT')) exit;

if (!metis_newsletter_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view subscribers.</div>';
    return;
}

metis_newsletter_ensure_schema();
$db = metis_db();

$can_manage = metis_newsletter_can_manage();

$lists_table = Metis_Tables::get('newsletter_lists');
$subs_table = Metis_Tables::get('newsletter_subs');
$contacts_table = Metis_Tables::get('contacts');

$dashboard_url = metis_portal_url('newsletter', 'dashboard');
$campaigns_url = metis_portal_url('newsletter', 'campaigns');
$templates_url = metis_portal_url('newsletter', 'theme');
$lists_url = metis_portal_url('newsletter', 'lists');
$subscribers_url = metis_portal_url('newsletter', 'subscribers');
$contact_url_base = metis_portal_url('contacts', 'contact');

$list_rows = $db->fetchAll(
    "SELECT id, name FROM {$lists_table} WHERE is_active = 1 ORDER BY name ASC"
) ?: [];


$rows = $db->fetchAll(
    "SELECT
        c.cid,
        c.first_name,
        c.last_name,
        c.email,
        GROUP_CONCAT(DISTINCT l.name ORDER BY l.name SEPARATOR '||') AS list_names,
        MAX(s.updated_at) AS updated_at
     FROM {$subs_table} s
     INNER JOIN {$contacts_table} c ON c.id = s.contact_id
     INNER JOIN {$lists_table} l ON l.id = s.list_id
     WHERE s.status = 'subscribed' AND l.is_active = 1
     GROUP BY c.id, c.cid, c.first_name, c.last_name, c.email
     ORDER BY updated_at DESC
     LIMIT 1000"
) ?: [];
?>

<div class="metis-newsletter" data-can-manage="<?php echo metis_escape_attr($can_manage ? '1' : '0'); ?>">
    <h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Newsletter Subscribers' ) ); ?></h1>
    <p class="mw-subtitle">Review subscription status by contact and list.</p>

    <div id="metis-newsletter-alert" class="mw-alert" style="display:none;"></div>

    <div class="mw-list-layout">

    <!-- Sidebar -->
    <aside class="mw-list-sidebar">
        <div class="mw-list-sidebar-section">
            <div class="mw-list-sidebar-label">Newsletter</div>
            <nav class="mw-list-sidebar-nav">
                <a class="mw-list-sidebar-nav-item" href="<?php echo metis_escape_url($dashboard_url); ?>">Dashboard</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo metis_escape_url($campaigns_url); ?>">Campaigns</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo metis_escape_url($templates_url); ?>">Theme</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo metis_escape_url($lists_url); ?>">Lists</a>
                <a class="mw-list-sidebar-nav-item is-active" href="<?php echo metis_escape_url($subscribers_url); ?>">Subscribers</a>
            </nav>
        </div>
        <div class="mw-list-sidebar-section">
            <div class="mw-list-sidebar-label">Search</div>
            <input id="metis-newsletter-subscriber-search" class="mw-input" type="text" placeholder="Name, email, list, or CID">
        </div>
        <?php if ( $can_manage ) : ?>
        <div class="mw-list-sidebar-actions">
            <button id="metis-newsletter-batch-btn" type="button" class="mw-btn mw-btn-xs">Batch Add Subscribers</button>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main content -->
    <div class="mw-list-content">
    <?php if ( $can_manage ) : ?>
    <div id="metis-newsletter-batch-panel" style="display:none; margin-bottom:14px;">
        <div class="mbe-toolbar">
            <button type="button" class="mw-btn mw-btn-xs" data-batch-action="add">Add Row</button>
            <button type="button" class="mw-btn mw-btn-xs" data-batch-action="save">Save Valid Rows</button>
            <button type="button" id="metis-newsletter-batch-close" class="mw-btn mw-btn-xs mw-btn-ghost">Close</button>
        </div>
        <div id="metis-newsletter-batch-entry"></div>
    </div>
    <?php endif; ?>
    <section class="mw-premium-table metis-newsletter-table" id="metis-newsletter-subscribers-panel">
        <div class="mw-premium-row mw-premium-header">
            <div class="mw-premium-cell">Subscriber</div>
            <div class="mw-premium-cell">Lists</div>
            <div class="mw-premium-cell">CID</div>
            <div class="mw-premium-cell">Updated</div>
        </div>
        <div id="metis-newsletter-subscriber-rows">
            <?php foreach ($rows as $row) :
                $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                $cid = (string) ($row['cid'] ?? '');
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $list_names = array_values(array_filter(array_map('trim', explode('||', (string) ($row['list_names'] ?? ''))), static fn($x) => $x !== ''));
                $href = $cid !== '' ? ($contact_url_base . '?cid=' . rawurlencode($cid)) : '';
                $search_blob = strtolower(trim(implode(' ', [
                    $name,
                    $email,
                    $cid,
                    implode(' ', $list_names),
                ])));
            ?>
                <div class="mw-premium-row metis-newsletter-row" data-search="<?php echo metis_escape_attr($search_blob); ?>" <?php if ($href !== '') : ?>data-row-href="<?php echo metis_escape_url($href); ?>"<?php endif; ?>>
                    <div class="mw-premium-cell">
                        <strong><?php echo metis_escape_html($name !== '' ? $name : '—'); ?></strong>
                        <?php if ($email !== '') : ?><div class="mw-muted"><?php echo metis_escape_html($email); ?></div><?php endif; ?>
                    </div>
                    <div class="mw-premium-cell">
                        <div class="metis-newsletter-chip-wrap">
                            <?php foreach ($list_names as $list_name) : ?><span class="mw-chip"><?php echo metis_escape_html($list_name); ?></span><?php endforeach; ?>
                            <?php if (empty($list_names)) : ?><span class="mw-muted">—</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="mw-premium-cell"><?php echo metis_escape_html($cid !== '' ? $cid : '—'); ?></div>
                    <div class="mw-premium-cell"><?php echo metis_escape_html(metis_newsletter_format_datetime((string) ($row['updated_at'] ?? ''))); ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($rows)) : ?><div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No subscribers found.</div></div><?php endif; ?>
        </div>
    </section>

    </div><!-- /mw-list-content -->
    </div><!-- /mw-list-layout -->

    <script id="metis-newsletter-data" type="application/json"><?php echo metis_json_encode([
        'ui' => [
            'view' => 'subscribers',
        ],
    ]); ?></script>
</div>

<?php if ( $can_manage ) : ?>
<link rel="stylesheet" href="<?php echo metis_escape_url( metis_home_url( '/assets/runtime/batch-entry.css' ) ); ?>">
<script src="<?php echo metis_escape_url( metis_home_url( '/assets/runtime/batch-entry.js' ) ); ?>" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const batchToggle = document.getElementById('metis-newsletter-batch-btn');
    const batchPanel = document.getElementById('metis-newsletter-batch-panel');
    const batchClose = document.getElementById('metis-newsletter-batch-close');

    if (!batchToggle || !batchPanel) {
        return;
    }

    const listOptions = <?php echo metis_json_encode(array_map(static function(array $row): array {
        return [
            'value' => (string) ((int) ($row['id'] ?? 0)),
            'label' => (string) ($row['name'] ?? ''),
        ];
    }, $list_rows)); ?>;

    let instance = null;
    batchToggle.addEventListener('click', function () {
        batchPanel.style.display = '';
        if (!instance && window.BatchEntry && typeof window.BatchEntry.init === 'function') {
            instance = window.BatchEntry.init({
                module: 'newsletter',
                action: 'subscribers-create',
                container: '#metis-newsletter-batch-entry',
                fields: [
                    { key: 'first_name', label: 'First Name', type: 'text', required: false },
                    { key: 'last_name', label: 'Last Name', type: 'text', required: false },
                    { key: 'email', label: 'Email', type: 'email', required: true },
                    { key: 'list_id', label: 'List', type: 'select', required: true, options: listOptions },
                    { key: 'status', label: 'Status', type: 'select', required: false, options: [
                        { value: 'subscribed', label: 'Subscribed' },
                        { value: 'unsubscribed', label: 'Unsubscribed' },
                        { value: 'bounced', label: 'Bounced' },
                        { value: 'rejected', label: 'Rejected' }
                    ] }
                ],
                totals: [
                    { key: 'email', type: 'count', label: 'Rows Entered' }
                ],
                allowAddRow: true,
                allowDeleteRow: true,
                autoAppendRow: true,
                saveMode: 'valid_only',
                                endpointBase: '<?php echo metis_escape_js( metis_home_url( '/api/batch' ) ); ?>',
                nonce: '<?php echo metis_escape_js( metis_runtime_create_nonce( 'metis_batch_api' ) ); ?>'
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
