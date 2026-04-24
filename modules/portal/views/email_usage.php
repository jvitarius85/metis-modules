<?php if (!defined('METIS_ROOT')) exit; ?>
<?php
$settings_email_url = metis_portal_url('settings', 'email');
?>
<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Email Usage' ) ); ?></h1>
<p class="mw-subtitle">Email usage is now managed in Settings under Email.</p>
<div class="mw-premium-wrap" style="max-width:760px;padding:20px;">
    <h2 style="margin:0 0 10px;">Email Moved To Settings</h2>
    <div>
        <p>Use Settings → Email to manage default sender settings and review module-level usage.</p>
        <div style="display:flex;justify-content:flex-start;">
            <a class="mw-btn" href="<?php echo metis_escape_url($settings_email_url); ?>">Open Settings Email</a>
        </div>
    </div>
</div>
