<?php
if (!defined('METIS_ROOT')) exit;

function metis_newsletter_audit_log(string $action, string $entity_type, int $entity_id, array $meta = []): void {
    $db = metis_db();
    $table = Metis_Tables::get('newsletter_audit');
    if (!metis_newsletter_table_exists($table)) return;

    $db->insert(
        $table,
        [
            'audit_code' => metis_generate_code('NA', $table, 'audit_code'),
            'action' => metis_key_clean($action),
            'entity_type' => metis_key_clean($entity_type),
            'entity_id' => max(0, $entity_id),
            'user_id' => metis_current_user_id() ?: null,
            'meta_json' => metis_json_encode($meta),
            'created_at' => metis_current_time('mysql'),
        ]
    );

    metis_audit_log_activity('newsletter_' . metis_key_clean($action), [
        'module' => 'newsletter',
        'resource' => [
            'type' => metis_key_clean($entity_type),
            'id' => $entity_id > 0 ? (string) $entity_id : '',
            'label' => metis_key_clean($action),
        ],
        'context' => $meta,
    ]);
}

function metis_newsletter_save_revision(string $entity_type, int $entity_id, string $doc_json, string $html_body, string $text_body, string $summary = ''): void {
    $db = metis_db();
    $table = Metis_Tables::get('newsletter_revisions');
    if (!metis_newsletter_table_exists($table) || $entity_id < 1) return;

    $db->insert(
        $table,
        [
            'revision_code' => metis_generate_code('NR', $table, 'revision_code'),
            'entity_type' => metis_key_clean($entity_type),
            'entity_id' => $entity_id,
            'summary' => metis_text_clean($summary),
            'doc_json' => $doc_json,
            'html_body' => $html_body,
            'text_body' => $text_body,
            'created_by' => metis_current_user_id() ?: null,
            'created_at' => metis_current_time('mysql'),
        ]
    );
}
