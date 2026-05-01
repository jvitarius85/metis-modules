<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_cms_require_view_permission( 'import' ) ) {
    return;
}

// The Import module handles the full import workflow at /metis/import/
// This view provides a quick-launch panel that redirects there.
$import_url = metis_portal_url( 'import', 'dashboard' );

if ( ! headers_sent() ) {
    metis_safe_redirect( $import_url );
    exit;
}
?>
<div class="metis-page-header">
    <div class="metis-page-header-left">
        <h1 class="metis-page-title">Import</h1>
        <p class="metis-subtitle">Launch WXR and Beaver Builder import workflows with preview-first review.</p>
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
<div class="metis-card">
    <h2>Import</h2>
    <p>Redirecting to the import dashboard.</p>
    <p><a href="<?php echo metis_escape_url( $import_url ); ?>" class="metis-btn metis-btn-primary">Open Import Tool</a></p>
</div>
