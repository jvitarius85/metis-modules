<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

// The Import module handles the full import workflow at /metis/import/
// This view provides a quick-launch panel that redirects there.
$import_url = metis_portal_url( 'import', 'dashboard' );

if ( ! headers_sent() ) {
    metis_safe_redirect( $import_url );
    exit;
}
?>
<div class="mw-page-header">
    <div class="mw-page-header-left">
        <h1 class="mw-page-title">Import</h1>
        <p class="mw-subtitle">Launch WXR and Beaver Builder import workflows with preview-first review.</p>
    </div>
</div>

<script>
(function() {
    var importUrl = <?php echo metis_json_encode( $import_url ); ?>;
    if (window.Metis && Metis.navigation && typeof Metis.navigation.replace === 'function') {
        Metis.navigation.replace(importUrl);
        return;
    }
    window.location.replace(importUrl);
}());
</script>
<div class="mw-card">
    <h2>Import</h2>
    <p>Redirecting to the import dashboard.</p>
    <p><a href="<?php echo metis_escape_url( $import_url ); ?>" class="mw-btn mw-btn-primary">Open Import Tool</a></p>
</div>
