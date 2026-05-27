<?php
declare(strict_types=1);

namespace Metis\Modules\Newsletter;

final class TemplateService {
    public static function resolveId( string $template_code ): int {
        $template_code = trim( $template_code );
        if ( $template_code === '' ) {
            return 0;
        }

        return (int) \metis_db()->scalar(
            'SELECT id FROM ' . \Metis_Tables::get( 'newsletter_templates' ) . ' WHERE template_code = %s LIMIT 1',
            [ $template_code ]
        );
    }

    public static function save( int $template_id, array $payload ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'newsletter_templates' );
        $format = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

        if ( $template_id > 0 ) {
            $ok = $db->update( $table, $payload, [ 'id' => $template_id ], $format, [ '%d' ] );
            return [
                'success' => $ok !== false,
                'template_id' => $template_id,
                'template_code' => self::codeById( $template_id ),
            ];
        }

        if ( \function_exists( 'metis_entity_id_service' ) ) {
            $payload = \metis_entity_id_service()->assignForInsert( 'newsletter_template', $payload );
        } else {
            $payload['template_code'] = \metis_generate_code( 'NT', $table, 'template_code' );
        }
        $payload['created_by'] = \metis_current_user_id() ?: null;

        $ok = $db->insert( $table, $payload, [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ] );
        if ( $ok === false ) {
            return [ 'success' => false, 'template_id' => 0, 'template_code' => '' ];
        }

        $template_id = (int) $db->lastInsertId();
        if ( $template_id > 0 && \function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->register( 'newsletter_template', $template_id, (string) ( $payload['newsletter_template_uid'] ?? $payload['template_code'] ?? '' ) );
        }

        return [
            'success' => true,
            'template_id' => $template_id,
            'template_code' => self::codeById( $template_id ),
        ];
    }

    public static function get( int $template_id, string $template_code = '' ): ?array {
        $table = \Metis_Tables::get( 'newsletter_templates' );
        if ( trim( $template_code ) !== '' ) {
            $row = \metis_db()->fetchOne(
                "SELECT id, template_code, name, subject, from_name, from_email, reply_to, doc_json, html_body, text_body, is_active, updated_at
                 FROM {$table}
                 WHERE template_code = %s
                 LIMIT 1",
                [ $template_code ]
            );
        } else {
            $row = \metis_db()->fetchOne(
                "SELECT id, template_code, name, subject, from_name, from_email, reply_to, doc_json, html_body, text_body, is_active, updated_at
                 FROM {$table}
                 WHERE id = %d
                 LIMIT 1",
                [ $template_id ]
            );
        }

        return is_array( $row ) ? $row : null;
    }

    public static function codeById( int $template_id ): string {
        if ( $template_id < 1 ) {
            return '';
        }

        return (string) \metis_db()->scalar(
            'SELECT template_code FROM ' . \Metis_Tables::get( 'newsletter_templates' ) . ' WHERE id = %d LIMIT 1',
            [ $template_id ]
        );
    }
}
