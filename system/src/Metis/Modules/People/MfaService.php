<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class MfaService {
    public static function activePasskeys( int $person_id ): array {
        $passkeys_table = \Metis_Tables::get( 'people_passkeys' );

        return \metis_db()->fetchAll(
            "SELECT id, label, created_at, last_used_at
             FROM {$passkeys_table}
             WHERE person_id = %d AND revoked_at IS NULL
             ORDER BY created_at DESC",
            [ $person_id ]
        ) ?: [];
    }

    public static function getPersonIdentity( int $person_id ): ?array {
        $people_table = \Metis_Tables::get( 'people' );
        $row = \metis_db()->fetchOne(
            "SELECT id, pid, email, display_name FROM {$people_table} WHERE id = %d LIMIT 1",
            [ $person_id ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function activePasskeyCredentialIds( int $person_id ): array {
        $passkeys_table = \Metis_Tables::get( 'people_passkeys' );

        return \metis_db()->column(
            "SELECT credential_id FROM {$passkeys_table} WHERE person_id = %d AND revoked_at IS NULL",
            [ $person_id ]
        ) ?: [];
    }

    public static function storeTotpSecret( int $person_id, string $encrypted_secret ): void {
        $people_table = \Metis_Tables::get( 'people' );

        \metis_db()->update(
            $people_table,
            [
                'totp_secret_enc' => $encrypted_secret,
                'totp_enabled' => 1,
                'requires_2fa' => 1,
                'mfa_method' => 'totp',
            ],
            [ 'id' => $person_id ],
            [ '%s', '%d', '%d', '%s' ],
            [ '%d' ]
        );
    }

    public static function passkeyExistsByCredentialId( string $credential_id ): bool {
        $passkeys_table = \Metis_Tables::get( 'people_passkeys' );

        return (int) \metis_db()->scalar(
            "SELECT id FROM {$passkeys_table} WHERE credential_id = %s LIMIT 1",
            [ $credential_id ]
        ) > 0;
    }

    public static function registerPasskey( int $person_id, string $credential_id, string $attestation_object_b64, string $transports_json, string $label, ?int $actor_id ): array {
        $passkeys_table = \Metis_Tables::get( 'people_passkeys' );
        $people_table = \Metis_Tables::get( 'people' );

        $ok = \metis_db()->insert( $passkeys_table, [
            'person_id' => $person_id,
            'credential_id' => $credential_id,
            'credential_public_key' => $attestation_object_b64,
            'sign_count' => 0,
            'transports_json' => $transports_json !== '' ? $transports_json : null,
            'label' => $label !== '' ? $label : 'Passkey',
            'created_by_person_id' => $actor_id,
        ], [ '%d', '%s', '%s', '%d', '%s', '%s', '%d' ] );

        if ( ! $ok ) {
            \metis_runtime_send_json_error( 'Failed to persist passkey.', 500 );
        }

        $passkey_id = (int) \metis_db()->lastInsertId();
        \metis_db()->execute( \metis_db()->prepare(
            "UPDATE {$people_table}
             SET passkey_enabled = 1,
                 requires_2fa = 1,
                 mfa_method = CASE WHEN mfa_method = 'none' THEN 'passkey' ELSE mfa_method END
             WHERE id = %d",
            $person_id
        ) );

        return [
            'id' => $passkey_id,
            'label' => $label !== '' ? $label : 'Passkey',
            'created_at' => \metis_current_time( 'mysql' ),
        ];
    }

    public static function getPasskeyById( int $passkey_id ): ?array {
        $passkeys_table = \Metis_Tables::get( 'people_passkeys' );
        $row = \metis_db()->fetchOne(
            "SELECT id, person_id, label, revoked_at FROM {$passkeys_table} WHERE id = %d LIMIT 1",
            [ $passkey_id ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function revokePasskey( int $passkey_id ): void {
        $passkeys_table = \Metis_Tables::get( 'people_passkeys' );

        \metis_db()->update(
            $passkeys_table,
            [ 'revoked_at' => \metis_current_time( 'mysql' ) ],
            [ 'id' => $passkey_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    public static function activePasskeyCount( int $person_id ): int {
        $passkeys_table = \Metis_Tables::get( 'people_passkeys' );

        return (int) \metis_db()->scalar(
            "SELECT COUNT(*) FROM {$passkeys_table} WHERE person_id = %d AND revoked_at IS NULL",
            [ $person_id ]
        );
    }

    public static function disablePasskeyFlag( int $person_id ): void {
        $people_table = \Metis_Tables::get( 'people' );

        \metis_db()->update(
            $people_table,
            [ 'passkey_enabled' => 0 ],
            [ 'id' => $person_id ],
            [ '%d' ],
            [ '%d' ]
        );
    }

    public static function revokeAllActivePasskeys( int $person_id ): int {
        if ( ! \Metis_Tables::has( 'people_passkeys' ) ) {
            return 0;
        }

        $passkeys_table = \Metis_Tables::get( 'people_passkeys' );
        $active_rows = \metis_db()->fetchAll(
            "SELECT id FROM {$passkeys_table} WHERE person_id = %d AND revoked_at IS NULL",
            [ $person_id ]
        ) ?: [];

        $revoked = 0;
        foreach ( $active_rows as $row ) {
            $updated = \metis_db()->update(
                $passkeys_table,
                [ 'revoked_at' => \metis_current_time( 'mysql' ) ],
                [ 'id' => (int) ( $row['id'] ?? 0 ) ],
                [ '%s' ],
                [ '%d' ]
            );
            if ( $updated !== false ) {
                $revoked++;
            }
        }

        return $revoked;
    }

    public static function resetMfa( int $person_id ): bool {
        $people_table = \Metis_Tables::get( 'people' );
        $updated = \metis_db()->update(
            $people_table,
            [
                'requires_2fa' => 0,
                'mfa_method' => 'none',
                'totp_enabled' => 0,
                'passkey_enabled' => 0,
                'totp_secret_enc' => null,
                'updated_at' => \metis_current_time( 'mysql' ),
            ],
            [ 'id' => $person_id ],
            [ '%d', '%s', '%d', '%d', '%s', '%s' ],
            [ '%d' ]
        );

        return $updated !== false;
    }
}
