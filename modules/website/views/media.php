<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}
?>
<?php $media_library_url = function_exists( 'metis_portal_url' ) ? metis_portal_url( 'media', 'library' ) : ( function_exists( 'metis_admin_url' ) ? metis_admin_url( 'media/library' ) : '/media/library/' ); ?>
<div class="mw-page-header">
    <div class="mw-page-header-left">
        <h1 class="mw-page-title">Media</h1>
        <p class="mw-subtitle">Centralized media management for website content.</p>
    </div>
    <div class="mw-page-header-right">
        <a class="mw-btn mw-btn-primary" href="<?php echo metis_escape_url( $media_library_url ); ?>">Open Media Library</a>
    </div>
</div>

<div class="metis-table-wrap">
    <div class="metis-empty-state">
        <div class="metis-empty-state-icon">&#128247;</div>
        <h2>Media moved to the shared library</h2>
        <p>Use the centralized Media module for uploads, folders/categories, filters, and previews.</p>
        <a class="mw-btn mw-btn-primary" href="<?php echo metis_escape_url( $media_library_url ); ?>">Go to Media Library</a>
    </div>
</div>
