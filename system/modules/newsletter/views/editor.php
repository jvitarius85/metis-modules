<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! metis_newsletter_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view newsletter.</div>';
    return;
}

metis_newsletter_ensure_schema();

$editor_key = (string) metis_get_query_var( 'metis_editor_key' );
$editor_new = (string) metis_get_query_var( 'metis_editor_new' );
$editor_context = (string) metis_get_query_var( 'metis_editor_context' );
$editor_kind = (string) metis_get_query_var( 'metis_editor_kind' );

if ( $editor_key === '' && $editor_new === '' ) {
    $path = (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ?? '' );
    if ( preg_match( '#/newsletter/editor/new/(campaign|template)/?$#i', $path, $m ) ) {
        $editor_kind = strtolower( (string) $m[1] ) === 'template' ? 'template' : 'campaign';
        $editor_context = 'newsletter';
        $editor_new = $editor_kind === 'template' ? 'newsletter_template' : 'newsletter_campaign';
    } elseif ( preg_match( '#/newsletter/editor/(campaign|template)/([A-Za-z0-9_-]+)/?$#i', $path, $m ) ) {
        $editor_kind = strtolower( (string) $m[1] ) === 'template' ? 'template' : 'campaign';
        $editor_context = 'newsletter';
        $editor_key = (string) $m[2];
    }
}

$context = ( $editor_kind === 'template' || $editor_new === 'newsletter_template' ) ? 'newsletter_template' : 'newsletter';
$editor_id = 0;
$editor_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_newsletter' ) : '';

if ( $editor_key !== '' ) {
    $db = metis_db();
    if ( $context === 'newsletter_template' ) {
        $templates_table = \Metis_Tables::get( 'newsletter_templates' );
        $editor_id = (int) $db->scalar(
            "SELECT id FROM {$templates_table} WHERE template_code = %s LIMIT 1",
            [ $editor_key ]
        );
    } else {
        $campaigns_table = \Metis_Tables::get( 'newsletter_campaigns' );
        $editor_id = (int) $db->scalar(
            "SELECT id FROM {$campaigns_table} WHERE campaign_code = %s LIMIT 1",
            [ $editor_key ]
        );
    }
}
?>
<div id="metis-editor-inline-root"></div>
<div
    id="metis-editor-bootstrap"
    data-editor-key="<?php echo metis_escape_attr( $editor_key ); ?>"
    data-editor-new="<?php echo metis_escape_attr( $editor_new ); ?>"
    data-editor-id="<?php echo metis_escape_attr( (string) $editor_id ); ?>"
    data-editor-nonce="<?php echo metis_escape_attr( $editor_nonce ); ?>"
    data-editor-context="<?php echo metis_escape_attr( $context ); ?>"
    data-editor-kind="<?php echo metis_escape_attr( $editor_kind ); ?>"
></div>
<div id="metis-editor-boot-status" class="metis-editor-boot-status">
    <div class="metis-editor-boot-card">
        <div class="metis-editor-boot-title">Loading Editor</div>
        <div class="metis-editor-boot-copy">Preparing newsletter editor...</div>
    </div>
</div>
