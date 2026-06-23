<?php
declare(strict_types=1);

namespace Metis\Modules\Forms;

use Metis\Modules\Forms\Concerns\SharedRepositoryLogic;

final class FormDefinitionRepository {
    use SharedRepositoryLogic;

    public static function blankForm(): array {
        $settings = self::defaultSettings();
        $schema = [];

        return [
            'id'                   => 0,
            'form_uuid'            => '',
            'slug'                 => '',
            'name'                 => 'Untitled form',
            'description'          => '',
            'status'               => 'draft',
            'latest_version_id'    => 0,
            'published_version_id' => 0,
            'payment_enabled'      => 0,
            'schema'               => $schema,
            'settings'             => $settings,
            'public_url'           => '',
            'submission_count'     => 0,
            'payments_enabled'     => false,
            'module_label'         => 'Unassigned',
            'versions'             => [],
        ];
    }

    public static function adminOptions(): array {
        SchemaManager::ensureSchema();

        return [
            'payment_defaults' => self::paymentDefaults(),
            'email_defaults'   => self::emailDefaults(),
            'campaigns'        => self::campaignOptions(),
            'users'            => self::userOptions(),
            'roles'            => Support::roleOptions(),
            'modules'          => Support::moduleOptions(),
            'module_flows'     => Support::moduleFlows(),
            'datasets'         => [
                'grandys_categories' => self::grandyCategoryOptions(),
                'grandys_items'      => self::grandyItemOptions(),
            ],
            'field_types'      => [
                [ 'type' => 'text', 'label' => 'Text', 'icon' => 'Aa' ],
                [ 'type' => 'email', 'label' => 'Email', 'icon' => '@' ],
                [ 'type' => 'number', 'label' => 'Number', 'icon' => '#' ],
                [ 'type' => 'textarea', 'label' => 'Long text', 'icon' => '¶' ],
                [ 'type' => 'select', 'label' => 'Dropdown', 'icon' => '▾' ],
                [ 'type' => 'radio', 'label' => 'Radio group', 'icon' => '◉' ],
                [ 'type' => 'checkbox', 'label' => 'Checkboxes', 'icon' => '☑' ],
                [ 'type' => 'date', 'label' => 'Date', 'icon' => '◷' ],
                [ 'type' => 'repeater', 'label' => 'Repeater', 'icon' => '⋮' ],
                [ 'type' => 'payment', 'label' => 'Payment', 'icon' => '$' ],
            ],
        ];
    }

    public static function listForms( int $limit = 100 ): array {
        SchemaManager::ensureSchema();

        $limit = max( 1, min( 500, $limit ) );
        $forms = self::table( 'forms' );
        $submissions = self::table( 'form_submissions' );
        $rows = self::db()->fetchAll(
            "SELECT f.*, COALESCE(s.submission_count, 0) AS submission_count
             FROM {$forms} f
             LEFT JOIN (
                SELECT form_id, COUNT(*) AS submission_count
                FROM {$submissions}
                GROUP BY form_id
             ) s ON s.form_id = f.id
             ORDER BY f.updated_at DESC, f.id DESC
             LIMIT %d",
            [ $limit ]
        );

        $formsOut = [];
        foreach ( $rows as $row ) {
            $settings = self::normalizeSettings( self::decodeJson( $row['settings_json'] ?? '' ) );
            $formsOut[] = [
                'id'               => (int) ( $row['id'] ?? 0 ),
                'slug'             => (string) ( $row['slug'] ?? '' ),
                'name'             => (string) ( $row['name'] ?? '' ),
                'description'      => (string) ( $row['description'] ?? '' ),
                'status'           => (string) ( $row['status'] ?? 'draft' ),
                'submission_count' => (int) ( $row['submission_count'] ?? 0 ),
                'payments_enabled' => ! empty( $row['payment_enabled'] ),
                'module_label'     => self::moduleLabel( (string) ( $settings['binding']['module'] ?? '' ) ),
                'public_url'       => Support::publicUrl( (string) ( $row['slug'] ?? '' ) ),
                'updated_at'       => (string) ( $row['updated_at'] ?? '' ),
            ];
        }

        return $formsOut;
    }

    public static function getFormById( int $form_id, bool $publishedOnly = false ): ?array {
        SchemaManager::ensureSchema();
        if ( $form_id < 1 ) {
            return null;
        }

        $forms = self::table( 'forms' );
        $row = self::db()->fetchOne( "SELECT * FROM {$forms} WHERE id = %d LIMIT 1", [ $form_id ] );
        if ( ! is_array( $row ) ) {
            return null;
        }

        if ( $publishedOnly && (string) ( $row['status'] ?? 'draft' ) !== 'published' ) {
            return null;
        }

        return self::hydrateFormRow( $row, $publishedOnly );
    }

