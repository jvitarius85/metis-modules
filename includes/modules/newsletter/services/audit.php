<?php
if (!defined('ABSPATH')) exit;

function metis_newsletter_audit_log(string $action, string $entity_type, int $entity_id, array $meta = []): void {
    global $wpdb;
    $table = Metis_Tables::get('newsletter_audit');
    if (!metis_newsletter_table_exists($table)) return;

    $wpdb->insert(
        $table,
        [
            'audit_code' => metis_generate_code('NA', $table, 'audit_code'),
            'action' => sanitize_key($action),
            'entity_type' => sanitize_key($entity_type),
            'entity_id' => max(0, $entity_id),
            'user_id' => metis_current_user_id() ?: null,
            'meta_json' => metis_json_encode($meta),
            'created_at' => current_time('mysql'),
        ],
        ['%s', '%s', '%s', '%d', '%d', '%s', '%s']
    );

    metis_audit_log_activity('newsletter_' . sanitize_key($action), [
        'module' => 'newsletter',
        'resource' => [
            'type' => sanitize_key($entity_type),
            'id' => $entity_id > 0 ? (string) $entity_id : '',
            'label' => sanitize_key($action),
        ],
        'context' => $meta,
    ]);
}

function metis_newsletter_save_revision(string $entity_type, int $entity_id, string $doc_json, string $html_body, string $text_body, string $summary = ''): void {
    global $wpdb;
    $table = Metis_Tables::get('newsletter_revisions');
    if (!metis_newsletter_table_exists($table) || $entity_id < 1) return;

    $wpdb->insert(
        $table,
        [
            'revision_code' => metis_generate_code('NR', $table, 'revision_code'),
            'entity_type' => sanitize_key($entity_type),
            'entity_id' => $entity_id,
            'summary' => sanitize_text_field($summary),
            'doc_json' => $doc_json,
            'html_body' => $html_body,
            'text_body' => $text_body,
            'created_by' => metis_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ],
        ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s']
    );
}
