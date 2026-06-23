<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class WorkflowTemplateService {
    public static function listTemplates(): array {
        $db = \metis_db();
        $agenda_table = \Metis_Tables::get( 'board_agenda_templates' );
        $decision_table = \Metis_Tables::get( 'board_decision_templates' );

        return [
            'agenda' => $db->fetchAll(
                "SELECT id, template_code, name, description, default_items_json, sort_order, is_required, is_active
                 FROM {$agenda_table}
                 ORDER BY sort_order ASC, id ASC"
            ) ?: [],
            'decisions' => $db->fetchAll(
                "SELECT id, template_code, title, description, default_outcome, sort_order, is_active
                 FROM {$decision_table}
                 ORDER BY sort_order ASC, id ASC"
            ) ?: [],
        ];
    }

    public static function saveAgendaTemplate( array $post ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'board_agenda_templates' );

        $template_id = (int) ( $post['template_id'] ?? 0 );
        $name = \metis_text_clean( \metis_runtime_unslash( $post['name'] ?? '' ) );
        $description = \metis_textarea_clean( \metis_runtime_unslash( $post['description'] ?? '' ) );
        $sort_order = max( 0, (int) ( $post['sort_order'] ?? 0 ) );
        $is_required = (int) ( $post['is_required'] ?? 0 ) === 1 ? 1 : 0;
        $is_active = (int) ( $post['is_active'] ?? 1 ) === 1 ? 1 : 0;
        $default_items_raw = (string) \metis_runtime_unslash( $post['default_items_json'] ?? '[]' );
        $default_items = json_decode( $default_items_raw, true );
        if ( ! is_array( $default_items ) ) {
            $default_items = [];
        }
        if ( $name === '' ) {
            \metis_runtime_send_json_error( 'Template name is required.', 422 );
        }

        $payload = [
            'name' => $name,
            'description' => $description,
            'default_items_json' => \metis_json_encode( array_values( array_filter( array_map( 'strval', $default_items ), static function ( string $value ): bool {
                return trim( $value ) !== '';
            } ) ) ),
            'sort_order' => $sort_order,
            'is_required' => $is_required,
            'is_active' => $is_active,
        ];

        if ( $template_id > 0 ) {
            $ok = $db->update( $table, $payload, [ 'id' => $template_id ], [ '%s', '%s', '%s', '%d', '%d', '%d' ], [ '%d' ] );
            if ( $ok === false ) {
                \metis_runtime_send_json_error( 'Failed to update agenda template.', 500 );
            }
        } else {
            $payload['template_code'] = Support::generateCode( 'BS', $table, 'template_code' );
            $ok = $db->insert( $table, $payload, [ '%s', '%s', '%s', '%d', '%d', '%d', '%s' ] );
            if ( ! $ok ) {
                \metis_runtime_send_json_error( 'Failed to save agenda template.', 500 );
            }
            $template_id = (int) $db->lastInsertId();
        }

        return [ 'template_id' => $template_id ];
    }

    public static function deactivateAgendaTemplate( int $template_id ): array {
        if ( $template_id < 1 ) {
            \metis_runtime_send_json_error( 'Template is required.', 422 );
        }

        $ok = \metis_db()->update(
            \Metis_Tables::get( 'board_agenda_templates' ),
            [ 'is_active' => 0 ],
            [ 'id' => $template_id ],
            [ '%d' ],
            [ '%d' ]
        );
        if ( $ok === false ) {
            \metis_runtime_send_json_error( 'Failed to deactivate template.', 500 );
        }

        return [ 'template_id' => $template_id ];
    }

    public static function saveDecisionTemplate( array $post ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'board_decision_templates' );

        $template_id = (int) ( $post['template_id'] ?? 0 );
        $title = \metis_text_clean( \metis_runtime_unslash( $post['title'] ?? '' ) );
        $description = \metis_textarea_clean( \metis_runtime_unslash( $post['description'] ?? '' ) );
        $sort_order = max( 0, (int) ( $post['sort_order'] ?? 0 ) );
        $default_outcome = \metis_key_clean( \metis_runtime_unslash( $post['default_outcome'] ?? 'pending' ) );
        if ( ! in_array( $default_outcome, [ 'pending', 'approved', 'rejected', 'tabled' ], true ) ) {
            $default_outcome = 'pending';
        }
        $is_active = (int) ( $post['is_active'] ?? 1 ) === 1 ? 1 : 0;
        if ( $title === '' ) {
            \metis_runtime_send_json_error( 'Decision template title is required.', 422 );
        }

        $payload = [
            'title' => $title,
            'description' => $description,
            'sort_order' => $sort_order,
            'default_outcome' => $default_outcome,
            'is_active' => $is_active,
        ];

        if ( $template_id > 0 ) {
            $ok = $db->update( $table, $payload, [ 'id' => $template_id ], [ '%s', '%s', '%d', '%s', '%d' ], [ '%d' ] );
            if ( $ok === false ) {
                \metis_runtime_send_json_error( 'Failed to update decision template.', 500 );
            }
        } else {
            $payload['template_code'] = Support::generateCode( 'BT', $table, 'template_code' );
            $ok = $db->insert( $table, $payload, [ '%s', '%s', '%d', '%s', '%d', '%s' ] );
            if ( ! $ok ) {
                \metis_runtime_send_json_error( 'Failed to save decision template.', 500 );
            }
            $template_id = (int) $db->lastInsertId();
        }

        return [ 'template_id' => $template_id ];
    }

    public static function deactivateDecisionTemplate( int $template_id ): array {
        if ( $template_id < 1 ) {
            \metis_runtime_send_json_error( 'Template is required.', 422 );
        }

        $ok = \metis_db()->update(
            \Metis_Tables::get( 'board_decision_templates' ),
            [ 'is_active' => 0 ],
            [ 'id' => $template_id ],
            [ '%d' ],
            [ '%d' ]
        );
        if ( $ok === false ) {
            \metis_runtime_send_json_error( 'Failed to deactivate template.', 500 );
        }

        return [ 'template_id' => $template_id ];
    }
}