    public static function getFormBySlug( string $slug, bool $publishedOnly = true ): ?array {
        SchemaManager::ensureSchema();
        $slug = \metis_slug_clean( $slug );
        if ( $slug === '' ) {
            return null;
        }

        $forms = self::table( 'forms' );
        $args = [ $slug ];
        $sql = "SELECT * FROM {$forms} WHERE slug = %s";
        if ( $publishedOnly ) {
            $sql .= " AND status = 'published'";
        }
        $sql .= ' LIMIT 1';

        $row = self::db()->fetchOne( $sql, $args );
        if ( ! is_array( $row ) ) {
            return null;
        }

        return self::hydrateFormRow( $row, $publishedOnly );
    }

    public static function saveForm( array $payload, int $user_id = 0 ): array {
        SchemaManager::ensureSchema();

        $normalized = self::normalizeIncomingForm( $payload );
        if ( $normalized['name'] === '' ) {
            return [ 'ok' => false, 'status' => 422, 'error' => 'Form name is required.' ];
        }

        $db = self::db();
        $forms = self::table( 'forms' );
        $versions = self::table( 'form_versions' );
        $form_id = (int) ( $normalized['id'] ?? 0 );
        $existing = $form_id > 0 ? self::getFormById( $form_id, false ) : null;
        $version_number = $existing ? ( (int) ( $existing['version_number'] ?? 0 ) + 1 ) : 1;
        $schema_json = self::encodeJson( $normalized['schema'] );
        $settings_json = self::encodeJson( $normalized['settings'] );
        $checksum = hash( 'sha256', $schema_json . '|' . $settings_json );
        $status = (string) $normalized['status'];
        $now = self::now();
        $form_uuid = $existing['form_uuid'] ?? \metis_generate_code( 'FRM', $forms, 'form_uuid' );
        $slug = self::uniqueSlug( $normalized['slug'], $form_id );

        $db->execute( 'START TRANSACTION' );

        try {
            if ( $existing ) {
                $updated = $db->update(
                    $forms,
                    [
                        'slug'            => $slug,
                        'name'            => $normalized['name'],
                        'description'     => $normalized['description'],
                        'status'          => $status,
                        'payment_enabled' => ! empty( $normalized['payment_enabled'] ) ? 1 : 0,
                        'settings_json'   => $settings_json,
                        'updated_by'      => $user_id > 0 ? $user_id : null,
                        'updated_at'      => $now,
                    ],
                    [ 'id' => $form_id ],
                    [ '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ],
                    [ '%d' ]
                );
                if ( $updated === false ) {
                    throw new \RuntimeException( 'Failed to update form.' );
                }
            } else {
                $inserted = $db->insert(
                    $forms,
                    [
                        'form_uuid'       => (string) $form_uuid,
                        'slug'            => $slug,
                        'name'            => $normalized['name'],
                        'description'     => $normalized['description'],
                        'status'          => $status,
                        'payment_enabled' => ! empty( $normalized['payment_enabled'] ) ? 1 : 0,
                        'settings_json'   => $settings_json,
                        'created_by'      => $user_id > 0 ? $user_id : null,
                        'updated_by'      => $user_id > 0 ? $user_id : null,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ],
                    [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s' ]
                );
                if ( ! $inserted ) {
                    throw new \RuntimeException( 'Failed to create form.' );
                }
                $form_id = (int) $db->lastInsertId();
            }

            if ( $form_id < 1 ) {
                throw new \RuntimeException( 'Form identifier was not created.' );
            }

            $version_inserted = $db->insert(
                $versions,
                [
                    'form_id'        => $form_id,
                    'version_number' => $version_number,
                    'schema_json'    => $schema_json,
                    'checksum'       => $checksum,
                    'notes'          => $status === 'published' ? 'Published' : 'Draft save',
                    'is_published'   => $status === 'published' ? 1 : 0,
                    'created_by'     => $user_id > 0 ? $user_id : null,
                    'created_at'     => $now,
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ]
            );
            if ( ! $version_inserted ) {
                throw new \RuntimeException( 'Failed to create form version.' );
            }

            $version_id = (int) $db->lastInsertId();
            if ( $version_id < 1 ) {
                throw new \RuntimeException( 'Form version identifier was not created.' );
            }

            $pointer_payload = [
                'latest_version_id' => $version_id,
                'status'            => $status,
                'updated_by'        => $user_id > 0 ? $user_id : null,
                'updated_at'        => $now,
            ];
            $pointer_formats = [ '%d', '%s', '%d', '%s' ];

            if ( $status === 'published' ) {
                $pointer_payload['published_version_id'] = $version_id;
                $pointer_formats[] = '%d';
                $publish_reset = $db->update(
                    $versions,
                    [ 'is_published' => 0 ],
                    [ 'form_id' => $form_id ],
                    [ '%d' ],
                    [ '%d' ]
                );
                if ( $publish_reset === false ) {
                    throw new \RuntimeException( 'Failed to reset published version markers.' );
                }
                $mark_live = $db->update(
                    $versions,
                    [ 'is_published' => 1 ],
                    [ 'id' => $version_id ],
                    [ '%d' ],
                    [ '%d' ]
                );
                if ( $mark_live === false ) {
                    throw new \RuntimeException( 'Failed to mark published version.' );
                }
            }

            $pointers = $db->update(
                $forms,
                $pointer_payload,
                [ 'id' => $form_id ],
                $pointer_formats,
                [ '%d' ]
            );
            if ( $pointers === false ) {
                throw new \RuntimeException( 'Failed to update form version pointers.' );
            }

            $db->execute( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $db->execute( 'ROLLBACK' );
            \Metis_Logger::error(
                'forms.save_failed',
                [
                    'module'  => 'forms',
                    'service' => 'save',
                    'error'   => $e->getMessage(),
                    'form_id' => $form_id,
                ]
            );

            return [ 'ok' => false, 'status' => 500, 'error' => 'Form save failed.' ];
        }

        $saved = self::getFormById( $form_id, false );
        if ( ! is_array( $saved ) ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Form could not be reloaded after save.' ];
        }

        $saved_canonical = self::normalizeIncomingForm(
            [
                'id'          => (int) ( $saved['id'] ?? 0 ),
                'name'        => (string) ( $saved['name'] ?? '' ),
                'slug'        => (string) ( $saved['slug'] ?? '' ),
                'description' => (string) ( $saved['description'] ?? '' ),
                'status'      => (string) ( $saved['status'] ?? 'draft' ),
                'settings'    => (array) ( $saved['settings'] ?? [] ),
                'schema'      => (array) ( $saved['schema'] ?? [] ),
            ]
        );
        $saved_checksum = hash(
            'sha256',
            self::encodeJson( $saved_canonical['schema'] ?? [] ) . '|' . self::encodeJson( $saved_canonical['settings'] ?? [] )
        );
        if ( $saved_checksum !== $checksum ) {
            return [ 'ok' => false, 'status' => 409, 'error' => 'Saved form did not match the requested changes.' ];
        }

        return [ 'ok' => true, 'status' => 200, 'form' => $saved ];
    }

    public static function publishForm( int $form_id, ?array $payload = null, int $user_id = 0 ): array {
        if ( is_array( $payload ) ) {
            $payload['id'] = $form_id > 0 ? $form_id : (int) ( $payload['id'] ?? 0 );
            $payload['status'] = 'published';
            return self::saveForm( $payload, $user_id );
        }

        $form = self::getFormById( $form_id, false );
        if ( ! is_array( $form ) ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Form not found.' ];
        }

        $payload = [
            'id'          => (int) $form['id'],
            'name'        => (string) $form['name'],
            'slug'        => (string) $form['slug'],
            'description' => (string) $form['description'],
            'status'      => 'published',
            'settings'    => (array) $form['settings'],
            'schema'      => (array) $form['schema'],
        ];

        return self::saveForm( $payload, $user_id );
    }

    public static function duplicateForm( int $form_id, int $user_id = 0 ): array {
        $form = self::getFormById( $form_id, false );
        if ( ! is_array( $form ) ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Form not found.' ];
        }

        $payload = [
            'name'        => (string) $form['name'] . ' Copy',
            'slug'        => (string) $form['slug'] . '-copy',
            'description' => (string) $form['description'],
            'status'      => 'draft',
            'settings'    => (array) $form['settings'],
            'schema'      => (array) $form['schema'],
        ];

        return self::saveForm( $payload, $user_id );
    }

    public static function canonicalizeFormPayload( array $payload ): array {
        return self::normalizeIncomingForm( $payload );
    }

    public static function deleteForm( int $form_id ): array {
        SchemaManager::ensureSchema();
        if ( $form_id < 1 ) {
            return [ 'ok' => false, 'status' => 404, 'error' => 'Form not found.' ];
        }

        $db = self::db();
        $db->execute( 'START TRANSACTION' );
        try {
            $db->delete( self::table( 'form_payment_sessions' ), [ 'form_id' => $form_id ], [ '%d' ] );
            $db->delete( self::table( 'form_submissions' ), [ 'form_id' => $form_id ], [ '%d' ] );
            $db->delete( self::table( 'form_versions' ), [ 'form_id' => $form_id ], [ '%d' ] );
            $deleted = $db->delete( self::table( 'forms' ), [ 'id' => $form_id ], [ '%d' ] );
            if ( $deleted === false ) {
                throw new \RuntimeException( 'Failed to delete form.' );
            }
            $db->execute( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $db->execute( 'ROLLBACK' );
            return [ 'ok' => false, 'status' => 500, 'error' => 'Form delete failed.' ];
        }

        return [ 'ok' => true, 'status' => 200 ];
    }
}
